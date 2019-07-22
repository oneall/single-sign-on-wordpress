<?php

/**
 * Initialize the plugin.
 */
function oa_single_sign_on_init ()
{
    // Current page.
    global $pagenow;

	//Add language file.
	if (function_exists ('load_plugin_textdomain'))
	{
		load_plugin_textdomain (' oa_single_sign_on', false, OA_SINGLE_SIGN_ON_BASE_PATH . '/languages/');
	}

	// Is the plugin configured?
	if (oa_single_sign_on_is_configured())
	{
    	// Read settings
    	$ext_settings = oa_single_sign_on_get_settings ();

    	// Check if we have a single sign-on login.
    	$status = oa_single_sign_on_check_for_sso_login ();

    	// Check what needs to be done.
    	switch (strtolower ($status->action))
    	{
    		// //////////////////////////////////////////////////////////////////////////
    		// No user found and we cannot add users.
    		// //////////////////////////////////////////////////////////////////////////
    		case 'new_user_no_login_autocreate_off' :

    		    // Grace Period
    		    oa_single_sign_on_set_login_wait_cookie ($settings ['blocked_wait_relogin']);

    		    // Add Log.
    		    oa_single_sign_on_add_log ('[INIT] @'.$status->action.'] Guest detected but account creation is disabled. Blocking automatic SSO re-login for [' . $settings ['blocked_wait_relogin'] . '] seconds, until [' . date("d/m/y H:i:s", $settings ['blocked_wait_relogin']) . ']');

    		break;

    		// //////////////////////////////////////////////////////////////////////////
    		// User found and logged in.
    		// //////////////////////////////////////////////////////////////////////////

    		// Created a new user.
    		case 'new_user_created_login':

    		// Logged in using the user_token.
    		case 'existing_user_login_user_token' :

    		// Logged in using a verified email address.
    		case 'existing_user_login_email_verified' :

    		// Logged in using an un-verified email address.
    		case 'existing_user_login_email_unverified' :

    			// Add log.
    			oa_single_sign_on_add_log ('[INIT] @'.$status->action.' - User is logged in');

    			// Remove cookies.
    			oa_single_sign_on_unset_login_wait_cookie ();

    			// Login user.
    			oa_single_sign_login_user ($status->user);

    			// Are we one the login page?
    			if (oa_single_sign_on_is_login_page ())
    			{
    			    // Do we have a redirection parameter?
    			    if (!empty ($_GET ['redirect_to']))
    			    {
    			        $redirect_to = esc_url_raw($_GET ['redirect_to']);
    			    }
    			    else
    			    {
    			        $redirect_to = admin_url ();
    			    }
    			}
    			else
    			{
    			    $redirect_to = oa_single_sign_on_get_current_url();
    			}

    			// Redirect.
    			wp_safe_redirect ($redirect_to);
    			exit ();

    		break;

    		// //////////////////////////////////////////////////////////////////////////
    		// User found, but we cannot log him in
    		// //////////////////////////////////////////////////////////////////////////

    		// User found, but autolink disabled.
    		case 'existing_user_no_login_autolink_off' :

    		// User found, but autolink not allowed.
    		case 'existing_user_no_login_autolink_not_allowed':

    		// Customer found, but autolink disabled for unverified emails.
    		case 'existing_user_no_login_autolink_off_unverified_emails' :

    			// Add a notice for the user.
    			oa_single_sign_on_enable_user_notice ($status->user);

    			// Grace period.
    			oa_single_sign_on_set_login_wait_cookie ($settings ['blocked_wait_relogin']);

    			// Add log.
    			oa_single_sign_on_add_log ('[INIT] @'.$status->action.'] - Blocking automatic SSO re-login for [' . $settings ['blocked_wait_relogin'] . '] seconds, until [' . date("d/m/y H:i:s", $settings ['blocked_wait_relogin']) . ']');

    		break;

    		// //////////////////////////////////////////////////////////////////////////
    		// Default
    		// //////////////////////////////////////////////////////////////////////////

    		// No callback received.
    		case 'no_callback_data_received':

    		// Default.
    		default :

    		    // If this value is in the future, we should not try to login the user with SSO.
    			$login_wait = oa_single_sign_on_get_login_wait_value_from_cookie ();

    			// Either the user is logged in (in this case refresh the session) or the wait time is over.
    			if (is_user_logged_in())
    			{
    			    // Read current user.
    			    $user = wp_get_current_user();

    			    // Add log.
    			    oa_single_sign_on_add_log ('[INIT] @'.$status->action.'] [UID' . $user->ID . '] - User is logged in, refreshing SSO session');

    			    // Enqueue scripts.
    				oa_single_sign_on_enqueue_scripts();
    			}
    			else
    			{
    			    // Wait time exceeded?
    			    if ($login_wait < time ())
    			    {
    			        // Add log.
    			        oa_single_sign_on_add_log ('[INIT] @'.$status->action.' - User is logged out. Checking for valid SSO session');

    			        // Enqueue scripts.
    			        oa_single_sign_on_enqueue_scripts();
    			    }
    			    else
    			    {
    			        oa_single_sign_on_add_log ('[INIT] @'.$status->action.' - User is logged out. Re-login disabled, ' . ($login_wait - time ()) . ' seconds remaining');
    			    }
    			}

    		break;
    	}
	}
}

