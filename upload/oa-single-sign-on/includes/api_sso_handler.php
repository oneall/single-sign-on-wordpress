<?php

/**
 * Update the given user's password in his cloud storage.
 */
function oa_single_sign_on_update_user_in_cloud($user, $password = null)
{
    // Result Container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Read settings.
    $ext_settings = oa_single_sign_on_get_settings();

    // We cannot make a connection without the subdomain.
    if (!empty($ext_settings['api_subdomain']))
    {
        // Check user.
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // We have the user, check if he has tokens.
            $tokens = oa_single_sign_on_get_local_storage_tokens_for_user($user);

            // Yes, we have a token.
            if ($tokens->have_been_retrieved)
            {
                // Build data.
                $request = array(
                    'update_mode' => 'replace',
                    'user' => array()
                );

                // Password updated.
                if (!empty($password))
                {
                    $request['user']['password'] = oa_single_sign_on_hash_string($password);
                }

                // Identity.
                $request['user']['identity'] = array(
                    'preferredUsername' => $user->user_login,
                    'displayName' => (!empty($user->display_name) ? $user->display_name : $user->user_login)
                );

                // Names.
                if (!empty(($user->first_name) || !empty($user->last_name)))
                {
                    $request['user']['identity']['name'] = array();

                    // First Name.
                    if (!empty($user->first_name))
                    {
                        $request['user']['identity']['name']['givenName'] = $user->first_name;
                    }

                    // Last Name.
                    if (!empty($user->last_name))
                    {
                        $request['user']['identity']['name']['familyName'] = $user->last_name;
                    }
                }

                // About Me.
                if (!empty($user->description))
                {
                    $request['user']['identity']['aboutMe'] = $user->description;
                }

                // User Avatar.
                $user_avatar = get_avatar_data($user->ID);
                if (!empty($user_avatar['url']))
                {
                    $request['user']['identity']['thumbnailUrl'] = $user_avatar['url'];
                }

                // User Roles.
                if (isset($user->roles) && is_array($user->roles) && count($user->roles) > 0)
                {
                    $request['user']['identity']['roles'] = array();
                    foreach ($user->roles as $role)
                    {
                        $request['user']['identity']['roles'][] = array(
                            'value' => $role
                        );
                    }
                }

                // User email.
                if (!empty($user->user_email))
                {
                    $request['user']['identity']['emails'] = array(
                        array(
                            'value' => $user->user_email,
                            'is_verified' => true
                        )
                    );
                }

                // API endpoint: http://docs.oneall.com/api/resources/storage/users/update-user/
                $api_resource_url = $ext_settings['api_url'] . '/storage/users/' . $tokens->user_token . '.json';

                // API options.
                $api_options = array(
                    'api_key' => $ext_settings['api_key'],
                    'api_secret' => $ext_settings['api_secret'],
                    'api_data' => @json_encode(array(
                        'request' => $request
                    ))
                );

                // Update user.
                $result = oa_single_sign_on_do_api_request($ext_settings['api_connection_handler'], $api_resource_url, 'PUT', $api_options);

                // Check result.
                if (is_object($result) && property_exists($result, 'http_code') && $result->http_code == 200)
                {
                    // Update status.
                    $status->action = 'customer_cloud_storage_password_updated';
                    $status->is_successfull = true;

                    // Add log.
                    oa_single_sign_on_add_log('[UPDATE CLOUD USER] Profile for user [' . $user->ID . ', ' . $tokens->user_token . '] updated in cloud storage');
                }
                else
                {
                    $status->action = 'http_request_failed';
                }
            }
            // No cloud storage user.
            else
            {
                $status->action = 'user_not_in_cloud_storage';
            }
        }
        // Invalid user specified.
        else
        {
            $status->action = 'invalid_data_object';
        }
    }
    // Extension not setup.
    else
    {
        $status->action = 'extension_not_setup';
    }

    // Done

    return $status;
}

/**
 * Lookup a user's credentials in the cloud storage.
 */
function oa_single_sign_on_lookup_user_auth_cloud($field, $value, $password)
{
    // Result container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Read settings.
    $ext_settings = oa_single_sign_on_get_settings();

    // We cannot make a connection without a subdomain.
    if (!empty($ext_settings['api_subdomain']))
    {
        // Load user.
        $user = get_user_by($field, $value);
        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
        {
            // Add log.
            oa_single_sign_on_add_log('[TRY CLOUD LOGIN] [UID' . $user->ID . '] Trying login with [' . $field . ':{' . $value . '}]');

            // We have the user, check if he has tokens
            $tokens = oa_single_sign_on_get_local_storage_tokens_for_user($user);

            // Yes, we have a token.
            if ($tokens->have_been_retrieved)
            {
                // API endpoint: http://docs.oneall.com/api/resources/storage/users/lookup-user/
                $api_resource_url = $ext_settings['api_url'] . '/storage/users/user/lookup.json';

                // API options.
                $api_options = array(
                    'api_key' => $ext_settings['api_key'],
                    'api_secret' => $ext_settings['api_secret'],
                    'api_data' => @json_encode(array(
                        'request' => array(
                            'user' => array(
                                'user_token' => $tokens->user_token,
                                'password' => oa_single_sign_on_hash_string($password)
                            )
                        )
                    ))
                );

                // Read connection details.
                $result = oa_single_sign_on_do_api_request($ext_settings['api_connection_handler'], $api_resource_url, 'POST', $api_options);

                // Check result.
                if (is_object($result) && property_exists($result, 'http_code') && property_exists($result, 'http_data'))
                {
                    // Decode result.
                    $decoded_result = @json_decode($result->http_data);

                    // Wrong password entered.
                    if ($result->http_code == 401)
                    {
                        // Add log.
                        oa_single_sign_on_add_log('[TRY CLOUD LOGIN] [UID' . $user->ID . '] Login failed, falling back to native authentication');
                    }
                    // Correct password entered.
                    elseif ($result->http_code == 200)
                    {
                        // Add Log.
                        oa_single_sign_on_add_log('[TRY CLOUD LOGIN] [UID' . $user->ID . '] Login succeeded, user_token [' . $tokens->user_token . '] assigned');

                        // Update status
                        $status->is_successfull = true;
                        $status->user = $user;

                        // Done.
                        return $status;
                    }
                }
            }
            else
            {
                // Add log.
                oa_single_sign_on_add_log('[TRY CLOUD LOGIN] [UID' . $user->ID . '] User has no local tokens, falling back to native authentication');
            }
        }
    }
    else
    {
        $status->action = 'extension_not_setup';
    }

    // Not found
    return $status;
}

