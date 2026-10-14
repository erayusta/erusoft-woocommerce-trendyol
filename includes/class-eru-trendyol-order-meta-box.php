<?php
/**
 * Trendyol sipariş ekranı metabox'ı.
 * İçeri alınan Trendyol siparişlerinde fatura tipi / VKN / vergi dairesi / TC /
 * e-fatura mükellefiyeti / kargo takip no gibi `_eru_trendyol_*` meta'sını gösterir.
 * Salt-okunur. HPOS + klasik depolama için çift hook.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Eru_Trendyol_Order_Meta_Box {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes_shop_order', array($this, 'register_metabox_classic'), 10, 1);
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array($this, 'register_metabox_hpos'), 10, 1);
    }

    public function register_metabox_classic($post) {
        if (!$post instanceof WP_Post) {
            return;
        }
        $this->register_metabox_for_screen('shop_order', wc_get_order($post->ID));
    }

    public function register_metabox_hpos($order) {
        $screen = wc_get_page_screen_id('shop-order');
        if (!$screen) {
            $screen = 'woocommerce_page_wc-orders';
        }
        $this->register_metabox_for_screen($screen, $order instanceof WC_Order ? $order : null);
    }

    private function register_metabox_for_screen($screen, $order) {
        // Yalnız Trendyol kaynaklı siparişlerde göster.
        if (!$order instanceof WC_Order || $order->get_meta('_eru_trendyol_source') !== 'trendyol') {
            return;
        }
        add_meta_box(
            'eru_trendyol_order_metabox',
            __('Trendyol Sipariş Bilgisi', 'eru-trendyol'),
            array($this, 'render_metabox'),
            $screen,
            'side',
            'default'
        );
    }

    public function render_metabox($post_or_order) {
        $order = ($post_or_order instanceof WP_Post) ? wc_get_order($post_or_order->ID) : $post_or_order;
        if (!$order instanceof WC_Order) {
            echo '<p style="color:#666;">' . esc_html__('Sipariş okunamadı.', 'eru-trendyol') . '</p>';
            return;
        }

        $commercial = $order->get_meta('_eru_trendyol_commercial') === 'yes';
        $einvoice   = $order->get_meta('_eru_trendyol_einvoice') === 'yes';
        $micro      = $order->get_meta('_eru_trendyol_micro') === 'yes';

        $tr_status   = (string) $order->get_meta('_eru_trendyol_status');
        $ty_statuses = Eru_Trendyol::statuses();
        $ty_slug     = $order->get_status();
        $ty_label    = isset($ty_statuses[$ty_slug]) ? $ty_statuses[$ty_slug] : wc_get_order_status_name($ty_slug);

        $tracking      = (string) $order->get_meta('_eru_trendyol_cargo_tracking');
        $tracking_link = (string) $order->get_meta('_eru_trendyol_cargo_tracking_link');
        $invoice_link  = (string) $order->get_meta('_eru_trendyol_invoice_link');

        $rows = array(
            __('Sipariş No', 'eru-trendyol')      => (string) $order->get_meta('_eru_trendyol_order_number'),
            __('Paket ID', 'eru-trendyol')        => (string) $order->get_meta('_eru_trendyol_package_id'),
            __('Trendyol Statü', 'eru-trendyol')  => $tr_status,
            __('Pazaryeri Statüsü', 'eru-trendyol')        => $ty_label,
            __('Fatura Tipi', 'eru-trendyol')     => $commercial ? __('Kurumsal (Ticari)', 'eru-trendyol') : __('Bireysel', 'eru-trendyol'),
            __('Firma Adı', 'eru-trendyol')       => $order->get_billing_company(),
            __('VKN', 'eru-trendyol')             => (string) $order->get_meta('_eru_trendyol_tax_number'),
            __('Vergi Dairesi', 'eru-trendyol')   => (string) $order->get_meta('_eru_trendyol_tax_office'),
            __('TC Kimlik No', 'eru-trendyol')    => (string) $order->get_meta('_eru_trendyol_identity_number'),
            __('E-Fatura Mükellefi', 'eru-trendyol') => $einvoice ? __('Evet', 'eru-trendyol') : __('Hayır', 'eru-trendyol'),
            __('Kargo Firması', 'eru-trendyol')   => (string) $order->get_meta('_eru_trendyol_cargo_provider'),
            __('Teslimat No', 'eru-trendyol')     => $tracking,
            __('ETGB No', 'eru-trendyol')         => (string) $order->get_meta('_eru_trendyol_etgb_no'),
            __('ETGB Tarihi', 'eru-trendyol')     => (string) $order->get_meta('_eru_trendyol_etgb_date'),
            __('Mikro İhracat', 'eru-trendyol')   => $micro ? __('Evet', 'eru-trendyol') : __('Hayır', 'eru-trendyol'),
        );
        ?>
        <table class="widefat striped" style="border:0;">
            <tbody>
            <?php foreach ($rows as $label => $value) : ?>
                <?php if ($value === '' || $value === null) { continue; } ?>
                <tr>
                    <td style="font-weight:600;width:42%;"><?php echo esc_html($label); ?></td>
                    <td><?php echo esc_html($value); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($tracking_link !== '' || $invoice_link !== '') : ?>
            <p style="margin-top:8px;">
                <?php if ($tracking_link !== '') : ?>
                    <a href="<?php echo esc_url($tracking_link); ?>" target="_blank" rel="noopener" class="button button-small">
                        <?php esc_html_e('Kargo Takip', 'eru-trendyol'); ?>
                    </a>
                <?php endif; ?>
                <?php if ($invoice_link !== '') : ?>
                    <a href="<?php echo esc_url($invoice_link); ?>" target="_blank" rel="noopener" class="button button-small">
                        <?php esc_html_e('Fatura', 'eru-trendyol'); ?>
                    </a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php
    }
}
