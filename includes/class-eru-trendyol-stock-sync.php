<?php
/**
 * Trendyol stok senkronizasyonu (outbound).
 *
 * Yayında ürünlerin stok adetini Trendyol price-and-inventory ucuna gönderir.
 * Settings'ten Aktif/Pasif (stock_sync_enabled).
 *
 * EŞLEŞTİRME (local ürün ↔ Trendyol):
 *  - ÖNCELİK: local SKU (`_sku`) = Trendyol `stockCode`.
 *  - YEDEK:   local GTIN/EAN (`_global_unique_id`) = Trendyol `barcode`.
 *  API'ye HER ZAMAN Trendyol'un kendi `barcode`'u gider (price-and-inventory barkodla eşler);
 *  stockCode eşleşmesinde barkod, Trendyol ürün listesinden (stockCode→barcode) çözülür.
 *
 * İKİ MOD:
 *  - DELTA (varsayılan, cron): WC stok değişim hook'larıyla "kirli" işaretlenen ürünleri,
 *    10 dk'lık cron yalnız bunları batch'leyip gönderir. TÜM ürünleri taramaz.
 *  - TAM (manuel buton): kapsamdaki tüm ürünleri tek seferde gönderir (ilk yükleme / drift düzeltme).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Trendyol_Stock_Sync {

    const DIRTY_META = '_eru_trendyol_stock_dirty';
    const MAX_PER_RUN = 4000; // bir delta turunda işlenecek azami ürün

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(ERU_TRENDYOL_STOCK_CRON_HOOK, array($this, 'run_delta'));       // 10 dk emniyet ağı
        add_action(ERU_TRENDYOL_STOCK_FLUSH_HOOK, array($this, 'run_delta'));      // anlık (debounced) flush
        add_action(ERU_TRENDYOL_PRODUCT_CRON_HOOK, array($this, 'run_product_match')); // saatlik kapsam+eşleştirme
        add_action('init', array($this, 'maybe_schedule'));

        // Stok değişiminde ürünü "kirli" işaretle + anlık flush zamanla.
        add_action('woocommerce_product_set_stock', array($this, 'mark_dirty_obj'), 10, 1);
        add_action('woocommerce_variation_set_stock', array($this, 'mark_dirty_obj'), 10, 1);
        add_action('woocommerce_updated_product_stock', array($this, 'mark_dirty_id'), 10, 1);
        add_action('woocommerce_product_object_updated_props', array($this, 'mark_dirty_props'), 10, 2);

        // Manuel "tüm stokları gönder".
        add_action('admin_post_eru_trendyol_stock_full', array($this, 'handle_full_sync'));
    }

    /** Ayara göre cron'ları kur/kaldır (10 dk delta + saatlik kapsam). */
    public function maybe_schedule() {
        $settings = Eru_Trendyol_Settings::get();
        $enabled  = !empty($settings['stock_sync_enabled']);

        $delta_next = wp_next_scheduled(ERU_TRENDYOL_STOCK_CRON_HOOK);
        $prod_next  = wp_next_scheduled(ERU_TRENDYOL_PRODUCT_CRON_HOOK);

        if ($enabled) {
            if (!$delta_next) {
                wp_schedule_event(time() + 120, ERU_TRENDYOL_STOCK_CRON_INTERVAL, ERU_TRENDYOL_STOCK_CRON_HOOK);
            }
            if (!$prod_next) {
                wp_schedule_event(time() + 300, 'hourly', ERU_TRENDYOL_PRODUCT_CRON_HOOK);
            }
        } else {
            if ($delta_next) {
                wp_unschedule_event($delta_next, ERU_TRENDYOL_STOCK_CRON_HOOK);
            }
            if ($prod_next) {
                wp_unschedule_event($prod_next, ERU_TRENDYOL_PRODUCT_CRON_HOOK);
            }
        }
    }

    /** Stok değişiminden sonra anlık (debounced) flush zamanla — order isteğini bloklamadan. */
    private function schedule_flush() {
        if (!wp_next_scheduled(ERU_TRENDYOL_STOCK_FLUSH_HOOK)) {
            $delay = defined('ERU_TRENDYOL_STOCK_FLUSH_DELAY') ? (int) ERU_TRENDYOL_STOCK_FLUSH_DELAY : 15;
            wp_schedule_single_event(time() + max(5, $delay), ERU_TRENDYOL_STOCK_FLUSH_HOOK);
        }
    }

    /* ---------- Kirli işaretleme (delta kuyruğu) ---------- */

    public function mark_dirty_obj($product) {
        if ($product instanceof WC_Product) {
            $this->mark_dirty($product->get_id());
        }
    }

    public function mark_dirty_id($product_id) {
        $this->mark_dirty((int) $product_id);
    }

    public function mark_dirty_props($product, $updated_props) {
        if ($product instanceof WC_Product && is_array($updated_props)
            && (in_array('stock_quantity', $updated_props, true) || in_array('stock_status', $updated_props, true))) {
            $this->mark_dirty($product->get_id());
        }
    }

    private function mark_dirty($product_id) {
        if (!$product_id) {
            return;
        }
        $settings = Eru_Trendyol_Settings::get();
        if (empty($settings['stock_sync_enabled'])) {
            return; // özellik kapalıysa kuyruğa yazma
        }
        update_post_meta($product_id, self::DIRTY_META, 1);
        $this->schedule_flush(); // anlık push
    }

    /* ---------- DELTA: yalnız kirli ürünler (cron) ---------- */

    public function run_delta() {
        $settings = Eru_Trendyol_Settings::get();
        if (empty($settings['stock_sync_enabled'])) {
            return;
        }

        global $wpdb;
        $dirty_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1' LIMIT %d",
            self::DIRTY_META, self::MAX_PER_RUN
        ));
        if (empty($dirty_ids)) {
            return; // değişen yok → sessiz
        }

        $client = new Eru_Trendyol_Client($settings);
        if (!$client->is_configured()) {
            Eru_Trendyol_Logger::error('Stok senkron: API kimlik bilgileri eksik.');
            return; // dirty bayrakları dursun, sonraki turda denenir
        }

        // Trendyol kapsamı (stockCode→barcode + barcode seti). Boşsa hiçbir şey gönderme.
        $scope = $this->trendyol_scope(false);
        if (empty($scope['sku2bc']) && empty($scope['bc'])) {
            Eru_Trendyol_Logger::info('Stok delta: Trendyol ürün listesi boş/alınamadı; gönderim atlandı.');
            return; // dirty dursun
        }

        // Kirli bayrağını TEMİZLE (gönderim sırasında gelen değişiklikler tekrar kirli işaretlenir).
        $this->clear_dirty($dirty_ids);

        $rows = $this->fetch_rows_for_ids($dirty_ids);
        $test_mode = !empty($settings['test_mode']);
        $sent = $this->send_rows($client, $rows, $test_mode, $scope);

        Eru_Trendyol_Logger::info(sprintf(
            'Stok delta: %d kirli ürün, %d kalem (Trendyol kapsamı) %s.',
            count($dirty_ids), $sent, $test_mode ? 'gönderilecekti (test modu)' : 'gönderildi'
        ));
    }

    /* ---------- TAM: kapsamdaki tüm ürünler (manuel) ---------- */

    public function run_full() {
        $settings = Eru_Trendyol_Settings::get();
        if (empty($settings['stock_sync_enabled'])) {
            return 'Stok gönderimi pasif.';
        }
        $client = new Eru_Trendyol_Client($settings);
        if (!$client->is_configured()) {
            return 'API kimlik bilgileri eksik.';
        }

        // Manuel tam gönderimde Trendyol ürün listesini TAZE çek.
        $scope = $this->trendyol_scope(true);
        if (empty($scope['sku2bc']) && empty($scope['bc'])) {
            return 'Trendyol ürün listesi alınamadı; gönderim yapılmadı.';
        }
        $test_mode = !empty($settings['test_mode']);

        global $wpdb;
        $limit = 2000; $offset = 0; $sent = 0; $scanned = 0;
        do {
            // SKU veya GTIN'i olan tüm yayında ürünler (eşleştirme PHP'de yapılır).
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, sku.meta_value AS sku, g.meta_value AS gtin, s.meta_value AS stock, m.meta_value AS manage_stock
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} sku ON p.ID = sku.post_id AND sku.meta_key = '_sku'
                 LEFT JOIN {$wpdb->postmeta} g ON p.ID = g.post_id AND g.meta_key = '_global_unique_id'
                 LEFT JOIN {$wpdb->postmeta} s ON p.ID = s.post_id AND s.meta_key = '_stock'
                 LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_manage_stock'
                 LEFT JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
                 WHERE p.post_type IN ('product','product_variation')
                   AND p.post_status = 'publish'
                   AND (p.post_parent = 0 OR parent.post_status = 'publish')
                   AND ( (sku.meta_value IS NOT NULL AND sku.meta_value <> '')
                      OR (g.meta_value IS NOT NULL AND g.meta_value <> '') )
                 ORDER BY p.ID ASC LIMIT %d OFFSET %d",
                $limit, $offset
            ));
            $scanned += count($rows);
            $sent    += $this->send_rows($client, $rows, $test_mode, $scope);
            $offset  += $limit;
        } while (count($rows) === $limit);

        $msg = sprintf('Tam stok gönderimi: %d ürün tarandı, %d kalem (Trendyol kapsamı) %s.',
            $scanned, $sent, $test_mode ? 'gönderilecekti (test modu)' : 'gönderildi');
        Eru_Trendyol_Logger::info($msg);
        return $msg;
    }

    public function handle_full_sync() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Yetkisiz.');
        }
        check_admin_referer('eru_trendyol_stock_full');
        $msg = $this->run_full();
        wp_safe_redirect(add_query_arg(
            array('page' => Eru_Trendyol_Settings::SLUG, 'eru_trendyol_one' => rawurlencode($msg)),
            admin_url('admin.php')
        ));
        exit;
    }

    /* ---------- Yardımcılar ---------- */

    /**
     * Local ürünü Trendyol barkoduna çözer: önce SKU=stockCode, sonra GTIN=barcode yedeği.
     * @param array $scope ['sku2bc'=>[stockCode=>barcode], 'bc'=>[barcode=>true]]
     * @return string  Trendyol barkodu ('' = eşleşme yok)
     */
    private function resolve_barcode($sku, $gtin, $scope) {
        $sku  = trim((string) $sku);
        $gtin = trim((string) $gtin);
        if ($sku !== '' && isset($scope['sku2bc'][$sku])) {
            return $scope['sku2bc'][$sku];   // SKU = stockCode → Trendyol barkodu
        }
        if ($gtin !== '' && isset($scope['bc'][$gtin])) {
            return $gtin;                    // yedek: local GTIN = Trendyol barkodu
        }
        return '';
    }

    /**
     * Satır kümesinden Trendyol'da eşleşenleri batch'leyip gönderir.
     * @param array $scope ['sku2bc'=>..., 'bc'=>...]
     */
    private function send_rows($client, $rows, $test_mode, $scope) {
        $batch = array(); $sent = 0; $failed_ids = array();
        foreach ($rows as $r) {
            $barcode = $this->resolve_barcode($r->sku ?? '', $r->gtin ?? '', $scope);
            if ($barcode === '') {
                continue; // Trendyol'da eşleşmiyor (ne SKU/stockCode ne barkod)
            }
            if ($r->manage_stock !== 'yes' || $r->stock === null || $r->stock === '') {
                continue; // stok yönetilmiyorsa adet bilinmez
            }
            $batch[] = array('barcode' => $barcode, 'quantity' => max(0, (int) $r->stock), '_pid' => (int) $r->ID);
            if (count($batch) >= 1000) {
                $sent += $this->flush($client, $batch, $test_mode, $failed_ids);
                $batch = array();
            }
        }
        if (!empty($batch)) {
            $sent += $this->flush($client, $batch, $test_mode, $failed_ids);
        }
        if (!empty($failed_ids)) {
            $this->mark_dirty_bulk($failed_ids); // başarısız olanları tekrar kuyruğa
        }
        return $sent;
    }

    /** Tek batch gönderir; başarısız olursa ID'leri $failed_ids'e ekler. */
    private function flush($client, $batch, $test_mode, &$failed_ids) {
        $ids = wp_list_pluck($batch, '_pid');
        // API'ye giderken iç alanı çıkar.
        $items = array_map(function ($b) {
            return array('barcode' => $b['barcode'], 'quantity' => $b['quantity']);
        }, $batch);

        if ($test_mode) {
            return count($items);
        }
        $res = $client->update_price_inventory($items);
        if (is_wp_error($res)) {
            $failed_ids = array_merge($failed_ids, $ids);
            return 0;
        }
        return count($items);
    }

    private function fetch_rows_for_ids($ids) {
        global $wpdb;
        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);
        if ($in === '') {
            return array();
        }
        return $wpdb->get_results(
            "SELECT p.ID, sku.meta_value AS sku, g.meta_value AS gtin, s.meta_value AS stock, m.meta_value AS manage_stock
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} sku ON p.ID = sku.post_id AND sku.meta_key = '_sku'
             LEFT JOIN {$wpdb->postmeta} g ON p.ID = g.post_id AND g.meta_key = '_global_unique_id'
             LEFT JOIN {$wpdb->postmeta} s ON p.ID = s.post_id AND s.meta_key = '_stock'
             LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_manage_stock'
             LEFT JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
             WHERE p.ID IN ($in)
               AND p.post_status = 'publish'
               AND (p.post_parent = 0 OR parent.post_status = 'publish')"
        );
    }

    private function clear_dirty($ids) {
        global $wpdb;
        $ids = array_map('intval', $ids);
        $in  = implode(',', $ids);
        if ($in !== '') {
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '" . esc_sql(self::DIRTY_META) . "' AND post_id IN ($in)");
        }
    }

    private function mark_dirty_bulk($ids) {
        foreach (array_map('intval', $ids) as $id) {
            if ($id) {
                update_post_meta($id, self::DIRTY_META, 1);
            }
        }
    }

    /* ---------- Trendyol ürün kapsamı (stockCode + barcode) — cache ---------- */

    const PRODUCTS_OPTION    = 'eru_trendyol_seller_products';
    const PRODUCTS_AT_OPTION = 'eru_trendyol_seller_products_at';
    const PRODUCTS_TTL       = 43200; // 12 saat

    /**
     * Trendyol kapsamını döndürür: ['sku2bc'=>[stockCode=>barcode], 'bc'=>[barcode=>true]].
     * Bayat/boşsa ya da $force ise taze çeker.
     */
    private function trendyol_scope($force = false) {
        $list = get_option(self::PRODUCTS_OPTION);
        $at   = (int) get_option(self::PRODUCTS_AT_OPTION, 0);

        if ($force || !is_array($list) || empty($list) || (time() - $at) > self::PRODUCTS_TTL) {
            $refreshed = $this->refresh_products();
            if (is_array($refreshed)) {
                $list = $refreshed;
            }
        }

        $sku2bc = array();
        $bc     = array();
        foreach ((array) $list as $p) {
            if (!is_array($p)) {
                continue;
            }
            $barcode = isset($p['barcode']) ? (string) $p['barcode'] : '';
            if ($barcode === '') {
                continue;
            }
            $bc[$barcode] = true;
            $stock_code = isset($p['stockCode']) ? (string) $p['stockCode'] : '';
            if ($stock_code !== '' && !isset($sku2bc[$stock_code])) {
                $sku2bc[$stock_code] = $barcode;
            }
        }
        return array('sku2bc' => $sku2bc, 'bc' => $bc);
    }

    /** Trendyol ürün listesini (barcode+stockCode) çekip option'a yazar. @return array|WP_Error */
    public function refresh_products() {
        $settings = Eru_Trendyol_Settings::get();
        $client   = new Eru_Trendyol_Client($settings);
        if (!$client->is_configured()) {
            return new WP_Error('eru_trendyol_not_configured', 'API kimlik bilgileri eksik.');
        }

        $res = $client->fetch_seller_products();
        if (is_wp_error($res)) {
            Eru_Trendyol_Logger::error('Trendyol ürün listesi alınamadı: ' . $res->get_error_message());
            return $res;
        }

        update_option(self::PRODUCTS_OPTION, $res, false);
        update_option(self::PRODUCTS_AT_OPTION, time(), false);
        Eru_Trendyol_Logger::info(sprintf('Trendyol ürün listesi yenilendi: %d ürün.', count($res)));
        return $res;
    }

    /* ---------- SAATLİK: kapsam yenile + yeni açılanları eşleştir/seed ---------- */

    /**
     * Trendyol ürün listesini taze çeker; yeni açılan (önceki turda barkodu olmayan) ürünler için
     * yereldeki eşleşen ürünleri (SKU=stockCode veya GTIN=barcode) stok kuyruğuna alıp flush zamanlar.
     */
    public function run_product_match() {
        $settings = Eru_Trendyol_Settings::get();
        if (empty($settings['stock_sync_enabled'])) {
            return;
        }

        $old = get_option(self::PRODUCTS_OPTION);
        $old = is_array($old) ? $old : array();
        $old_bc = array();
        foreach ($old as $p) {
            if (is_array($p) && !empty($p['barcode'])) {
                $old_bc[(string) $p['barcode']] = true;
            }
        }

        $new = $this->refresh_products(); // taze çek + option'a yaz
        if (is_wp_error($new)) {
            return;
        }

        // Yeni açılan ürünler: barkodu eski listede OLMAYANLAR.
        $newly_stockcodes = array();
        $newly_barcodes   = array();
        foreach ($new as $p) {
            if (!is_array($p) || empty($p['barcode'])) {
                continue;
            }
            $b = (string) $p['barcode'];
            if (isset($old_bc[$b])) {
                continue;
            }
            $newly_barcodes[] = $b;
            if (!empty($p['stockCode'])) {
                $newly_stockcodes[] = (string) $p['stockCode'];
            }
        }

        if (empty($newly_barcodes) && empty($newly_stockcodes)) {
            Eru_Trendyol_Logger::info(sprintf('Trendyol kapsamı yenilendi: %d ürün, yeni açılan yok.', count($new)));
            return;
        }

        $marked = $this->mark_dirty_by_keys($newly_stockcodes, $newly_barcodes);
        Eru_Trendyol_Logger::info(sprintf(
            'Trendyol kapsamı: %d ürün, %d yeni açıldı → %d yerel ürün stok kuyruğuna alındı.',
            count($new), count($newly_barcodes), $marked
        ));

        if ($marked > 0) {
            $this->schedule_flush();
        }
    }

    /**
     * Verilen stockCode'lara (SKU) veya barkodlara (GTIN) karşılık gelen yereldeki yayında
     * ürünleri kirli işaretler. @return int işaretlenen benzersiz ürün sayısı
     */
    private function mark_dirty_by_keys($stock_codes, $barcodes) {
        global $wpdb;
        $stock_codes = array_values(array_unique(array_filter(array_map('strval', (array) $stock_codes))));
        $barcodes    = array_values(array_unique(array_filter(array_map('strval', (array) $barcodes))));
        if (empty($stock_codes) && empty($barcodes)) {
            return 0;
        }

        $seen = array();

        // ÖNCELİK: SKU = stockCode
        foreach (array_chunk($stock_codes, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} sku ON p.ID = sku.post_id AND sku.meta_key = '_sku'
                 LEFT JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
                 WHERE sku.meta_value IN ($placeholders)
                   AND p.post_type IN ('product','product_variation')
                   AND p.post_status = 'publish'
                   AND (p.post_parent = 0 OR parent.post_status = 'publish')",
                $chunk
            ));
            foreach ($ids as $id) {
                $seen[(int) $id] = true;
            }
        }

        // YEDEK: GTIN = barcode
        foreach (array_chunk($barcodes, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%s'));
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} g ON p.ID = g.post_id AND g.meta_key = '_global_unique_id'
                 LEFT JOIN {$wpdb->posts} parent ON p.post_parent = parent.ID
                 WHERE g.meta_value IN ($placeholders)
                   AND p.post_type IN ('product','product_variation')
                   AND p.post_status = 'publish'
                   AND (p.post_parent = 0 OR parent.post_status = 'publish')",
                $chunk
            ));
            foreach ($ids as $id) {
                $seen[(int) $id] = true;
            }
        }

        $count = 0;
        foreach ($seen as $id => $_) {
            if ($id) {
                update_post_meta($id, self::DIRTY_META, 1);
                $count++;
            }
        }
        return $count;
    }
}