/**
 * Lookup a user's login and password in the cloud storage.
 */
function oa_single_sign_on_lookup_user($login, $password)
{
    // Result container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Login using an email address.
    if (filter_var($login, FILTER_VALIDATE_EMAIL))
    {
        // Lookup using an email address.
        $result = oa_single_sign_on_lookup_user_auth_cloud('email', $login, $password);

        // Found user for the email/password.
        if ($result->is_successfull === true)
        {
            // Add Log.
            oa_single_sign_on_add_log('[LOOKUP USER] [UID' . $result->user->ID . '] User found for email [{' . $login . '}]');

            // Update status.
            $status->is_successfull = true;
            $status->user = $result->user;
            $status->field = 'email';
            $status->value = $login;

            // Done.
            return $status;
        }
    }

   // Lookup using a login.
    $result = oa_single_sign_on_lookup_user_auth_cloud('login', $login, $password);

    // Found user for the email/password.
    if ($result->is_successfull === true)
    {
        // Add log.
        oa_single_sign_on_add_log('[LOOKUP USER] [UID' . $result->user->ID . '] User found for login [{' . $login . '}]');

        // Update status.
        $status->is_successfull = true;
        $status->user = $result->user;
        $status->field = 'email';
        $status->value = $login;

        // Done.
        return $status;
    }

    // Error.
    return $status;
}

/**
 * End the single sign-on session for the given user
 */
function oa_single_sign_on_end_session_for_user($user)
{
    // Result container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Add log.
    oa_single_sign_on_add_log('[END SESSION] [UID' . $user->ID . '] Removing session token');

    // Read local storage.
    $tokens = oa_single_sign_on_get_local_storage_tokens_for_user($user);
    if ($tokens->have_been_retrieved)
    {
        // Remove session data from WordPress.
        $remove_local_session = oa_single_sign_on_remove_local_sso_session_token_for_user($user);

        // Remove session data from Cloud.
        $remove_distant_session = oa_single_sign_on_remove_session_for_identity_token($tokens->identity_token);

        // Removed.
        if ($remove_distant_session->is_successfull === true)
        {
            // Success
            $status->is_successfull = true;

            // Add log.
            oa_single_sign_on_add_log('[END SESSION] [UID' . $user->ID . '] Session token removed');
        }
    }

    // Done.
    return $status;
}

/**
 * Start a new single sign-on session for the given user
 */
function oa_single_sign_on_start_session_for_user($user, $retry_if_invalid = true)
{
    // Result container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Read the user's sso session token
    $tokens = oa_single_sign_on_get_local_storage_tokens_for_user($user);

    // User has no tokens yet.
    if (!$tokens->have_been_retrieved)
    {
        // Add log.
        oa_single_sign_on_add_log('[START SESSION] [UID' . $user->ID . '] User has no tokens. Creating tokens.');

        // Add user to cloud storage.
        $add_user = oa_single_sign_on_add_user_to_cloud_storage($user);

        // User added.
        if ($add_user->is_successfull === true)
        {
            // Update status.
            $status->identity_token = $add_user->identity_token;
            $status->user_token = $add_user->user_token;

            // Add log.
            oa_single_sign_on_add_log('[START SESSION] [UID' . $user->ID . '] Tokens created, user_token [' . $status->user_token . '] identity_token [' . $status->identity_token . ']');

            // Add to database.
            $add_tokens = oa_single_sign_on_add_local_storage_tokens_for_user($user, $status->user_token, $status->identity_token);
        }
    }
    // User has already tokens.
    else
    {
        // Update status.
        $status->identity_token = $tokens->identity_token;
        $status->user_token = $tokens->user_token;

        // Add log.
        oa_single_sign_on_add_log('[START SESSION] [UID' . $user->ID . '] User has already tokens, user_token [' . $status->user_token . '] identity_token [' . $status->identity_token . ']');
    }

    // Start a new session.
    if (!empty($status->identity_token))
    {
        // Add log.
        oa_single_sign_on_add_log('[START SESSION] [UID' . $user->ID . '] Starting session');

        // Start a new session.
        $start_session = oa_single_sign_on_start_session_for_identity_token($status->identity_token);

        // Session started.
        if ($start_session->is_successfull === true)
        {
            // Update Status
            $status->sso_session_token = $start_session->sso_session_token;
            $status->date_expiration = $start_session->date_expiration;
            $status->is_successfull = true;

            // Add Log.
            oa_single_sign_on_add_log('[START SESSION] [UID' . $user->ID . '] Session created, sso_session_token [' . $status->sso_session_token . ']');

            // Store session data.
            oa_single_sign_on_add_local_sso_session_token_for_user($user, $status->sso_session_token, $status->date_expiration);
        }
        else
        {
            // Invalid identity
            if ($start_session->action == 'invalid_identity_token')
            {
                // Add log.
                oa_single_sign_on_add_log('[START SESSION] [UID' . $user->ID . '] Removing invalid token');

                // Remove Tokens.
                oa_single_sign_on_remove_local_storage_tokens_for_user($user);

                // Retry?
                if ($retry_if_invalid)
                {
                    return oa_single_sign_on_start_session_for_user($user, false);
                }
            }
        }
    }

    // Session created.
    return $status;
}

