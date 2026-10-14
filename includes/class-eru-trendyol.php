<?php
/**
 * Trendyol ana sınıfı (singleton).
 * - Özel `trendyol-yeni` sipariş statüsü
 * - 5 dakikalık cron senkronizasyonu
 * - Manuel "Şimdi çek" (admin-post)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Trendyol {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    protected function __construct() {
        // Ayar sayfası
        Eru_Trendyol_Settings::get_instance();

        // Sipariş ekranı metabox'ı (fatura/vergi/kargo görünürlüğü)
        Eru_Trendyol_Order_Meta_Box::get_instance();

        // Ürün stok senkronizasyonu (Trendyol'a outbound; Settings'ten Aktif/Pasif)
        Eru_Trendyol_Stock_Sync::get_instance();

        // Özel statü
        add_action('init', array($this, 'register_status'));
        add_filter('wc_order_statuses', array($this, 'add_status_to_list'));

        // Cron
        add_action(ERU_TRENDYOL_CRON_HOOK, array($this, 'cron_sync'));

        // Manuel çekme
        add_action('admin_post_eru_trendyol_manual_sync', array($this, 'handle_manual_sync'));
        // Tek sipariş çekme (no ile)
        add_action('admin_post_eru_trendyol_import_one', array($this, 'handle_import_one'));

        // Outbound: bizde sipariş "Trendyol - Sipariş Alındı"ya geçince Trendyol'a Picking bildir.
        add_action('woocommerce_order_status_trendyol-alindi', array($this, 'notify_trendyol_picking'), 10, 2);
        // Nefeslik süre sonrası "Hazırlanıyor"a ilerletme (zamanlanmış tek seferlik event).
        add_action('eru_trendyol_advance_hazir', array($this, 'advance_to_hazir'), 10, 1);

        // CRM köprüsü GÜVENLİK AĞI: bir Trendyol siparişi İZOLE-DIŞI bir yolla (manuel admin
        // dropdown, toplu işlem, programatik hook, ERP update-order-status) CRM-relevant bir statüye
        // (trendyol-yeni/hazir/iptal/iade) çekilirse köprüyü yine tetikle. isolated_save bu hook'u save
        // boyunca söktüğü için cron/importer yolundaki AÇIK çağrılarla ÇAKIŞMAZ; ayrıca
        // maybe_fire_crm_sync idempotent (meta guard) + dinleyen tarafta ayrıca kontrol edilebilir.
        add_action('woocommerce_order_status_changed', array($this, 'on_status_changed_crm_bridge'), 20, 4);

        // Cron kaydı düşmüşse tamamla (deaktif/aktif olmadan)
        add_action('init', array($this, 'maybe_schedule_cron'));

        // Sipariş KALICI silinince dedupe kaydını da temizle ki paket BAŞTAN içeri alınabilsin.
        // (Aksi halde eru_trendyol_orders satırı ölü wc_order_id'yi işaret eder; yeniden çekiş "güncelle"
        //  yoluna düşüp boşa çıkar.) Çöpe atma DAHİL DEĞİL — geri yüklemede mükerrer olmasın; çöbü
        //  boşaltınca/kalıcı silince bu hook'lar yine tetiklenir. HPOS + eski posts yolları kapsanır.
        add_action('woocommerce_before_delete_order', array($this, 'cleanup_dedupe_on_delete'), 10, 1);
        add_action('before_delete_post', array($this, 'cleanup_dedupe_on_delete'), 10, 1);
    }

    /**
     * Güvenlik ağı: bir Trendyol siparişi İZOLE-DIŞI bir yolla CRM-relevant statüye (yeni/hazir/iptal/
     * iade) geçtiğinde ERP/CRM köprüsünü tetikler. importer'ın izole-save yollarındaki açık çağrıları
     * ile meta guard sayesinde çakışmaz (o yollarda bu hook zaten sökülüdür).
     *
     * @param int    $order_id
     * @param string $old_status
     * @param string $new_status  wc- öneksiz slug (ör. "trendyol-hazir")
     * @param mixed  $order
     */
    public function on_status_changed_crm_bridge($order_id, $old_status, $new_status, $order) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order || $order->get_meta('_eru_trendyol_source') !== 'trendyol') {
            return;
        }
        $importer = new Eru_Trendyol_Importer();
        $importer->maybe_fire_crm_sync($order, $new_status);

        // İzole-dışı yolla (manuel admin, toplu işlem, ERP) iptal/iade'ye çekildiyse: aynı Trendyol
        // sipariş no'lu aktif paket varsa iptal paketin kodunu 99 önekiyle ayrıştır (ERP kod çakışması).
        if (in_array($new_status, array('trendyol-iptal', 'trendyol-iade'), true)) {
            $importer->reconcile_duplicate_codes((string) $order->get_meta('_eru_trendyol_order_number'));
        }
    }

    /**
     * Bir Trendyol siparişi silindiğinde/çöpe atıldığında eru_trendyol_orders dedupe satırını siler.
     * Böylece aynı paket sonradan SIFIRDAN (build_order yoluyla) yeniden içeri alınabilir.
     *
     * @param int $order_id Silinen sipariş/post ID'si.
     */
    public function cleanup_dedupe_on_delete($order_id) {
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return; // shop_order değilse (örn. başka bir post türü) dokunma.
        }
        if ($order->get_meta('_eru_trendyol_source') !== 'trendyol') {
            return; // Trendyol siparişi değilse dokunma.
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'eru_trendyol_orders';
        $package_id = (int) $order->get_meta('_eru_trendyol_package_id');

        // Önce paket id ile (UNIQUE anahtar), yoksa wc_order_id ile temizle.
        $deleted = 0;
        if ($package_id > 0) {
            $deleted = (int) $wpdb->delete($table, array('shipment_package_id' => $package_id), array('%d'));
        }
        if ($deleted === 0) {
            $deleted = (int) $wpdb->delete($table, array('wc_order_id' => $order_id), array('%d'));
        }

        if ($deleted > 0 && class_exists('Eru_Trendyol_Logger')) {
            Eru_Trendyol_Logger::info(sprintf(
                'Sipariş #%d silindi/çöpe atıldı → dedupe kaydı temizlendi (paket #%d). Paket yeniden çekilebilir.',
                $order_id,
                $package_id
            ));
        }
    }

    protected function __clone() {}
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }

    /**
     * Eklentinin Trendyol sipariş statüleri (wc- öneksiz slug => etiket).
     * Importer::map_status() bu slug'lara eşler.
     */
    public static function statuses() {
        return array(
            ERU_TRENDYOL_STATUS        => _x('Trendyol - Yeni', 'Order status', 'eru-trendyol'),
            // Bize özel, ELLE geçirilen ara statü — Trendyol API statüsüyle EŞLEŞMEZ (map_status üretmez).
            'trendyol-alindi' => _x('Trendyol - Sipariş Alındı', 'Order status', 'eru-trendyol'),
            // Trendyol Picking/Invoiced karşılığı (hazırlık aşaması).
            'trendyol-hazir'   => _x('Trendyol - Hazırlanıyor', 'Order status', 'eru-trendyol'),
            'trendyol-kargo'     => _x('Trendyol - Kargolandı', 'Order status', 'eru-trendyol'),
            'trendyol-teslim'     => _x('Trendyol - Teslim Edildi', 'Order status', 'eru-trendyol'),
            'trendyol-iptal'      => _x('Trendyol - İptal', 'Order status', 'eru-trendyol'),
            'trendyol-iade'       => _x('Trendyol - İade', 'Order status', 'eru-trendyol'),
        );
    }

    public function register_status() {
        foreach (self::statuses() as $slug => $label) {
            register_post_status('wc-' . $slug, array(
                'label'                     => $label,
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                /* translators: %s: sipariş sayısı */
                'label_count'               => _n_noop(
                    $label . ' <span class="count">(%s)</span>',
                    $label . ' <span class="count">(%s)</span>',
                    'eru-trendyol'
                ),
            ));
        }
    }

    public function add_status_to_list($statuses) {
        $trendyol = self::statuses();
        $new = array();
        foreach ($statuses as $key => $label) {
            $new[$key] = $label;
            if ('wc-processing' === $key) {
                foreach ($trendyol as $slug => $t_label) {
                    $new['wc-' . $slug] = $t_label;
                }
            }
        }
        // 'wc-processing' yoksa sona ekle.
        foreach ($trendyol as $slug => $t_label) {
            if (!isset($new['wc-' . $slug])) {
                $new['wc-' . $slug] = $t_label;
            }
        }
        return $new;
    }

    public function maybe_schedule_cron() {
        if (!wp_next_scheduled(ERU_TRENDYOL_CRON_HOOK)) {
            wp_schedule_event(time() + 60, ERU_TRENDYOL_CRON_INTERVAL, ERU_TRENDYOL_CRON_HOOK);
        }
    }

    /**
     * Bizde sipariş "Trendyol - Sipariş Alındı"ya geçince: hemen bir şey yapma, sadece
     * ERU_TRENDYOL_PICKING_DWELL (vars. 30) sn sonrasına bir event zamanla. Süre dolunca
     * advance_to_hazir hem Trendyol'a "İşleme Al" (Picking) bildirir HEM statüyü Hazırlanıyor'a çeker.
     *
     * Yalnız gerçek statü geçişinde tetiklenir; içe-aktarım isolated_save ile statü hook'larını
     * nötrlediği ve map_status bu statüyü üretmediği için import sırasında ÇALIŞMAZ.
     *
     * @param int      $order_id
     * @param WC_Order $order
     */
    public function notify_trendyol_picking($order_id, $order = null) {
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order || $order->get_meta('_eru_trendyol_source') !== 'trendyol') {
            return;
        }
        if (!(int) $order->get_meta('_eru_trendyol_package_id')) {
            return;
        }

        // "Sipariş Alındı"ya giriş anını damgala — cron fallback dwell hesabı için.
        $order->update_meta_data('_eru_trendyol_alindi_at', time());
        $order->save();

        // Trendyol'a "İşleme Al" (Picking) bildirimini HEMEN ve SENKRON gönder. Eskiden bu deferred
        // WP-Cron event'inin içindeydi; canlıda event güvenilir ateşlenmediği için Trendyol hiç haber
        // almıyordu. Idempotent (bir kez gönderilir).
        $this->push_picking($order);

        // Statüyü dwell sonrası "Hazırlanıyor"a çek: hızlı yol tek-seferlik event, GARANTİ fallback
        // 5 dk'lık cron (advance_pending_alindi). Böylece event ateşlenmese bile takılı kalmaz.
        $dwell = defined('ERU_TRENDYOL_PICKING_DWELL') ? (int) ERU_TRENDYOL_PICKING_DWELL : 30;
        wp_clear_scheduled_hook('eru_trendyol_advance_hazir', array($order_id));
        wp_schedule_single_event(time() + max(5, $dwell), 'eru_trendyol_advance_hazir', array($order_id));

        Eru_Trendyol_Logger::info(sprintf('Sipariş #%d "Sipariş Alındı"; Picking bildirildi, %dsn sonra Hazırlanıyor.', $order_id, $dwell));
    }

    /**
     * Trendyol'a "İşleme Al" (Picking) bildirir. Idempotent: bir kez gönderilmişse tekrar göndermez.
     * Test modunda dış yazma yapılmaz.
     *
     * @return bool Gönderildi/zaten gönderilmişse true; başarısız/atlandıysa false.
     */
    private function push_picking($order) {
        if ($order->get_meta('_eru_trendyol_picking_pushed') === 'yes') {
            return true; // zaten bildirildi
        }
        $package_id = (int) $order->get_meta('_eru_trendyol_package_id');
        if (!$package_id) {
            return false;
        }

        $settings = Eru_Trendyol_Settings::get();
        if (!empty($settings['test_mode'])) {
            Eru_Trendyol_Logger::info(sprintf('Test modu: paket #%d Picking bildirimi atlandı (sipariş #%d).', $package_id, $order->get_id()));
            return false;
        }

        $client = new Eru_Trendyol_Client($settings);
        $lines  = $this->picking_lines_from_order($order);
        $res    = $client->update_package_status($package_id, 'Picking', $lines);
        if (is_wp_error($res)) {
            Eru_Trendyol_Logger::error(
                sprintf('Paket #%d Picking bildirimi başarısız: %s', $package_id, $res->get_error_message()),
                array('order_id' => $order->get_id(), 'package_id' => $package_id, 'data' => $res->get_error_data())
            );
            $order->add_order_note('Trendyol "İşleme Al" (Picking) bildirimi BAŞARISIZ: ' . $res->get_error_message());
            $order->save();
            return false; // damga yazılmaz → sonraki turda (event/cron) tekrar denenir
        }

        $order->update_meta_data('_eru_trendyol_picking_pushed', 'yes');
        $order->update_meta_data('_eru_trendyol_picking_pushed_at', current_time('mysql'));
        $order->add_order_note('Trendyol bilgilendirildi: paket "İşleme alındı" (Picking).');
        $order->save();
        return true;
    }

    /**
     * Zamanlanmış event (Sipariş Alındı + dwell sonrası): hızlı yol. Sipariş hâlâ "Sipariş Alındı"da
     * ise Picking'i garantiler (eksikse) ve statüyü "Hazırlanıyor"a çeker.
     */
    public function advance_to_hazir($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_eru_trendyol_source') !== 'trendyol') {
            return;
        }
        // Araya başka geçiş (iptal/manuel) girdiyse dokunma.
        if ($order->get_status() !== 'trendyol-alindi') {
            return;
        }
        $this->do_advance_to_hazir($order);
    }

    /**
     * "Sipariş Alındı" → "Hazırlanıyor" ilerletmenin ortak çekirdeği (hem hızlı event hem cron
     * fallback kullanır). Picking eksikse idempotent olarak bildirir, statüyü izole çeker, CRM köprüsü.
     */
    private function do_advance_to_hazir($order) {
        // Picking henüz gitmediyse burada (idempotent) dener — sync push başarısız olduysa retry.
        $this->push_picking($order);

        // Statüyü "Hazırlanıyor"a çek (izole — ESD/kargo tetiklenmeden).
        $importer = new Eru_Trendyol_Importer();
        $importer->update_status_isolated($order, 'trendyol-hazir', 'Trendyol akışı: "Hazırlanıyor" statüsüne geçirildi.');

        // CRM köprüsü: trendyol-hazir => NEGATİF (ERP'ye aktarım; site "processing" karşılığı).
        // Pozitif sipariş düştüğünde (trendyol-yeni/import) gitti; burada denge tamamlanır.
        // İzole save DIŞINDA, tek sefer.
        $importer->maybe_fire_crm_sync($order, 'trendyol-hazir');

        Eru_Trendyol_Logger::info(sprintf('Sipariş #%d "Hazırlanıyor"a geçirildi.', $order->get_id()));
    }

    /**
     * GARANTİ fallback: dwell'i dolmuş "Sipariş Alındı" siparişlerini 5 dk'lık cron'da Hazırlanıyor'a
     * ilerletir (+ eksikse Picking bildirir). Hızlı-yol event'i (eru_trendyol_advance_hazir) çeşitli
     * sebeplerle ateşlenmese bile sipariş takılı kalmaz. Idempotent.
     */
    private function advance_pending_alindi() {
        $dwell = defined('ERU_TRENDYOL_PICKING_DWELL') ? (int) ERU_TRENDYOL_PICKING_DWELL : 30;

        $order_ids = wc_get_orders(array(
            'status'     => 'trendyol-alindi',
            'limit'      => 50,
            'return'     => 'ids',
            'meta_key'   => '_eru_trendyol_source',
            'meta_value' => 'trendyol',
            'orderby'    => 'date',
            'order'      => 'ASC',
        ));
        if (empty($order_ids)) {
            return;
        }

        $now = time();
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order || $order->get_status() !== 'trendyol-alindi') {
                continue;
            }
            $at = (int) $order->get_meta('_eru_trendyol_alindi_at');
            // Damga varsa dwell dolmadan dokunma; damga yoksa (fix öncesi takılı sipariş) hemen ilerlet.
            if ($at > 0 && ($now - $at) < $dwell) {
                continue;
            }
            $this->do_advance_to_hazir($order);
        }
    }

    /**
     * Ham Trendyol paket verisinden (_eru_trendyol_raw) Picking için lineId+quantity listesi kurar.
     */
    private function picking_lines_from_order($order) {
        $raw  = $order->get_meta('_eru_trendyol_raw');
        $data = $raw ? json_decode($raw, true) : null;

        $lines = array();
        if (is_array($data) && !empty($data['lines']) && is_array($data['lines'])) {
            foreach ($data['lines'] as $line) {
                if (isset($line['id'])) {
                    $lines[] = array(
                        'lineId'   => (int) $line['id'],
                        'quantity' => isset($line['quantity']) ? max(1, (int) $line['quantity']) : 1,
                    );
                }
            }
        }
        return $lines;
    }

    /** Cron callback. */
    public function cron_sync() {
        $settings = Eru_Trendyol_Settings::get();
        if (empty($settings['enabled'])) {
            return;
        }
        $this->sync($settings);
        // Hızlı-yol event'i ateşlenmese bile dwell'i dolmuş "Sipariş Alındı"ları garanti ilerlet.
        $this->advance_pending_alindi();
    }

    /** Manuel "Şimdi çek". */
    public function handle_manual_sync() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Yetkisiz.');
        }
        check_admin_referer('eru_trendyol_manual_sync');

        $settings = Eru_Trendyol_Settings::get();
        $this->sync($settings, true);

        wp_safe_redirect(add_query_arg(
            array('page' => Eru_Trendyol_Settings::SLUG, 'eru_trendyol_synced' => 1),
            admin_url('admin.php')
        ));
        exit;
    }

    /** Tek siparişi numarasıyla içeri al (karşılaştırma/test). */
    public function handle_import_one() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Yetkisiz.');
        }
        check_admin_referer('eru_trendyol_import_one');

        $order_number = isset($_POST['order_number']) ? sanitize_text_field(wp_unslash($_POST['order_number'])) : '';
        $msg = $this->import_single($order_number);

        wp_safe_redirect(add_query_arg(
            array('page' => Eru_Trendyol_Settings::SLUG, 'eru_trendyol_one' => rawurlencode($msg)),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Verilen sipariş numarasını çekip içeri alır. Statü/tarih filtresi ve test modu BYPASS.
     *
     * @return string Kullanıcıya gösterilecek sonuç mesajı
     */
    public function import_single($order_number) {
        $order_number = trim((string) $order_number);
        if ($order_number === '') {
            return 'Sipariş numarası boş.';
        }

        $settings = Eru_Trendyol_Settings::get();
        $client   = new Eru_Trendyol_Client($settings);
        if (!$client->is_configured()) {
            return 'API kimlik bilgileri eksik.';
        }

        $packages = $client->fetch_by_order_number($order_number);
        if (is_wp_error($packages)) {
            $err = 'API hatası: ' . $packages->get_error_message();
            Eru_Trendyol_Logger::error($err, $packages->get_error_data());
            return $err;
        }
        if (empty($packages)) {
            return 'Bu sipariş no için paket bulunamadı: ' . $order_number;
        }

        $importer = new Eru_Trendyol_Importer();
        $ok = 0;
        $fail = 0;
        foreach ($packages as $package) {
            // Manuel tek-sipariş çekme: mevcut sipariş varsa uçur, sıfırdan kur (force).
            $res = $importer->import_package($package, true);
            if (is_wp_error($res)) {
                $fail++;
            } else {
                $ok++;
            }
        }

        $msg = sprintf('Sipariş %s: %d paket bulundu, %d içeri alındı, %d hata.', $order_number, count($packages), $ok, $fail);
        Eru_Trendyol_Logger::info($msg);
        return $msg;
    }

    /**
     * Asıl senkronizasyon.
     *
     * @param array $settings
     * @param bool  $force Manuel tetiklemede enabled'dan bağımsız çalışır.
     */
    public function sync($settings, $force = false) {
        // Eşzamanlı çalışmayı engelle
        if (get_transient('eru_trendyol_sync_lock')) {
            return;
        }
        set_transient('eru_trendyol_sync_lock', 1, 5 * MINUTE_IN_SECONDS);

        $result_msg = '';
        try {
            $client = new Eru_Trendyol_Client($settings);
            if (!$client->is_configured()) {
                $result_msg = 'API kimlik bilgileri eksik.';
                Eru_Trendyol_Logger::error($result_msg);
                return;
            }

            $lookback = max(1, (int) ($settings['lookback_hours'] ?? 24));
            $end_ms   = time() * 1000;
            $start_ms = (time() - $lookback * HOUR_IN_SECONDS) * 1000;

            $packages = $client->fetch_all_packages($settings['pull_statuses'] ?? Eru_Trendyol_Installer::DEFAULT_PULL_STATUSES, $start_ms, $end_ms);
            if (is_wp_error($packages)) {
                $result_msg = 'API hatası: ' . $packages->get_error_message();
                Eru_Trendyol_Logger::error($result_msg, $packages->get_error_data());
                return;
            }

            $count = count($packages);

            // Test modu: sadece logla, içeri alma.
            if (!empty($settings['test_mode']) && !$force) {
                $result_msg = sprintf('Test modu: %d paket bulundu (içeri alınmadı).', $count);
                Eru_Trendyol_Logger::info($result_msg);
                return;
            }

            $importer = new Eru_Trendyol_Importer();
            $imported = 0;
            $failed   = 0;
            foreach ($packages as $package) {
                $res = $importer->import_package($package);
                if (is_wp_error($res)) {
                    $failed++;
                } else {
                    $imported++;
                }
            }

            $result_msg = sprintf('%d paket tarandı, %d içeri alındı, %d hata.', $count, $imported, $failed);
            Eru_Trendyol_Logger::info($result_msg);
        } finally {
            delete_transient('eru_trendyol_sync_lock');
            update_option('eru_trendyol_last_sync', current_time('mysql'));
            if ($result_msg !== '') {
                update_option('eru_trendyol_last_result', $result_msg);
            }
        }
    }
}
