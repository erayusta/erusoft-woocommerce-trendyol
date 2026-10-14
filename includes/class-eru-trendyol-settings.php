<?php
/**
 * Trendyol ayar sayfası (WooCommerce alt menüsü) + kimlik bilgileri.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Trendyol_Settings {

    const SLUG  = 'eru-trendyol';
    const GROUP = 'eru_trendyol_settings_group';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /** Ayarları varsayılanlarla birleştirip döndürür. */
    public static function get() {
        $saved = get_option(ERU_TRENDYOL_OPTION_KEY, array());
        if (!is_array($saved)) {
            $saved = array();
        }
        return wp_parse_args($saved, Eru_Trendyol_Installer::default_settings());
    }

    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            __('Trendyol Siparişleri', 'eru-trendyol'),
            __('Trendyol Siparişleri', 'eru-trendyol'),
            'manage_woocommerce',
            self::SLUG,
            array($this, 'render_page')
        );
    }

    public function register_settings() {
        register_setting(
            self::GROUP,
            ERU_TRENDYOL_OPTION_KEY,
            array('sanitize_callback' => array($this, 'sanitize'))
        );
    }

    public function sanitize($input) {
        $out = self::get(); // mevcut değerlerle başla, kaybetme

        $out['enabled']         = !empty($input['enabled']) ? 1 : 0;
        $out['test_mode']       = !empty($input['test_mode']) ? 1 : 0;
        $out['seller_id']       = preg_replace('/\D/', '', (string) ($input['seller_id'] ?? ''));
        $out['api_key']         = sanitize_text_field($input['api_key'] ?? '');
        $out['api_secret']      = sanitize_text_field($input['api_secret'] ?? '');
        $out['integration_ref'] = sanitize_text_field($input['integration_ref'] ?? '');

        $statuses = sanitize_text_field($input['pull_statuses'] ?? Eru_Trendyol_Installer::DEFAULT_PULL_STATUSES);
        $out['pull_statuses'] = $statuses !== '' ? $statuses : Eru_Trendyol_Installer::DEFAULT_PULL_STATUSES;

        $out['lookback_hours'] = max(1, min(720, (int) ($input['lookback_hours'] ?? 24)));

        $out['stock_sync_enabled'] = !empty($input['stock_sync_enabled']) ? 1 : 0;

        return $out;
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $s = self::get();

        $last_sync   = get_option('eru_trendyol_last_sync');
        $last_result = get_option('eru_trendyol_last_result');
        $manual_url  = wp_nonce_url(
            admin_url('admin-post.php?action=eru_trendyol_manual_sync'),
            'eru_trendyol_manual_sync'
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Trendyol Siparişleri', 'eru-trendyol'); ?></h1>

            <?php if (isset($_GET['eru_trendyol_synced'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Manuel çekme tamamlandı. Sonucu aşağıda görebilirsiniz.', 'eru-trendyol'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['eru_trendyol_one'])) : ?>
                <div class="notice notice-info is-dismissible">
                    <p><?php echo esc_html(rawurldecode(wp_unslash($_GET['eru_trendyol_one']))); ?></p>
                </div>
            <?php endif; ?>

            <table class="widefat" style="max-width:640px;margin-bottom:16px;">
                <tbody>
                    <tr>
                        <td><strong><?php esc_html_e('Son senkron', 'eru-trendyol'); ?></strong></td>
                        <td><?php echo $last_sync ? esc_html($last_sync) : '—'; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Son sonuç', 'eru-trendyol'); ?></strong></td>
                        <td><?php echo $last_result ? esc_html($last_result) : '—'; ?></td>
                    </tr>
                </tbody>
            </table>

            <p>
                <a href="<?php echo esc_url($manual_url); ?>" class="button button-secondary">
                    <?php esc_html_e('Şimdi çek', 'eru-trendyol'); ?>
                </a>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px;">
                <?php wp_nonce_field('eru_trendyol_import_one'); ?>
                <input type="hidden" name="action" value="eru_trendyol_import_one">
                <label for="eru_trendyol_order_number"><strong><?php esc_html_e('Tek sipariş çek (sipariş no)', 'eru-trendyol'); ?></strong></label><br>
                <input type="text" id="eru_trendyol_order_number" name="order_number" class="regular-text" placeholder="<?php esc_attr_e('Trendyol sipariş numarası', 'eru-trendyol'); ?>">
                <button type="submit" class="button button-primary"><?php esc_html_e('Bu siparişi çek', 'eru-trendyol'); ?></button>
                <p class="description"><?php esc_html_e('Statü ve tarih filtresine takılmaz, test modunu bypass eder. Karşılaştırma için tek sipariş içeri almakta kullanın.', 'eru-trendyol'); ?></p>
            </form>

            <?php if (!empty($s['stock_sync_enabled'])) :
                $ty_products = get_option('eru_trendyol_seller_products');
                $ty_count    = is_array($ty_products) ? count($ty_products) : 0;
                $ty_at       = (int) get_option('eru_trendyol_seller_products_at', 0);
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:24px;">
                <?php wp_nonce_field('eru_trendyol_stock_full'); ?>
                <input type="hidden" name="action" value="eru_trendyol_stock_full">
                <button type="submit" class="button button-secondary"><?php esc_html_e('Tüm stokları Trendyol\'a gönder', 'eru-trendyol'); ?></button>
                <p class="description">
                    <?php
                    printf(
                        /* translators: 1: ürün sayısı, 2: son yenileme */
                        esc_html__('Trendyol kapsamı: %1$d ürün — son yenileme: %2$s. Eşleştirme SKU (stockCode) öncelikli, GTIN/barkod yedeklidir. Yalnız bu kapsamdaki ürünler gönderilir; yerelde 3000 ürün olsa da Trendyol\'da olan ~%1$d gönderilir.', 'eru-trendyol'),
                        (int) $ty_count,
                        $ty_at ? esc_html(date_i18n('Y-m-d H:i', $ty_at)) : '—'
                    );
                    ?><br>
                    <?php esc_html_e('Bu buton Trendyol ürün listesini TAZE çeker ve kapsamın tamamını gönderir. Normalde cron yalnız stoğu DEĞİŞEN ürünleri (delta) gönderir.', 'eru-trendyol'); ?>
                </p>
            </form>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Otomatik içe aktarım', 'eru-trendyol'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[enabled]" value="1" <?php checked($s['enabled'], 1); ?>>
                                <?php esc_html_e('5 dakikalık cron ile siparişleri otomatik çek', 'eru-trendyol'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Satıcı ID (Cari ID)', 'eru-trendyol'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[seller_id]" value="<?php echo esc_attr($s['seller_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API Key', 'eru-trendyol'); ?></th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[api_key]" value="<?php echo esc_attr($s['api_key']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('API Secret', 'eru-trendyol'); ?></th>
                        <td><input type="password" class="regular-text" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[api_secret]" value="<?php echo esc_attr($s['api_secret']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Entegrasyon Referans Kodu', 'eru-trendyol'); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[integration_ref]" value="<?php echo esc_attr($s['integration_ref']); ?>">
                            <p class="description"><?php esc_html_e('User-Agent başlığında kullanılır. Boşsa "SelfIntegration" gönderilir.', 'eru-trendyol'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Çekilecek statüler', 'eru-trendyol'); ?></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[pull_statuses]" value="<?php echo esc_attr($s['pull_statuses']); ?>">
                            <p class="description"><?php esc_html_e('Virgülle ayır. Statü senkronu için tüm yaşam döngüsü önerilir: Created,Picking,Invoiced,Shipped,AtCollectionPoint,Delivered,Cancelled,UnDelivered,Returned', 'eru-trendyol'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Geriye dönük süre (saat)', 'eru-trendyol'); ?></th>
                        <td><input type="number" min="1" max="720" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[lookback_hours]" value="<?php echo esc_attr($s['lookback_hours']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Test modu', 'eru-trendyol'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[test_mode]" value="1" <?php checked($s['test_mode'], 1); ?>>
                                <?php esc_html_e('Test modu (sadece logla, gözlem amaçlı)', 'eru-trendyol'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Ürün stok gönderimi', 'eru-trendyol'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(ERU_TRENDYOL_OPTION_KEY); ?>[stock_sync_enabled]" value="1" <?php checked(!empty($s['stock_sync_enabled']), true); ?>>
                                <?php esc_html_e('Trendyol\'a ürün stoklarını gönder', 'eru-trendyol'); ?>
                            </label>
                            <p class="description"><?php esc_html_e('Stok değişince ANLIK push (debounced); SAATLİK Trendyol ürün listesi yenilenip yeni açılanlar eşleştirilir; 10 dk\'lık delta emniyet ağı. Yalnız Trendyol\'da kayıtlı (GTIN/EAN) ürünler gönderilir. Test modunda yalnız loglanır.', 'eru-trendyol'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e('Son loglar', 'eru-trendyol'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:160px;"><?php esc_html_e('Tarih', 'eru-trendyol'); ?></th>
                        <th style="width:70px;"><?php esc_html_e('Seviye', 'eru-trendyol'); ?></th>
                        <th><?php esc_html_e('Mesaj', 'eru-trendyol'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (Eru_Trendyol_Logger::recent(30) as $row) : ?>
                        <tr>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo esc_html($row->level); ?></td>
                            <td><?php echo esc_html($row->message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