/**
 * Check if a login is being made over SSO (Callback Handler).
 */
function oa_single_sign_on_check_for_sso_login()
{
    // Roles.
    global $wp_roles;

    // Result container.
    $status = new stdClass();
    $status->action = 'error';

    // Callback handler.
    if (isset($_POST) && !empty($_POST['oa_action']) && $_POST['oa_action'] == 'single_sign_on' && isset($_POST['connection_token']) && oa_single_sign_on_is_uuid($_POST['connection_token']))
    {
        $connection_token = $_POST['connection_token'];

        // Add log.
        oa_single_sign_on_add_log('[SSO Callback] Callback for connection_token [' . $connection_token . '] detected');

        // Read settings.
        $ext_settings = oa_single_sign_on_get_settings();

        // We cannot make a connection without a subdomain.
        if (!empty($ext_settings['api_subdomain']))
        {
            // See: http://docs.oneall.com/api/resources/connections/read-connection-details/
            $api_resource_url = $ext_settings['api_url'] . '/connections/' . $connection_token . '.json';

            // API options.
            $api_options = array(
                'api_key' => $ext_settings['api_key'],
                'api_secret' => $ext_settings['api_secret']
            );

            // Read connection details.
            $result = oa_single_sign_on_do_api_request($ext_settings['api_connection_handler'], $api_resource_url, 'GET', $api_options);

            // Check result.
            if (is_object($result) && property_exists($result, 'http_code') && $result->http_code == 200 && property_exists($result, 'http_data'))
            {
                // Decode result.
                $decoded_result = @json_decode($result->http_data);

                // Check data.
                if (is_object($decoded_result) && isset($decoded_result->response->result->data->user))
                {
                    // Extract user data.
                    $data = $decoded_result->response->result->data;

                    // The user_token uniquely identifies the user.
                    $user_token = $data->user->user_token;

                    // The identity_token uniquely identifies the user's data.
                    $identity_token = $data->user->identity->identity_token;

                    // Add log.
                    oa_single_sign_on_add_log('[CALLBACK] Token user_token [' . $user_token . '] / identity_token [' . $identity_token . '] retrieved');

                    // Add to status.
                    $status->user_token = $user_token;
                    $status->identity_token = $identity_token;

                    // Check if we have a customer for this user_token.
                    $user = oa_single_sign_on_get_user_for_user_token($user_token);

                    // User found.
                    if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
                    {
                        // Add Log.
                        oa_single_sign_on_add_log('[CALLBACK] [UID' . $user->ID . '] User logged in for user_token [' . $user_token . ']');

                        // Update (This is just to make sure that the table is always correct).
                        oa_single_sign_on_add_local_storage_tokens_for_user($user, $user_token, $identity_token);

                        // Update status.
                        $status->action = 'existing_user_login_user_token';
                        $status->user = $user;

                        // Done.
                        return $status;
                    }

                    // Add Log.
                    oa_single_sign_on_add_log('[CALLBACK] No user found for user_token [' . $user_token . ']. Trying email lookup.');

                    // Retrieve email from identity.
                    if (isset($data->user->identity->emails) && is_array($data->user->identity->emails) && count($data->user->identity->emails) > 0)
                    {
                        // Email details.
                        $email = $data->user->identity->emails[0]->value;
                        $email_is_verified = ($data->user->identity->emails[0]->is_verified ? true : false);
                        $email_is_random = false;

                        // Check if we have a user for this email.
                        $user = oa_single_sign_on_get_user_for_email($email);

                        // User found.
                        if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
                        {
                            // Update Status
                            $status->user = $user;

                            // Add log.
                            oa_single_sign_on_add_log('[CALLBACK] [UID' . $user->ID . '] User found for email [{' . $email . ']}');

                            // Automatic link is disabled.
                            if ($ext_settings['accounts_autolink'] == 'nobody')
                            {
                                // Add log.
                                oa_single_sign_on_add_log('[CALLBACK] [UID' . $user->ID . '] Autolink is disabled for everybody.');

                                // Update status.
                                $status->action = 'existing_user_no_login_autolink_off';

                                // Done.
                                return $status;
                            }
                            // Automatic link is enabled.
                            else
                            {
                                // Automatic link is disabled for admins.
                                if ($ext_settings['accounts_autolink'] == 'everybody_except_admin' && user_can($user->ID, 'manage_options'))
                                {
                                    // Add log.
                                    oa_single_sign_on_add_log('[CALLBACK] [UID' . $user->ID . '] User is admin and Autolink is disabled for admins.');

                                    // Update status.
                                    $status->action = 'existing_user_no_login_autolink_not_allowed';

                                    // Done.
                                    return $status;
                                }

                                // The email has been verified.
                                if ($email_is_verified)
                                {
                                    // Add log.
                                    oa_single_sign_on_add_log('[CALLBACK] [UID' . $user->ID . '] Autolink enabled/Email verified. Linking user_token [' . $user_token . '] to user');

                                    // Add to database.
                                    $add_tokens = oa_single_sign_on_add_local_storage_tokens_for_user($user, $user_token, $identity_token);

                                    // Update Status.
                                    $status->action = 'existing_user_login_email_verified';

                                    // Done.
                                    return $status;
                                }
                                // The email has NOT been verified.
                                else
                                {
                                    // We can use unverified emails.
                                    if ($ext_settings['accounts_linkunverified'] == 'enabled')
                                    {
                                        // Add log.
                                        oa_single_sign_on_add_log('[CALLBACK] [UID' . $user->ID . '] Autolink enabled/Email unverified. Linking user_token [' . $user_token . '] to user');

                                        // Add to database.
                                        $add_tokens = oa_single_sign_on_add_local_storage_tokens_for_user($user, $user_token, $identity_token);

                                        // Update Status.
                                        $status->action = 'existing_user_login_email_unverified';

                                        // Done.
                                        return $status;
                                    }
                                    // We cannot use unverified emails.
                                    else
                                    {
                                        // Add log.
                                        oa_single_sign_on_add_log('[CALLBACK] [UID' . $user->ID . '] Autolink enabled/Unverified email not allowed. May not link user_token [' . $user_token . '] to user');

                                        // Update status.
                                        $status->action = 'existing_user_no_login_autolink_off_unverified_emails';

                                        // Done.
                                        return $status;
                                    }
                                }
                            }
                        }
                        // No user found.
                        else
                        {
                            // Add Log
                            oa_single_sign_on_add_log('[CALLBACK] No user found for email [{' . $email . '}]');
                        }
                    }
                    else
                    {
                        // Create random email.
                        $email = oa_single_sign_on_create_random_email();
                        $email_is_verified = false;
                        $email_is_random = true;

                        // Add log.
                        oa_single_sign_on_add_log('[CALLBACK] Identity provides no email address. Random address [' . $email . '] generated.');
                    }

                    // /////////////////////////////////////////////////////////////////////////
                    // This is a new user
                    // /////////////////////////////////////////////////////////////////////////

                    // We cannot create new accounts
                    if ($ext_settings['accounts_autocreate'] === false)
                    {
                        // Add log.
                        oa_single_sign_on_add_log('[SSO Callback] New user, but account creation disabled. Cannot create user for user_token [' . $user_token . ']');

                        // Update status.
                        $status->action = 'new_user_no_login_autocreate_off';

                        // Done.
                        return $status;
                    }

                    // Add log.
                    oa_single_sign_on_add_log('[SSO Callback] New user, account creation enabled. Creating user for user_token [' . $user_token . ']');

                    // Generate a password for the user.
                    $password = wp_generate_password();
                    $password = apply_filters('oa_single_sign_on_filter_new_user_password', $password, $data->user->identity);

                    // First name.
                    $first_name = '';
                    if (!empty($data->user->identity->name->givenName))
                    {
                        $first_name = $data->user->identity->name->givenName;
                    }
                    else if (!empty($data->user->identity->displayName))
                    {
                        $names = explode(' ', $data->user->identity->displayName);
                        $first_name = $names[0];
                    }
                    else if (!empty($data->user->identity->name->formatted))
                    {
                        $names = explode(' ', $data->user->identity->name->formatted);
                        $first_name = $names[0];
                    }
                    $first_name = apply_filters('oa_single_sign_on_filter_new_user_firstname', $first_name, $data->user->identity);

                    // Last name.
                    $last_name = '';
                    if (!empty($data->user->identity->name->familyName))
                    {
                        $last_name = $data->user->identity->name->familyName;
                    }
                    else if (!empty($data->user->identity->displayName))
                    {
                        $names = explode(' ', $data->user->identity->displayName);
                        if (!empty($names[1]))
                        {
                            $last_name = $names[1];
                        }
                    }
                    else if (!empty($data->user->identity->name->formatted))
                    {
                        $names = explode(' ', $data->user->identity->name->formatted);
                        if (!empty($names[1]))
                        {
                            $last_name = $names[1];
                        }
                    }
                    $last_name = apply_filters('oa_single_sign_on_filter_new_user_lastname', $last_name, $data->user->identity);

                    // Login.
                    if (!empty($data->user->identity->preferredUsername))
                    {
                        $login = $data->user->identity->preferredUsername;
                    }
                    else
                    {
                        if (!empty($first_name))
                        {
                            if (!empty($last_name))
                            {
                                $login = strtolower($first_name . '.' . $last_name);
                            }
                            else
                            {
                                $login = strtolower($first_name);
                            }
                        }
                        else
                        {
                            $login = $email;
                        }
                    }
                    $login = apply_filters('oa_single_sign_on_filter_new_user_login', $login, $data->user->identity);

                    // Display name.
                    if (!empty($data->user->identity->displayName))
                    {
                        $display_name = $data->user->identity->displayName;
                    }
                    else
                    {
                        if (!empty($first_name))
                        {
                            if (!empty($last_name))
                            {
                                $display_name = ucwords(strtolower(trim($first_name . ' ' . $last_name)));
                            }
                            else
                            {
                                $display_name = ucwords(strtolower(trim($first_name)));
                            }
                        }
                        else
                        {
                            $display_name = $login;
                        }
                    }
                    $display_name = apply_filters('oa_single_sign_on_filter_new_user_display_name', $display_name, $data->user->identity);

                    // Website.
                    $website = '';
                    if (isset($data->user->identity->urls) && is_array($data->user->identity->urls))
                    {
                        $parts = array_shift($data->user->identity->urls);
                        if (!empty($parts->value))
                        {
                            $website = $parts->value;
                        }
                    }
                    $website = apply_filters('oa_single_sign_on_filter_new_user_website', $website, $data->user->identity);

                    // Role.
                    $role = '';
                    if (isset($data->user->identity->roles) && is_array($data->user->identity->roles))
                    {
                        // Loop through roles.
                        while ($role == '' && (list(, $part) = each($data->user->identity->roles)))
                        {
                            // Do we have a value?
                            if (!empty($part->value))
                            {
                                // Check if it's a valid role.
                                if ($wp_roles instanceof WP_Roles && $wp_roles->is_role($part->value))
                                {
                                    $role = $part->value;
                                }
                            }
                        }
                    }

                    // Use default role.
                    if (empty($role))
                    {
                        $role = get_option('default_role');
                    }
                    $role = apply_filters('oa_single_sign_on_filter_new_user_role', $role, $data->user->identity);

                    // Build user data.
                    $fields = array(
                        'user_login' => $login,
                        'display_name' => $display_name,
                        'user_email' => $email,
                        'first_name' => $first_name,
                        'last_name' => $last_name,
                        'user_url' => $website,
                        'user_pass' => $password,
                        'role' => $role
                    );

                    // Filter for user_data.
                    $fields = apply_filters('oa_single_sign_on_filter_new_user_fields', $fields, $data->user->identity);

                    // Hook before adding the user.
                    do_action('oa_single_sign_on_action_before_user_insert', $fields, $data->user->identity);

                    // Create a new user.
                    $userid = wp_insert_user($fields);
                    if (is_numeric($userid) && ($user = get_userdata($userid)) !== false)
                    {
                        // Add log.
                        oa_single_sign_on_add_log('[SSO Callback]  [UID' . $user->ID . '] User created for user_token [' . $user_token . ']');

                        // Send registration email?
                        if ($ext_settings['accounts_sendmail'])
                        {
                            // We cannot send emails to random email addresses.
                            if (!$email_is_random)
                            {
                                // Can emails be sent?
                                if (function_exists('wp_new_user_notification'))
                                {
                                    // Send mail
                                    wp_new_user_notification($user->ID);

                                    // Add log.
                                    oa_single_sign_on_add_log('[SSO Callback] [UID' . $user->ID . '] New user created. Sent email using wp_new_user_notification');
                                }
                                else
                                {
                                    // Add log.
                                    oa_single_sign_on_add_log('[SSO Callback] [UID' . $user->ID . '] New user created. No email sent. wp_new_user_notification not found.');
                                }
                            }
                        }

                        // Add to database.
                        $add_tokens = oa_single_sign_on_add_local_storage_tokens_for_user($user, $user_token, $identity_token);

                        // Login user.
                        oa_single_sign_login_user($user);

                        // Update status.
                        $status->action = 'new_user_created_login';
                        $status->user_token = $user_token;
                        $status->identity_token = $identity_token;
                        $status->user = $user;
                    }
                    else
                    {
                        $status->action = 'user_creation_failed';
                    }
                }
                else
                {
                    $status->action = 'api_data_decode_failed';
                }
            }
            else
            {
                $status->action = 'api_connection_failed';
            }
        }
        else
        {
            $status->action = 'extension_not_setup';
        }
    }
    else
    {
        $status->action = 'no_callback_data_received';
    }


	// Done.
    return $status;
}

