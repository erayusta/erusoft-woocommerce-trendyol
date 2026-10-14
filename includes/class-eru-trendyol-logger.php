<?php
/**
 * Trendyol basit DB logger.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Trendyol_Logger {

    public static function log($level, $message, $context = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'eru_trendyol_log';

        $wpdb->insert(
            $table,
            array(
                'level'      => substr((string) $level, 0, 16),
                'message'    => (string) $message,
                'context'    => $context !== null ? wp_json_encode($context) : null,
                'created_at' => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s')
        );
    }

    public static function info($message, $context = null) {
        self::log('info', $message, $context);
    }

    public static function error($message, $context = null) {
        self::log('error', $message, $context);
    }

    /** Son N log satırı (admin gösterimi için). */
    public static function recent($limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'eru_trendyol_log';
        $limit = max(1, (int) $limit);
        return $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT $limit");
    }
}
