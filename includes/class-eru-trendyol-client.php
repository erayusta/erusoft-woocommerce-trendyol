<?php
/**
 * Trendyol API istemcisi — getShipmentPackages.
 *
 * GET https://apigw.trendyol.com/integration/order/sellers/{sellerId}/orders
 * Auth: Basic base64(apiKey:apiSecret)
 * User-Agent: "{sellerId} - {integration_ref}" (yoksa "{sellerId} - SelfIntegration")
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Trendyol_Client {

    private $seller_id;
    private $api_key;
    private $api_secret;
    private $user_agent;

    public function __construct($settings) {
        $this->seller_id  = trim((string) ($settings['seller_id'] ?? ''));
        $this->api_key    = trim((string) ($settings['api_key'] ?? ''));
        $this->api_secret = trim((string) ($settings['api_secret'] ?? ''));

        $ref = trim((string) ($settings['integration_ref'] ?? ''));
        $this->user_agent = $this->seller_id . ' - ' . ($ref !== '' ? $ref : 'SelfIntegration');
    }

    public function is_configured() {
        return $this->seller_id !== '' && $this->api_key !== '' && $this->api_secret !== '';
    }

    /**
     * Tek sayfa sipariş paketi çeker.
     *
     * @param array $args status, startDate, endDate, page, size, orderByField, orderByDirection
     * @return array|WP_Error Çözümlenmiş cevap gövdesi (content, page, totalPages, ...).
     */
    public function get_shipment_packages($args = array()) {
        if (!$this->is_configured()) {
            return new WP_Error('eru_trendyol_not_configured', 'Trendyol API kimlik bilgileri eksik.');
        }

        $defaults = array(
            'page'             => 0,
            'size'             => 200,
            'orderByField'     => 'CreatedDate',
            'orderByDirection' => 'DESC',
        );
        $args = array_merge($defaults, array_filter($args, function ($v) {
            return $v !== null && $v !== '';
        }));

        $url = trailingslashit(ERU_TRENDYOL_API_BASE) . rawurlencode($this->seller_id) . '/orders';
        $url = add_query_arg($args, $url);

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
                'User-Agent'    => $this->user_agent,
                'Accept'        => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'eru_trendyol_http_' . $code,
                'Trendyol API HTTP ' . $code,
                array('body' => $body)
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new WP_Error('eru_trendyol_bad_json', 'Trendyol API geçersiz JSON döndü.', array('body' => $body));
        }

        return $data;
    }

    /**
     * Tek bir siparişi numarasıyla çeker — statü ve dar tarih filtresine TAKILMADAN.
     * Karşılaştırma/test için tek sipariş içeri almakta kullanılır.
     *
     * @param string $order_number Trendyol orderNumber
     * @return array|WP_Error  Paket dizisi (bir sipariş birden çok paket içerebilir)
     */
    public function fetch_by_order_number($order_number) {
        $order_number = trim((string) $order_number);
        if ($order_number === '') {
            return new WP_Error('eru_trendyol_no_order_number', 'Sipariş numarası boş.');
        }

        // Geniş bir tarih penceresi (son 365 gün) — bazı ortamlar tarih ister; statü verilmez.
        $end_ms   = time() * 1000;
        $start_ms = (time() - 365 * DAY_IN_SECONDS) * 1000;

        $all  = array();
        $page = 0;
        do {
            $data = $this->get_shipment_packages(array(
                'orderNumber' => $order_number,
                'page'        => $page,
                'size'        => 200,
                'startDate'   => $start_ms,
                'endDate'     => $end_ms,
            ));
            if (is_wp_error($data)) {
                return $data;
            }

            $content = isset($data['content']) && is_array($data['content']) ? $data['content'] : array();
            foreach ($content as $pkg) {
                $all[] = $pkg;
            }

            $total_pages = isset($data['totalPages']) ? (int) $data['totalPages'] : 1;
            $page++;
        } while ($page < $total_pages && $page < 20);

        return $all;
    }

    /**
     * Paket statüsünü Trendyol'da günceller (outbound).
     * Örn. Created -> Picking ("Hazırlanıyor") bildirimi.
     *
     * PUT {API_BASE}/{sellerId}/shipment-packages/{packageId}
     * Body: { "lines": [{lineId, quantity}], "params": {}, "status": "Picking" }
     *
     * @param int    $package_id shipmentPackageId
     * @param string $status     Trendyol statüsü (ör. "Picking")
     * @param array  $lines      [{lineId, quantity}, ...] (Picking için gerekli)
     * @return true|WP_Error
     */
    public function update_package_status($package_id, $status, $lines = array()) {
        if (!$this->is_configured()) {
            return new WP_Error('eru_trendyol_not_configured', 'Trendyol API kimlik bilgileri eksik.');
        }
        $package_id = (int) $package_id;
        if (!$package_id) {
            return new WP_Error('eru_trendyol_no_package_id', 'Geçersiz paket ID.');
        }

        $url = trailingslashit(ERU_TRENDYOL_API_BASE) . rawurlencode($this->seller_id) . '/shipment-packages/' . $package_id;

        $payload = array(
            'status' => (string) $status,
            'params' => (object) array(),
        );
        if (!empty($lines)) {
            $payload['lines'] = array_values($lines);
        }

        $response = wp_remote_request($url, array(
            'method'  => 'PUT',
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
                'User-Agent'    => $this->user_agent,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            Eru_Trendyol_Logger::error('Trendyol paket statü güncelleme bağlantı hatası: ' . $response->get_error_message(),
                array('url' => $url, 'payload' => $payload));
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Tam istek/yanıt log'a — Trendyol'un ne döndürdüğünü görmek için.
        Eru_Trendyol_Logger::info(
            sprintf('Trendyol updatePackage #%d status=%s -> HTTP %d', $package_id, $status, $code),
            array('url' => $url, 'payload' => $payload, 'response' => $body)
        );

        if ($code < 200 || $code >= 300) {
            return new WP_Error(
                'eru_trendyol_http_' . $code,
                'Trendyol paket statü güncelleme HTTP ' . $code,
                array('body' => $body)
            );
        }

        return true;
    }

    /**
     * Satıcının Trendyol'daki ürünlerini çeker (sayfalı): her ürün için barkod + stockCode.
     * Stok senkronu eşleştirmeyi stockCode (= bizim SKU) öncelikli, barkod yedekli yapar;
     * API'ye HER ZAMAN Trendyol barkodu gider (price-and-inventory barkodla eşler).
     *
     * GET {PRODUCT_BASE}/{sellerId}/products?page=&size=&approved=true
     *
     * @return array|WP_Error  [ ['barcode'=>'...', 'stockCode'=>'...'], ... ] (barkodsuzlar atlanır)
     */
    public function fetch_seller_products() {
        if (!$this->is_configured()) {
            return new WP_Error('eru_trendyol_not_configured', 'Trendyol API kimlik bilgileri eksik.');
        }

        $products = array();
        $page = 0;
        do {
            $url = trailingslashit(ERU_TRENDYOL_PRODUCT_BASE) . rawurlencode($this->seller_id) . '/products';
            $url = add_query_arg(array('page' => $page, 'size' => 1000, 'approved' => 'true'), $url);

            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
                    'User-Agent'    => $this->user_agent,
                    'Accept'        => 'application/json',
                ),
            ));
            if (is_wp_error($response)) {
                return $response;
            }
            $code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            if ($code < 200 || $code >= 300) {
                return new WP_Error('eru_trendyol_http_' . $code, 'Trendyol ürün listesi HTTP ' . $code, array('body' => $body));
            }
            $data = json_decode($body, true);
            $content = (is_array($data) && isset($data['content']) && is_array($data['content'])) ? $data['content'] : array();
            foreach ($content as $p) {
                $barcode = isset($p['barcode']) ? (string) $p['barcode'] : '';
                if ($barcode === '') {
                    continue; // API anahtarı barkod; barkodsuz ürüne stok gönderilemez
                }
                $products[] = array(
                    'barcode'   => $barcode,
                    'stockCode' => isset($p['stockCode']) ? (string) $p['stockCode'] : '',
                );
            }
            $total_pages = isset($data['totalPages']) ? (int) $data['totalPages'] : 1;
            $page++;
        } while ($page < $total_pages && $page < 100);

        return $products;
    }

    /**
     * Ürün stok/fiyat günceller (outbound, batch).
     * POST {INVENTORY_BASE}/{sellerId}/products/price-and-inventory
     * Body: { "items": [{ "barcode": "...", "quantity": N }, ...] }  (stok-only: fiyat alanları yok)
     *
     * @param array $items [{barcode, quantity[, salePrice, listPrice]}, ...] (≤1000)
     * @return string|true|WP_Error  Başarılıysa yanıt gövdesi (batchRequestId içerir)
     */
    public function update_price_inventory($items) {
        if (!$this->is_configured()) {
            return new WP_Error('eru_trendyol_not_configured', 'Trendyol API kimlik bilgileri eksik.');
        }
        $items = array_values(array_filter((array) $items));
        if (empty($items)) {
            return true;
        }

        $url = trailingslashit(ERU_TRENDYOL_INVENTORY_BASE) . rawurlencode($this->seller_id) . '/products/price-and-inventory';

        $response = wp_remote_post($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
                'User-Agent'    => $this->user_agent,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode(array('items' => $items)),
        ));

        if (is_wp_error($response)) {
            Eru_Trendyol_Logger::error('Trendyol stok güncelleme bağlantı hatası: ' . $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        Eru_Trendyol_Logger::info(
            sprintf('Trendyol stok güncelleme: %d kalem -> HTTP %d', count($items), $code),
            array('response' => $body)
        );

        if ($code < 200 || $code >= 300) {
            return new WP_Error('eru_trendyol_http_' . $code, 'Trendyol stok güncelleme HTTP ' . $code, array('body' => $body));
        }

        return $body;
    }

    /**
     * Verilen statü(ler) ve tarih aralığı için TÜM sayfaları çeker, paketleri düz dizi olarak döner.
     *
     * @return array|WP_Error
     */
    public function fetch_all_packages($statuses, $start_ms, $end_ms) {
        $statuses = array_filter(array_map('trim', explode(',', (string) $statuses)));
        if (empty($statuses)) {
            $statuses = array(''); // statüsüz tek çağrı
        }

        $all = array();
        foreach ($statuses as $status) {
            $page = 0;
            do {
                $args = array(
                    'page'         => $page,
                    'size'         => 200,
                    'startDate'    => $start_ms,
                    'endDate'      => $end_ms,
                    // Tarih penceresini PAKET SON DEĞİŞİKLİK zamanına göre uygula: günler önce
                    // oluşmuş ama statüsü yeni ilerleyen (Shipped/Delivered) siparişleri de yakalar.
                    'orderByField' => 'PackageLastModifiedDate',
                );
                if ($status !== '') {
                    $args['status'] = $status;
                }

                $data = $this->get_shipment_packages($args);
                if (is_wp_error($data)) {
                    return $data;
                }

                $content = isset($data['content']) && is_array($data['content']) ? $data['content'] : array();
                foreach ($content as $pkg) {
                    $all[] = $pkg;
                }

                $total_pages = isset($data['totalPages']) ? (int) $data['totalPages'] : 1;
                $page++;
            } while ($page < $total_pages && $page < 100); // güvenlik tavanı
        }

        return $all;
    }
}