/**
 * Add a user to the cloud storage.
 */
function oa_single_sign_on_add_user_to_cloud_storage($user)
{
    // Result Container
    $status = new stdClass();
    $status->is_successfull = false;
    $status->identity_token = null;
    $status->user_token = null;

    // Read settings.
    $ext_settings = oa_single_sign_on_get_settings();

    // We cannot make a connection without the subdomain.
    if (!empty($ext_settings['api_subdomain']))
    {
        // Add log.
        oa_single_sign_on_add_log('[ADD CLOUD] [UID' . $user->ID . '] Setting up user in cloud storage');

        // ////////////////////////////////////////////////////////////////////////////////////////////////
        // First make sure that we don't create duplicate users!
        // ////////////////////////////////////////////////////////////////////////////////////////////////

        // API endpoint: http://docs.oneall.com/api/resources/storage/users/lookup-user/
        $api_resource_url = $ext_settings['api_url'] . '/storage/users/user/lookup.json';

        // API options.
        $api_options = array(
            'api_key' => $ext_settings['api_key'],
            'api_secret' => $ext_settings['api_secret'],
            'api_data' => @json_encode(array(
                'request' => array(
                    'user' => array(
                        'login' => $user->user_email
                    )
                )
            ))
        );

        // User lookup.
        $result = oa_single_sign_on_do_api_request($ext_settings['api_connection_handler'], $api_resource_url, 'POST', $api_options);

        // Check result.
        if (is_object($result) && property_exists($result, 'http_code') && $result->http_code == 200 && property_exists($result, 'http_data'))
        {
            // Decode result.
            $decoded_result = @json_decode($result->http_data);

            // Check data.
            if (is_object($decoded_result) && isset($decoded_result->response->result->data->user))
            {
                // Update status.
                $status->action = 'existing_user_read';
                $status->is_successfull = true;
                $status->user_token = $decoded_result->response->result->data->user->user_token;
                $status->identity_token = $decoded_result->response->result->data->user->identity->identity_token;

                // Add log.
                oa_single_sign_on_add_log('[ADD CLOUD] Email [{' . $user->user_email . '}] found in cloud storage, user_token [' . $status->user_token . '] identity_token [' . $status->identity_token . '] assigned');

                // Done.
                return $status;
            }
        }

        // ////////////////////////////////////////////////////////////////////////////////////////////////
        // If we are getting here, then a new identity needs to be added
        // ////////////////////////////////////////////////////////////////////////////////////////////////

        // Build data.
        $identity = array(
            'preferredUsername' => $user->user_login,
            'displayName' => (!empty($user->display_name) ? $user->display_name : $user->user_login)
        );

        // Names.
        if (!empty(($user->first_name) || !empty($user->last_name)))
        {
            $identity['name'] = array();

            // First name.
            if (!empty($user->first_name))
            {
                $identity['name']['givenName'] = $user->first_name;
            }

            // Last name.
            if (!empty($user->last_name))
            {
                $identity['name']['familyName'] = $user->last_name;
            }
        }

        // About me.
        if (!empty($user->description))
        {
            $identity['aboutMe'] = $user->description;
        }

        // Avatar.
        $user_avatar = get_avatar_data($user->ID);
        if (!empty($user_avatar['url']))
        {
            $identity['thumbnailUrl'] = $user_avatar['url'];
        }

        // User Roles.
        if (isset($user->roles) && is_array($user->roles) && count($user->roles) > 0)
        {
            $identity['roles'] = array();
            foreach ($user->roles as $role)
            {
                $identity['roles'][] = array(
                    'value' => $role
                );
            }
        }

        // User email.
        if (!empty($user->user_email))
        {
            $identity['emails'] = array(
                array(
                    'value' => $user->user_email,
                    'is_verified' => true
                )
            );
        }

        // User Account.
        $identity['accounts'] = array(
            array(
                'domain' => get_site_url(null, '', 'http'),
                'userid' => $user->ID
            )
        );

        // User URL.
        if (!empty($user->user_url))
        {
            $identity['urls'] = array(
                array(
                    'value' => $user->user_url,
                    'type' => 'personal'
                )
            );
        }

        // API Endpoint: http://docs.oneall.com/api/resources/storage/users/create-user/
        $api_resource_url = $ext_settings['api_url'] . '/storage/users.json';

        // API Options.
        $api_options = array(
            'api_key' => $ext_settings['api_key'],
            'api_secret' => $ext_settings['api_secret'],
            'api_data' => @json_encode(array(
                'request' => array(
                    'user' => array(
                        'login' => $user->user_email,
                        'password' => $user->user_pass,
                        'identity' => $identity
                    )
                )
            )
            )
        );

        // Add User.
        $result = oa_single_sign_on_do_api_request($ext_settings['api_connection_handler'], $api_resource_url, 'POST', $api_options);

        // Check result. 201 Returned !!!
        if (is_object($result) && property_exists($result, 'http_code') && $result->http_code == 201 && property_exists($result, 'http_data'))
        {
            // Decode result.
            $decoded_result = @json_decode($result->http_data);

            // Check data.
            if (is_object($decoded_result) && isset($decoded_result->response->result->data->user))
            {
                // Update status.
                $status->action = 'new_user_created';
                $status->is_successfull = true;
                $status->user_token = $decoded_result->response->result->data->user->user_token;
                $status->identity_token = $decoded_result->response->result->data->user->identity->identity_token;

                // Add Log.
                oa_single_sign_on_add_log('[ADD CLOUD] [UID' . $user->ID . '] User added, user_token [' . $status->user_token . '] and identity_token [' . $status->identity_token . '] assigned');

                // Done.

                return $status;
            }
        }
    }

    // Error.

    return $status;
}

