<?php

/**
 * Initialize the plugin.
 */
function oa_single_sign_on_init()
{
    // Current page.
    global $pagenow;

    //Add language file.
    if (function_exists('load_plugin_textdomain'))
    {
        load_plugin_textdomain(' oa_single_sign_on', false, OA_SINGLE_SIGN_ON_BASE_PATH . '/languages/');
    }

    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Read settings
        $ext_settings = oa_single_sign_on_get_settings();

        // Check if we have a single sign-on login.
        $status = oa_single_sign_on_check_for_sso_login();

        // Check what needs to be done.
        switch (strtolower($status->action))
        {
            // //////////////////////////////////////////////////////////////////////////
            // No user found and we cannot add users.
            // //////////////////////////////////////////////////////////////////////////
            case 'new_user_no_login_autocreate_off':
                // Grace Period
                oa_single_sign_on_set_login_wait_cookie($settings['blocked_wait_relogin']);

                // Add Log.
                oa_single_sign_on_add_log('[INIT] [@' . $status->action . '] Guest detected but account creation is disabled. Blocking automatic SSO re-login for [' . $settings['blocked_wait_relogin'] . '] seconds, until [' . date("d/m/y H:i:s", $settings['blocked_wait_relogin']) . ']');

                break;

            // //////////////////////////////////////////////////////////////////////////
            // User found and logged in.
            // //////////////////////////////////////////////////////////////////////////

            // Created a new user.
            case 'new_user_created_login':
            // Logged in using the user_token.
            case 'existing_user_login_user_token':
            // Logged in using a verified email address.
            case 'existing_user_login_email_verified':
            // Logged in using an un-verified email address.
            case 'existing_user_login_email_unverified':
                // Add log.
                oa_single_sign_on_add_log('[INIT] [@' . $status->action . '] - User is logged in');

                // Remove cookies.
                oa_single_sign_on_unset_login_wait_cookie();

                // Login user.
                oa_single_sign_login_user($status->user);

                // Are we one the login page?
                if (oa_single_sign_on_is_login_page())
                {
                    // Do we have a redirection parameter?
                    if (!empty($_GET['redirect_to']))
                    {
                        $redirect_to = esc_url_raw($_GET['redirect_to']);
                    }
                    else
                    {
                        $redirect_to = admin_url();
                    }
                }
                else
                {
                    $redirect_to = oa_single_sign_on_get_current_url();
                }

                // Redirect.
                wp_safe_redirect($redirect_to);
                exit();

                break;

            // //////////////////////////////////////////////////////////////////////////
            // User found, but we cannot log him in
            // //////////////////////////////////////////////////////////////////////////

            // User found, but autolink disabled.
            case 'existing_user_no_login_autolink_off':
            // User found, but autolink not allowed.
            case 'existing_user_no_login_autolink_not_allowed':
            // Customer found, but autolink disabled for unverified emails.
            case 'existing_user_no_login_autolink_off_unverified_emails':
                // Add a notice for the user.
                oa_single_sign_on_enable_user_notice($status->user);

                // Grace period.
                oa_single_sign_on_set_login_wait_cookie($settings['blocked_wait_relogin']);

                // Add log.
                oa_single_sign_on_add_log('[INIT] [@' . $status->action . '] - Blocking automatic SSO re-login for [' . $settings['blocked_wait_relogin'] . '] seconds, until [' . date("d/m/y H:i:s", $settings['blocked_wait_relogin']) . ']', 10);

                break;

            // //////////////////////////////////////////////////////////////////////////
            // Default
            // //////////////////////////////////////////////////////////////////////////

            // No callback received.
            case 'no_callback_data_received':
            // Default.
            default:

                // If this value is in the future, we should not try to login the user with SSO.
                $login_wait = oa_single_sign_on_get_login_wait_value_from_cookie();

                // Either the user is logged in (in this case refresh the session) or the wait time is over.
                if (is_user_logged_in())
                {
                    // Read current user.
                    $user = wp_get_current_user();

                    // Add log.
                    oa_single_sign_on_add_log('[INIT] [@' . $status->action . '] [UID' . $user->ID . '] - User is logged in, refreshing SSO session', 10);

                    // Enqueue scripts.
                    oa_single_sign_on_enqueue_scripts();
                }
                else
                {
                    // Wait time exceeded?
                    if ($login_wait < time())
                    {
                        // Add log.
                        oa_single_sign_on_add_log('[INIT] [@' . $status->action . ' - User is logged out. Checking for valid SSO session', 10);

                        // Enqueue scripts.
                        oa_single_sign_on_enqueue_scripts();
                    }
                    else
                    {
                        oa_single_sign_on_add_log('[INIT] [@' . $status->action . ' - User is logged out. Re-login disabled, ' . ($login_wait - time()) . ' seconds remaining', 10);
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
    if (!empty($GLOBALS['pagenow']) && strtolower($GLOBALS['pagenow']) == 'wp-login.php')
    {
        return true;
    }

    // $_SERVER['PHP_SELF'] is equal to "/wp-login.php"
    if (!empty($_SERVER['PHP_SELF']) && strtolower($_SERVER['PHP_SELF']) == '/wp-login.php')
    {
        return true;
    }

    // Not a login page.

    return false;
}

/**
 * Enqueues the scripts used for single sign-on.
 */
function oa_single_sign_on_enqueue_scripts()
{
    add_action('wp_enqueue_scripts', 'oa_single_sign_on_sso_js');
    add_action('admin_enqueue_scripts', 'oa_single_sign_on_sso_js');
    add_action('login_enqueue_scripts', 'oa_single_sign_on_sso_js');
}

/**
 * Remove the user notice if there is any.
 */
function oa_single_sign_on_after_login($user_login, $user)
{
    oa_single_sign_on_remove_flush_user_notice($user);
}
add_filter('wp_login', 'oa_single_sign_on_after_login', 10, 2);

/**
 * Update user cloud password after profile update.
 */
function oa_single_sign_on_after_profile_update($userid)
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Read current user.
        $user = get_user_by('ID', $userid);

        // Make sure the user is authenticated.
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // Read settings.
            $ext_settings = oa_single_sign_on_get_settings();

            // New Password.
            $password = null;

            // Synchronize credentials?
            if ($ext_settings['accounts_sync_credentials'] == 'enabled')
            {
                // Do we have a password?
                if (isset($_POST) && is_array($_POST))
                {
                    if (!empty($_POST['pass1']) && !empty($_POST['pass2']))
                    {
                        if ($_POST['pass1'] == $_POST['pass2'])
                        {
                            $password = $_POST['pass1'];
                        }
                    }
                }
            }

            // Update in cloud storage.
            $update_user = oa_single_sign_on_update_user_in_cloud($user, $password);

            // Add log.
            oa_single_sign_on_add_log('[USER-UPDATE] [UID' . $user->ID . '] Synchronize data -> ' . $update_user->action);

            // Add user.
            if ($update_user->action == 'user_not_in_cloud_storage')
            {
                // Add user to cloud storage.
                $add_user = oa_single_sign_on_add_user_to_cloud_storage($user, $password);

                // User added.
                if ($add_user->is_successfull === true)
                {
                    $add_tokens = oa_single_sign_on_add_local_storage_tokens_for_user($user, $add_user->user_token, $add_user->identity_token);
                }

                // Add log.
                oa_single_sign_on_add_log('[USER-UPDATE] [UID' . $user->ID . '] Add data -> ' . $add_user->action);
            }
        }
    }
}
add_action('profile_update', 'oa_single_sign_on_after_profile_update', 10, 1);

