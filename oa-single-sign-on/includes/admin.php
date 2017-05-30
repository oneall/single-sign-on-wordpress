<?php

/**
 * Adds administration area menu and links
 **/
function oa_single_sign_on_admin_menu ()
{
	// Setup
	$page = add_menu_page ('OneAll Single Sign On ' . __ ('Settings', 'oa_single_sign_on'), 'Single Sign On', 'manage_options', 'oa_single_sign_on_settings', 'oa_single_sign_on_admin_settings_menu', 'dashicons-admin-network');
	add_action ('admin_print_styles-' . $page, 'oa_single_sign_on_admin_css');

	// Fix Setup title
	global $submenu;
	if (is_array ($submenu) and isset ($submenu ['oa_single_sign_on_settings']))
	{
		$submenu ['oa_single_sign_on_setup'] [0] [0] = __ ('Settings', 'oa_single_sign_on');
	}

	add_action ('admin_init', 'oa_single_sign_on_admin_settings');
	add_action ('admin_enqueue_scripts', 'oa_single_sign_on_admin_js');
}
add_action ('admin_menu', 'oa_single_sign_on_admin_menu');

/**
 * Adds an activation message to be displayed once.
 */
function oa_single_sign_on_admin_message ()
{
    if (get_option ('oa_single_sign_on_activation_message') !== '1')
    {
        echo '<div class="updated"><p><strong>' . __ ('Thank you for using Single Sign-On!', 'oa_single_sign_on') . '</strong> ' . sprintf (__ ('Please complete the <strong><a href="%s">Single Sign On Setup</a></strong> to enable the plugin.', 'oa_social_login'), 'admin.php?page=oa_single_sign_on_settings') . '</p></div>';
        update_option ('oa_single_sign_on_activation_message', '1');
    }
}
add_action ('admin_notices', 'oa_single_sign_on_admin_message');


/**
 * Autodetect API Connection Handler
 */
function oa_single_sign_on_admin_autodetect_api_connection_handler ()
{
	// Check AJAX Nonce
	check_ajax_referer ('oa_single_sign_on_ajax_nonce');

	// Check if CURL is available
	if (oa_single_sign_on_check_curl_available ())
	{
		// Check CURL HTTPS - Port 443
		if (oa_single_sign_on_check_curl (true) === true)
		{
			echo 'success_autodetect_api_curl_https';
			die ();
		}
		// Check CURL HTTP - Port 80
		elseif (oa_single_sign_on_check_curl (false) === true)
		{
			echo 'success_autodetect_api_curl_http';
			die ();
		}
		else
		{
			echo 'error_autodetect_api_curl_ports_blocked';
			die ();
		}
	}
	// Check if FSOCKOPEN is available
	elseif (oa_single_sign_on_check_fsockopen_available ())
	{
		// Check FSOCKOPEN HTTPS - Port 443
		if (oa_single_sign_on_check_fsockopen (true) == true)
		{
			echo 'success_autodetect_api_fsockopen_https';
			die ();
		}
		// Check FSOCKOPEN HTTP - Port 80
		elseif (oa_single_sign_on_check_fsockopen (false) == true)
		{
			echo 'success_autodetect_api_fsockopen_http';
			die ();
		}
		else
		{
			echo 'error_autodetect_api_fsockopen_ports_blocked';
			die ();
		}
	}

	// No working handler found
	echo 'error_autodetect_api_no_handler';
	die ();
}
add_action ('wp_ajax_oa_single_sign_on_admin_autodetect_api_connection_handler', 'oa_single_sign_on_admin_autodetect_api_connection_handler');


/**
 * Check API Settings through an Ajax Call
 */
