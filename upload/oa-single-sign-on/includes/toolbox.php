<?php

// /////////////////////////////////////////////////////////////////////////////
// COOKIE TO SKIP THE RE-LOGIN AFTER LOGUT FOR A GIVEN PERIOD
// /////////////////////////////////////////////////////////////////////////////

/**
 * Parses the debug log.
 */
function oa_single_sign_on_parse_debug_log ($prefix = '[OASSO]')
{
    // File to read.
    $debug_file = WP_CONTENT_DIR . '/debug.log';
    $prefix_length = strlen($prefix);

    // Result.
    $result = '';

    // Check if file exists.
    if ( ! file_exists ($debug_file))
    {
        $result = sprintf (__('The debug file (%s) does not seem to exist.'), $debug_file);
    }
    else
    {
        if ( ! is_readable ($debug_file))
        {
            $result = sprintf (__('The debug file (%s) is not readable.'), $debug_file);
        }
        else
        {
            if ($fh = fopen($debug_file, 'r'))
            {
                $lines = array ();

                while (!feof($fh))
                {
                    $line = trim(fgets($fh));
                    if (substr( $line, 0, $prefix_length ) == $prefix)
                    {
                        $lines[] = trim(substr($line, $prefix_length));
                    }
                }
                fclose($fh);


                // Format.
                $result = implode ("\n", array_reverse(array_slice($lines, -100)));
            }
        }
    }

    return $result;
}

/**
 * Remove the login-wait cookie.
 */
function oa_single_sign_on_unset_login_wait_cookie()
{
    if (isset($_COOKIE) && is_array($_COOKIE) && isset($_COOKIE[OA_SINGLE_SIGN_ON_LOGIN_WAIT_COOKIE_KEY]))
    {
        unset($_COOKIE[OA_SINGLE_SIGN_ON_LOGIN_WAIT_COOKIE_KEY]);
    }

    // Remove Cookie.
    setcookie(OA_SINGLE_SIGN_ON_LOGIN_WAIT_COOKIE_KEY, '', (time() - (15 * 60)), COOKIEPATH, COOKIE_DOMAIN);
}

/**
 * Get the login-wait value from a cookie.
 */
function oa_single_sign_on_get_login_wait_value_from_cookie()
{
    if (isset($_COOKIE) && is_array($_COOKIE) && isset($_COOKIE[OA_SINGLE_SIGN_ON_LOGIN_WAIT_COOKIE_KEY]))
    {
        return $_COOKIE[OA_SINGLE_SIGN_ON_LOGIN_WAIT_COOKIE_KEY];
    }
    return 0;
}

/**
 * Set the login wait cookie.
 */
function oa_single_sign_on_set_login_wait_cookie($period)
{
    // No automatic logins before this time.
    $period = ($period + time());

    // Set cookies.
    setcookie(OA_SINGLE_SIGN_ON_LOGIN_WAIT_COOKIE_KEY, $period, $period, COOKIEPATH, COOKIE_DOMAIN);
    $_COOKIE[OA_SINGLE_SIGN_ON_LOGIN_WAIT_COOKIE_KEY] = $period;
}

// /////////////////////////////////////////////////////////////////////////////
// FUNCTIONS
// /////////////////////////////////////////////////////////////////////////////

/**
 * Mask a given string.
 */
function oa_single_sign_on_mask($string, $keep = 3)
{
    $masked = $string;

    if (strlen($string) >= $keep)
    {
        $masked = substr($string, 0, $keep);
        $masked .= str_repeat('*', (strlen($string) - $keep));
    }

    return $masked;
}

/**
 * Hash a string using the plugin's API settings.
 */
function oa_single_sign_on_hash_string($password)
{
    // Read settings
    $ext_settings = oa_single_sign_on_get_settings();

    // We cannot make a connection without the subdomain.
    if (!empty($ext_settings['api_key']) && !empty($ext_settings['api_subdomain']))
    {
        return sha1($ext_settings['api_key'] . $password . $ext_settings['api_subdomain']);
    }

    // Error
    return null;
}