/**
 * Reset Password when email registration is activated
 */
function oa_single_sign_on_after_password_reset($user, $new_pass)
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Make sure the user is authenticated.
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // Read settings.
            $ext_settings = oa_single_sign_on_get_settings();

            // Update in cloud storage.
            $update_user = oa_single_sign_on_update_user_in_cloud($user, $new_pass);

            // Add log.
            oa_single_sign_on_add_log('[USER-UPDATE] [UID' . $user->ID . '] Password reset-> ' . $update_user->action);
        }
    }
}

// add the action
add_action('after_password_reset', 'oa_single_sign_on_after_password_reset', 10, 2);

/**
 * Add user to cloud storage on register
 */
function oa_single_sign_on_after_user_register($userid)
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Read current user.
        $user = get_user_by('ID', $userid);

        // Make sure the user is authenticated.
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // Password.
            $password = null;

            // Do we have a password?
            if (isset($_POST) && is_array($_POST))
            {
                if (!empty($_POST['pass1']) && !empty($_POST['pass2']))
                {
                    if ($_POST['pass1'] == $_POST['pass2'])
                    {
                        $password = $_POST['pass1'];
                    }
                }
            }

            // Add user to cloud storage.
            $add_user = oa_single_sign_on_add_user_to_cloud_storage($user, $password);

            // Add log.
            oa_single_sign_on_add_log('[USER-ADD] [UID' . $user->ID . '] Synchronize data -> ' . $add_user->action);

            // User added.
            if ($add_user->is_successfull === true)
            {
                // Add token.
                $add_tokens = oa_single_sign_on_add_local_storage_tokens_for_user($user, $add_user->user_token, $add_user->identity_token);

                // Update cloud.
                if ($add_user->action == 'existing_user_read')
                {
                    // Add log.
                    oa_single_sign_on_add_log('[USER-ADD] [UID' . $user->ID . '] User exists, updating data [{' . $password . '}]');

                    // Update.
                    $result = oa_single_sign_on_update_user_in_cloud($user, $password);
                }
            }
            // User already exists
            else
            {
            }

            // Remove the profile update hook which is triggered too.
            remove_action('profile_update', 'oa_single_sign_on_after_profile_update', 10, 1);
        }
    }
}
add_action('user_register', 'oa_single_sign_on_after_user_register', 60, 1);

