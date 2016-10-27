<?php

/**
 * Send an API request by using the given handler
 */
function oa_single_sign_on_do_api_request ($handler, $url, $opts = array (), $timeout = 25)
{
	// Proxy Settings
	if (defined('WP_PROXY_HOST') && defined ('WP_PROXY_PORT'))
	{
		$opts['proxy_url'] = (defined('WP_PROXY_HOST') ? WP_PROXY_HOST : '');
		$opts['proxy_port'] = (defined('WP_PROXY_PORT') ? WP_PROXY_PORT : '');
		$opts['proxy_username'] = (defined('WP_PROXY_USERNAME') ? WP_PROXY_USERNAME : '');
		$opts['proxy_password'] = (defined('WP_PROXY_PASSWORD') ? WP_PROXY_PASSWORD : '');
	}
	
	//FSOCKOPEN
	if ($handler == 'fsockopen')
	{
		return oa_single_sign_on_fsockopen_request ($url, $opts, $timeout);
	}
	//CURL
	else
	{
		return oa_single_sign_on_curl_request ($url, $opts, $timeout);
	}
}

/**
 * **************************************************************************************************************
 * ************************************************* FSOCKOPEN **************************************************
 * **************************************************************************************************************
 */

/**
 * Check if fsockopen is available.
 */
function oa_single_sign_on_check_fsockopen_available ()
{
	//Make sure fsockopen has been loaded
	if (function_exists ('fsockopen') AND function_exists ('fwrite'))
	{
		$disabled_functions = oa_single_sign_on_get_disabled_functions ();

		//Make sure fsockopen has not been disabled
		if (!in_array ('fsockopen', $disabled_functions) AND !in_array ('fwrite', $disabled_functions))
		{
			//Loaded and enabled
			return true;
		}
	}

	//Not loaded or disabled
	return false;
}

/**
 * Check if fsockopen is enabled and can be used to connect to OneAll.
 */
