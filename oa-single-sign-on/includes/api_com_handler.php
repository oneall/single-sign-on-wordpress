<?php

/**
 * Send an API request by using the given handler
 */
function oa_single_sign_on_do_api_request ($handler, $url, $method = 'GET', $options = array(), $timeout = 25)
{
	// Proxy Settings
	if (defined('WP_PROXY_HOST') && defined ('WP_PROXY_PORT'))
	{
		$options['proxy_url'] = (defined('WP_PROXY_HOST') ? WP_PROXY_HOST : '');
		$options['proxy_port'] = (defined('WP_PROXY_PORT') ? WP_PROXY_PORT : '');
		$options['proxy_username'] = (defined('WP_PROXY_USERNAME') ? WP_PROXY_USERNAME : '');
		$options['proxy_password'] = (defined('WP_PROXY_PASSWORD') ? WP_PROXY_PASSWORD : '');
	}

	// FSOCKOPEN
	if (strtolower (trim($handler)) == 'fsockopen')
	{
		return oa_single_sign_on_do_fsockopen_request ($url, $method, $options, $timeout);
	}
	// CURL
	else
	{
		return oa_single_sign_on_do_curl_request ($url, $method, $options, $timeout);
	}
}

/**
 * Return the list of disabled PHP functions.
 */
