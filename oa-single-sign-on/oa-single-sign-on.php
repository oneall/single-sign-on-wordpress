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
 * Check technical requirements before activating the plugin
 */
function oa_single_sign_on_activate ()
{
	if (!function_exists ('oa_social_login_activate'))
	{
		deactivate_plugins (basename (dirname (__FILE__)) . '/' . basename (__FILE__));
		_ ('This plugin requires Social Login to be installed first.', 'oa_single_sign_on');
		exit;
	}
}
register_activation_hook (__FILE__, 'oa_single_sign_on_activate');


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
