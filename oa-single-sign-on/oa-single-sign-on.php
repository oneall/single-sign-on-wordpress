<?php
/*
Plugin Name: Single Sign-On
Plugin URI: http://www.oneall.com/
Description: Automatically sign in users as they browse between multiple and independent WordPress blogs in your ecosystem.
Version: 1.0
Author: Claude Schlesser
Author URI: http://www.oneall.com/
License: GPL2
*/

define ('OA_SINGLE_SIGN_ON_PLUGIN_URL', plugins_url () . '/' . basename (dirname (__FILE__)));
define ('OA_SINGLE_SIGN_ON_BASE_PATH', dirname (plugin_basename (__FILE__)));
define ('OA_SINGLE_SIGN_ON_VERSION', '1.0');

/**
 * Adds a setup link in the plugin list
 **/
function oa_single_sign_on_add_setup_link ($links, $file)
{
    static $oa_single_sign_on_plugin = null;

    if (is_null ($oa_single_sign_on_plugin))
    {
        $oa_single_sign_on_plugin = plugin_basename (__FILE__);
    }

    if ($file == $oa_single_sign_on_plugin)
    {
        $settings_link = '<a href="admin.php?page=oa_single_sign_on_settings">' . __ ('Setup', 'oa_single_sign_on') . '</a>';
        array_unshift ($links, $settings_link);
    }
    return $links;
}
add_filter ('plugin_action_links', 'oa_single_sign_on_add_setup_link', 10, 2);


/**
 * Include required files
 */
require_once(dirname (__FILE__) . '/includes/toolbox.php');
require_once(dirname (__FILE__) . '/includes/communication.php');
require_once(dirname (__FILE__) . '/includes/plugin.php');
require_once(dirname (__FILE__) . '/includes/admin.php');

/**
 * Initialise
 */
add_action('init', 'oa_single_sign_on_init', 10);
