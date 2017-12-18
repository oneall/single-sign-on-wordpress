<?php
/*
Plugin Name: Single Sign-On SSO
Plugin URI: http://www.oneall.com/
Description: Automatically sign in users as they browse between multiple and independent WordPress blogs in your ecosystem.
Version: 1.0.0
Author: OneAll
Author URI: http://www.oneall.com/company/imprint/
License: GPL2
 */

define('OA_SINGLE_SIGN_ON_PLUGIN_URL', plugins_url() . '/' . basename(dirname(__FILE__)));
define('OA_SINGLE_SIGN_ON_BASE_PATH', dirname(plugin_basename(__FILE__)));
define('OA_SINGLE_SIGN_ON_VERSION', '1.0.0');

/**
 * Check technical requirements before activating the plugin (Wordpress 3.0 or newer required)
 */
function oa_single_sign_on_activate()
{
    if (!function_exists('register_post_status'))
    {
        deactivate_plugins(basename(dirname(__FILE__)) . '/' . basename(__FILE__));
        echo sprintf(__('This plugin requires WordPress %s or newer. Please update your WordPress installation to activate this plugin.', 'oa_single_sign_on'), '3.0');
        exit;
    }
}
register_activation_hook(__FILE__, 'oa_single_sign_on_activate');

/**
 * Add Setup Link
 **/
function oa_single_sign_on_add_setup_link($links, $file)
{
    static $oa_single_sign_on_plugin = null;

    if (is_null($oa_single_sign_on_plugin))
    {
        $oa_single_sign_on_plugin = plugin_basename(__FILE__);
    }

    if ($file == $oa_single_sign_on_plugin)
    {
        $links[] = '<a href="admin.php?page=oa_single_sign_on_settings">' . __('Open Settings', 'oa_single_sign_on') . '</a>';
    }

    return $links;
}
add_filter('plugin_action_links', 'oa_single_sign_on_add_setup_link', 10, 2);

/**
 * Include required files.
 */
require_once dirname(__FILE__) . '/includes/toolbox.php';
require_once dirname(__FILE__) . '/includes/api_com_handler.php';
require_once dirname(__FILE__) . '/includes/api_sso_handler.php';
require_once dirname(__FILE__) . '/includes/user_interface.php';
require_once dirname(__FILE__) . '/includes/admin_interface.php';
require_once dirname(__FILE__) . '/includes/core.php';

/**
 * Initialise plugin.
 */
add_action('init', 'oa_single_sign_on_init', 10);
