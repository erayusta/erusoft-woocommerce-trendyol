<?php
/**
 * Trendyol kurulum/migrasyon.
 * - eru_trendyol_orders : içeri alınan paketlerin dedupe + durum kaydı
 * - eru_trendyol_log    : cron/işlem logları
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Trendyol_Installer {

    /** Şema sürümü; şema değişince artır + update_to_X_Y_Z() ekle. */
    private static $db_version = '1.1.0';

    /** Cron'un çekeceği varsayılan statüler — tam yaşam döngüsü (statü senkronu için). */
    const DEFAULT_PULL_STATUSES = 'Created,Picking,Invoiced,Shipped,AtCollectionPoint,Delivered,Cancelled,UnDelivered,Returned';

    public static function install() {
        $installed = get_option('eru_trendyol_db_version');

        if (!$installed) {
            self::initial_setup();
            return;
        }
        self::maybe_update($installed);
    }

    private static function initial_setup() {
        if (false === get_option(ERU_TRENDYOL_OPTION_KEY)) {
            add_option(ERU_TRENDYOL_OPTION_KEY, self::default_settings());
        }
        self::create_tables();
        add_option('eru_trendyol_db_version', self::$db_version);
    }

    private static function maybe_update($installed_version) {
        if (version_compare($installed_version, self::$db_version, '<')) {
            // Migrasyon merdiveni:
            if (version_compare($installed_version, '1.1.0', '<')) {
                self::update_to_1_1_0();
            }
            self::create_tables(); // dbDelta idempotent
            update_option('eru_trendyol_db_version', self::$db_version);
        }
    }

    /**
     * 1.1.0: cron yalnız 'Created,Picking' çekiyordu; statü senkronu için tam yaşam
     * döngüsüne taşı. Yalnızca kullanıcı eski varsayılanı hiç değiştirmediyse güncelle.
     */
    private static function update_to_1_1_0() {
        $settings = get_option(ERU_TRENDYOL_OPTION_KEY);
        if (is_array($settings)
            && (empty($settings['pull_statuses']) || $settings['pull_statuses'] === 'Created,Picking')) {
            $settings['pull_statuses'] = self::DEFAULT_PULL_STATUSES;
            update_option(ERU_TRENDYOL_OPTION_KEY, $settings);
        }
    }

    public static function default_settings() {
        return array(
            'enabled'         => 0,
            'test_mode'       => 1,
            'seller_id'       => '',
            'api_key'         => '',
            'api_secret'      => '',
            'integration_ref' => '',          // User-Agent: "{seller_id} - {ref}" / yoksa SelfIntegration
            'pull_statuses'   => self::DEFAULT_PULL_STATUSES,
            'lookback_hours'  => 24,
            'stock_sync_enabled' => 0,        // Trendyol'a ürün stok gönderimi (Aktif/Pasif)
        );
    }

    private static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $orders = $wpdb->prefix . 'eru_trendyol_orders';
        $log    = $wpdb->prefix . 'eru_trendyol_log';

        $sql_orders = "CREATE TABLE $orders (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shipment_package_id bigint(20) NOT NULL,
            order_number varchar(64) DEFAULT NULL,
            wc_order_id bigint(20) DEFAULT NULL,
            status varchar(32) DEFAULT 'imported',
            review tinyint(1) NOT NULL DEFAULT 0,
            error text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY shipment_package_id (shipment_package_id),
            KEY wc_order_id (wc_order_id),
            KEY status (status)
        ) $charset_collate;";

        $sql_log = "CREATE TABLE $log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            level varchar(16) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_orders);
        dbDelta($sql_log);
    }
}
