<?php


// Sends a request to the OneAll API
function oa_single_sign_on_curl_request1 ($operation, $args = array ())
{
	// Read Social Login settings
	$settings = get_option ('oa_social_login_settings');

	//API Settings
	$api_connection_handler = ((!empty ($settings ['api_connection_handler']) AND $settings ['api_connection_handler'] == 'fsockopen') ? 'fsockopen' : 'curl');
	$api_connection_use_https = ((!isset ($settings ['api_connection_use_https']) OR $settings ['api_connection_use_https'] == '1') ? true : false);
	$api_subdomain = (!empty ($settings ['api_subdomain']) ? trim ($settings ['api_subdomain']) : '');
		
	//We cannot make a connection without a subdomain
	if (!empty ($api_subdomain))
	{
		//API Credentials
		$api_opts = array ();
		$api_opts['api_key'] = (!empty ($settings ['api_key']) ? $settings ['api_key'] : '');
		$api_opts['api_secret'] = (!empty ($settings ['api_secret']) ? $settings ['api_secret'] : '');
			
		switch ($operation)
		{
			// http://docs.oneall.com/api/resources/sso/list-all-sessions/
			case 'list_sso_sessions':
				$api_resource_url = ($api_connection_use_https ? 'https' : 'http') . '://' . $api_subdomain . '.api.oneall.com/sso/sessions.json';
				
				// Method
				$api_resource_method = 'GET';
			break;
		
			//	http://docs.oneall.com/api/resources/sso/start-session/		
			case 'start_sso_session':		
				$api_resource_url = ($api_connection_use_https ? 'https' : 'http') . '://' . $api_subdomain . '.api.oneall.com/sso/sessions/identities/'.$args['identity_token'].'.json';
		
				// Method
				$api_resource_method = 'PUT';			
			break;
			
			// http://docs.oneall.com/api/resources/sso/destroy-session/
			case 'destroy_sso_session':
				$api_resource_url = ($api_connection_use_https ? 'https' : 'http') . '://' . $api_subdomain . '.api.oneall.com/sso/sessions/identities/'.$args['identity_token'].'.json?confirm_deletion=true';
				
				// Method
				$api_resource_method = 'DELETE';
			break;
		}
				
		//Send request
		$curl = curl_init ();
		curl_setopt ($curl, CURLOPT_URL, $api_resource_url);
		curl_setopt ($curl, CURLOPT_HEADER, 0);
		curl_setopt ($curl, CURLOPT_TIMEOUT, 15);
		curl_setopt ($curl, CURLOPT_VERBOSE, 0);		
		curl_setopt ($curl, CURLOPT_CUSTOMREQUEST, $api_resource_method);
		curl_setopt ($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt ($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt ($curl, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt ($curl, CURLOPT_USERAGENT, 'SingleSignOn 1.0 WP (+http://www.oneall.com/)');
		curl_setopt ($curl, CURLOPT_USERPWD, $api_opts ['api_key'] . ":" . $api_opts ['api_secret']);

		//Make request
		if (($http_data = curl_exec ($curl)) !== false)
		{
			return @json_decode ($http_data);
		}
		else
		{
			return null;
		}
	}

	//Done
	return null;
}

// Initialize
function oa_single_sign_on_init ()
{
	//Add language file.
	if (function_exists ('load_plugin_textdomain'))
	{
		load_plugin_textdomain (' oa_single_sign_on', false, OA_SINGLE_SIGN_ON_BASE_PATH . '/languages/');
	}
	
	
	if (session_id () == "")
	{
		session_start ();
	}
	
	// Read Social Login settings
	$settings = get_option ('oa_social_login_settings');
	
	// Make sure Social Login exists and has been setup
	if (function_exists ('oa_social_login_callback'))
	{
		if (!empty ($settings ['api_key']) && !empty ($settings ['api_secret']) && !empty ($settings ['api_subdomain']))
		{
			// Automatic Login
			if (!empty ($_POST ['connection_token']) && !empty ($_POST ['oa_action']) && $_POST ['oa_action'] == 'single_sign_on')
			{
				$_POST ['oa_action'] = "social_login";
				oa_social_login_callback ();
			}
			// Check Session
			else
			{
				oa_single_sign_on_check ();
			}
		}
	}
}

// Checks if SSO session is still valid
function oa_single_sign_on_check ()
{
	if (session_id () == "")
	{
		session_start ();
	}
	
	// Make sure we have the required information
	if ( ! empty ($_SESSION ['oa_sso_expires']) && ! empty ($_SESSION ['oa_sso_session_token']))
	{
		// Check if the session is expired
		if ($_SESSION ['oa_sso_expires'] < time())
		{		
			// Read session
			$result = oa_single_sign_on_curl_request ('get_sso_session', array ('sso_session_token' => $_SESSION['oa_sso_session_token']));
			
			//Check result
			if (is_object ($result) && ! empty ($result->response->result->data->sso_session->sso_session_token))
			{
				$oa_sso_expires = strtotime ($result->response->result->data->sso_session->date_expiration);
				
				// Update date
				if ($_SESSION ['oa_sso_expires'] <> $oa_sso_expires)
				{
					$_SESSION ['oa_sso_expires'] = $oa_sso_expires;
				}
			}
			else
			{
				wp_logout ();
				wp_redirect (home_url ());
				exit ();
			}
		}
	}
}

// Starts the session when a user logs in with Social Login
function oa_single_sign_on_start_session ($user_data, $identity, $redirect_to)
{
	if (session_id () == "")
	{
		session_start ();
	}

	// Read token
	$sso_session = oa_single_sign_on_get_session_for_identity_token ($identity->identity_token);
		
	// Start new session
	if ($sso_session === false)
	{
		//Retrieve connection details
		$result = oa_single_sign_on_curl_request ('start_sso_session', array ('identity_token' => $identity->identity_token));
		
		//Check result
		if (is_object ($result) && ! empty ($result->response->result->data->sso_session->sso_session_token))
		{
			$_SESSION ['oa_sso_session_token'] = $result->response->result->data->sso_session->sso_session_token;
			$_SESSION ['oa_sso_identity_token'] = $identity->identity_token;
			$_SESSION ['oa_sso_expires'] = strtotime ($result->response->result->data->sso_session->date_expiration);
		}
	}
	else
	{ 
		$_SESSION ['oa_sso_session_token'] = $sso_session->sso_session_token;
		$_SESSION ['oa_sso_identity_token'] = $sso_session->identity_token;
		$_SESSION ['oa_sso_expires'] = strtotime ($sso_session->date_expiration);
	}
}
add_action('oa_social_login_action_before_user_redirect', 'oa_single_sign_on_start_session', 10, 3);


// Returns the sso_session for a given identity_token
function oa_single_sign_on_get_session_for_identity_token ($identity_token)
{	
	//Retrieve connection details
	$result = oa_single_sign_on_curl_request ('list_sso_sessions');	
			
	// Check result
	if (is_object ($result) AND isset ($result->response->result->data->sso_sessions->entries))
	{
		foreach ($result->response->result->data->sso_sessions->entries as $sso_session)
		{				
			if ($sso_session->identity_token == $identity_token)
			{
				// Session found
				return $sso_session;
			}
		}		
		
	}
	
	// No session found
	return false;
}


// Destroys the SSO session when the users logs out
function oa_single_sign_on_logout ()
{
	if (session_id () == "")
	{
		session_start ();
	}
	
	// Make sure we have an identity token
	if ( ! empty ($_SESSION['oa_sso_identity_token']))
	{
		$result = oa_single_sign_on_curl_request ('destroy_sso_session', array ('identity_token' => $_SESSION['oa_sso_identity_token']));
	}	
}
add_action('wp_logout', 'oa_single_sign_on_logout');


// Javascript
function oa_single_sign_on_frontend_scripts ()
{
	if (session_id () == "")
	{
		session_start ();
	}
	
	// Token
	$sso_session_token = ( ! empty ($_SESSION['oa_sso_session_token']) ? $_SESSION['oa_sso_session_token'] : '');
	
	?>
		<script type="text/javascript">
			var sso_session_token = '<?php echo $sso_session_token; ?>'; 
			var _oneall = window._oneall || [];
			if (typeof sso_session_token === 'string' && sso_session_token.length > 0) {		
				_oneall.push(['single_sign_on', 'do_register_sso_session', sso_session_token]);
			} else {	
				_oneall.push(['single_sign_on', 'set_callback_uri',  window.location.href]);   
				_oneall.push(['single_sign_on', 'do_check_for_sso_session']);
			}
		</script>
	<?php
}
add_action('wp_enqueue_scripts', 'oa_single_sign_on_frontend_scripts');
