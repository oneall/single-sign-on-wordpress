<?php

/**
 * Initialize the plugin.
 */
function oa_single_sign_on_init ()
{
	//Add language file.
	if (function_exists ('load_plugin_textdomain'))
	{
		load_plugin_textdomain (' oa_single_sign_on', false, OA_SINGLE_SIGN_ON_BASE_PATH . '/languages/');
	}

	// Check if we have a single sign-on login.
	$status = oa_single_sign_on_check_for_sso_login ();

	// Nothing has been done
	switch (strtolower ($status->action))
	{
		// //////////////////////////////////////////////////////////////////////////
		// No user found and we cannot add users
		// //////////////////////////////////////////////////////////////////////////
		case 'new_user_no_login_autocreate_off' :

			// Add log
			oa_single_sign_on_add_log ('[INIT] @'.$status->action.' - New user detected but account creation is disabled');

			// This value prevents SSO from re-trying to login the user.
			oa_single_sign_on_set_grace_period ();

		break;

		// //////////////////////////////////////////////////////////////////////////
		// User found and logged in
		// //////////////////////////////////////////////////////////////////////////

		// Logged in using the user_token
		case 'existing_user_login_user_token' :

		// Logged in using a verified email address
		case 'existing_user_login_email_verified' :

			// Logged in using an un-verified email address
		case 'existing_user_login_email_unverified' :

			// Add Log
			oa_single_sign_on_add_log ('[INIT] @'.$status->action.' - User is logged in');

			// Remove grace period
			oa_single_sign_on_unset_grace_period ();

			// Login user.
			oa_single_sign_login_user ($status->user);

			// Refresh Page
			wp_redirect (oa_single_sign_on_get_current_url());
			exit ();

		break;

		// //////////////////////////////////////////////////////////////////////////
		// User found, but we cannot log him in
		// //////////////////////////////////////////////////////////////////////////

		// User found, but autolink disabled
		case 'existing_user_no_login_autolink_off' :

		// User found, but autolink not allowed
		case 'existing_user_no_login_autolink_not_allowed':

		// Customer found, but autolink disabled for unverified emails
		case 'existing_user_no_login_autolink_off_unverified_emails' :

			// Add Log
			oa_single_sign_on_add_log ('[INIT] @'.$status->action.' - User it not loggedin');

			// This value prevents SSO from re-trying to login the user.
			oa_single_sign_on_set_grace_period ();

			// Add a notice for the user
			oa_single_sign_on_enable_user_notice ($status->user);

		break;

		// //////////////////////////////////////////////////////////////////////////
		// Default
		// //////////////////////////////////////////////////////////////////////////
		default :

			// Read data
			$sso_grace_period = oa_single_sign_on_get_grace_period ();

			// If this value is in the future, we should not try to login the user with SSO
			if ( ! is_numeric ($sso_grace_period) || $sso_grace_period < time ())
			{
				// Adds the SSO login checker
				add_action('wp_enqueue_scripts', 'oa_single_sign_on_sso_js');
				add_action('admin_enqueue_scripts', 'oa_single_sign_on_sso_js');
			}
			else
			{
				// Add Log
				oa_single_sign_on_add_log ('[INIT] @'.$status->action.' - Skipping SSO Library, Grace Period '.$sso_grace_period);
			}

		break;
	}
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
		if (isset ($_POST) && ! empty ($_POST['pass1']) && ! empty ($_POST['pass2']))
		{
			if ($_POST['pass1'] == $_POST['pass2'])
			{
				// Read current user.
				$user = get_user_by ('ID', $userid);

				// Update password.
				$result = oa_single_sign_on_update_customer_cloud_password ($user, $_POST['pass1']);
			}
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
		// Read current user.
		$user = wp_get_current_user();

		// End session.
		oa_single_sign_on_end_session_for_user ($user);
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
				$data [] = " _oneall.push(['single_sign_on', 'do_register_sso_session', '" . $sso_session_token . "']);";
			}
			// Check for session
			else
			{
				$data [] = " _oneall.push(['single_sign_on', 'set_callback_uri', window.location.href]);";
				$data [] = " _oneall.push(['single_sign_on', 'do_check_for_sso_session']);";
			}

			$data [] = "//]]>";
			$data [] = "</script>";
			$data [] = "";

			// Add SSO JavaScript
			echo implode ("\n", $data);
		}
	}
}