function oa_single_sign_on_get_disabled_php_functions ()
{
	$disabled_functions = trim (ini_get ('disable_functions'));
	if (strlen ($disabled_functions) == 0)
	{
		$disabled_functions = array();
	}
	else
	{
		$disabled_functions = explode (',', $disabled_functions);
		$disabled_functions = array_map ('trim', $disabled_functions);
	}
	return $disabled_functions;
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// FSOCKOPEN
////////////////////////////////////////////////////////////////////////////////////////////////////////////////

/**
 * Send an fsockopen request.
 */
function oa_single_sign_on_do_fsockopen_request ($url, $method = 'GET', $options = array(), $timeout = 15)
{
	// Store the result
	$result = new stdClass ();

	// Make sure that this is a valid URL
	if (($uri = parse_url ($url)) == false)
	{
		$result->http_code = -1;
		$result->http_data = null;
		$result->http_error = 'invalid_uri';
		return $result;
	}

	// Make sure that we can handle the scheme
	switch ($uri ['scheme'])
	{
		case 'http' :
			$port = (isset ($uri ['port']) ? $uri ['port'] : 80);
			$host = ($uri ['host'] . ($port != 80 ? ':' . $port : ''));
			$fp = @fsockopen ($uri ['host'], $port, $errno, $errstr, $timeout);
			break;

		case 'https' :
			$port = (isset ($uri ['port']) ? $uri ['port'] : 443);
			$host = ($uri ['host'] . ($port != 443 ? ':' . $port : ''));
			$fp = @fsockopen ('ssl://' . $uri ['host'], $port, $errno, $errstr, $timeout);
			break;

		default :
			$result->http_code = -1;
			$result->http_data = null;
			$result->http_error = 'invalid_schema';
			return $result;
			break;
	}

	// Make sure that the socket has been opened properly
	if (!$fp)
	{
		$result->http_code = -$errno;
		$result->http_data = null;
		$result->http_error = trim ($errstr);
		return $result;
	}

	// Construct the path to act on
	$path = (isset ($uri ['path']) ? $uri ['path'] : '/');
	if (isset ($uri ['query']))
	{
		$path .= '?' . $uri ['query'];
	}

	// Send request headers.
	fwrite ($fp, strtoupper ($method) . " " . $path . " HTTP/1.1\r\n");
	fwrite ($fp, "Host: " . $host . "\r\n");
	fwrite ($fp, "User-Agent: " . oa_single_sign_on_get_agent() . "\r\n");

	// Add POST data ?
	if (isset ($options ['api_data']) && !empty ($options ['api_data']))
	{
		fwrite ($fp, "Content-length: " . strlen ($options ['api_data']) . "\r\n");
	}

	// Enable basic authentication?
	if (isset ($options ['api_key']) && isset ($options ['api_secret']))
	{
		fwrite ($fp, "Authorization: Basic " . base64_encode ($options ['api_key'] . ":" . $options ['api_secret']) . "\r\n");
	}

	// Close request.
	fwrite ($fp, "Connection: close\r\n\r\n");

	// Add POST data ?
	if (isset ($options ['api_data']))
	{
		fwrite ($fp, $options ['api_data']);
	}

	// Fetch response
	$response = '';
	while ( !feof ($fp) )
	{
		$response .= fread ($fp, 1024);
	}

	// Close connection
	fclose ($fp);

	// Parse response
	list ($response_header, $response_body) = explode ("\r\n\r\n", $response, 2);

	// Parse header
	$response_header = preg_split ("/\r\n|\n|\r/", $response_header);
	list ($header_protocol, $header_code, $header_status_message) = explode (' ', trim (array_shift ($response_header)), 3);

	// Build result
	$result->http_code = $header_code;
	$result->http_data = $response_body;

	// Done
	return $result;
}

/**
 * Check if fsockopen is available.
 */
function oa_single_sign_on_is_fsockopen_available ()
{
	// Make sure fsockopen has been loaded
	if (function_exists ('fsockopen') && function_exists ('fwrite'))
	{
		// Read the disabled functions
		$disabled_functions = oa_single_sign_on_get_disabled_php_functions ();

		// Make sure fsockopen has not been disabled
		if (!in_array ('fsockopen', $disabled_functions) and !in_array ('fwrite', $disabled_functions))
		{
			// Loaded and enabled
			return true;
		}
	}

	// Not loaded or disabled
	return false;
}

/**
 * Check if fsockopen is enabled and can be used to connect to OneAll.
 */
function oa_single_sign_on_is_api_connection_fsockopen_ok ($secure = true)
{
	if (oa_single_sign_on_is_fsockopen_available())
	{
		$result = oa_single_sign_on_do_api_request ('FSOCKOPEN', ($secure ? 'https' : 'http') . '://www.oneall.com/ping.html');
		if (is_object ($result) && property_exists ($result, 'http_code') && $result->http_code == 200)
		{
			if (property_exists ($result, 'http_data'))
			{
				if (strtolower ($result->http_data) == 'ok')
				{
					return true;
				}
			}
		}
	}
	return false;
}

////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// CURL
////////////////////////////////////////////////////////////////////////////////////////////////////////////////

/**
 * Send a CURL request.
 */
function oa_single_sign_on_do_curl_request ($url, $method = 'GET', $options = array(), $timeout = 15)
{
	// Store the result
	$result = new stdClass ();

	// Send request
	$curl = curl_init ();
	curl_setopt ($curl, CURLOPT_URL, $url);
	curl_setopt ($curl, CURLOPT_HEADER, 0);
	curl_setopt ($curl, CURLOPT_TIMEOUT, $timeout);
	curl_setopt ($curl, CURLOPT_VERBOSE, 0);
	curl_setopt ($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt ($curl, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt ($curl, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt ($curl, CURLOPT_USERAGENT, oa_single_sign_on_get_agent());

	// HTTP Method
	switch (strtoupper ($method))
	{
		case 'DELETE' :
			curl_setopt ($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
			break;

		case 'PUT' :
			curl_setopt ($curl, CURLOPT_CUSTOMREQUEST, 'PUT');
			break;

		case 'POST' :
			curl_setopt ($curl, CURLOPT_POST, 1);
			break;

		default :
			curl_setopt ($curl, CURLOPT_HTTPGET, 1);
			break;
	}

	// HTTP AUTH
	if (isset ($options ['api_key']) and isset ($options ['api_secret']))
	{
		curl_setopt ($curl, CURLOPT_USERPWD, $options ['api_key'] . ":" . $options ['api_secret']);
	}

	// POST Data
	if (isset ($options ['api_data']))
	{
		curl_setopt ($curl, CURLOPT_POSTFIELDS, $options ['api_data']);
	}

	// Make request
	if (($http_data = curl_exec ($curl)) !== false)
	{
		$result->http_code = curl_getinfo ($curl, CURLINFO_HTTP_CODE);
		$result->http_data = $http_data;
		$result->http_error = null;
	}
	else
	{
		$result->http_code = -1;
		$result->http_data = null;
		$result->http_error = curl_error ($curl);
	}

	// Done
	return $result;
}

/**
 * Check if CURL has been loaded and is not disabled.
 */
function oa_single_sign_on_is_curl_available ()
{
	// Make sure CURL has been loaded.
	if (in_array ('curl', get_loaded_extensions ()) && function_exists ('curl_init') && function_exists ('curl_exec'))
	{
		// Read the disabled functions.
		$disabled_functions = oa_single_sign_on_get_disabled_php_functions ();

		// Make sure CURL has not been disabled.
		if (!in_array ('curl_init', $disabled_functions) && !in_array ('curl_exec', $disabled_functions))
		{
			// Loaded and enabled.
			return true;
		}
	}

	// Not loaded or disabled.
	return false;
}

/**
 * Check if CURL is available and can be used to connect to OneAll
 */
function oa_single_sign_on_is_api_connection_curl_ok ($secure = true)
{
	// Is CURL available and enabled?
	if (oa_single_sign_on_is_curl_available ())
	{
		// Make a request to the OneAll API.
		$result = oa_single_sign_on_do_api_request ('CURL', ($secure ? 'https' : 'http') . '://www.oneall.com/ping.html');
		if (is_object ($result) && property_exists ($result, 'http_code') && $result->http_code == 200)
		{
			if (property_exists ($result, 'http_data'))
			{
				if (strtolower ($result->http_data) == 'ok')
				{
					return true;
				}
			}
		}
	}
	return false;
}