/**
 * Return the user agent to be used in http requests.
 */
function oa_single_sign_on_get_agent()
{
    return 'SingleSignOn ' . OA_SINGLE_SIGN_ON_VERSION . 'WP (+http://www.oneall.com/)';
}

/**
 * Write to the WordPress log file.
 */
function oa_single_sign_on_add_log($message)
{
    // Read settings
    $ext_settings = oa_single_sign_on_get_settings();

    // Is logging enabled
    if ($ext_settings['debug_log'] == 'enabled')
    {
        // Debug file
        $debug_folder = WP_CONTENT_DIR;
        $debug_file = 'debug.log';
        $debug_file_absolute = $debug_folder . '/' . $debug_file;
        $debug_file_writeable = false;

        // Make sure we can write to it.
        if (is_writable($debug_file_absolute))
        {
            $debug_file_writeable = true;
        }
        else
        {
            if (!file_exists($debug_file_absolute))
            {
                if (is_writable($debug_folder))
                {
                    if (touch($debug_file_absolute) == true)
                    {
                        $debug_file_writeable = true;
                    }
                }
            }
        }

        if ($debug_file_writeable)
        {
            $message = trim($message);

            // Masks the indicated string-
            if (preg_match_all("/\{([^\}]+)\}/", $message, $matches, PREG_SET_ORDER))
            {
                foreach ($matches as $match)
                {
                    $message = str_replace($match[0], oa_single_sign_on_mask($match[1]), $message);
                }
            }

            @file_put_contents($debug_file_absolute, '[OASSO] [' . date("d/m/y H:i:s", time()) . '] ' . trim($message) . "\n", FILE_APPEND);
        }
    }
}

/**
 * Check if a given v4 UUID is valid.
 */
function oa_single_sign_on_is_uuid($uuid)
{
    return preg_match('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}/i', trim($uuid));
}

/**
 * Check if SSO is configured.
 */
function oa_single_sign_on_is_configured()
{
    // Read settings.
    $settings = get_option('oa_single_sign_on_settings');

    // To be useable, all API settings are required.
    if (!empty($settings['api_subdomain']) && !empty($settings['api_key']) && !empty($settings['api_secret']))
    {
        return true;
    }

    // Not useable
    return false;
}

/**
 * Return the extension settings.
 */