/**
 * Try cloud authentication before any other logins.
 */
function oa_single_sign_on_before_authenticate_lookup($user, $login, $password)
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Lookup user.
        $result = oa_single_sign_on_lookup_user($login, $password);

        // Returning the user will log him in.
        if ($result->is_successfull === true)
        {
            return $result->user;
        }
    }

    // Not found.

    return;
}
add_filter('authenticate', 'oa_single_sign_on_before_authenticate_lookup', 10, 3);

/**
 * Update cloud password after a successful authentication.
 */
function oa_single_sign_on_after_authenticate_update($user, $login, $password)
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Make sure the user is authenticated.
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // Read settings.
            $ext_settings = oa_single_sign_on_get_settings();

            // Synchronize credentials?
            if ($ext_settings['accounts_sync_credentials'] == 'enabled')
            {
                // Add log.
                oa_single_sign_on_add_log('[USER-UPDATE] [UID' . $user->ID . '] Synchronizing user credentials [{' . $password . '}]');

                // Update.
                $result = oa_single_sign_on_update_user_in_cloud($user, $password);
            }
        }
        else
        {
            // First try : Cloud storage / Login + password + existing user => Failed
            // Secnd try : Local storage / Login + password => Failed
            // New try : Cloud storage / Login + password without local user
            $result = oa_single_sign_on_lookup_user($login, $password, true);

            // Returning the user and log him in.
            if ($result->is_successfull === true)
            {
                return $result->user;
            }
        }
    }

    // Just return the orginal value.

    return $user;
}
add_filter('authenticate', 'oa_single_sign_on_after_authenticate_update', 90, 3);

/**
 * Destroy the SSO session when the users logs out.
 */
