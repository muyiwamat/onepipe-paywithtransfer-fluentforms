<?php
/**
 * Plugin Name: OnePipe PayWithTransfer for Fluent Forms
 * Plugin URI:  https://github.com/muiywamat/onepipe-paywithtransfer
 * Description: Adds OnePipe PayWithTransfer as a payment method in Fluent Forms. Customers pay by bank transfer to a generated and static virtual account.
 * Version:     1.0.0
 * Author:      Múyìwá Mátùlúkò
 * Author URI:  https://muyosan.com.ng
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: onepipe-pwt
 * Requires at least: 5.6
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ONEPIPE_PWT_VERSION', '1.0.0' );
define( 'ONEPIPE_PWT_PATH', plugin_dir_path( __FILE__ ) );
define( 'ONEPIPE_PWT_URL', plugin_dir_url( __FILE__ ) );
define( 'ONEPIPE_PWT_FILE', __FILE__ );

/**
 * Check if Fluent Forms and Fluent Forms Pro are active.
 */
function onepipe_pwt_check_dependencies() {
    $fluentform_active     = defined( 'FLUENTFORM' ) || class_exists( 'FluentForm\App\Modules\Form\Form' );
    $fluentform_pro_active = defined( 'FLUENTFORMPRO' ) || class_exists( 'FluentFormPro\Payments\PaymentMethods\BasePaymentMethod' );

    return $fluentform_active && $fluentform_pro_active;
}

/**
 * Show admin notice when dependencies are missing.
 */
function onepipe_pwt_missing_dependencies_notice() {
    if ( onepipe_pwt_check_dependencies() ) {
        return;
    }
    ?>
    <div class="notice notice-error">
        <p>
            <strong><?php esc_html_e( 'OnePipe PayWithTransfer', 'onepipe-pwt' ); ?>:</strong>
            <?php esc_html_e( 'This plugin requires Fluent Forms and Fluent Forms Pro (with payment module) to be installed and activated.', 'onepipe-pwt' ); ?>
        </p>
    </div>
    <?php
}
add_action( 'admin_notices', 'onepipe_pwt_missing_dependencies_notice' );

/**
 * Initialize the plugin when Fluent Forms is loaded.
 */
function onepipe_pwt_init() {
    if ( ! onepipe_pwt_check_dependencies() ) {
        return;
    }

    require_once ONEPIPE_PWT_PATH . 'includes/class-loader.php';
    ( new OnePipe_PWT_Loader() )->init();
}
add_action( 'fluentform/loaded', 'onepipe_pwt_init' );