/**
 * Return the cloud storage tokens of a user stored in the local database.
 */
function oa_single_sign_on_get_local_storage_tokens_for_user($user)
{
    // Result Container.
    $status = new stdClass();
    $status->have_been_retrieved = false;

    // Verify user object.
    if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
    {
        // Load user's tokens.
        $user_token = get_user_meta($user->ID, 'oa_single_sign_on_user_token', true);
        $identity_token = get_user_meta($user->ID, 'oa_single_sign_on_identity_token', true);

        // Optional
        $sso_session_token = get_user_meta($user->ID, 'oa_single_sign_on_sso_session_token', true);

        // Tokens found.
        if (!empty($user_token) && !empty($identity_token))
        {
            // Update Status.
            $status->identity_token = $identity_token;
            $status->user_token = $user_token;
            $status->sso_session_token = (empty($sso_session_token) ? null : $sso_session_token);
            $status->have_been_retrieved = true;
        }
    }

    // Done.

    return $status;
}

/**
 * Returns the sso session token of a user from the local database.
 */
function oa_single_sign_on_get_local_sso_session_token_for_user($user)
{
    // Result Container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Verify user object.
    if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
    {
        // Load user's sso_session_token.
        $sso_session_token = get_user_meta($user->ID, 'oa_single_sign_on_sso_session_token', true);
        $sso_session_token_expiration = get_user_meta($user->ID, 'oa_single_sign_on_sso_session_token_expiration', true);

        // Token found and not expired.
        if (!empty($sso_session_token) && (empty($sso_session_token_expiration) || $sso_session_token_expiration >= time()))
        {
            // Update Status.
            $status->sso_session_token = $sso_session_token;
            $status->date_expiration = $sso_session_token_expiration;
            $status->is_successfull = true;
        }
    }

    // Done.

    return $status;
}

