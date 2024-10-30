<?php
/**
 * @package  Jetpush
 */
/*
 * Plugin Name: JetPush for WooCommerce
 * Plugin URI: http://www.jetpush.com/intergrations/wordpress
 * Description: Customers shouldn't be strangers. JetPush helps you to know your customers and communicate better.
 * Version: 1.0.5
 * Author: JetPush
 * Author URI: https://www.jetpush.com
 */
if ( ! defined( 'ABSPATH' ) )
{
    exit; // Exit if accessed directly
}

define('JETPUSH_VERSION', '1.0.5');
define('JETPUSH_DOMAIN', 'http://www.jetpush.com');
define('API_PATH', '/api/v1');
define('JETPUSH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JETPUSH_PLUGIN', plugin_basename(__FILE__));
define('JETPUSH_SETTING_PAGE', 'jetpush');

if( ! class_exists('Jetpush')):

final class Jetpush {
    // jetpush
    protected static $_instance = null;

    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'activation_check'));
        register_deactivation_hook(__FILE__, array($this, 'uninstall'));

        // run once woocommerce is fully loaded
        // so we easy extend its class.
        add_action('woocommerce_loaded', array($this, 'init'));

        // our hook for other plugin.
        do_action('jetpush_loaded');
    }

    /**
     * Init the jetpush plugin
     * @return [type] [description]
     */
    public function init() {
        do_action('jetpush_init');

        // include required file
        $this->includes();

        // intergration jetpush with woocommerce
        add_filter('woocommerce_integrations', array($this, 'add_jetpush_integration'));

        // woocommerce only create cookie when customer own at least 1 item in their cart
        // but we need to track product view and category view
        // even when user din't add anything to their cart
        // because of that, we nede to call set_customer_cookie when init.
        // it mean that set cookie for everyone when they first enter the site.
        add_action('init', array( $this, 'set_customer_cookie' ));

        // load hooks for admin
        add_action('admin_init', array($this, 'load_admin_hooks'), 100);
    }

    /**
     * Use woocommerce section handler to set cookie
     */
    public function set_customer_cookie() {
        WC()->session->set_customer_session_cookie(true);
    }

    /**
     * Hock for admin panel
     * @return [type] [description]
     */
    public function load_admin_hooks() {

        // add setting link to plugin overview page.
        add_filter('plugin_action_links_' . JETPUSH_PLUGIN, array($this, 'add_settings_link'));
    }

    /**
     * Add setting link to show onn plugin overview
     * @return [type] [description]
     */
    public function add_settings_link($link) {
        $link[] = '<a href="' . get_admin_url(null,'admin.php?page=wc-settings&tab=integration&section=jetpush'  ) . '">' . __( 'Settings', JETPUSH_SETTING_PAGE) . '</a>';

        return $link;
    }

    /**
     * Add jetpush settings tab together with woocommerce setting tabs.
     * @param [type] $tabs [description]
     */
    public function add_settings_tab($tabs) {
        $tabs[JETPUSH_SETTING_PAGE] = __('Jetpush', JETPUSH_SETTING_PAGE);
        return $tabs;
    }

    public function add_jetpush_integration($methods) {
        $methods[] = 'WC_Integration_Jetpush';
        return $methods;
    }

    /**
     * Include required file
     */
    public function includes() {
        include_once('includes/class-wc-intergration-jetpush.php');
    }

    /**
     * Automatically disable the plugin on activation
     * If it doesn't meet the minimum requirements
     */
    public function activation_check() {
        $result = $this->check_requirements();
        if ($result === false) {
            deactivate_plugins(JETPUSH_PLUGIN);
            wp_die($this->disabled_notice(), 'Jetpush plugin cannot be activated', array(
                'back_link' => true
            ));
        }
    }

    /**
     * Tell user what to do in able to active jetpush plugin
     * @return [string] [tell user what to do in able to active jetpush plugin ]
     */
    private function disabled_notice() {
        $msg = '<h1>Jetpush plugin cannot be activated</h1>';
        $msg .= '<p>Reasons could be:</p>';
        $msg .= '<ul>';
        $msg .= '<li>Your wordpress version was outdate. (lower than 3.7).</li>';
        $msg .= '<li>Woocommerce plugin was not installed.</li>';
        $msg .= '<li>Woocommerce plugin version lower than 2.1.</li>';
        $msg .= '</ul>';

        return $msg;
    }

    /**
     * Remove this plugin
     * @return [type] [description]
     */
    public function uninstall() {
    }

    /**
     * We need to check wordpress version and woocommerce version
     * to make sure this wordpress setup meet the requirements
     *
     * @return [boolean] [true if OK]
     */
    protected function check_requirements() {
        global $woocommerce;
        // ensure wp_version > 3.7
        if ( version_compare( $GLOBALS['wp_version'], '3.7', '<' ) ) {
             return false;
         }

        // ensure woocommerce already actived
        $active_plugins = apply_filters('active_plugins', get_option( 'active_plugins'));
        if( ! in_array('woocommerce/woocommerce.php', $active_plugins)) {
            return false;
        }

        // ensure woocommerce version > 2.1
        if ($woocommerce->version < '2.1') {
            return false;
        }

        return true;
    }

}
endif;

function JP() {
    return Jetpush::instance();
}

$GLOBALS['jetpush'] = JP();