/**
 * Check if it's a login page?
 */
function oa_single_sign_on_is_login_page()
{
    $path = str_replace(array('\\', '/'), DIRECTORY_SEPARATOR, ABSPATH);

    // Was wp-login.php or wp-register.php included during this execution?
    if (in_array($path . 'wp-login.php', get_included_files()) || in_array($path . 'wp-register.php', get_included_files()))
    {
        return true;
    }

    // $GLOBALS['pagenow'] is equal to "wp-login.php"
    if ( ! empty ($GLOBALS['pagenow']) && strtolower($GLOBALS['pagenow']) == 'wp-login.php')
    {
         return true;
    }

    // $_SERVER['PHP_SELF'] is equal to "/wp-login.php"
    if ( ! empty ($_SERVER['PHP_SELF']) && strtolower ($_SERVER['PHP_SELF']) == '/wp-login.php')
    {
        return true;
    }

    // Not a login page.
    return false;
}


/**
 * Enqueues the scripts used for single sign-on.
 */
function  oa_single_sign_on_enqueue_scripts ()
{
    add_action('wp_enqueue_scripts', 'oa_single_sign_on_sso_js');
    add_action('admin_enqueue_scripts', 'oa_single_sign_on_sso_js');
    add_action('login_enqueue_scripts', 'oa_single_sign_on_sso_js');
}


/**
 * Remove the user notice if there is any.
 */
function  oa_single_sign_on_after_login ($user_login, $user)
{
	oa_single_sign_on_remove_flush_user_notice ($user);
}
add_filter( 'wp_login', 'oa_single_sign_on_after_login', 10, 2);

/**
 * Update user cloud password.
 */
function oa_single_sign_on_after_profile_update ($userid)
{
	// Is the plugin configured?
	if (oa_single_sign_on_is_configured())
	{
		// Read current user.
		$user = get_user_by ('ID', $userid);

		// Check user.
		if (is_object ($user) && $user instanceof WP_User && ! empty ($user->ID))
		{
			// New Password.
			$new_password = null;

			// Profile Updated
			if (isset ($_POST) && is_array ($_POST) && ! empty ($_POST['submit']))
			{
				if (! empty ($_POST['pass1']) && ! empty ($_POST['pass2']))
				{
					if ($_POST['pass1'] == $_POST['pass2'])
					{
						$new_password = $_POST['pass1'];
					}
				}
			}

			// Update.
			$result = oa_single_sign_on_update_user_in_cloud ($user, $new_password);
		}
	}
}
add_action('profile_update', 'oa_single_sign_on_after_profile_update', 10, 1);

/**
 * Try cloud authentication.
 */
function oa_single_sign_on_authenticate ($user, $login, $password)
{
	// Is the plugin configured?
	if (oa_single_sign_on_is_configured())
	{
		// Lookup user
		$result = oa_single_sign_on_lookup_user ($login, $password);

		// Returning the user will log him in.
		if ($result->is_successfull === true)
		{
			return $result->user;
		}
	}

	// Not found.
	return;
}
add_filter( 'authenticate', 'oa_single_sign_on_authenticate', 10, 3 );


/**
 * Destroy the SSO session when the users logs out.
 */