/**
 * Add the sso session token of a user to the local database.
 */
function oa_single_sign_on_add_local_sso_session_token_for_user($user, $sso_session_token, $date_expiration = null)
{
    // Result Container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Verify user object.
    if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
    {
        // Set Meta.
        update_user_meta($user->ID, 'oa_single_sign_on_sso_session_token', $sso_session_token);

        // Optional
        if (!empty($date_expiration) && is_numeric($date_expiration))
        {
            update_user_meta($user->ID, 'oa_single_sign_on_sso_session_token_expiration', $date_expiration);
        }
        else
        {
            delete_user_meta($user->ID, 'oa_single_sign_on_sso_session_token_expiration');
        }

        // Update Status.
        $status->sso_session_token = $sso_session_token;
        $status->date_expiration = $date_expiration;
        $status->is_successfull = true;
    }

    // Done

    return $status;
}

/**
 * Remove the sso session token of a user from the local database.
 */
function oa_single_sign_on_remove_local_sso_session_token_for_user($user)
{
    // Result Container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Verify user object.
    if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
    {
        // Read old value.
        $session_token = get_user_meta($user->ID, 'oa_single_sign_on_sso_session_token', true);

        // Remove old value.
        delete_user_meta($user->ID, 'oa_single_sign_on_sso_session_token');

        // Add Log.
        oa_single_sign_on_add_log('[REMOVE SESSION TOKEN] [UID' . $user->ID . '] Meta [oa_single_sign_on_sso_session_token] removed');

        // Update Status.
        $status->sso_session_token = $session_token;
        $status->is_successfull = true;
    }

    // Done

    return $status;
}