function oa_single_sign_on_auth_close()
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Read settings.
        $ext_settings = oa_single_sign_on_get_settings();

        // Read current user.
        $user = get_user_by('ID', get_current_user_id());

        // Make sure the user is authenticated.
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // Destroy session.
            if (!empty($ext_settings['logout_everywhere']))
            {
                // Add log.
                oa_single_sign_on_add_log('[LOGOUT] [UID' . $user->ID . '] User logout, removing SSO session');

                // End session.
                oa_single_sign_on_end_session_for_user($user);
            }
            else
            {
                // Add log.
                oa_single_sign_on_add_log('[LOGOUT] [UID' . $user->ID . '] User logout, keeping SSO session');
            }

            // Wait until relogging in?
            if (!empty($ext_settings['logout_wait_relogin']) && $ext_settings['logout_wait_relogin'] > 0)
            {
                // Grace period.
                oa_single_sign_on_set_login_wait_cookie($ext_settings['logout_wait_relogin']);

                // Add log.
                oa_single_sign_on_add_log('[LOGOUT] [UID' . $user->ID . '] User logout. No automatic SSO re-login for [' . $ext_settings['logout_wait_relogin'] . '] seconds, until [' . date("d/m/y H:i:s", time() + $ext_settings['logout_wait_relogin']) . ']');
            }
            // No waiting.
            else
            {
                // Remove the cookie.
                oa_single_sign_on_unset_login_wait_cookie();
            }
        }
        else
        {
            // Add log.
            oa_single_sign_on_add_log('[LOGOUT] Could not  get current user', 30);
        }
    }
}
add_action('clear_auth_cookie', 'oa_single_sign_on_auth_close', 5);

/**
 * Starts the SSO session when the users logs in.
 */
function oa_single_sign_on_auth_open($auth_cookie, $expire, $expiration, $userid, $scheme)
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Read current user.
        $user = get_user_by('ID', $userid);

        // Check data.
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // Add log.
            oa_single_sign_on_add_log('[LOGIN] [UID' . $user->ID . '] User login, starting SSO session');

            // Start session.
            oa_single_sign_on_start_session_for_user($user);
        }
    }
}
add_action('set_auth_cookie', 'oa_single_sign_on_auth_open', 10, 5);

/**
 * Adds the SSO Javascript to the WordPress header.
 */
function oa_single_sign_on_sso_js()
{
    // Is the plugin configured?
    if (oa_single_sign_on_is_configured())
    {
        // Read settings
        $ext_settings = oa_single_sign_on_get_settings();

        // SSO Session Token.
        $sso_session_token = null;

        // Read current user.
        $user = wp_get_current_user();

        // User is logged in
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // Logged in.
            $user_is_logged_in = true;

            // Retrieve his SSO session token.
            $get_sso_session_token = oa_single_sign_on_get_local_sso_session_token_for_user($user);

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
        if (!$user_is_logged_in || ($user_is_logged_in && !empty($sso_session_token)))
        {
            // Build SSO JavaScript
            $data = array();
            $data[] = "<!-- OneAll.com / Single Sign-On for WordPress /v " . OA_SINGLE_SIGN_ON_VERSION . " -->";
            $data[] = "<script type=\"text/javascript\">";
            $data[] = "//<![CDATA[";
            $data[] = " var have_oa_lib = ((typeof window.oneall !== 'undefined') ? true : false);";
            $data[] = " (function(){if (!have_oa_lib){";
            $data[] = "  var lib = document.createElement('script');";
            $data[] = "  lib.type = 'text/javascript'; lib.async = true;";
            $data[] = "  lib.src = '//" . $ext_settings['base_url'] . "/socialize/library.js';";
            $data[] = "  var node = document.getElementsByTagName('script')[0];";
            $data[] = "  node.parentNode.insertBefore(lib, node); have_oa_lib = true;";
            $data[] = " }})();";
            $data[] = " var _oneall = (_oneall || []);";

            // Register session
            if (!empty($sso_session_token))
            {
                oa_single_sign_on_add_log('[SSO JS] [UID' . $user->ID . '] Open session found, registering token [' . $sso_session_token . ']', 10);

                // Register.
                $data[] = " _oneall.push(['single_sign_on', 'do_register_sso_session', '" . $sso_session_token . "']);";
            }
            // Check for session
            else
            {
                oa_single_sign_on_add_log('[SSO JS] No open session found, checking...', 10);

                // Check for open session.
                $data[] = " _oneall.push(['single_sign_on', 'do_check_for_sso_session', window.location.href, true]);";
            }

            $data[] = "//]]>";
            $data[] = "</script>";
            $data[] = "";

            // Add SSO JavaScript
            echo implode("\n", $data);
        }
    }
}