function oa_single_sign_on_auth_close ()
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Read settings.
        $ext_settings = oa_single_sign_on_get_settings ();

		// Read current user.
		$user = wp_get_current_user();

		// Destroy session.
		if ( ! empty ($ext_settings ['logout_everywhere']))
		{
		    // Add log.
		    oa_single_sign_on_add_log ('[AUTH CLOSE] [UID' . $user->ID . '] User logout, removing SSO session');

			// End session.
			oa_single_sign_on_end_session_for_user ($user);
		}
		else
		{
		    // Add log.
		    oa_single_sign_on_add_log ('[AUTH CLOSE] [UID' . $user->ID . '] User logout, keeping SSO session');
		}

		// Wait until relogging in?
		if (! empty ($ext_settings ['logout_wait_relogin']) && $ext_settings ['logout_wait_relogin'] > 0)
		{
			// Grace period.
			oa_single_sign_on_set_login_wait_cookie ($ext_settings ['logout_wait_relogin']);

			// Add log.
			oa_single_sign_on_add_log ('[AUTH CLOSE] [UID'.$user->ID.'] User logout. No automatic SSO re-login for [' . $ext_settings ['logout_wait_relogin'] . '] seconds, until [' . date("d/m/y H:i:s", time() + $ext_settings ['logout_wait_relogin']) . ']');
		}
		// No waiting.
		else
		{
		    // Remove the cookie.
		    oa_single_sign_on_unset_login_wait_cookie ();
		}
	}
}
add_action('clear_auth_cookie', 'oa_single_sign_on_auth_close');

/**
 * Starts the SSO session when the users has logged in.
 */
function oa_single_sign_on_auth_open ($auth_cookie, $expire, $expiration, $userid, $scheme)
{
	// Is the plugin configured?
	if (oa_single_sign_on_is_configured())
	{
		// Read current user.
		$user = get_user_by ('ID', $userid);

		// Start session.
		oa_single_sign_on_start_session_for_user ($user);
	}
}
add_action('set_auth_cookie', 'oa_single_sign_on_auth_open', 10, 5);


/**
 * Adds the SSO Javascript to the WordPress header.
 */
function oa_single_sign_on_sso_js ()
{
	// Is the plugin configured?
	if (oa_single_sign_on_is_configured())
	{
		// Read settings
		$ext_settings = oa_single_sign_on_get_settings ();

		// SSO Session Token.
		$sso_session_token = null;

		// Read current user.
		$user = wp_get_current_user();

		// User is logged in
		if (is_object ($user) && $user instanceof WP_User && ! empty ($user->ID))
		{
			// Logged in.
			$user_is_logged_in = true;

			// Retrieve his SSO session token.
			$get_sso_session_token = oa_single_sign_on_get_local_sso_session_token_for_user ($user);

			// SSO session token found
			if ($get_sso_session_token->is_successfull === true)
			{
				$sso_session_token = $get_sso_session_token->sso_session_token;
			}
		}
		// User is logged out
		else
		{
			// Logged out.
			$user_is_logged_in = false;
		}

		// Either logged out, or logged in and having a token
		if ( ! $user_is_logged_in || ($user_is_logged_in && ! empty ($sso_session_token)))
		{
			// Build SSO JavaScript
			$data = array();
			$data [] = "<!-- OneAll.com / Single Sign-On for WordPress /v " . OA_SINGLE_SIGN_ON_VERSION . " -->";
			$data [] = "<script type=\"text/javascript\">";
			$data [] = "//<![CDATA[";
			$data [] = " var have_oa_lib = ((typeof window.oneall !== 'undefined') ? true : false);";
			$data [] = " (function(){if (!have_oa_lib){";
			$data [] = "  var lib = document.createElement('script');";
			$data [] = "  lib.type = 'text/javascript'; lib.async = true;";
			$data [] = "  lib.src = '//" . $ext_settings ['base_url'] . "/socialize/library.js';";
			$data [] = "  var node = document.getElementsByTagName('script')[0];";
			$data [] = "  node.parentNode.insertBefore(lib, node); have_oa_lib = true;";
			$data [] = " }})();";
			$data [] = " var _oneall = (_oneall || []);";

			// Register session
			if ( ! empty ($sso_session_token))
			{
			    oa_single_sign_on_add_log ('[SSO JS] [UID'.$user->ID.'] Open session found, registering token ['.$sso_session_token.']');

			    // Register.
				$data [] = " _oneall.push(['single_sign_on', 'do_register_sso_session', '" . $sso_session_token . "']);";
			}
			// Check for session
			else
			{
			    oa_single_sign_on_add_log ('[SSO JS] No open session found, checking...');

			    // Check for open session.
				$data [] = " _oneall.push(['single_sign_on', 'do_check_for_sso_session', window.location.href, true]);";
			}

			$data [] = "//]]>";
			$data [] = "</script>";
			$data [] = "";

			// Add SSO JavaScript
			echo implode ("\n", $data);
		}
	}
}