/**
 * Removes the cloud storage tokens of a user from the local database.
 */
function oa_single_sign_on_remove_local_storage_tokens_for_user($user)
{
    // Result Container.
    $status = new stdClass();
    $status->is_successfull = false;

    // Verify user object.
    if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
    {
        // Remove Meta.
        delete_user_meta($user->ID, 'oa_single_sign_on_user_token');
        delete_user_meta($user->ID, 'oa_single_sign_on_identity_token');

        // Update Status.
        $status->is_successfull = true;
    }

    // Done

    return $status;
}

/**
 * Add the cloud storage tokens of a user to the local database.
 */
function oa_single_sign_on_add_local_storage_tokens_for_user($user, $user_token, $identity_token)
{
    // Result Container.
    $status = new stdClass();
    $status->have_been_added = false;

    // Verify user object.
    if (is_object($user) && $user instanceof WP_User && !empty($user->ID))
    {
        // Set Meta.
        update_user_meta($user->ID, 'oa_single_sign_on_user_token', $user_token);
        update_user_meta($user->ID, 'oa_single_sign_on_identity_token', $identity_token);

        // Update Status.
        $status->user_token = $user_token;
        $status->identity_token = $identity_token;
        $status->have_been_added = true;
    }

    // Done

    return $status;
}

/**
 * Start a new Single Sign-On session for the given identity_token.
 */