function oa_single_sign_on_admin_check_api_settings ()
{
	check_ajax_referer ('oa_single_sign_on_ajax_nonce');

	// Check if all fields have been filled out
	if (empty ($_POST ['api_subdomain']) or empty ($_POST ['api_key']) or empty ($_POST ['api_secret']))
	{
		die ('error_not_all_fields_filled_out');
	}

	// Check the handler
	$api_connection_handler = ((!empty ($_POST ['api_connection_handler']) and $_POST ['api_connection_handler'] == 'fsockopen') ? 'fsockopen' : 'curl');
	$api_connection_use_https = ((!isset ($_POST ['api_connection_use_https']) or $_POST ['api_connection_use_https'] == '1') ? true : false);

	// FSOCKOPEN
	if ($api_connection_handler == 'fsockopen')
	{
		if (!oa_single_sign_on_check_fsockopen ($api_connection_use_https))
		{
			die ('error_selected_handler_faulty');
		}
	}
	// CURL
	else
	{
		if (!oa_single_sign_on_check_curl ($api_connection_use_https))
		{
			die ('error_selected_handler_faulty');
		}
	}

	$api_subdomain = trim (strtolower ($_POST ['api_subdomain']));
	$api_key = trim ($_POST ['api_key']);
	$api_secret = trim ($_POST ['api_secret']);

	// Full domain entered
	if (preg_match ("/([a-z0-9\-]+)\.api\.oneall\.com/i", $api_subdomain, $matches))
	{
		$api_subdomain = $matches [1];
	}

	// Check subdomain format
	if (!preg_match ("/^[a-z0-9\-]+$/i", $api_subdomain))
	{
		die ('error_subdomain_wrong_syntax');
	}

	// Domain
	$api_domain = $api_subdomain . '.api.oneall.com';

	// Connection to
	$api_resource_url = ($api_connection_use_https ? 'https' : 'http') . '://' . $api_domain . '/tools/ping.json';

	// Get connection details
	$result = oa_single_sign_on_do_api_request ($api_connection_handler, $api_resource_url, array('api_key' => $api_key, 'api_secret' => $api_secret), 15);

	// Parse result
	if (is_object ($result) and property_exists ($result, 'http_code') and property_exists ($result, 'http_data'))
	{
		switch ($result->http_code)
		{
			// Success
			case 200 :
				die ('success');

			// Authentication Error
			case 401 :
				die ('error_authentication_credentials_wrong');

			// Wrong Subdomain
			case 404 :
				die ('error_subdomain_wrong');
		}
	}

	die ('error_communication');
}
add_action ('wp_ajax_oa_single_sign_on_admin_check_api_settings', 'oa_single_sign_on_admin_check_api_settings');

/**
 * Add Settings JS
 */