function oa_single_sign_on_get_settings()
{
    // Read settings.
    $args = get_option('oa_single_sign_on_settings');

    // API Connection Handler.
    $settings['api_connection_handler'] = (isset($args['api_connection_handler']) ? strtolower($args['api_connection_handler']) : '');
    $settings['api_connection_handler'] = (($args['api_connection_handler'] == 'fsockopen') ? 'fsockopen' : 'curl');

    // API Connection Port.
    $settings['connection_port'] = ((!isset($args['api_connection_use_https']) || !empty($args['api_connection_use_https'])) ? 443 : 80);
    $settings['connection_protocol'] = (($settings['connection_port'] == 80) ? 'http' : 'https');

    // API Settings.
    $settings['api_subdomain'] = (isset($args['api_subdomain']) ? $args['api_subdomain'] : '');
    $settings['api_key'] = (isset($args['api_key']) ? $args['api_key'] : '');
    $settings['api_secret'] = (isset($args['api_secret']) ? $args['api_secret'] : '');

    // Automatic Account Creation.
    $settings['accounts_autocreate'] = (isset($args['accounts_autocreate']) ? $args['accounts_autocreate'] : 'enabled');
    $settings['accounts_autocreate'] = ((in_array($settings['accounts_autocreate'], array('enabled', 'disabled' ))) ? $settings['accounts_autocreate'] : 'enabled');

    // Automatic Account Link.
    $settings['accounts_autolink'] = (isset($args['accounts_autolink']) ? $args['accounts_autolink'] : 'everybody_except_admin');
    $settings['accounts_autolink'] = ((in_array($settings['accounts_autolink'], array('nobody', 'everybody', 'everybody_except_admin'))) ? $settings['accounts_autolink'] : 'everybody_except_admin');

    // Automatic Account Link for unverified email.
    $settings['accounts_linkunverified'] = (isset($args['accounts_linkunverified']) ? $args['accounts_linkunverified'] : 'enabled');

    // Account Email Sending
    $settings['accounts_sendmail'] = (isset($args['accounts_sendmail']) ? $args['accounts_sendmail'] : 1);
    $settings['accounts_sendmail'] = (empty($settings['accounts_sendmail']) ? false : true);

    // Account Reimder
    $settings['accounts_remind'] = (isset($args['accounts_remind']) ? $args['accounts_remind'] : 'enabled');
    $settings['accounts_remind'] = (in_array($settings['accounts_remind'], array('enabled', 'disabled')) ? $settings['accounts_remind'] : 'enabled');

    // SSO Session Settings.
    $settings['session_lifetime'] = (isset($args['session_lifetime']) ? $args['session_lifetime'] : 86400);
    $settings['session_top_realm'] = (isset($args['session_top_realm']) ? $args['session_top_realm'] : '');
    $settings['session_sub_realm'] = (isset($args['session_sub_realm']) ? $args['session_sub_realm'] : '');

    // Disables the login for this period whenever an automatic login fails.
    $settings['blocked_wait_relogin'] = 3600;

    // Disables the re-login for this period after a logout.
    $settings['logout_wait_relogin'] = (isset($args['logout_wait_relogin']) ? $args['logout_wait_relogin'] : OA_SINGLE_SIGN_ON_LOGOUT_WAIT_RELOGIN_DEFAULT);

    // Destroy Session on Logout
    $settings['logout_everywhere'] = ((isset($args['logout_everywhere']) && $args['logout_everywhere'] == 0) ? 0 : 1);

    // Debug Log.
    $settings['debug_log'] = (isset($args['debug_log']) ? $args['debug_log'] : 'disabled');
    $settings['debug_log'] = ((in_array($settings['debug_log'], array('enabled', 'disabled'))) ? $settings['debug_log'] : 'disabled');

    // Helper Settings.
    $settings['base_url'] = ($settings['api_subdomain'] . '.api.oneall.com');
    $settings['api_url'] = ($settings['connection_protocol'] . '://' . $settings['base_url']);

    // Done
    return $settings;
}

/**
 * Check if the current connection is being made over https.
 */