function oa_single_sign_on_start_session_for_identity_token($identity_token)
{
    // Result Container.
    $status = new stdClass();
    $status->is_successfull = false;

    // We need the identity_token to create a session.
    if (!empty($identity_token))
    {
        // Read settings.
        $ext_settings = oa_single_sign_on_get_settings();

        // We cannot make a connection without the subdomain.
        if (!empty($ext_settings['api_subdomain']))
        {
            // ////////////////////////////////////////////////////////////////////////////////////////////////
            // Start a new Single Sign-On Session
            // ////////////////////////////////////////////////////////////////////////////////////////////////

            // API Endpoint: http://docs.oneall.com/api/resources/sso/identity/start-session/
            $api_resource_url = $ext_settings['api_url'] . '/sso/sessions/identities/' . $identity_token . '.json';

            // API Options.
            $api_options = array(
                'api_key' => $ext_settings['api_key'],
                'api_secret' => $ext_settings['api_secret'],
                'api_data' => @json_encode(array(
                    'request' => array(
                        'sso_session' => array(
                            'top_realm' => $ext_settings['session_top_realm'],
                            'sub_realm' => $ext_settings['session_sub_realm'],
                            'lifetime' => $ext_settings['session_lifetime'],
                            'data' => get_site_url()
                        )
                    )
                ))
            );

            // Create Session
            $result = oa_single_sign_on_do_api_request($ext_settings['api_connection_handler'], $api_resource_url, 'PUT', $api_options);

            // Check result. 201 Returned !!!
            if (is_object($result) && property_exists($result, 'http_code') && property_exists($result, 'http_data'))
            {
                // Success
                if ($result->http_code == 201)
                {
                    // Decode result
                    $decoded_result = @json_decode($result->http_data);

                    // Check result.
                    if (is_object($decoded_result) && isset($decoded_result->response->result->data->sso_session))
                    {
                        // Update status.
                        $status->action = 'session_started';
                        $status->sso_session_token = $decoded_result->response->result->data->sso_session->sso_session_token;
                        $status->date_expiration = $decoded_result->response->result->data->sso_session->date_expiration;
                        $status->is_successfull = true;

                        // Add log.
                        oa_single_sign_on_add_log('[START SESSION IDENTITY] Session [' . $status->sso_session_token . '] for identity [' . $identity_token . '] added to repository');
                    }
                    else
                    {
                        $status->action = 'invalid_user_object';
                    }
                }
                elseif ($result->http_code == 404)
                {
                    $status->action = 'invalid_identity_token';
                }
                else
                {
                    $status->action = ('http_error_' . $result->http_code);
                }
            }
            else
            {
                $status->action = 'http_request_failed';
            }
        }
        // Extension not setup
        else
        {
            $status->action = 'extension_not_setup';
        }
    }
    else
    {
        $status->action = 'empty_identity_token';
    }

    // Done

    return $status;
}

/**
 * Remove a Single Sign-On session for the given sso_session_token.
 */
function oa_single_sign_on_remove_session_for_sso_session_token($sso_session_token)
{
    // Result container.
    $status = new stdClass();
    $status->action = null;
    $status->is_successfull = false;

    // We need the sso_session_token to remove the session.
    if (!empty($sso_session_token))
    {
        // Read settings.
        $ext_settings = oa_single_sign_on_get_settings();

        // We cannot make a connection without the subdomain.
        if (!empty($ext_settings['api_subdomain']))
        {
            // ////////////////////////////////////////////////////////////////////////////////////////////////
            // Destroy an existing Single Sign-On Session
            // ////////////////////////////////////////////////////////////////////////////////////////////////

            // API Endpoint: http://docs.oneall.com/api/resources/sso/delete-session/
            $api_resource_url = $ext_settings['api_url'] . '/sso/sessions/' . $sso_session_token . '.json?confirm_deletion=true';

            // API Options
            $api_options = array(
                'api_key' => $ext_settings['api_key'],
                'api_secret' => $ext_settings['api_secret']
            );

            // Delete Session.
            $result = oa_single_sign_on_do_api_request($ext_settings['api_connection_handler'], $api_resource_url, 'DELETE', $api_options);

            // Check result.
            if (is_object($result) && property_exists($result, 'http_code') && $result->http_code == 200)
            {
                // Update status.
                $status->action = 'session_deleted';
                $status->is_successfull = true;

                // Add log.
                oa_single_sign_on_add_log('[REMOVE SESSION] Session [' . $sso_session_token . '] removed from repository');
            }
        }
        // Extension not setup.
        else
        {
            $status->action = 'extension_not_setup';
        }
    }

    // Done

    return $status;
}

/**
 * Remove a Single Sign-On session for the given identity_token.
 */
function oa_single_sign_on_remove_session_for_identity_token($identity_token)
{
    // Result container.
    $status = new stdClass();
    $status->action = null;
    $status->is_successfull = false;

    // We need the sso_session_token to remove the session.
    if (!empty($identity_token))
    {
        // Read settings.
        $ext_settings = oa_single_sign_on_get_settings();

        // We cannot make a connection without the subdomain.
        if (!empty($ext_settings['api_subdomain']))
        {
            // ////////////////////////////////////////////////////////////////////////////////////////////////
            // Destroy an existing Single Sign-On Session
            // ////////////////////////////////////////////////////////////////////////////////////////////////

            // API Endpoint: http://docs.oneall.com/api/resources/sso/delete-session/
            $api_resource_url = $ext_settings['api_url'] . '/sso/sessions/identities/' . $identity_token . '.json?confirm_deletion=true';

            // API Options
            $api_options = array(
                'api_key' => $ext_settings['api_key'],
                'api_secret' => $ext_settings['api_secret']
            );

            // Delete session.
            $result = oa_single_sign_on_do_api_request($ext_settings['api_connection_handler'], $api_resource_url, 'DELETE', $api_options);

            // Check result.
            if (is_object($result) && property_exists($result, 'http_code') && $result->http_code == 200)
            {
                // Update status.
                $status->action = 'session_deleted';
                $status->is_successfull = true;

                // Add log.
                oa_single_sign_on_add_log('[REMOVE SESSION] Sessions for identity_token [' . $identity_token . '] removed from repository');
            }
        }
        // Extension not setup.
        else
        {
            $status->action = 'extension_not_setup';
        }
    }

    // Done.
    return $status;
}
