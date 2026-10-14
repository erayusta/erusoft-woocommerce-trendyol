<?php
/**
 * Trendyol importer — getShipmentPackages paketini WooCommerce siparişine dönüştürür.
 *
 * İZOLASYON: sipariş kaydedilirken `woocommerce_new_order` dinleyicileri o save boyunca
 * geçici nötrlenir ve hemen geri yüklenir. Böylece CRM stok senkronu, kara liste iptali,
 * risk skorlama, sipariş kodu üreticisi vb. bu siparişlerde ÇALIŞMAZ — ve hiçbir
 * başka eklenti/tema dosyası değiştirilmez. Siparişler özel `trendyol-yeni` statüsünde durur;
 * status-transition tabanlı eklentiler (ESD, kargo, havale) zaten tetiklenmez. Stok düşürülmez.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Trendyol_Importer {

    /**
     * Tek paketi içeri alır.
     *
     * @param array $package getShipmentPackages content[] elemanı
     * @return int|WP_Error  WooCommerce sipariş ID'si veya hata
     */
    /**
     * @param array $package Trendyol paketi.
     * @param bool  $force   Manuel "Sipariş çek"te true: mevcut sipariş DURUYOR olsa bile uçurulup
     *                       sıfırdan kurulur. Cron'da false: mevcut sipariş güncellenir (toplu reset
     *                       / mükerrer olmasın). Silinmiş sipariş her iki modda da self-heal edilir.
     */
    public function import_package($package, $force = false) {
        global $wpdb;

        $package_id = isset($package['shipmentPackageId']) ? (int) $package['shipmentPackageId'] : 0;
        if (!$package_id) {
            return new WP_Error('eru_trendyol_no_package_id', 'Paket içinde shipmentPackageId yok.');
        }

        $table = $wpdb->prefix . 'eru_trendyol_orders';

        // Daha önce içeri alındıysa: davranış moda göre değişir (force/update/self-heal).
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT id, wc_order_id FROM $table WHERE shipment_package_id = %d", $package_id)
        );
        if ($existing && !empty($existing->wc_order_id)) {
            $existing_id = (int) $existing->wc_order_id;
            if ($force) {
                // Manuel çekme: mevcut siparişi UÇUR ve sıfırdan kur. delete(true) dedupe hook'unu da
                // tetikler; yine de satırı garantiye almak için açıkça siliyoruz.
                $old = wc_get_order($existing_id);
                if ($old) {
                    $old->delete(true);
                }
                $wpdb->delete($table, array('id' => (int) $existing->id), array('%d'));
                Eru_Trendyol_Logger::info(sprintf(
                    'Manuel çekme (force): paket #%d eski sipariş #%d uçuruldu; sıfırdan içeri alınıyor.',
                    $package_id, $existing_id
                ));
            } elseif ($this->order_exists_in_db($existing_id)) {
                // Cron: sipariş hâlâ duruyorsa güncelle (kargo no, fatura, statü ilerlemesi).
                return $this->update_existing_order($existing_id, $package);
            } else {
                // Sipariş GERÇEKTEN silinmiş (doğrudan tablo kontrolü; wc_get_order cache ıskasına
                // güvenmeyiz) → bayat dedupe satırını temizle ve sıfırdan içeri al.
                $wpdb->delete($table, array('id' => (int) $existing->id), array('%d'));
                Eru_Trendyol_Logger::info(sprintf(
                    'Bayat dedupe temizlendi: paket #%d eski sipariş #%d silinmiş; sıfırdan içeri alınıyor.',
                    $package_id, $existing_id
                ));
            }
        }

        try {
            $order = $this->build_order($package);
        } catch (\Exception $e) {
            $this->record_failure($package_id, $package, $e->getMessage());
            return new WP_Error('eru_trendyol_build_failed', $e->getMessage());
        }

        $review = $order->get_meta('_eru_trendyol_review') === 'yes' ? 1 : 0;
        $order_id = $this->isolated_save($order);

        if (!$order_id) {
            $this->record_failure($package_id, $package, 'Sipariş kaydedilemedi.');
            return new WP_Error('eru_trendyol_save_failed', 'Sipariş kaydedilemedi.');
        }

        // Dedupe kaydı
        $wpdb->replace(
            $table,
            array(
                'shipment_package_id' => $package_id,
                'order_number'        => isset($package['orderNumber']) ? (string) $package['orderNumber'] : null,
                'wc_order_id'         => $order_id,
                'status'              => 'imported',
                'review'              => $review,
                'error'              => null,
                'created_at'          => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%d', '%s', '%s')
        );

        Eru_Trendyol_Logger::info(
            sprintf('Paket #%d -> sipariş #%d içeri alındı%s.', $package_id, $order_id, $review ? ' (inceleme gerekli)' : ''),
            array('package_id' => $package_id, 'order_id' => $order_id)
        );

        // CRM köprüsü — site siparişiyle aynı denge: sipariş DÜŞTÜĞÜ an POZİTİF.
        // İzole save DIŞINDA, tek sefer (meta guard). Sipariş ilk görüldüğünde zaten ileri statüde
        // düştüyse (hazir/iptal/iade) negatifi de tetikle ki denge aynı anda tamamlansın.
        $this->maybe_fire_crm_sync($order, 'trendyol-yeni');

        // Sipariş ilk görüldüğünde zaten ileri bir statüde düştüyse negatifi de tetikle ki denge
        // aynı anda tamamlansın. KAPI KALDIRILDI (7 Ağu 2026): burada eski slug listesi
        // (hazir/iptal/iade) vardı; paket import anında Shipped/Delivered ise sipariş
        // `trendyol-kargo`/`trendyol-teslim`e düşüyor, liste onu tanımıyor ve negatif HİÇ
        // gitmiyordu. Eşik kararı artık tek noktada — maybe_fire_crm_sync içinde, rank tabanlı.
        // Koşulsuz çağırmak güvenli: metod hem eşiği hem meta guard'ı kendisi uygular.
        $this->maybe_fire_crm_sync($order, $order->get_status());

        // Aynı Trendyol sipariş no'su birden fazla pakete bölündüyse (iptal sonrası yeniden
        // paket oluşturma) iptal paketlerin kodunu 99 önekiyle ayrıştır.
        $this->reconcile_duplicate_codes((string) ($package['orderNumber'] ?? ''));

        return $order_id;
    }

    /**
     * Aynı Trendyol sipariş numarası birden fazla WC siparişine düştüyse (Trendyol, kalem iptalinde
     * eski paketi iptal edip aynı orderNumber ile YENİ packageId oluşturur), İPTAL/İADE paketlerin
     * `code` meta'sını 99 önekiyle ayrıştırır (örn. 11435401196 → 9911435401196). Böylece ERP'ye
     * giden get-orders cevabında iptal paket, aktif paketle aynı sipariş kodunu taşımaz ve ERP
     * tarafında mükerrer kod çakışması olmaz. Aktif paket orijinal kodu taşımaya devam eder;
     * tek paketli siparişlerde koda dokunulmaz. Idempotent — öneklenmiş kodu tekrar öneklemez.
     */
    public function reconcile_duplicate_codes($order_number) {
        global $wpdb;

        $order_number = trim((string) $order_number);
        if ($order_number === '') {
            return;
        }

        $table = $wpdb->prefix . 'eru_trendyol_orders';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT wc_order_id FROM $table WHERE order_number = %s AND wc_order_id IS NOT NULL",
            $order_number
        ));
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (count($ids) < 2) {
            return; // tek paket — kod çakışması yok
        }

        foreach ($ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order || $order->get_meta('_eru_trendyol_source') !== 'trendyol') {
                continue;
            }
            if (!in_array($order->get_status(), array('trendyol-iptal', 'trendyol-iade'), true)) {
                continue; // aktif paket orijinal kodu taşır
            }
            if ($order->get_meta('code') !== $order_number) {
                continue; // zaten öneklenmiş
            }
            $order->update_meta_data('code', '99' . $order_number);
            $order->add_order_note(sprintf(
                'Sipariş kodu 99%1$s yapıldı: %1$s numaralı Trendyol siparişinin aktif başka paketi var; iptal paket ERP tarafında aynı kodla çakışmasın.',
                $order_number
            ));
            $order->save();
            Eru_Trendyol_Logger::info(sprintf(
                'Sipariş #%d: kod 99%s olarak ayrıştırıldı (mükerrer Trendyol sipariş no, iptal paket).',
                $oid, $order_number
            ));
        }
    }

    /**
     * Sipariş DB'de gerçekten var mı — doğrudan tablo kontrolü (wc_get_order cache/HPOS ıskasına
     * güvenmeden). HPOS açıksa wc_orders, ayrıca eski/senkron postu için wp_posts kontrol edilir.
     */
    private function order_exists_in_db($order_id) {
        global $wpdb;
        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return false;
        }
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            $t = $wpdb->prefix . 'wc_orders';
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE id = %d", $order_id)) === $order_id) {
                return true;
            }
        }
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type IN ('shop_order','shop_order_placehold')",
            $order_id
        ));
        return (bool) $exists;
    }

    /**
     * Trendyol paketinden (henüz kaydedilmemiş) WC_Order nesnesi kurar.
     */
    private function build_order($package) {
        $order = new WC_Order();

        $currency = !empty($package['currencyCode']) ? sanitize_text_field($package['currencyCode']) : 'TRY';
        $order->set_currency($currency);
        $order->set_created_via('trendyol');
        $order->set_payment_method('trendyol');
        $order->set_payment_method_title('Trendyol');
        $order->set_customer_note('Trendyol pazaryeri siparişi — tahsilat ve kargo Trendyol tarafından yönetilir.');

        if (!empty($package['orderDate'])) {
            // Trendyol orderDate'i ms epoch ama Türkiye yerel saatini UTC'ymiş gibi kodlar
            // (GMT+3 gömülü). set_date_created'a düz numeric verirsek WC bunu UTC sayıp ekranda
            // site timezone'una çevirir ve +3 saat kayar (18:55 → 21:55). Bu yüzden epoch'un UTC
            // okumasını (= Trendyol'un kastettiği yerel duvar saati) alıp site timezone'unda bir
            // WC_DateTime kuruyoruz; böylece DB'ye doğru GMT yazılır, ekranda doğru saat görünür.
            $ts = intval($package['orderDate'] / 1000);
            try {
                $local = new WC_DateTime(gmdate('Y-m-d H:i:s', $ts), new DateTimeZone(wc_timezone_string()));
                $order->set_date_created($local);
            } catch (Exception $e) {
                $order->set_date_created($ts); // güvenli fallback
            }
        }

        // --- İşaretleme + izolasyon meta'sı (save'den ÖNCE) ---
        $order->update_meta_data('_eru_trendyol_source', 'trendyol');
        $order->update_meta_data('_eru_trendyol_package_id', (int) $package['shipmentPackageId']);
        $order->update_meta_data('_eru_trendyol_order_number', (string) ($package['orderNumber'] ?? ''));
        $order->update_meta_data('_eru_trendyol_imported_at', current_time('mysql'));
        if (!empty($package['orderNumber'])) {
            $order->update_meta_data('code', (string) $package['orderNumber']); // sipariş kodu olarak pazaryeri sipariş no'su
        }

        // --- Belge / fatura / kargo meta'sı (ortak yardımcı; güncellemede de kullanılır) ---
        $this->apply_package_meta($order, $package);

        // --- Adresler ---
        $invoice_addr  = isset($package['invoiceAddress']) && is_array($package['invoiceAddress']) ? $package['invoiceAddress'] : array();
        $shipment_addr = isset($package['shipmentAddress']) && is_array($package['shipmentAddress']) ? $package['shipmentAddress'] : array();
        $email = isset($package['customerEmail']) ? sanitize_email($package['customerEmail']) : '';

        $billing  = $this->map_address($invoice_addr, $package);
        $shipping = $this->map_address($shipment_addr, $package);
        if ($email) {
            $billing['email'] = $email;
        }

        $order->set_address($billing, 'billing');
        $order->set_address($shipping, 'shipping');

        // --- Satırlar ---
        $needs_review = false;
        $tax_acc = array();
        $lines = isset($package['lines']) && is_array($package['lines']) ? $package['lines'] : array();
        foreach ($lines as $line) {
            if ($this->add_line($order, $line, $tax_acc) === false) {
                $needs_review = true;
            }
        }
        if (empty($lines)) {
            $needs_review = true;
        }
        if ($needs_review) {
            $order->update_meta_data('_eru_trendyol_review', 'yes');
        }

        // --- Toplamlar: Trendyol tutarını koru (mağaza fiyatından yeniden hesaplama yapma) ---
        $order->set_shipping_total(0);
        if (isset($package['packageTotalPrice'])) {
            $order->set_total(wc_format_decimal($package['packageTotalPrice']));
        }

        // Oran bazlı KDV kalemlerini siparişe ekle + sipariş geneli vergi toplamını yaz.
        // (get_taxes()/get_tax_totals() ve get_total_tax() tutarlılığı.)
        $cart_tax = $this->apply_tax_items($order, $tax_acc);
        if ($cart_tax > 0) {
            $order->set_cart_tax(wc_format_decimal($cart_tax));
        }

        $order->set_status($this->map_status($package['status'] ?? ''));
        $order->add_order_note(sprintf('Trendyol içe aktarım: paket #%d / sipariş no %s (Trendyol statü: %s)',
            (int) $package['shipmentPackageId'],
            (string) ($package['orderNumber'] ?? '-'),
            (string) ($package['status'] ?? '-')
        ));

        return $order;
    }

    /**
     * Trendyol paket verisinden fatura/vergi/kargo/belge meta'sını siparişe yazar.
     * Hem ilk içe aktarımda hem güncellemede çağrılır.
     */
    private function apply_package_meta($order, $package) {
        $invoice_addr = isset($package['invoiceAddress']) && is_array($package['invoiceAddress']) ? $package['invoiceAddress'] : array();

        // VKN: önce invoiceAddress.taxNumber (kurumsal genelde burada), sonra top-level taxNumber.
        $tax_number = $this->first_nonempty(
            $invoice_addr['taxNumber'] ?? null,
            $package['taxNumber'] ?? null
        );
        // TC: önce tcIdentityNumber, sonra identityNumber (alan adı ortama göre değişebiliyor).
        $identity = $this->first_nonempty(
            $package['tcIdentityNumber'] ?? null,
            $package['identityNumber'] ?? null,
            $invoice_addr['tcIdentityNumber'] ?? null
        );
        // Vergi dairesi: önce invoiceAddress.taxOffice, sonra top-level.
        $tax_office = $this->first_nonempty(
            $invoice_addr['taxOffice'] ?? null,
            $package['taxOffice'] ?? null
        );

        $this->set_meta_if($order, '_eru_trendyol_tax_number', $tax_number);
        $this->set_meta_if($order, '_eru_trendyol_identity_number', $identity);
        $this->set_meta_if($order, '_eru_trendyol_tax_office', $tax_office);

        // Fatura tipi (bireysel/kurumsal) + e-fatura mükellefiyeti.
        $is_commercial = !empty($package['commercial']);
        $order->update_meta_data('_eru_trendyol_commercial', $is_commercial ? 'yes' : 'no');
        $order->update_meta_data('_eru_trendyol_einvoice', ($is_commercial && $tax_number !== '') ? 'yes' : 'no');
        $order->update_meta_data('_eru_trendyol_micro', !empty($package['micro']) ? 'yes' : 'no');

        // Belge / fatura
        $this->set_meta_if($order, '_eru_trendyol_invoice_link', $package['invoiceLink'] ?? null);
        $this->set_meta_if($order, '_eru_trendyol_etgb_no', $package['etgbNo'] ?? null);
        $this->set_meta_if($order, '_eru_trendyol_etgb_date', $package['etgbDate'] ?? null);

        // Kargo
        $this->set_meta_if($order, '_eru_trendyol_cargo_provider', $package['cargoProviderName'] ?? null);
        $this->set_meta_if($order, '_eru_trendyol_cargo_tracking', $package['cargoTrackingNumber'] ?? null);
        $this->set_meta_if($order, '_eru_trendyol_cargo_tracking_link', $package['cargoTrackingLink'] ?? null);
        $this->set_meta_if($order, '_eru_trendyol_cargo_sender_number', $package['cargoSenderNumber'] ?? null);

        // Admin sipariş aramasında bulunabilirlik: Trendyol sipariş no'su + paket no'sunu
        // transaction_id'ye yaz. `code` meta'sı HPOS'ta YALNIZ FTS index KAPALIYKEN aranır
        // (woocommerce_order_table_search_query_meta_keys). FTS açıkken meta araması tamamen
        // bypass edilir; transaction_id ise OrdersTableSearchQuery::generate_where_for_search_filter
        // içinde FTS'ten bağımsız HER ZAMAN LIKE ile aranan core bir alan. Hem orderNumber hem
        // shipmentPackageId'yi koyuyoruz ki personel hangisini ararsa arasın bulsun.
        $search_refs = array();
        if (!empty($package['orderNumber'])) {
            $search_refs[] = (string) $package['orderNumber'];
        }
        if (!empty($package['shipmentPackageId'])) {
            $search_refs[] = (string) $package['shipmentPackageId'];
        }
        if (!empty($search_refs)) {
            $order->set_transaction_id(implode(' / ', $search_refs));
        }

        // Trendyol ham statüsü + tüm yanıt (alan doğrulama / veri kaybını önleme).
        $this->set_meta_if($order, '_eru_trendyol_status', $package['status'] ?? null);
        $order->update_meta_data('_eru_trendyol_raw', wp_json_encode($package));

        // Materyal imza — cron'un "değişiklik yoksa dokunma" kontrolü için (ilk importta da damgalanır).
        $order->update_meta_data('_eru_trendyol_sync_hash', $this->sync_signature($package));
    }

    /**
     * Trendyol paketinin MATERYAL halinin imzası. Sadece senkron edilen alanları kapsar
     * (volatil alanlar hariç) — Trendyol bunları değiştirmediyse cron mükerrer güncelleme yapmaz.
     */
    private function sync_signature($package) {
        $ia = isset($package['invoiceAddress']) && is_array($package['invoiceAddress']) ? $package['invoiceAddress'] : array();
        $sa = isset($package['shipmentAddress']) && is_array($package['shipmentAddress']) ? $package['shipmentAddress'] : array();

        $material = array(
            'status'        => (string) ($package['status'] ?? ''),
            'cargoProvider' => (string) ($package['cargoProviderName'] ?? ''),
            'cargoTracking' => (string) ($package['cargoTrackingNumber'] ?? ''),
            'cargoLink'     => (string) ($package['cargoTrackingLink'] ?? ''),
            'invoiceLink'   => (string) ($package['invoiceLink'] ?? ''),
            'etgbNo'        => (string) ($package['etgbNo'] ?? ''),
            'etgbDate'      => (string) ($package['etgbDate'] ?? ''),
            'commercial'    => !empty($package['commercial']),
            'micro'         => !empty($package['micro']),
            'taxNumber'     => (string) ($ia['taxNumber'] ?? ($package['taxNumber'] ?? '')),
            'taxOffice'     => (string) ($ia['taxOffice'] ?? ($package['taxOffice'] ?? '')),
            'identity'      => (string) ($package['tcIdentityNumber'] ?? ($package['identityNumber'] ?? '')),
            'total'         => (string) ($package['packageTotalPrice'] ?? ''),
            'invoiceAddr'   => $ia,
            'shipmentAddr'  => $sa,
            'lines'         => isset($package['lines']) ? $package['lines'] : array(),
        );

        return md5((string) wp_json_encode($material));
    }

    /**
     * Trendyol paket statüsünü eklentinin Trendyol statü slug'ına eşler.
     * `eru_trendyol_status_map` filtresiyle özelleştirilebilir.
     */
    private function map_status($trendyol_status) {
        $status = trim((string) $trendyol_status);

        $map = array(
            'Created'           => 'trendyol-yeni',
            'Picking'           => 'trendyol-hazir',
            'Invoiced'          => 'trendyol-hazir',
            'Shipped'           => 'trendyol-kargo',
            'AtCollectionPoint' => 'trendyol-kargo',
            'UnDelivered'       => 'trendyol-kargo',
            'Delivered'         => 'trendyol-teslim',
            'Cancelled'         => 'trendyol-iptal',
            'UnSupplied'        => 'trendyol-iptal',
            'UnPacked'          => 'trendyol-iptal',
            'Returned'          => 'trendyol-iade',
        );
        $map = apply_filters('eru_trendyol_status_map', $map);

        return ($status !== '' && isset($map[$status])) ? $map[$status] : ERU_TRENDYOL_STATUS;
    }

    /**
     * Siparişi İZOLE şekilde verilen statüye çeker (ESD/kargo/CRM tetiklenmeden).
     * Zamanlanmış "Hazırlanıyor"a ilerletme event'i bunu kullanır.
     *
     * @return int Sipariş ID (0 = hata)
     */
    public function update_status_isolated($order, $new_status, $note = '') {
        $order->set_status($new_status);
        if ($note !== '') {
            $order->add_order_note($note);
        }
        return $this->isolated_save($order);
    }

    /**
     * Sipariş CRM-relevant bir Trendyol statüsüne GİRDİĞİNDE ERP/CRM köprüsünü
     * tek sefer tetikler. İzolasyon korunur — yalnızca stok pozitif/negatif action'ı fırlatılır,
     * ESD/kargo/havale tetiklenmez; statü save'i izole olduğu için bu çağrı izole save DIŞINDA yapılır.
     * dinleyen entegrasyon kendi tarafında ayrıca kontrol yapabilir.
     *
     * Site siparişiyle BİREBİR AYNI denge mantığı (pozitif "new order", negatif "processing"):
     *  - trendyol-yeni          => POZİTİF (sipariş düştü; CRM'de stok tutulur)
     *  - trendyol-hazir         => NEGATİF (ERP'ye aktarım; stok ERP'ye devredilir)
     *  - trendyol-iptal / -iade => NEGATİF (aynı "release"; hazir'dan ÖNCE iptalde stok geri döner)
     *
     * Pozitif/negatif birer kez tetiklenir (meta guard). CRM tarafındaki guard net TEK negatifi garantiler:
     * hazir'da negatif gittiyse sonraki iptal/iade tekrar göndermez (pos=neg → bloklanır) — doğru,
     * ERP devraldıktan sonraki iptali ERP yönetir. Hazir'dan ÖNCE iptalde negatif ilk kez gider.
     */
    public function maybe_fire_crm_sync($order, $new_status) {
        if (!$order instanceof WC_Order) {
            return;
        }
        $order_id = $order->get_id();

        // POZİTİF: sipariş düştüğü an (trendyol-yeni) — site "new order" karşılığı.
        if ($new_status === 'trendyol-yeni') {
            if ($order->get_meta('_eru_trendyol_crm_pos') === 'yes') {
                return; // zaten tetiklendi (idempotency)
            }
            $order->update_meta_data('_eru_trendyol_crm_pos', 'yes');
            $order->save_meta_data();
            do_action('eru_trendyol_crm_positive', $order_id);
            Eru_Trendyol_Logger::info(sprintf('Sipariş #%d: CRM pozitif köprü action\'ı fırlatıldı (trendyol-yeni).', $order_id));
            return;
        }

        // NEGATİF: ERP'ye aktarım (trendyol-hazir) VEYA iptal/iade — hepsi aynı "release".
        //
        // EŞİK RANK TABANLI, slug listesi DEĞİL (7 Ağu 2026). Eskiden liste yalnız
        // hazir/iptal/iade idi; sipariş `hazir`e HİÇ UĞRAMADAN ileri sıçradığında negatif hiç
        // gitmiyordu ve stok CRM'de asılı kalıyordu. Sıçrama üç yolla oluyor:
        //   - Trendyol biz aktarmadan paketi kargoya veriyor (yeni → kargo)
        //   - ERP aktardı ama dwell dolmadan kargo olayı geldi (alindi → kargo, hazir atlanır)
        //   - Sipariş import anında zaten Delivered (yeni → teslim)
        //
        // Rank >= hazir olan HER statü negatif tetikler: hazir(2), kargo(3), teslim(4),
        // iptal(5), iade(6). yeni(0) ve alindi(1) eşiğin altında — orada stok hâlâ bizde.
        // Trendyol-dışı/dahili statüler rank -1 → tetiklemez (sipariş dahili akışa geçmiştir).
        //
        // AKIŞI BOZMAZ: `_eru_trendyol_crm_neg` guard'ı yalnız İLK tetiklemeyi geçirir; çalışan
        // akışlarda `hazir` zaten kargo/teslim'den önce geldiği için kazanan değişmez. Yeni
        // statüler sadece hiçbir negatifin gitmediği durumda devreye girer (ölçüldü: davranışı
        // değişen akış 0, deliği kapanan akış 3).
        $negatif_esigi = $this->trendyol_status_rank('trendyol-hazir');
        $yeni_rank     = $this->trendyol_status_rank($new_status);

        if ($yeni_rank >= 0 && $yeni_rank >= $negatif_esigi) {
            if ($order->get_meta('_eru_trendyol_crm_neg') === 'yes') {
                return;
            }
            $order->update_meta_data('_eru_trendyol_crm_neg', 'yes');
            $order->save_meta_data();
            do_action('eru_trendyol_crm_negative', $order_id, $new_status);
            Eru_Trendyol_Logger::info(sprintf('Sipariş #%d: CRM negatif köprü action\'ı fırlatıldı (%s).', $order_id, $new_status));
        }
    }

    /**
     * Trendyol yaşam döngüsü sırası (ileri-yön kontrolü). Trendyol-dışı/dahili statüler -1 döner;
     * böylece sipariş dahili akışa geçince cron statüye dokunmaz.
     */
    private function trendyol_status_rank($slug) {
        $rank = array(
            'trendyol-yeni'           => 0,
            'trendyol-alindi' => 1, // manuel ara statü
            'trendyol-hazir'   => 2,
            'trendyol-kargo'     => 3,
            'trendyol-teslim'         => 4,
            'trendyol-iptal'          => 5,
            'trendyol-iade'           => 6,
        );
        return isset($rank[$slug]) ? $rank[$slug] : -1;
    }

    /**
     * Mevcut Trendyol siparişini paket verisiyle günceller (mükerrer oluşturmadan).
     * Fatura/vergi/kargo meta'sını + adresleri + statüyü tazeler; satır kalemlerine dokunmaz.
     *
     * @return int|WP_Error
     */
    private function update_existing_order($wc_order_id, $package) {
        global $wpdb;

        $table      = $wpdb->prefix . 'eru_trendyol_orders';
        $package_id = (int) $package['shipmentPackageId'];

        $order = wc_get_order($wc_order_id);
        if (!$order) {
            // ESKİDEN: dedupe silinip sipariş yeniden oluşturuluyordu. Bu, geçici bir yükleme
            // hatasında (HPOS tutarsızlığı/cache) siparişi trendyol-yeni olarak YENİDEN yaratıp
            // manuel statüyü (Sipariş Alındı vb.) sıfırlıyor + mükerrer sipariş üretiyordu.
            // Artık yeniden oluşturmuyoruz; loglayıp atlıyoruz. Sipariş gerçekten silindiyse
            // dedupe kaydı elle temizlenip yeniden çekilebilir.
            Eru_Trendyol_Logger::error(sprintf(
                'Paket #%d için sipariş #%d yüklenemedi; güncelleme atlandı (yeniden oluşturulmadı).',
                $package_id, $wc_order_id
            ), array('package_id' => $package_id, 'wc_order_id' => $wc_order_id));
            return (int) $wc_order_id;
        }
        if ($order->get_meta('_eru_trendyol_source') !== 'trendyol') {
            return new WP_Error('eru_trendyol_not_trendyol_order', 'Sipariş Trendyol kaynaklı değil; güncellenmedi.');
        }

        // DEĞİŞİKLİK YOKSA HİÇ DOKUNMA: Trendyol'un materyal verisi (statü/kargo/fatura/adres/satır)
        // bir öncekiyle aynıysa cron her turda mükerrer güncelleme + log üretmesin.
        $signature = $this->sync_signature($package);
        if ($order->get_meta('_eru_trendyol_sync_hash') === $signature) {
            // Değişiklik yok — ama 99-önek ayrıştırması bu özellikten ÖNCE içeri alınmış iptal
            // paketlerde hiç çalışmamış olabilir; öneksiz iptal/iade paketse self-heal dene.
            // (Koşullar bellekteki order'dan okunur; yaygın durumda ek sorgu maliyeti yok.)
            if (in_array($order->get_status(), array('trendyol-iptal', 'trendyol-iade'), true)
                && $order->get_meta('code') === (string) ($package['orderNumber'] ?? '')) {
                $this->reconcile_duplicate_codes((string) ($package['orderNumber'] ?? ''));
            }
            return (int) $wc_order_id; // sessiz no-op
        }

        // Fatura/vergi/kargo/ham meta'yı tazele.
        $this->apply_package_meta($order, $package);

        // Adresleri tazele (Trendyol'da fatura adresi/firma sonradan netleşebilir).
        $invoice_addr  = isset($package['invoiceAddress']) && is_array($package['invoiceAddress']) ? $package['invoiceAddress'] : array();
        $shipment_addr = isset($package['shipmentAddress']) && is_array($package['shipmentAddress']) ? $package['shipmentAddress'] : array();
        $email = isset($package['customerEmail']) ? sanitize_email($package['customerEmail']) : '';
        if (!empty($invoice_addr)) {
            $billing = $this->map_address($invoice_addr, $package);
            if ($email) {
                $billing['email'] = $email;
            }
            $order->set_address($billing, 'billing');
        }
        if (!empty($shipment_addr)) {
            $order->set_address($this->map_address($shipment_addr, $package), 'shipping');
        }

        // Satır kalemleri: YALNIZ kalem yoksa kur. Her turda yeniden kurmak personelin/ERP'nin
        // manuel kalem düzenlemelerini ezerdi; taze importta kalemler zaten KDV'li kurulu.
        $lines = isset($package['lines']) && is_array($package['lines']) ? $package['lines'] : array();
        if (!empty($lines) && count($order->get_items()) === 0) {
            $needs_review = false;
            $tax_acc = array();
            foreach ($lines as $line) {
                if ($this->add_line($order, $line, $tax_acc) === false) {
                    $needs_review = true;
                }
            }
            $cart_tax = $this->apply_tax_items($order, $tax_acc);
            $order->set_cart_tax($cart_tax > 0 ? wc_format_decimal($cart_tax) : 0);
            $order->update_meta_data('_eru_trendyol_review', $needs_review ? 'yes' : 'no');

            // Tutarı yalnız burada (ilk kurulumda) Trendyol toplamına sabitle.
            if (isset($package['packageTotalPrice'])) {
                $order->set_total(wc_format_decimal($package['packageTotalPrice']));
            }
        }

        // Statü güncelleme kuralı:
        //  - İPTAL/İADE İSTİSNASI: Trendyol siparişi iptal/iade ederse statü HER DURUMDA buna
        //    çekilir — sipariş dahili akışa (packed, processing vb.) geçmiş olsa bile.
        //  - Diğer durumlar: sadece sipariş hâlâ bir trendyol-* statüsündeyken ve İLERİ yönde.
        //    Dahili statüye geçtiyse cron dokunmaz (Trendyol "Created"da kalsa bile resetlemez).
        $new_status  = $this->map_status($package['status'] ?? '');
        $old_status  = $order->get_status();
        $is_terminal = in_array($new_status, array('trendyol-iptal', 'trendyol-iade'), true);

        if ($is_terminal) {
            $apply = ($new_status !== $old_status);
        } elseif ($old_status === 'trendyol-alindi') {
            // "Sipariş Alındı"da cron statüye DOKUNMAZ. Hazırlanıyor'a geçiş, Picking bildirimi
            // sonrası zamanlanan nefeslik event'i (eru_trendyol_advance_hazir) ile yapılır —
            // böylece iki statü arasında garantili bir boşluk olur, cron anında atlamaz.
            $apply = false;
        } else {
            $old_rank = $this->trendyol_status_rank($old_status);
            $new_rank = $this->trendyol_status_rank($new_status);
            $apply    = ($old_rank >= 0 && $new_rank > $old_rank);
        }

        if ($apply) {
            $order->set_status($new_status);
            $order->add_order_note(sprintf('Trendyol güncelleme: statü %s → %s (Trendyol: %s)',
                $old_status, $new_status, (string) ($package['status'] ?? '-')));
        }

        $order_id = $this->isolated_save($order);
        if (!$order_id) {
            return new WP_Error('eru_trendyol_update_failed', 'Sipariş güncellenemedi.');
        }

        $wpdb->update(
            $table,
            array('status' => 'updated', 'updated_at' => current_time('mysql')),
            array('shipment_package_id' => $package_id),
            array('%s', '%s'),
            array('%d')
        );

        Eru_Trendyol_Logger::info(
            sprintf('Paket #%d -> sipariş #%d güncellendi (statü: %s).', $package_id, $order_id, $new_status),
            array('package_id' => $package_id, 'order_id' => $order_id)
        );

        // Statü CRM-relevant bir Trendyol noktasına GİRDİYSE (cron yeni→hazir, ya da iptal/iade)
        // ERP/CRM köprüsünü izole save DIŞINDA, tek sefer tetikle.
        if ($apply) {
            $this->maybe_fire_crm_sync($order, $new_status);
        }

        // İptal/iade'ye geçtiyse: aynı sipariş no'lu aktif paket varsa kodu 99 önekiyle ayrıştır.
        if ($apply && $is_terminal) {
            $this->reconcile_duplicate_codes((string) ($package['orderNumber'] ?? ''));
        }

        return $order_id;
    }

    /**
     * Tek satırı siparişe ekler. Ürün SKU ile eşleşmezse ham satır olarak ekler.
     *
     * @return bool true: eşleşti, false: eşleşmedi (inceleme gerekli)
     */
    private function add_line($order, $line, &$tax_acc = null) {
        $stock_code = isset($line['stockCode']) ? trim((string) $line['stockCode']) : '';
        $barcode    = isset($line['barcode']) ? trim((string) $line['barcode']) : '';
        $name       = isset($line['productName']) ? sanitize_text_field($line['productName']) : ($stock_code ?: 'Trendyol ürünü');
        $qty        = isset($line['quantity']) ? max(1, (int) $line['quantity']) : 1;

        $unit  = isset($line['lineUnitPrice']) ? (float) $line['lineUnitPrice'] : 0.0;
        $gross = isset($line['lineGrossAmount']) ? (float) $line['lineGrossAmount'] : ($unit * $qty);
        if ($unit <= 0 && $qty > 0) {
            $unit = $gross / $qty;
        }
        $discount = isset($line['lineTotalDiscount']) ? (float) $line['lineTotalDiscount'] : 0.0;

        $subtotal = $unit * $qty;             // Trendyol tüketici fiyatı — KDV DAHİL
        $total    = max(0, $subtotal - $discount);

        // SKU eşleşmesi: önce stockCode, sonra barcode.
        $product_id = 0;
        if ($stock_code !== '') {
            $product_id = wc_get_product_id_by_sku($stock_code);
        }
        if (!$product_id && $barcode !== '') {
            $product_id = wc_get_product_id_by_sku($barcode);
        }

        $product = $product_id ? wc_get_product($product_id) : null;
        $matched = ($product instanceof WC_Product);

        // KDV oranı: eşleşen üründen gelir; EŞLEŞMEYEN satır da KDV'siz kalmasın diye kdv20 varsayılır
        // (Trendyol tüketici fiyatı her zaman KDV dahildir, mağaza ağırlıklı %20). Matrah (hariç) ve
        // KDV içeriden ayrılır.
        $tax_class = $matched ? $product->get_tax_class() : 'kdv20';
        $rate_info = $this->tax_rate_for_class($tax_class);
        // Eşleşen ürünün sınıfında tanımlı oran yoksa (örn. standart/0) yine de kdv20'ye düş.
        if ($rate_info === null && $tax_class !== 'kdv20') {
            $rate_info = $this->tax_rate_for_class('kdv20');
        }
        $percent       = $rate_info ? (float) $rate_info['percent'] : 0.0;
        $subtotal_excl = $percent > 0 ? $subtotal / (1 + $percent / 100) : $subtotal;
        $total_excl    = $percent > 0 ? $total    / (1 + $percent / 100) : $total;
        $subtotal_tax  = $subtotal - $subtotal_excl;
        $total_tax     = $total - $total_excl;

        $item = new WC_Order_Item_Product();
        $item->set_quantity($qty);
        $item->set_subtotal(wc_format_decimal($subtotal_excl));
        $item->set_total(wc_format_decimal($total_excl));

        // KDV'yi orana BAĞLI yaz: set_subtotal_tax/set_total_tax skaler değerleri WooCommerce
        // yeniden yüklemede _line_tax_data (oran→tutar) dizisinden ezdiği için, vergiyi rate_id'li
        // diziyle vermek ŞART; aksi halde reload sonrası kalem KDV'si 0'a düşer (fatura/ERP çıktısı sapıtır).
        if ($rate_info && $percent > 0) {
            $rate_id = (int) $rate_info['id'];
            $item->set_taxes(array(
                'total'    => array($rate_id => wc_format_decimal($total_tax)),
                'subtotal' => array($rate_id => wc_format_decimal($subtotal_tax)),
            ));
            if (is_array($tax_acc)) {
                if (!isset($tax_acc[$rate_id])) {
                    $tax_acc[$rate_id] = array(
                        'percent'  => $percent,
                        'label'    => $rate_info['label'],
                        'total'    => 0.0,
                        'subtotal' => 0.0,
                    );
                }
                $tax_acc[$rate_id]['total']    += $total_tax;
                $tax_acc[$rate_id]['subtotal'] += $subtotal_tax;
            }
        } else {
            $item->set_taxes(false);
        }

        $item->add_meta_data('trendyol_stock_code', $stock_code, true);
        $item->add_meta_data('trendyol_barcode', $barcode, true);

        // Trendyol komisyonu: satır seviyesinde `commission` ORAN olarak gelir (ör. 13 = %13).
        // ERP'ye sipariş çıktısıyla taşınması için hem oranı hem hesaplanan TL tutarını kalem
        // meta'sına yaz. Matrah = satır tutarı (KDV dahil, indirim sonrası = müşterinin ödediği
        // tutar, Trendyol komisyon matrahı). ERP entegrasyonunuz bu meta'yı okuyup çıktıya ekleyebilir.
        $commission_rate = isset($line['commission']) ? (float) $line['commission'] : 0.0;
        if ($commission_rate > 0) {
            $commission_amount = $total * $commission_rate / 100;
            $item->add_meta_data('_eru_trendyol_commission_rate', wc_format_decimal($commission_rate), true);
            $item->add_meta_data('_eru_trendyol_commission_amount', wc_format_decimal($commission_amount), true);
        }

        if ($matched) {
            $item->set_product($product);
        } else {
            $item->set_name($name);
            $item->set_tax_class($tax_class);
            $item->add_meta_data('_eru_trendyol_unmatched', 'yes', true);
        }

        $order->add_item($item);
        return $matched;
    }

    /**
     * Verilen KDV sınıfı için WooCommerce'te tanımlı vergi oranını döndürür.
     * @return array{id:int,percent:float,label:string}|null
     */
    private function tax_rate_for_class($tax_class) {
        static $cache = array();
        $key = (string) $tax_class;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $result = null;
        if (class_exists('WC_Tax')) {
            $rates = WC_Tax::get_rates_for_tax_class($key);
            if (!empty($rates)) {
                $first  = reset($rates);
                $result = array(
                    'id'      => (int) $first->tax_rate_id,
                    'percent' => (float) $first->tax_rate,
                    'label'   => ($first->tax_rate_name !== '' ? $first->tax_rate_name : 'KDV'),
                );
            }
        }
        $cache[$key] = $result;
        return $result;
    }

    /**
     * Biriken oran→KDV toplamlarından siparişe WC_Order_Item_Tax kalemleri ekler ve toplam
     * KDV'yi döndürür. Order-level get_taxes()/get_tax_totals() (fatura/ERP'nin okuduğu yer)
     * bu kalemler olmadan boş döner.
     */
    private function apply_tax_items($order, $tax_acc) {
        if (empty($tax_acc) || !is_array($tax_acc)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($tax_acc as $rate_id => $info) {
            $tax_item = new WC_Order_Item_Tax();
            $tax_item->set_rate_id((int) $rate_id);
            $tax_item->set_rate_percent((float) $info['percent']);
            $tax_item->set_label($info['label']);
            $tax_item->set_tax_total(wc_format_decimal($info['total']));
            $tax_item->set_shipping_tax_total(0);
            $order->add_item($tax_item);
            $sum += (float) $info['total'];
        }
        return $sum;
    }

    /**
     * Trendyol adresini WooCommerce adres dizisine çevirir.
     */
    private function map_address($addr, $package) {
        $first = isset($addr['firstName']) ? sanitize_text_field($addr['firstName']) : '';
        $last  = isset($addr['lastName']) ? sanitize_text_field($addr['lastName']) : '';
        if ($first === '' && $last === '') {
            $full = isset($addr['fullName']) ? trim((string) $addr['fullName']) : '';
            if ($full === '') {
                $full = trim(((string) ($package['customerFirstName'] ?? '')) . ' ' . ((string) ($package['customerLastName'] ?? '')));
            }
            if ($full !== '') {
                $parts = preg_split('/\s+/', $full);
                $last  = array_pop($parts);
                $first = implode(' ', $parts);
                if ($first === '') {
                    $first = $last;
                    $last  = '';
                }
            }
        }

        $address_1 = isset($addr['address1']) ? sanitize_text_field($addr['address1']) : '';
        $address_2 = trim(
            (isset($addr['address2']) ? sanitize_text_field($addr['address2']) : '') . ' ' .
            (isset($addr['neighborhood']) ? sanitize_text_field($addr['neighborhood']) : '')
        );

        // İl/ilçe: WooCommerce'de (Hezarfen) state = il plaka anahtarı ("TR34"), city = ilçe adı.
        // Trendyol ise city=il adı, district=ilçe gönderir — doğrudan yazılırsa il/ilçe ters düşer.
        $district  = isset($addr['district']) ? sanitize_text_field($addr['district']) : '';
        $state_key = self::resolve_tr_state_key($addr);

        return array(
            'first_name' => $first,
            'last_name'  => $last,
            'company'    => isset($addr['company']) ? sanitize_text_field($addr['company']) : '',
            'address_1'  => $address_1,
            'address_2'  => $address_2,
            'city'       => $district,
            'state'      => $state_key,
            'postcode'   => isset($addr['postalCode']) ? sanitize_text_field($addr['postalCode']) : '',
            'country'    => !empty($addr['countryCode']) ? sanitize_text_field($addr['countryCode']) : 'TR',
            'phone'      => isset($addr['phone']) ? sanitize_text_field($addr['phone']) : '',
        );
    }

    /**
     * Trendyol adresinden WooCommerce TR il anahtarını ("TR34") çözer.
     * Sıra: (1) cityCode plaka, (2) il adından WC TR states (Hezarfen) ters eşleme, (3) ham il adı.
     */
    private static function resolve_tr_state_key($addr) {
        if (isset($addr['cityCode']) && (int) $addr['cityCode'] > 0) {
            return sprintf('TR%02d', (int) $addr['cityCode']);
        }

        $city_name = isset($addr['city']) ? trim((string) $addr['city']) : '';
        if ($city_name !== '' && function_exists('WC') && WC()->countries) {
            $states = WC()->countries->get_states('TR');
            if (is_array($states) && !empty($states)) {
                $key = array_search($city_name, $states, true);
                if ($key !== false) {
                    return (string) $key;
                }
            }
        }

        return sanitize_text_field($city_name);
    }

    /**
     * Siparişi İZOLE kaydeder: `woocommerce_new_order` dinleyicileri save süresince nötrlenir.
     *
     * @return int Sipariş ID'si (0 = hata)
     */
    private function isolated_save($order) {
        global $wp_filter;

        // Save süresince nötrlenecek hook'lar: yeni sipariş + TÜM statü-geçiş ailesi.
        // Böylece statü güncellemelerinde de ESD/kargo/CRM/havale/fraud tetiklenmez.
        $hooks = array(
            'woocommerce_new_order',
            'woocommerce_order_edit_status',
        );
        foreach (array_keys($wp_filter) as $hook_name) {
            // woocommerce_order_status_changed, woocommerce_order_status_{slug},
            // woocommerce_order_status_{from}_to_{to} — hepsi bu önekle başlar.
            if (strpos($hook_name, 'woocommerce_order_status_') === 0) {
                $hooks[] = $hook_name;
            }
        }
        $hooks = array_unique($hooks);

        // 7 Ağu 2026 — WooCommerce'in KENDİ sipariş sayacı muaf tutulur.
        // OrderCountCacheService şu iki kancaya bağlıdır: `woocommerce_new_order` ve
        // `woocommerce_order_status_changed`. Kancayı komple kaldırdığımızda sayaç
        // güncellenmiyordu; admin sipariş listesindeki statü rozetleri gerçek veriden
        // kopuyordu (canlıda "Trendyol - Sipariş Alındı (7)" görünüp içi boş çıkıyordu,
        // "Trendyol - Yeni" ise -5'e düşmüştü). Bu yüzden artık kancayı komple silmek
        // yerine, yalnızca çekirdek sayaç callback'lerini bırakıp gerisini nötrlüyoruz.
        $saved = array();
        foreach ($hooks as $hook) {
            if (!isset($wp_filter[$hook])) {
                continue;
            }
            $saved[$hook] = $wp_filter[$hook];

            $korunacak = self::sayac_callbackleri($wp_filter[$hook]);
            if (empty($korunacak)) {
                unset($wp_filter[$hook]);
                continue;
            }

            $yeni_hook = new \WP_Hook();
            foreach ($korunacak as $oncelik => $cbs) {
                foreach ($cbs as $cb) {
                    $yeni_hook->add_filter($hook, $cb['function'], $oncelik, $cb['accepted_args']);
                }
            }
            $wp_filter[$hook] = $yeni_hook;
        }

        $order_id = 0;
        try {
            $order_id = (int) $order->save();
        } catch (\Exception $e) {
            Eru_Trendyol_Logger::error('Sipariş kaydında istisna: ' . $e->getMessage());
            $order_id = 0;
        } finally {
            foreach ($saved as $hook => $cbs) {
                $wp_filter[$hook] = $cbs; // her durumda geri yükle
            }
        }

        return $order_id;
    }

    /**
     * İzole save sırasında KORUNACAK callback'ler: yalnızca WooCommerce'in sipariş
     * sayacı önbelleğini güncelleyenler. Diğer her şey (ESD, kargo, CRM, havale, fraud)
     * eskisi gibi nötrlenir.
     *
     * Sınıf adı tam eşleşme yerine parça eşleşmesiyle aranır ki WooCommerce sınıfı
     * başka bir namespace'e taşırsa sessizce kırılmasın.
     */
    private static function sayac_callbackleri($hook_obj) {
        $bulunan = array();

        if (!is_object($hook_obj) || !isset($hook_obj->callbacks) || !is_array($hook_obj->callbacks)) {
            return $bulunan;
        }

        foreach ($hook_obj->callbacks as $oncelik => $cbs) {
            foreach ($cbs as $cb) {
                $fn = isset($cb['function']) ? $cb['function'] : null;
                if (!is_array($fn) || !isset($fn[0]) || !is_object($fn[0])) {
                    continue;
                }
                if (false !== strpos(get_class($fn[0]), 'OrderCountCacheService')) {
                    $bulunan[$oncelik][] = $cb;
                }
            }
        }

        return $bulunan;
    }

    /** Verilen değerlerden boş olmayan ilkini string olarak döner. */
    private function first_nonempty(...$values) {
        foreach ($values as $v) {
            if ($v !== null && $v !== '' && $v !== false) {
                return is_scalar($v) ? (string) $v : '';
            }
        }
        return '';
    }

    private function set_meta_if($order, $key, $value) {
        if ($value === null || $value === '') {
            return;
        }
        $order->update_meta_data($key, is_scalar($value) ? sanitize_text_field((string) $value) : wp_json_encode($value));
    }

    private function record_failure($package_id, $package, $message) {
        global $wpdb;
        $table = $wpdb->prefix . 'eru_trendyol_orders';

        $wpdb->replace(
            $table,
            array(
                'shipment_package_id' => (int) $package_id,
                'order_number'        => isset($package['orderNumber']) ? (string) $package['orderNumber'] : null,
                'wc_order_id'         => null,
                'status'              => 'error',
                'review'              => 0,
                'error'              => (string) $message,
                'created_at'          => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%d', '%s', '%s')
        );

        Eru_Trendyol_Logger::error(
            sprintf('Paket #%d içeri alınamadı: %s', $package_id, $message),
            array('package_id' => $package_id)
        );
    }
}
