<?php

/**
 * Adds administration area menu and links
 */
function oa_single_sign_on_admin_menu ()
{
	// Setup
	$page = add_menu_page ('OneAll Single Sign On ' . __ ('Settings', 'oa_single_sign_on'), 'Single Sign-On', 'manage_options', 'oa_single_sign_on_settings', 'oa_single_sign_on_admin_settings_menu', 'dashicons-admin-network');
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
    // Make sure the plugin is installed.
    if (function_exists ('oa_single_sign_on_get_settings'))
    {
        // Do not display inside of it's own settings.
        if ( ! isset ($_REQUEST['page']) || strtolower ($_REQUEST['page']) <> 'oa_single_sign_on_settings')
        {
            // Read settings.
            $ext_settings = oa_single_sign_on_get_settings ();

            // If the subdomain is empty, the setup has not been done.
            if (empty ($ext_settings ['api_subdomain']))
            {
                echo '<div class="updated"><p><strong>' . __ ('Thank you for using Single Sign-On!', 'oa_single_sign_on') . '</strong> ' . sprintf (__ ('Please <strong><a href="%s">complete the setup</a></strong> in order to enable the plugin.', 'oa_single_sign_on'), 'admin.php?page=oa_single_sign_on_settings') . '</p></div>';

            }
        }
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
	if (oa_single_sign_on_is_curl_available ())
	{
		// Check CURL HTTPS - Port 443
		if (oa_single_sign_on_is_api_connection_curl_ok (true) === true)
		{
			echo 'success_autodetect_api_curl_https';
			die ();
		}
		// Check CURL HTTP - Port 80
		elseif (oa_single_sign_on_is_api_connection_curl_ok (false) === true)
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
	elseif (oa_single_sign_on_is_fsockopen_available ())
	{
		// Check FSOCKOPEN HTTPS - Port 443
		if (oa_single_sign_on_is_api_connection_fsockopen_ok (true) == true)
		{
			echo 'success_autodetect_api_fsockopen_https';
			die ();
		}
		// Check FSOCKOPEN HTTP - Port 80
		elseif (oa_single_sign_on_is_api_connection_fsockopen_ok (false) == true)
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
	$api_connection_handler = ((!empty ($_POST ['api_connection_handler']) && $_POST ['api_connection_handler'] == 'fsockopen') ? 'fsockopen' : 'curl');
	$api_connection_use_https = ((!isset ($_POST ['api_connection_use_https']) || $_POST ['api_connection_use_https'] == '1') ? true : false);

	// FSOCKOPEN
	if ($api_connection_handler == 'fsockopen')
	{
		if ( ! oa_single_sign_on_is_api_connection_fsockopen_ok ($api_connection_use_https))
		{
			die ('error_selected_handler_faulty');
		}
	}
	// CURL
	else
	{
		if ( ! oa_single_sign_on_is_api_connection_curl_ok ($api_connection_use_https))
		{
			die ('error_selected_handler_faulty');
		}
	}

	// API Credentials
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

	// API Endpoint
	$api_resource_url = ($api_connection_use_https ? 'https' : 'http') . '://' . $api_domain . '/site.json';

	// API Options.
	$api_options = array(
		'api_key' => $api_key,
		'api_secret' => $api_secret,
	);

	// Make request
	$result = oa_single_sign_on_do_api_request ($api_connection_handler, $api_resource_url, 'GET', $api_options);

	// Parse result
	if (is_object ($result) && property_exists ($result, 'http_code') && property_exists ($result, 'http_data'))
	{
		switch ($result->http_code)
		{
			// Success
			case 200:

				// Decode result
				$decoded_result = @json_decode ($result->http_data);

				// Check result
				if (is_object ($decoded_result) && isset ($decoded_result->response->result->data->site))
				{
					// Site Details
					$site = $decoded_result->response->result->data->site;

					// Check if our plans has the cloud storage
					if (empty ($site->subscription_plan->features->has_single_signon))
					{
						die ('error_plan_has_no_single_signon');
					}
					// Success
					else
					{
						die ('success');
					}
				}
			break;


			// Authentication Error
			case 401:
				die ('error_authentication_credentials_wrong');
			break;

			// Wrong Subdomain
			case 404:
				die ('error_subdomain_wrong');
			break;
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
			'oa_single_sign_on_js_115' => __ ('The API subdomain is correct, but one or both keys are invalid.', 'oa_single_sign_on'),
			'oa_single_sign_on_js_116' => __ ('Connection handler does not work, try using the Autodetection.', 'oa_single_sign_on'),
			'oa_single_sign_on_js_201a' => __ ('Detected CURL on Port 443 - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_201b' => __ ('Detected CURL on Port 80 - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_201c' => __ ('CURL is available but both ports (80, 443) are blocked for outbound requests.', 'oa_single_sign_on'),
			'oa_single_sign_on_js_202a' => __ ('Detected FSOCKOPEN on Port 443 - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_202b' => __ ('Detected FSOCKOPEN on Port 80 - do not forget to save your changes!', 'oa_single_sign_on'),
			'oa_single_sign_on_js_202c' => __ ('FSOCKOPEN is available but both ports (80, 443) are blocked for outbound requests.', 'oa_single_sign_on'),
			'oa_single_sign_on_js_211' => __ ('Autodetection Error - Please make sure that either PHP CURL or FSOCKOPEN are installed and enabled.', 'oa_single_sign_on'),
			'oa_single_sign_on_js_301' => __ ('The SSO API is not enabled in the subscription plan of this OneAll site.', 'oa_single_sign_on')
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
    // Clear cookies.
    oa_single_sign_on_unset_login_wait_cookie ();

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
	$fields [] = 'accounts_autocreate';
	$fields [] = 'accounts_autolink';
	$fields [] = 'accounts_remind';
	$fields [] = 'debug_log';
	$fields [] = 'logout_wait_relogin';
	$fields [] = 'logout_everywhere';

	// Extract fields
	foreach ($fields as $field)
	{
		// Value is given
		if (isset ($settings [$field]))
		{
			$sanitzed_settings [$field] = trim ($settings [$field]);
		}
	}

	// Sanitize Re-Login Grace Period.
	if (empty ($sanitzed_settings ['logout_wait_relogin']))
	{
		$sanitzed_settings ['logout_wait_relogin'] = 0;
	}
	elseif ( ! is_numeric ($sanitzed_settings ['logout_wait_relogin']) || $sanitzed_settings ['logout_wait_relogin'] < 0)
	{
		$sanitzed_settings ['logout_wait_relogin'] = OA_SINGLE_SIGN_ON_LOGOUT_WAIT_RELOGIN_DEFAULT;
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
 * Display Settings Page \ Selector.
 */
function oa_single_sign_on_admin_settings_menu ()
{
    if (get_option ('oa_single_sign_welcome_read') !== '1')
    {
        oa_single_sign_on_admin_settings_menu_welcome ();
    }
    else
    {
        oa_single_sign_on_admin_settings_menu_display ();
    }
}

/**
 * Display Settings Page \ Settings
 */
function oa_single_sign_on_admin_settings_menu_welcome()
{
    // Disable this page.
    update_option ('oa_single_sign_welcome_read', 1);

    ?>
        <div class="wrap">
            <div id="sso-welcome">
                <div class="sso-title">
                    Thank you for using Single Sign-On!
                </div>
                <div class="sso-info">
                    Automatically create accounts and sign users in as they browse between  multiple and independent blogs in your eco-system or among partner sites.
                </div>
                <div class="sso-logo">
                    <img src="<?php echo OA_SINGLE_SIGN_ON_PLUGIN_URL ?>/assets/img/logo.png" alt="Single Sign-On" />
                </div>
                <div class="sso-boxes">
                    <div class="sso-box sso-box-about-us">
                        <div class="sso-box-wrap">
                            <div class="sso-box-title">
                                About Us
                            </div>
                            <div class="sso-box-body">
                                <p>
                                    Single Sign-On is powered by the <a href="https://www.oneall.com" target="_blank">OneAll</a> User Integration platform,
                                    a state-of-the-art security environment that is 100% compliant with all US &amp; EU data protection laws
                                    and used by more than 300,000 websites worldwide - including small websites, growing startups and large corporate enterprises.
                                </p>
                             </div>
                         </div>
                    </div>
                    <div class="sso-box sso-box-about-plugin">
                        <div class="sso-box-wrap">
                            <div class="sso-box-title">
                                About Single Sign-On
                            </div>
                            <div class="sso-box-body">
                                <p>
                                    Automatically create accounts and sign users in as they browse between independent blogs, domains and databases in your network.
                                    Take away the need for your users to register for a new account or re-enter their authentication credentials on each of your websites.
                                </p>
                            </div>
                         </div>
                    </div>
                     <div class="sso-box sso-box-support">
                         <div class="sso-box-wrap">
                          <div class="sso-box-title">
                              Get Started
                          </div>
                          <div class="sso-box-body">
                              <p>
                                 The setup will only take a few minutes and a dedicated support team is there is assist you during the integration.
                                 The plugin is fully compatible with our <a href="https://docs.oneall.com/plugins/#filter-sso" target="_blank">other SSO plugins</a>
                                 and we also have a <a href="https://docs.oneall.com/services/implementation-guide/single-sign-on/" target="_blank">full implementation guide</a> allowing you to connect your WordPress to any other platforms.
                              </p>
                          </div>
                         </div>
                     </div>
                </div>
                <div class="clearfix"></div>
                <div class="sso-start">
                  <a href="admin.php?page=oa_single_sign_on_settings" class="sso-btn">Setup Single-Sign On</a>
                </div>
            </div>
         </div>
    <?php
}


/**
 * Display Settings Page \ Settings
 */
function oa_single_sign_on_admin_settings_menu_display()
{
	// Read settings
	$settings = get_option ('oa_single_sign_on_settings');

	?>
	<div class="wrap">
		<div id="sso-setup">
		    <div class="sso-title">
                  OneAll Single Sign-On <?php echo OA_SINGLE_SIGN_ON_VERSION; ?>
            </div>

				<?php
					if (empty (	$settings ['api_subdomain']))
					{
						?>
						    <div class="sso-info">
                               <?php
                                   _e ('Automatically create accounts and sign users in as they browse between  multiple and independent blogs in your eco-system or among partner sites.', 'oa_single_sign_on');
                               ?>
                            </div>
							<div class="sso-setup-message">
								<p>
									To enable Single Sign-On you first of all need to create a <a href="https://app.oneall.com/signup/wpsso" target="_blank">OneAll</a> account.
									The OneAll SSO server will securely encrypt the data and make it available to other websites in your eco-system.
									This allows the seamless login and registration of your users when they go from on of your websites to another.
									The service is 100% compliant with all US &amp; EU data protection laws and includes 99,95% Uptime SLA.
							     </p>
							     <p>
									After having created your OneAll account, please create a new site and upgrade it to a <a href="https://www.oneall.com/pricing-and-plans/premium/" target="_blank">plan</a> that includes the SSO API.
									This will give you the API credentials that are necessary to enable Single Sign-On.<br />
							     </p>
								 <p class="sso-access-account">
									<a href="https://app.oneall.com/signup/wpsso" class="sso-btn sso-btn-login" target="_blank">Click here to setup your account</a>
								</p>
							</div>
						<?php
					}
					else
					{
						?>
							<div class="sso-setup-message">
								<p>
									<?php
									    _e ('Your API Account is setup correctly.', 'oa_single_sign_on');
									 ?>
									 <?php
									    _e ('To add another blog to your Single Sign-On network, simply install the plugin on that website too and make sure to use the same API Credentials.', 'oa_single_sign_on');
									 ?>
								</p>
								<p class="sso-access-account">
									<a href="https://app.oneall.com/signin/" class="sso-btn sso-btn-login" target="_blank"><?php _e ('Login to my OneAll account', 'oa_single_sign_on'); ?></a>
									<a href="https://app.oneall.com/open-support-ticket/" class="sso-btn sso-btn-help"  target="_blank"><?php _e ('Open a support ticket', 'oa_single_sign_on')?></a>
								</p>
							</div>
						<?php
					}

					if (!empty ($_REQUEST ['settings-updated']) and strtolower ($_REQUEST ['settings-updated']) == 'true')
					{
						?>
							<div class="sso-updated-message">
								<?php
								    _e ('Your modifications have been saved successfully!');
								?>
							</div>
						<?php
					}
				?>
				<form method="post" action="options.php">
					<?php
						settings_fields ('oa_single_sign_on_settings_group');
					?>
					<table class="form-table">
						<tr class="sso-row sso-row-head">
							<th colspan="2">
								<?php _e ('API Connection', 'oa_single_sign_on'); ?>
							</th>
						</tr>
						<?php
							$api_connection_handler = ((empty ($settings ['api_connection_handler']) or $settings ['api_connection_handler'] != 'fsockopen') ? 'curl' : 'fsockopen');
						?>
						<tr class="sso-row sso-row-even">
							<td rowspan="2" class="sso-col sso-col-label">
								<label><?php _e ('API Connection Handler', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<input type="radio" id="oa_single_sign_on_api_connection_handler_curl" name="oa_single_sign_on_settings[api_connection_handler]" value="curl" <?php echo (($api_connection_handler <> 'fsockopen') ? 'checked="checked"' : ''); ?> />
								<label for="oa_single_sign_on_api_connection_handler_curl"><?php _e ('Use PHP CURL to communicate with the API', 'oa_single_sign_on'); ?> <strong>(<?php _e ('Default', 'oa_single_sign_on') ?>)</strong></label><br />
								<span class="description"><?php _e ('Using CURL is recommended but it might be disabled on some servers.', 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
						<tr class="sso-row sso-row-even">
							<td class="sso-col sso-col-value">
								<input type="radio" id="oa_single_sign_on_api_connection_handler_fsockopen" name="oa_single_sign_on_settings[api_connection_handler]" value="fsockopen" <?php echo (($api_connection_handler == 'fsockopen') ? 'checked="checked"' : ''); ?> />
								<label for="oa_single_sign_on_api_connection_handler_fsockopen"><?php _e ('Use PHP FSOCKOPEN to communicate with the API', 'oa_single_sign_on'); ?> </label><br />
								<span class="description"><?php _e ('Try using FSOCKOPEN if you encounter any problems with CURL.', 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
						<?php
							$api_connection_use_https = ((!isset ($settings ['api_connection_use_https']) or $settings ['api_connection_use_https'] == '1') ? true : false);
						?>
						<tr class="sso-row sso-row-odd">
							<td rowspan="2" class="sso-col sso-col-label">
								<label><?php _e ('API Connection Port', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<input type="radio" id="oa_single_sign_on_api_connection_handler_use_https_1" name="oa_single_sign_on_settings[api_connection_use_https]" value="1" <?php echo ($api_connection_use_https ? 'checked="checked"' : ''); ?> />
								<label for="oa_single_sign_on_api_connection_handler_use_https_1"><?php _e ('Communication via HTTPS on port 443', 'oa_single_sign_on'); ?> <strong>(<?php _e ('Default', 'oa_single_sign_on') ?>)</strong></label><br />
								<span class="description"><?php _e ('Using port 443 is secure but you might need OpenSSL', 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
						<tr class="sso-row sso-row-odd">
							<td class="sso-col sso-col-value">
								<input type="radio" id="oa_single_sign_on_api_connection_handler_use_https_0" name="oa_single_sign_on_settings[api_connection_use_https]" value="0" <?php echo (!$api_connection_use_https ? 'checked="checked"' : ''); ?> />
								<label for="oa_single_sign_on_api_connection_handler_use_https_0"><?php _e ('Communication via HTTP on port 80', 'oa_single_sign_on'); ?> </label><br />
								<span class="description"><?php _e ("Using port 80 is a bit faster, doesn't need OpenSSL but is less secure", 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
						<tr class="sso-row sso-row-foot">
							<td class="sso-col sso-col-label">
								<a class="button-primary" id="oa_single_sign_on_admin_autodetect_api_connection_handler" href="#"><?php _e ('Autodetect API Connection', 'oa_single_sign_on'); ?></a>
							</td>
							<td class="sso-col sso-col-value">
								<div id="oa_single_sign_on_api_connection_handler_result"></div>
							</td>
						</tr>
					</table>
					<table class="form-table">
						<tr class="sso-row sso-row-head">
							<th class="sso-col sso-col-label">
								<?php _e ('API Credentials', 'oa_single_sign_on'); ?>
							</th>
							<th class="sso-col sso-col-value">
								<a href="https://app.oneall.com/applications/" target="_blank"><?php _e ('Click here to create and view your API Credentials', 'oa_single_sign_on'); ?></a>
							</th>
						</tr>
						<tr class="sso-row sso-row-even">
							<td class="sso-col sso-col-label">
								<label for="oa_single_sign_on_settings_api_subdomain"><?php _e ('API Subdomain', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<input size="48" type="text" id="oa_single_sign_on_settings_api_subdomain" name="oa_single_sign_on_settings[api_subdomain]" value="<?php echo (isset ($settings ['api_subdomain']) ? htmlspecialchars ($settings ['api_subdomain']) : ''); ?>" />
							</td>
						</tr>
						<tr class="sso-row sso-row-odd">
							<td class="sso-col sso-col-label">
								<label for="oa_single_sign_on_settings_api_key"><?php _e ('API Public Key', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<input size="48" type="text" id="oa_single_sign_on_settings_api_key" name="oa_single_sign_on_settings[api_key]" value="<?php echo (isset ($settings ['api_key']) ? htmlspecialchars ($settings ['api_key']) : ''); ?>" />
							</td>
						</tr>
						<tr class="sso-row sso-row-even">
							<td class="sso-col sso-col-label">
								<label for="oa_single_sign_on_settings_api_secret"><?php _e ('API Private Key', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<input size="48" type="text" id="oa_single_sign_on_settings_api_secret" name="oa_single_sign_on_settings[api_secret]" value="<?php echo (isset ($settings ['api_secret']) ? htmlspecialchars ($settings ['api_secret']) : ''); ?>" />
							</td>
						</tr>
						<tr class="sso-row sso-row-foot">
							<td class="sso-col sso-col-label">
								<a class="button-primary" id="oa_single_sign_on_admin_check_api_settings" href="#"><?php _e ('Verify API Settings', 'oa_single_sign_on'); ?> </a>
							</td>
							<td class="sso-col sso-col-value">
								<div id="oa_single_sign_on_api_test_result"></div>
							</td>
						</tr>
					</table>
					<table class="form-table">
						<tr class="sso-row sso-row-head">
							<th colspan="2" class="sso-col sso-col-label">
								<?php
								    _e ('Single Sign-On Settings', 'oa_single_sign_on');
								?>
							</th>
						</tr>
						<tr class="sso-row sso-row-even">
							<td class="sso-col sso-col-label">
								<label><?php _e ('Automatic Account Creation', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<?php
									$accounts_autocreate = ((isset ($settings ['accounts_autocreate']) && in_array ($settings ['accounts_autocreate'], array ('enabled', 'disabled'))) ? $settings ['accounts_autocreate'] : 'enabled');
								?>
								<select name="oa_single_sign_on_settings[accounts_autocreate]" id="oa_single_sign_on_settings_accounts_autocreate">
									<option value="enabled"<?php echo ($accounts_autocreate == 'enabled' ? ' selected="selected"' : ''); ?>><?php _e ('Enable automatic account creation (Default)'); ?></option>
									<option value="disabled"<?php echo ($accounts_autocreate == 'disabled' ? ' selected="selected"' : ''); ?>><?php _e ('Disable automatic account creation'); ?></option>
								</select><br />
								<span class="description"><?php _e ('If enabled, the plugin automatically creates new user accounts for SSO users that visit the blog but do not have an account yet. These users are then automatically logged in with the new account.', 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
						<tr class="sso-row sso-row-odd">
							<td class="sso-col sso-col-label">
								<label><?php _e ('Automatic Account Link', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<?php
									$accounts_autolink = ((isset ($settings ['accounts_autolink']) && in_array ($settings ['accounts_autolink'], array ('nobody', 'everybody', 'everybody_except_admin'))) ? $settings ['accounts_autolink'] : 'everybody_except_admin');
								?>
								<select name="oa_single_sign_on_settings[accounts_autolink]" id="oa_single_sign_on_settings_accounts_autolink">
									<option value="nobody"<?php echo ($accounts_autolink == 'nobody' ? ' selected="selected"' : ''); ?>><?php _e ('Disable automatic account link'); ?></option>
									<option value="everybody"<?php echo ($accounts_autolink == 'everybody' ? ' selected="selected"' : ''); ?>><?php _e ('Enable automatic link for all types of accounts'); ?></option>
									<option value="everybody_except_admin"<?php echo ($accounts_autolink == 'everybody_except_admin' ? ' selected="selected"' : ''); ?>><?php _e ('Enable automatic link for all types of accounts, except the admin account (Default)'); ?></option>
								</select><br />
								<span class="description"><?php _e ('If enabled, the plugin tries to link SSO users that visit the blog to already existing user accounts. To link accounts the email address of the SSO user is matched against the email addresses of the existing users.', 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
						<tr class="sso-row sso-row-even">
							<td class="sso-col sso-col-label">
								<label><?php _e ('Account Reminder', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<?php
									$accounts_remind = ((isset ($settings ['accounts_remind']) && in_array ($settings ['accounts_remind'], array ('enabled', 'disabled'))) ? $settings ['accounts_remind'] : 'enabled');
								?>
								<select name="oa_single_sign_on_settings[accounts_remind]" id="oa_single_sign_on_settings_accounts_accounts_remind">
									<option value="enabled"<?php echo ($accounts_remind == 'enabled' ? ' selected="selected"' : ''); ?>><?php _e ('Enable account reminder (Default)'); ?></option>
									<option value="disabled"<?php echo ($accounts_remind == 'disabled' ? ' selected="selected"' : ''); ?>><?php _e ('Disable account reminder'); ?></option>
								</select><br />
								<span class="description"><?php _e ('If enabled, the plugin will display a popup reminding the SSO user of his account if an existing account has been found, but the user could not be logged in by the plugin (eg. if Automatic Link is disabled).', 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
						<tr class="sso-row sso-row-odd">
							<td class="sso-col sso-col-label">
								<label><?php _e ('Destroy Session On Logout', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<?php
									$logout_everywhere = ((! isset ($settings ['logout_everywhere']) || ! empty ($settings ['logout_everywhere'])) ? 1 : 0);
								?>
								<select name="oa_single_sign_on_settings[logout_everywhere]" id="oa_single_sign_on_settings_accounts_logout_everywhere">
									<option value="1"<?php echo ($logout_everywhere == 1 ? ' selected="selected"' : ''); ?>><?php _e ('Yes. Destroy the SSO session on logout (Default, Recommended)'); ?></option>
									<option value="0"<?php echo ($logout_everywhere == 0 ? ' selected="selected"' : ''); ?>><?php _e ('No. Keep the SSO session on logout.'); ?></option>
								</select><br />
								<span class="description"><?php _e ('If enabled, the plugin destroys the user\'s SSO session whenever he logs out from WordPress. If you disable this setting, then do not use an empty value for the login delay, otherwise the user will be re-logged in instantly.', 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
						<tr class="sso-row sso-row-even">
							<td class="sso-col sso-col-label">
								<label for="oa_single_sign_on_settings_logout_wait_relogin"><?php _e ('Re-Login Delay (Seconds)', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<?php
									$logout_wait_relogin = ((isset ($settings ['logout_wait_relogin']) && $settings ['logout_wait_relogin'] >= 0) ? $settings ['logout_wait_relogin'] : OA_SINGLE_SIGN_ON_LOGOUT_WAIT_RELOGIN_DEFAULT);
								?>
								<input type="text" id="oa_single_sign_on_settings_logout_wait_relogin" name="oa_single_sign_on_settings[logout_wait_relogin]" value="<?php echo (empty ($logout_wait_relogin) ? '' : $logout_wait_relogin); ?>" /><br />
								<span class="description"><?php _e ('Whenever a user logs out, the plugin will not retry to login that user for the entered period. Please enter a positive integer or leave empty in order to disable.', 'oa_single_sign_on'); ?></span>
							</td>
						</tr>
					</table>
					<table class="form-table">
						<tr class="sso-row sso-row-head">
							<th class="sso-col sso-col-label" colspan="2">
								<?php _e ('Debug Log', 'oa_single_sign_on'); ?>
							</th>
						</tr>
						<tr class="sso-row sso-row-odd">
							<td class="sso-col sso-col-label">
								<label><?php _e ('Debug Logging', 'oa_single_sign_on'); ?></label>
							</td>
							<td class="sso-col sso-col-value">
								<?php
									$debug_log = ((isset ($settings ['debug_log']) && in_array ($settings ['debug_log'], array ('enabled', 'disabled'))) ? $settings ['debug_log'] : 'disabled');
								?>
								<select name="oa_single_sign_on_settings[debug_log]" id="oa_single_sign_on_settings_debug_log">
									<option value="enabled"<?php echo ($debug_log == 'enabled' ? ' selected="selected"' : ''); ?>><?php _e ('Enable logging', 'oa_single_sign_on'); ?></option>
									<option value="disabled"<?php echo ($debug_log == 'disabled' ? ' selected="selected"' : ''); ?>><?php _e ('Disable logging (Default)', 'oa_single_sign_on'); ?></option>
									</select><br />
								<span class="description"><?php printf (__ ("If enabled, the plugin will write a log of it's actions to the WordPress <strong>%s</strong> file. This file must be writeable by the plugin. The last 100 Single Sign-On logs are displayed below.", 'oa_single_sign_on'), WP_CONTENT_DIR . '/debug.log'); ?></span>
							</td>
						</tr>
						<tr class="sso-row sso-row-even">
							<td class="sso-col sso-col-label" colspan="2">
							    <textarea rows="10" cols="50" class="sso-debug-log regular-text code" readonly="readonly"><?php echo oa_single_sign_on_parse_debug_log(); ?></textarea>
							</td>
						</tr>
					</table>
					<p class="sso-submit">
						<input type="hidden" name="page" value="setup" />
						<input type="submit" class="sso-save" value="<?php _e ('Save Configuration', 'oa_single_sign_on') ?>" />
					</p>
				</form>
			</div>
		</div>
<?php
}