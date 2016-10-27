<?php

/**
 * Check if the current connection is being made over https
 */
function oa_single_sign_on_https_on ()
{
	if (!empty ($_SERVER ['SERVER_PORT']))
	{
		if (trim ($_SERVER ['SERVER_PORT']) == '443')
		{
			return true;
		}
	}

	if (!empty ($_SERVER ['HTTP_X_FORWARDED_PROTO']))
	{
		if (strtolower (trim ($_SERVER ['HTTP_X_FORWARDED_PROTO'])) == 'https')
		{
			return true;
		}
	}

	if (!empty ($_SERVER ['HTTPS']))
	{
		if (strtolower (trim ($_SERVER ['HTTPS'])) == 'on' OR trim ($_SERVER ['HTTPS']) == '1')
		{
			return true;
		}
	}

	return false;
}


/**
 * Return the current url
 */
function oa_single_sign_on_get_current_url ()
{
	//Extract parts
	$request_uri = (isset ($_SERVER ['REQUEST_URI']) ? $_SERVER ['REQUEST_URI'] : $_SERVER ['PHP_SELF']);
	$request_protocol = (oa_single_sign_on_https_on () ? 'https' : 'http');
	$request_host = (isset ($_SERVER ['HTTP_X_FORWARDED_HOST']) ? $_SERVER ['HTTP_X_FORWARDED_HOST'] : (isset ($_SERVER ['HTTP_HOST']) ? $_SERVER ['HTTP_HOST'] : $_SERVER ['SERVER_NAME']));

	//Port of this request
	$request_port = '';

	//We are using a proxy
	if (isset ($_SERVER ['HTTP_X_FORWARDED_PORT']))
	{
		// SERVER_PORT is usually wrong on proxies, don't use it!
		$request_port = intval ($_SERVER ['HTTP_X_FORWARDED_PORT']);
	}
	//Does not seem like a proxy
	elseif (isset ($_SERVER ['SERVER_PORT']))
	{
		$request_port = intval ($_SERVER ['SERVER_PORT']);
	}

	// Remove standard ports
	$request_port = (!in_array ($request_port, array (80, 443)) ? $request_port : '');

	//Add your own filters
	$request_port = apply_filters ('oa_single_sign_on_filter_current_url_port', $request_port);
	$request_protocol = apply_filters ('oa_single_sign_on_filter_current_url_protocol', $request_protocol);
	$request_host = apply_filters ('oa_single_sign_on_filter_current_url_host', $request_host);
	$request_uri = apply_filters ('oa_single_sign_on_filter_current_url_uri', $request_uri);

	//Build url
	$current_url = $request_protocol . '://' . $request_host . ( ! empty ($request_port) ? (':'.$request_port) : '') . $request_uri;

	//Remove the oa_single_sign_on_source argument
	if (strpos ($current_url, 'oa_single_sign_on_source') !== false)
	{
		//Break up url
		list($url_part, $query_part) = array_pad (explode ('?', $current_url), 2, '');
		parse_str ($query_part, $query_vars);

		//Remove oa_single_sign_on_source argument
		if (is_array ($query_vars) AND isset ($query_vars ['oa_single_sign_on_source']))
		{
			unset ($query_vars ['oa_single_sign_on_source']);
		}

		//Build new url
		$current_url = $url_part . ((is_array ($query_vars) AND count ($query_vars) > 0) ? ('?' . http_build_query ($query_vars)) : '');
	}

	//Apply filters
	$current_url = apply_filters ('oa_single_sign_on_filter_current_url', $current_url);

	//Done
	return $current_url;
}


/**
 * Return the list of disabled functions.
 */
function oa_single_sign_on_get_disabled_functions ()
{
	$disabled_functions = trim (ini_get ('disable_functions'));
	if (strlen ($disabled_functions) == 0)
	{
		$disabled_functions = array ();
	}
	else
	{
		$disabled_functions = explode (',', $disabled_functions);
		$disabled_functions = array_map ('trim', $disabled_functions);
	}
	return $disabled_functions;
}


/**
 * Escape an attribute
 */
function oa_single_sign_on_esc_attr ($string)
{
	//Available since Wordpress 2.8
	if (function_exists ('esc_attr'))
	{
		return esc_attr ($string);
	}
	//Deprecated as of Wordpress 2.8
	elseif (function_exists ('attribute_escape'))
	{
		return attribute_escape ($string);
	}
	return htmlspecialchars ($string);
}


/**
 * Get the userid for a given token
 */
function oa_single_sign_on_get_userid_by_token ($token)
{
	global $wpdb;

	// Sanitize token.
	$token = trim (strval ($token));

	// The token is required.
	if (strlen ($token) == 0)
	{
		return false;
	}

	// Read user for this token.
	$sql = "SELECT u.ID FROM " . $wpdb->usermeta . " AS um	INNER JOIN " . $wpdb->users . " AS u ON (um.user_id=u.ID)	WHERE um.meta_key = 'oa_single_sign_on_user_token' AND um.meta_value=%s";
	return $wpdb->get_var ($wpdb->prepare ($sql, $token));
}


/**
 * Get the token for a given userid
 */
function oa_single_sign_on_get_token_by_userid ($userid)
{
	global $wpdb;
	$sql = "SELECT um.meta_value FROM " . $wpdb->usermeta . " AS um	INNER JOIN " . $wpdb->users . " AS u ON (um.user_id=u.ID)	WHERE um.meta_key = 'oa_single_sign_on_user_token' AND u.ID=%d";
	return $wpdb->get_var ($wpdb->prepare ($sql, $userid));
}