function oa_single_sign_on_https_on()
{
    if (!empty($_SERVER['SERVER_PORT']))
    {
        if (trim($_SERVER['SERVER_PORT']) == '443')
        {
            return true;
        }
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
    {
        if (strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO'])) == 'https')
        {
            return true;
        }
    }

    if (!empty($_SERVER['HTTPS']))
    {
        if (strtolower(trim($_SERVER['HTTPS'])) == 'on' or trim($_SERVER['HTTPS']) == '1')
        {
            return true;
        }
    }

    return false;
}

/**
 * Return the current url.
 */
function oa_single_sign_on_get_current_url()
{
    // Extract parts
    $request_uri = (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : $_SERVER['PHP_SELF']);
    $request_protocol = (oa_single_sign_on_https_on() ? 'https' : 'http');
    $request_host = (isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? $_SERVER['HTTP_X_FORWARDED_HOST'] : (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']));

    // Port of this request
    $request_port = '';

    // We are using a proxy
    if (isset($_SERVER['HTTP_X_FORWARDED_PORT']))
    {
        // SERVER_PORT is usually wrong on proxies, don't use it!
        $request_port = intval($_SERVER['HTTP_X_FORWARDED_PORT']);
    }
    // Does not seem like a proxy
    elseif (isset($_SERVER['SERVER_PORT']))
    {
        $request_port = intval($_SERVER['SERVER_PORT']);
    }

    // Remove standard ports
    $request_port = (!in_array($request_port, array(80, 443)) ? $request_port : '');

    // Add your own filters
    $request_port = apply_filters('oa_single_sign_on_filter_current_url_port', $request_port);
    $request_protocol = apply_filters('oa_single_sign_on_filter_current_url_protocol', $request_protocol);
    $request_host = apply_filters('oa_single_sign_on_filter_current_url_host', $request_host);
    $request_uri = apply_filters('oa_single_sign_on_filter_current_url_uri', $request_uri);

    // Build url
    $current_url = $request_protocol . '://' . $request_host . (!empty($request_port) ? (':' . $request_port) : '') . $request_uri;

    // Apply filters
    $current_url = apply_filters('oa_single_sign_on_filter_current_url', $current_url);

    // Done
    return $current_url;
}

/**
 * Return the list of disabled functions.
 */
function oa_single_sign_on_get_disabled_functions()
{
    $disabled_functions = trim(ini_get('disable_functions'));
    if (strlen($disabled_functions) == 0)
    {
        $disabled_functions = array();
    }
    else
    {
        $disabled_functions = explode(',', $disabled_functions);
        $disabled_functions = array_map('trim', $disabled_functions);
    }
    return $disabled_functions;
}

/**
 * Escape an attribute.
 */
function oa_single_sign_on_esc_attr($string)
{
    // Available since Wordpress 2.8
    if (function_exists('esc_attr'))
    {
        return esc_attr($string);
    }
    // Deprecated as of Wordpress 2.8
    elseif (function_exists('attribute_escape'))
    {
        return attribute_escape($string);
    }
    return htmlspecialchars($string);
}

/**
 * Log the user in.
 */
function oa_single_sign_login_user($user)
{
    // Set the cookie and login
    wp_clear_auth_cookie();
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);
}

/**
 * Get the user for a given email address.
 */
function oa_single_sign_on_get_user_for_email($email)
{
    // Read existing user.
    if (($userid = email_exists($email)) !== false)
    {
        return get_userdata($userid);
    }

    // Error
    return null;
}

/**
 * Create a random email address.
 */
function oa_single_sign_on_create_random_email($domain = 'example.com')
{
    do
    {
        $email = md5(uniqid(wp_rand(10000, 99000))) . '@' . $domain;
    }
    while ( email_exists($email) );

    // Done
    return $email;
}

/**
 * Get the user for a given user_token.
 */
function oa_single_sign_on_get_user_for_user_token($token)
{
    global $wpdb;

    // Sanitize token.
    $token = trim(strval($token));

    // The token is required.
    if (strlen($token) == 0)
    {
        return false;
    }

    // Read user for this token.
    $sql = "SELECT u.ID FROM " . $wpdb->usermeta . " AS um	INNER JOIN " . $wpdb->users . " AS u ON (um.user_id=u.ID)	WHERE um.meta_key = 'oa_single_sign_on_user_token' AND um.meta_value=%s";
    $userid = $wpdb->get_var($wpdb->prepare($sql, $token));

    // Do we have user
    if (!empty($userid))
    {
        return get_userdata($userid);
    }

    // Error
    return null;
}

/**
 * Get the token for a given userid.
 */
function oa_single_sign_on_get_token_by_userid($userid)
{
    global $wpdb;

    // Sanitize userid.
    $userid = intval($userid);

    // The userid is required.
    if (empty($userid) || $userid <= 0)
    {
        return false;
    }

    $sql = "SELECT um.meta_value FROM " . $wpdb->usermeta . " AS um	INNER JOIN " . $wpdb->users . " AS u ON (um.user_id=u.ID)	WHERE um.meta_key = 'oa_single_sign_on_user_token' AND u.ID=%d";
    return $wpdb->get_var($wpdb->prepare($sql, $userid));
}