function oa_single_sign_on_check_fsockopen ($secure = true)
{
	if (oa_single_sign_on_check_fsockopen_available ())
	{
		$result = oa_single_sign_on_do_api_request ('fsockopen', ($secure ? 'https' : 'http') . '://www.oneall.com/ping.html');
		if (is_object ($result) AND property_exists ($result, 'http_code') AND $result->http_code == 200)
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

/**
 * Send an fsockopen request.
 */
function oa_single_sign_on_fsockopen_request ($url, $options = array (), $timeout = 15)
{
	//Store the result
	$result = new stdClass ();

    //Make sure that this is a valid URL
    if (($uri = parse_url ($url)) === false)
    {
        $result->http_error = 'invalid_uri';
        return $result;
    }	

    //Check the scheme
    if ($uri ['scheme'] == 'https')
    {
        $port = (isset ($uri ['port']) ? $uri ['port'] : 443);
        $url = ($uri ['host'] . ($port != 443 ? ':' . $port : ''));
        $url_protocol = 'https://';
        $url_prefix = 'ssl://';
    }
    else
    {
        $port = (isset ($uri ['port']) ? $uri ['port'] : 80);
        $url = ($uri ['host'] . ($port != 80 ? ':' . $port : ''));
        $url_protocol = 'http://';
        $url_prefix = '';
    }
    
    //Construct the path to act on
    $path = (isset ($uri ['path']) ? $uri ['path'] : '/').( ! empty ($uri ['query']) ? ('?'.$uri ['query']) : '');
    
	//HTTP Headers
    $headers = array();
     
    // We are using a proxy
    if (! empty ($options ['proxy_url']) && ! empty ($options ['proxy_port']))
    {
    	// Open Socket
    	$fp = @fsockopen ($options ['proxy_url'], $options ['proxy_port'], $errno, $errstr, $timeout);
    
    	//Make sure that the socket has been opened properly
    	if (!$fp)
    	{
    		$result->http_error = trim ($errstr);
    		return $result;
    	}
    
    	// HTTP Headers
    	$headers[] = "GET " . $url_protocol . $url . $path . " HTTP/1.0";
    	$headers[] = "Host: " . $url . ":" . $port;
    
    	// Proxy Authentication
    	if ( ! empty ($options ['proxy_username']) && ! empty ($options ['proxy_password']))
    	{
    		$headers [] = 'Proxy-Authorization: Basic ' . base64_encode ($options ['proxy_username'] . ":" . $options ['proxy_password']);
    	}
    
    }
    // We are not using a proxy
    else
    {
    	// Open Socket
    	$fp = @fsockopen ($url_prefix . $url, $port, $errno, $errstr, $timeout);
    
    	//Make sure that the socket has been opened properly
    	if (!$fp)
    	{
    		$result->http_error = trim ($errstr);
    		return $result;
    	}
    
    	// HTTP Headers
    	$headers[] = "GET " . $path." HTTP/1.0";
    	$headers[] = "Host: " . $url;
    }
    
    //Enable basic authentication
    if (isset ($options ['api_key']) AND isset ($options ['api_secret']))
    {
    	$headers [] = 'Authorization: Basic ' . base64_encode ($options ['api_key'] . ":" . $options ['api_secret']);
    }
    
    //Build and send request
    fwrite ($fp, (implode ("\r\n", $headers). "\r\n\r\n"));
    
    //Fetch response
    $response = '';
    while (!feof ($fp))
    {
    	$response .= fread ($fp, 1024);
    }
    
    //Close connection
    fclose ($fp);
    
    //Parse response
    list($response_header, $response_body) = explode ("\r\n\r\n", $response, 2);
    
    //Parse header
    $response_header = preg_split ("/\r\n|\n|\r/", $response_header);
    list($header_protocol, $header_code, $header_status_message) = explode (' ', trim (array_shift ($response_header)), 3);
    
    //Build result
    $result->http_code = $header_code;
    $result->http_data = $response_body;
    
    //Done
    return $result;
}

/**
 * **************************************************************************************************************
 ** *************************************************** CURL ****************************************************
 * **************************************************************************************************************
 */

/**
 * Check if cURL has been loaded and is enabled.
 */
function oa_single_sign_on_check_curl_available ()
{
	//Make sure cURL has been loaded
	if (in_array ('curl', get_loaded_extensions ()) AND function_exists ('curl_init') AND function_exists ('curl_exec'))
	{
		$disabled_functions = oa_single_sign_on_get_disabled_functions ();

		//Make sure cURL not been disabled
		if (!in_array ('curl_init', $disabled_functions) AND !in_array ('curl_exec', $disabled_functions))
		{
			//Loaded and enabled
			return true;
		}
	}

	//Not loaded or disabled
	return false;
}

/**
 * Check if CURL is available and can be used to connect to OneAll
 */
function oa_single_sign_on_check_curl ($secure = true)
{
	if (oa_single_sign_on_check_curl_available ())
	{
		$result = oa_single_sign_on_do_api_request ('curl', ($secure ? 'https' : 'http') . '://www.oneall.com/ping.html');
		if (is_object ($result) AND property_exists ($result, 'http_code') AND $result->http_code == 200)
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

/**
 * Send a CURL request.
 */
function oa_single_sign_on_curl_request ($url, $options = array (), $timeout = 15)
{
	//Store the result
	$result = new stdClass ();

	//Send request
	$curl = curl_init ();
	curl_setopt ($curl, CURLOPT_URL, $url);
	curl_setopt ($curl, CURLOPT_HEADER, 0);
	curl_setopt ($curl, CURLOPT_TIMEOUT, $timeout);
	curl_setopt ($curl, CURLOPT_VERBOSE, 0);
	curl_setopt ($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt ($curl, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt ($curl, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt ($curl, CURLOPT_USERAGENT, 'SingleSignOn ' . OA_SINGLE_SIGN_ON_VERSION . 'WP (+http://www.oneall.com/)');
	
	// BASIC AUTH?
	if (isset ($options ['api_key']) AND isset ($options ['api_secret']))
	{
		curl_setopt ($curl, CURLOPT_USERPWD, $options ['api_key'] . ":" . $options ['api_secret']);
	}
	
	// Proxy Settings
	if ( ! empty ($options ['proxy_url']) && ! empty ($options ['proxy_port']))
	{
		// Proxy Location
		curl_setopt ($curl, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
		curl_setopt ($curl, CURLOPT_PROXY, $options ['proxy_url']);
			
		// Proxy Port
		curl_setopt ($curl, CURLOPT_PROXYPORT, $options ['proxy_port']);		
	
		// Proxy Authentication
		if ( ! empty ($options ['proxy_username']) && ! empty ($options ['proxy_password']))
		{
			curl_setopt ($curl, CURLOPT_PROXYAUTH, CURLAUTH_ANY);
			curl_setopt ($curl, CURLOPT_PROXYUSERPWD, $options ['proxy_username'] . ':' . $options ['proxy_password']);
		}
	}

	//Make request
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

	//Done
	return $result;
}