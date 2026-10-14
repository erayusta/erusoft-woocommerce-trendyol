<?php
/**
 * Plugin Name: Erusoft WooCommerce Trendyol Eklentisi
 * Description: Trendyol pazaryeri sipariş entegrasyonu — düşen siparişleri 5 dakikalık cron ile İZOLE olarak WooCommerce'e içeri alır; mağazanızın stok/ERP/kargo akışını tetiklemez.
 * Version: 1.2.0
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Requires Plugins: woocommerce
 * Author: EruSoft
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: eru-trendyol
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ERU_TRENDYOL_VERSION')) {
    define('ERU_TRENDYOL_VERSION', '1.2.0');
}
if (!defined('ERU_TRENDYOL_DB_VERSION')) {
    define('ERU_TRENDYOL_DB_VERSION', '1.1.0');
}
if (!defined('ERU_TRENDYOL_PLUGIN_FILE')) {
    define('ERU_TRENDYOL_PLUGIN_FILE', __FILE__);
}
if (!defined('ERU_TRENDYOL_PLUGIN_DIR')) {
    define('ERU_TRENDYOL_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('ERU_TRENDYOL_PLUGIN_URL')) {
    define('ERU_TRENDYOL_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// Sabitler
define('ERU_TRENDYOL_OPTION_KEY', 'eru_trendyol_settings');
define('ERU_TRENDYOL_CRON_HOOK', 'eru_trendyol_sync_orders');
define('ERU_TRENDYOL_CRON_INTERVAL', 'eru_trendyol_five_minutes');
define('ERU_TRENDYOL_STATUS', 'trendyol-yeni');          // wc- öneksiz slug (tüm statü slug'ları ≤20 karakter)
// "Sipariş Alındı" sonrası bekleme (saniye). Bu süre dolunca Trendyol'a "İşleme Al" (Picking)
// bildirilir VE statü "Hazırlanıyor"a çekilir.
if (!defined('ERU_TRENDYOL_PICKING_DWELL')) {
    define('ERU_TRENDYOL_PICKING_DWELL', 30);
}
define('ERU_TRENDYOL_API_BASE', 'https://apigw.trendyol.com/integration/order/sellers');
define('ERU_TRENDYOL_INVENTORY_BASE', 'https://apigw.trendyol.com/integration/inventory/sellers'); // stok/fiyat
define('ERU_TRENDYOL_PRODUCT_BASE', 'https://apigw.trendyol.com/integration/product/sellers');     // satıcı ürün listesi
define('ERU_TRENDYOL_STOCK_CRON_HOOK', 'eru_trendyol_stock_sync_cron');           // 10 dk delta emniyet ağı
define('ERU_TRENDYOL_STOCK_CRON_INTERVAL', 'eru_trendyol_thirty_minutes');
define('ERU_TRENDYOL_STOCK_FLUSH_HOOK', 'eru_trendyol_stock_flush');             // anlık (debounced) push
define('ERU_TRENDYOL_PRODUCT_CRON_HOOK', 'eru_trendyol_product_match_cron');     // saatlik kapsam + eşleştirme
if (!defined('ERU_TRENDYOL_STOCK_FLUSH_DELAY')) {
    define('ERU_TRENDYOL_STOCK_FLUSH_DELAY', 15); // stok değişiminden sonra anlık push gecikmesi (sn)
}

require_once ERU_TRENDYOL_PLUGIN_DIR . 'includes/class-eru-trendyol-installer.php';
require_once ERU_TRENDYOL_PLUGIN_DIR . 'includes/class-eru-trendyol-logger.php';
require_once ERU_TRENDYOL_PLUGIN_DIR . 'includes/class-eru-trendyol-client.php';
require_once ERU_TRENDYOL_PLUGIN_DIR . 'includes/class-eru-trendyol-importer.php';
require_once ERU_TRENDYOL_PLUGIN_DIR . 'includes/class-eru-trendyol-settings.php';
require_once ERU_TRENDYOL_PLUGIN_DIR . 'includes/class-eru-trendyol-order-meta-box.php';
require_once ERU_TRENDYOL_PLUGIN_DIR . 'includes/class-eru-trendyol-stock-sync.php';
require_once ERU_TRENDYOL_PLUGIN_DIR . 'includes/class-eru-trendyol.php';

// HPOS uyumu
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            ERU_TRENDYOL_PLUGIN_FILE,
            true
        );
    }
});

// Özel cron aralıkları (sipariş: 5 dk, stok: 30 dk)
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules[ERU_TRENDYOL_CRON_INTERVAL])) {
        $schedules[ERU_TRENDYOL_CRON_INTERVAL] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Her 5 Dakika (Trendyol)', 'eru-trendyol'),
        );
    }
    if (!isset($schedules[ERU_TRENDYOL_STOCK_CRON_INTERVAL])) {
        $schedules[ERU_TRENDYOL_STOCK_CRON_INTERVAL] = array(
            'interval' => 10 * MINUTE_IN_SECONDS,
            'display'  => __('Her 10 Dakika (Trendyol Stok)', 'eru-trendyol'),
        );
    }
    return $schedules;
});

// Aktivasyon / deaktivasyon
register_activation_hook(__FILE__, 'eru_trendyol_activate');
register_deactivation_hook(__FILE__, 'eru_trendyol_deactivate');

function eru_trendyol_activate() {
    Eru_Trendyol_Installer::install();
    if (!wp_next_scheduled(ERU_TRENDYOL_CRON_HOOK)) {
        wp_schedule_event(time() + 60, ERU_TRENDYOL_CRON_INTERVAL, ERU_TRENDYOL_CRON_HOOK);
    }
}

function eru_trendyol_deactivate() {
    wp_clear_scheduled_hook(ERU_TRENDYOL_CRON_HOOK);
    wp_clear_scheduled_hook(ERU_TRENDYOL_STOCK_CRON_HOOK);
    wp_clear_scheduled_hook(ERU_TRENDYOL_PRODUCT_CRON_HOOK);
    wp_clear_scheduled_hook(ERU_TRENDYOL_STOCK_FLUSH_HOOK);
}

// Üretimde dosya güncellemesi sonrası şema migrasyonu
add_action('plugins_loaded', array('Eru_Trendyol_Installer', 'install'));

// Ana sınıfı başlat
add_action('plugins_loaded', function () {
    Eru_Trendyol::get_instance();
}, 11);