function oa_single_sign_on_admin_js ($hook)
{
	if (stripos ($hook, 'oa_single_sign_on') !== false)
	{
		if (!wp_script_is ('oa_single_sign_on_admin_js', 'registered'))
		{
			wp_register_script ('oa_single_sign_on_admin_js', OA_SINGLE_SIGN_ON_PLUGIN_URL . "/assets/js/admin.js");
		}

		// Nonce for Ajax Calls
		$oa_single_sign_on_ajax_nonce = wp_create_nonce ('oa_single_sign_on_ajax_nonce');

		// Add Javascript
		wp_enqueue_script ('oa_single_sign_on_admin_js');
		wp_enqueue_script ('jquery');

		// Add API Messages
		wp_localize_script ('oa_single_sign_on_admin_js', 'objectL10n', array(
			'oa_single_sign_on_ajax_nonce' => $oa_single_sign_on_ajax_nonce,
			'oa_single_sign_on_js_1' => __ ('Contacting API - please wait this may take a few minutes ...', 'oa_single_sign_on'),
			'oa_single_sign_on_js_101' => __ ('The settings are correct - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_111' => __ ('Please fill out each of the fields above.', 'oa_single_sign_on'),
			'oa_single_sign_on_js_112' => __ ('The subdomain does not exist. Have you filled it out correctly?', 'oa_single_sign_on'),
			'oa_single_sign_on_js_113' => __ ('The subdomain has a wrong syntax!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_114' => __ ('Could not contact API. Are outbound requests on port 443 allowed?', 'oa_single_sign_on'),
			'oa_single_sign_on_js_115' => __ ('The API subdomain is correct, but one or both keys are invalid', 'oa_single_sign_on'),
			'oa_single_sign_on_js_116' => __ ('Connection handler does not work, try using the Autodetection', 'oa_single_sign_on'),
			'oa_single_sign_on_js_201a' => __ ('Detected CURL on Port 443 - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_201b' => __ ('Detected CURL on Port 80 - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_201c' => __ ('CURL is available but both ports (80, 443) are blocked for outbound requests', 'oa_single_sign_on'),
			'oa_single_sign_on_js_202a' => __ ('Detected FSOCKOPEN on Port 443 - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_202b' => __ ('Detected FSOCKOPEN on Port 80 - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_202c' => __ ('FSOCKOPEN is available but both ports (80, 443) are blocked for outbound requests', 'oa_single_sign_on'),
			'oa_single_sign_on_js_211' => sprintf (__ ('Autodetection Error - our <a href="%s" target="_blank">documentation</a> helps you fix this issue.', 'oa_single_sign_on'), 'http://docs.oneall.com/plugins/guide/social-login-wordpress/#help')
		));
	}
}

/**
 * Add Settings CSS
 */
function oa_single_sign_on_admin_css ($hook = '')
{
	if (!wp_style_is ('oa_single_sign_on_admin_css', 'registered'))
	{
		wp_register_style ('oa_single_sign_on_admin_css', OA_SINGLE_SIGN_ON_PLUGIN_URL . "/assets/css/admin.css");
	}

	if (did_action ('wp_print_styles'))
	{
		wp_print_styles ('oa_single_sign_on_admin_css');
	}
	else
	{
		wp_enqueue_style ('oa_single_sign_on_admin_css');
	}
}

/**
 * Register plugin settings and their sanitization callback
 */
function oa_single_sign_on_admin_settings ()
{
	register_setting ('oa_single_sign_on_settings_group', 'oa_single_sign_on_settings', 'oa_single_sign_on_admin_settings_validate');
}

/**
 * Plugin settings sanitization callback
 */
function oa_single_sign_on_admin_settings_validate ($settings)
{
	// Settings page?
	$page = (!empty ($_POST ['page']) ? strtolower ($_POST ['page']) : '');

	// Store the sanitzed settings
	$sanitzed_settings = get_option ('oa_single_sign_on_settings');

	// Check format
	if (!is_array ($sanitzed_settings))
	{
		$sanitzed_settings = array();
	}

	// //////////////////////////////////////////////////////////////////////////////////////////////////////////
	// Settings
	// //////////////////////////////////////////////////////////////////////////////////////////////////////////

	// Fields
	$fields = array ();
	$fields [] = 'api_connection_handler';
	$fields [] = 'api_connection_use_https';
	$fields [] = 'api_subdomain';
	$fields [] = 'api_key';
	$fields [] = 'api_secret';

	// Extract fields
	foreach ($fields as $field)
	{
		// Value is given
		if (isset ($settings [$field]))
		{
			$sanitzed_settings [$field] = trim ($settings [$field]);
		}
	}

	// Sanitize API Use HTTPS
	$sanitzed_settings ['api_connection_use_https'] = (empty ($sanitzed_settings ['api_connection_use_https']) ? 0 : 1);

	// Sanitize API Connection handler
	if (isset ($sanitzed_settings ['api_connection_handler']) and in_array (strtolower ($sanitzed_settings ['api_connection_handler']), array('curl', 'fsockopen')))
	{
		$sanitzed_settings ['api_connection_handler'] = strtolower ($sanitzed_settings ['api_connection_handler']);
	}
	else
	{
		$sanitzed_settings ['api_connection_handler'] = 'curl';
	}

	// Sanitize API Subdomain
	if (isset ($sanitzed_settings ['api_subdomain']))
	{
		// Subdomain is always in lowercase
		$api_subdomain = strtolower ($sanitzed_settings ['api_subdomain']);

		// Full domain entered
		if (preg_match ("/([a-z0-9\-]+)\.api\.oneall\.com/i", $api_subdomain, $matches))
		{
			$api_subdomain = $matches [1];
		}

		$sanitzed_settings ['api_subdomain'] = $api_subdomain;
	}

	// Done
	return $sanitzed_settings;
}


/**
 * Display Settings Page
 */
function oa_single_sign_on_admin_settings_menu ()
{
	?>
<div class="wrap">
	<div id="oa_single_sign_on_page" class="oa_single_sign_on_setup">
		<h2>OneAll Single Sign On <?php echo OA_SINGLE_SIGN_ON_VERSION; ?></h2>
				<?php
	if (get_option ('oa_single_sign_on_api_settings_verified') !== '1')
	{
		?>
							<p>
								<?php _e ('Allow your visitors to comment, login and register with 30+ Social Networks like for example Twitter, Facebook, LinkedIn, Instagram, VKontakte, Google or Yahoo.', 'oa_single_sign_on'); ?>
								<strong><?php _e ('Draw a larger audience and increase your user engagement in a  few simple steps.', 'oa_single_sign_on'); ?> </strong>
		</p>
		<div class="oa_single_sign_on_box" id="oa_single_sign_on_box_started">
			<div class="oa_single_sign_on_box_title">
									<?php _e ('Get Started!', 'oa_single_sign_on'); ?>
								</div>
			<p>
									<?php printf (__ ('To be able to use this plugin you first of all need to create a free account at %s and setup a Site.', 'oa_single_sign_on'), '<a href="https://app.oneall.com/signup/wp" target="_blank">http://www.oneall.com</a>'); ?>
									<?php _e ('After having created your account and setup your Site, please enter the Site settings in the form below.', 'oa_single_sign_on'); ?>
									<?php _e ("Don't worry the setup is free and takes only a few minutes!", 'oa_single_sign_on'); ?>
								</p>
			<p class="oa_single_sign_on_button_wrap">
				<a class="button-secondary" href="https://app.oneall.com/signup/wp" target="_blank"><strong><?php _e ('Click here to setup your free account', 'oa_single_sign_on'); ?></strong></a>
			</p>
			<h3>
									<?php printf (__ ('You are in good company! This plugin is used on more than %s websites!', 'oa_single_sign_on'), '300,000'); ?>
								</h3>
		</div>
						<?php
	}
	else
	{
		?>
							<p></p>
		<div class="oa_single_sign_on_box" id="oa_single_sign_on_box_status">
			<div class="oa_single_sign_on_box_title">
									<?php _e ('Your API Account is setup correctly', 'oa_single_sign_on'); ?>
								</div>
			<p>
									<?php _e ('Login to your OneAll account to manage your social networks and to access your User Insights.', 'oa_single_sign_on'); ?>
									<?php _e ("Determine which social networks are popular amongst your users and tailor your registration experience to increase your users' engagement.", 'oa_single_sign_on'); ?>
								</p>
			<p class="oa_single_sign_on_button_wrap">
				<a class="button-secondary" href="https://app.oneall.com/signin/" target="_blank"><strong><?php _e ('Login to my OneAll account', 'oa_single_sign_on'); ?></strong>
				</a> <a class="button-secondary" href="https://app.oneall.com/insights/" target="_blank"><strong><?php _e ('Access my User Insights', 'oa_single_sign_on'); ?></strong>
				</a>
			</p>
		</div>
						<?php
	}

	if (!empty ($_REQUEST ['settings-updated']) and strtolower ($_REQUEST ['settings-updated']) == 'true')
	{
		?>
							<div class="oa_single_sign_on_box" id="oa_single_sign_on_box_updated">
								<?php _e ('Your modifications have been saved successfully!'); ?>
							</div>
						<?php
	}
	?>
				<form method="post" action="options.php">
					<?php
	settings_fields ('oa_single_sign_on_settings_group');
	$settings = get_option ('oa_single_sign_on_settings');
	?>
					<table class="form-table oa_single_sign_on_table">
				<tr class="row_head">
					<th colspan="2"><?php _e ('API Connection Handler', 'oa_single_sign_on'); ?>
							</th>
				</tr>
						<?php
	$api_connection_handler = ((empty ($settings ['api_connection_handler']) or $settings ['api_connection_handler'] != 'fsockopen') ? 'curl' : 'fsockopen');
	?>
						<tr class="row_even">
					<td rowspan="2" class="row_multi" style="width: 200px">
						<label><?php _e ('API Connection Handler', 'oa_single_sign_on'); ?>:</label>
					</td>
					<td>
						<input type="radio" id="oa_single_sign_on_api_connection_handler_curl"
							name="oa_single_sign_on_settings[api_connection_handler]" value="curl"
							<?php echo (($api_connection_handler <> 'fsockopen') ? 'checked="checked"' : ''); ?> /> <label
							for="oa_single_sign_on_api_connection_handler_curl"><?php _e ('Use PHP CURL to communicate with the API', 'oa_single_sign_on'); ?> <strong>(<?php _e ('Default', 'oa_single_sign_on') ?>)</strong></label><br />
						<span class="description"><?php _e ('Using CURL is recommended but it might be disabled on some servers.', 'oa_single_sign_on'); ?></span>
					</td>
				</tr>
				<tr class="row_even">
					<td>
						<input type="radio" id="oa_single_sign_on_api_connection_handler_fsockopen"
							name="oa_single_sign_on_settings[api_connection_handler]" value="fsockopen"
							<?php echo (($api_connection_handler == 'fsockopen') ? 'checked="checked"' : ''); ?> /> <label
							for="oa_single_sign_on_api_connection_handler_fsockopen"><?php _e ('Use PHP FSOCKOPEN to communicate with the API', 'oa_single_sign_on'); ?> </label><br />
						<span class="description"><?php _e ('Try using FSOCKOPEN if you encounter any problems with CURL.', 'oa_single_sign_on'); ?></span>
					</td>
				</tr>
						<?php
	$api_connection_use_https = ((!isset ($settings ['api_connection_use_https']) or $settings ['api_connection_use_https'] == '1') ? true : false);
	?>
						<tr class="row_even">
					<td rowspan="2" class="row_multi" style="width: 200px">
						<label><?php _e ('API Connection Port', 'oa_single_sign_on'); ?>:</label>
					</td>
					<td>
						<input type="radio" id="oa_single_sign_on_api_connection_handler_use_https_1"
							name="oa_single_sign_on_settings[api_connection_use_https]" value="1"
							<?php echo ($api_connection_use_https ? 'checked="checked"' : ''); ?> /> <label
							for="oa_single_sign_on_api_connection_handler_use_https_1"><?php _e ('Communication via HTTPS on port 443', 'oa_single_sign_on'); ?> <strong>(<?php _e ('Default', 'oa_single_sign_on') ?>)</strong></label><br />
						<span class="description"><?php _e ('Using port 443 is secure but you might need OpenSSL', 'oa_single_sign_on'); ?></span>
					</td>
				</tr>
				<tr class="row_even">
					<td>
						<input type="radio" id="oa_single_sign_on_api_connection_handler_use_https_0"
							name="oa_single_sign_on_settings[api_connection_use_https]" value="0"
							<?php echo (!$api_connection_use_https ? 'checked="checked"' : ''); ?> /> <label
							for="oa_single_sign_on_api_connection_handler_use_https_0"><?php _e ('Communication via HTTP on port 80', 'oa_single_sign_on'); ?> </label><br />
						<span class="description"><?php _e ("Using port 80 is a bit faster, doesn't need OpenSSL but is less secure", 'oa_single_sign_on'); ?></span>
					</td>
				</tr>
				<tr class="row_foot">
					<td>
						<a class="button-primary" id="oa_single_sign_on_admin_autodetect_api_connection_handler" href="#"><?php _e ('Autodetect API Connection', 'oa_single_sign_on'); ?></a>
					</td>
					<td>
						<div id="oa_single_sign_on_api_connection_handler_result"></div>
					</td>
				</tr>
			</table>
			<table class="form-table oa_single_sign_on_table">
				<tr class="row_head">
					<th>
								<?php _e ('API Settings', 'oa_single_sign_on'); ?>
							</th>
					<th>
						<a href="https://app.oneall.com/applications/" target="_blank"><?php _e ('Click here to create and view your API Credentials', 'oa_single_sign_on'); ?></a>
					</th>
				</tr>
				<tr class="row_even">
					<td style="width: 200px">
						<label for="oa_single_sign_on_settings_api_subdomain"><?php _e ('API Subdomain', 'oa_single_sign_on'); ?>:</label>
					</td>
					<td>
						<input type="text" id="oa_single_sign_on_settings_api_subdomain" name="oa_single_sign_on_settings[api_subdomain]"
							size="65"
							value="<?php echo (isset ($settings ['api_subdomain']) ? htmlspecialchars ($settings ['api_subdomain']) : ''); ?>" />
					</td>
				</tr>
				<tr class="row_odd">
					<td style="width: 200px">
						<label for="oa_single_sign_on_settings_api_key"><?php _e ('API Public Key', 'oa_single_sign_on'); ?>:</label>
					</td>
					<td>
						<input type="text" id="oa_single_sign_on_settings_api_key" name="oa_single_sign_on_settings[api_key]" size="65"
							value="<?php echo (isset ($settings ['api_key']) ? htmlspecialchars ($settings ['api_key']) : ''); ?>" />
					</td>
				</tr>
				<tr class="row_even">
					<td style="width: 200px">
						<label for="oa_single_sign_on_settings_api_secret"><?php _e ('API Private Key', 'oa_single_sign_on'); ?>:</label>
					</td>
					<td>
						<input type="text" id="oa_single_sign_on_settings_api_secret" name="oa_single_sign_on_settings[api_secret]" size="65"
							value="<?php echo (isset ($settings ['api_secret']) ? htmlspecialchars ($settings ['api_secret']) : ''); ?>" />
					</td>
				</tr>
				<tr class="row_foot">
					<td>
						<a class="button-primary" id="oa_single_sign_on_admin_check_api_settings" href="#"><?php _e ('Verify API Settings', 'oa_single_sign_on'); ?> </a>
					</td>
					<td>
						<div id="oa_single_sign_on_api_test_result"></div>
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="hidden" name="page" value="setup" /> <input type="submit" class="button-primary"
					value="<?php _e ('Save Changes', 'oa_single_sign_on') ?>" />
			</p>
		</form>
	</div>
</div>
<?php
}