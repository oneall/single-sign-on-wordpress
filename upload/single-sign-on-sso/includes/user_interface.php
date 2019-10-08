<?php


/**
 * Enables a notice for the user.
 */
function oa_single_sign_on_enable_user_notice ($user, $period = 3600)
{
	// Verify user object.
	if (is_object ($user) && $user instanceof WP_User && ! empty ($user->ID))
	{
		// Read notices
		$old_notices = get_option ('oa_single_sign_on_notices');
		if ( ! is_array ($old_notices))
		{
			$old_notices = array ();
		}

		// Removes duplicates
		$new_notices = array ();
		foreach ($old_notices AS $notice)
		{
			if ( isset ($notice['userid']) && $notice['userid'] <> $user->ID)
			{
				$new_notices[] = $notice;
			}
		}

		// Generate a hash
		$hash = oa_single_sign_on_hash_string ($user->ID . time ());

		// Add notice
		$notices[] = array (
			'hash' => $hash,
			'userid' => $user->ID,
			'displayed' => 0,
			'expires' => (time () + $period)
		);

		// Save notices
		update_option ('oa_single_sign_on_notices', $notices);

		// Add Cookie
		setcookie('oa_sso_notice', $hash, (time () + $period), COOKIEPATH, COOKIE_DOMAIN);
		$_COOKIE['oa_sso_notice'] = $hash;
	}
}

/**
 * Remove a user a notice.
 */
function oa_single_sign_on_remove_user_notice ($user)
{
	// Verify user object.
	if (is_object ($user) && $user instanceof WP_User && ! empty ($user->ID))
	{
		// Current notices.
		$old_notices = get_option ('oa_single_sign_on_notices');
		if ( ! is_array ($old_notices))
		{
			$old_notices = array ();
		}

		// New notices
		$new_notices = array ();
		foreach ($old_notices AS $notice)
		{
			if (isset ($notice['userid']) && $notice['userid'] <> $user->ID)
			{
				$new_notices[] = $notice;
			}
		}

		// Save notices
		update_option ('oa_single_sign_on_notices', $new_notices);
	}
}

/**
 * Removes a user a notice's cookies.
 */
function oa_single_sign_on_remove_user_notice_cookies ()
{
	if (isset ($_COOKIE) && is_array ($_COOKIE) && isset ($_COOKIE['oa_sso_notice']))
	{
		unset ($_COOKIE['oa_sso_notice']);
	}

	// Remove Cookie.
	setcookie('oa_sso_notice', '', (time()-(15*60)), COOKIEPATH, COOKIE_DOMAIN);
}

/**
 * Removes all notice data for a user.
 */
function oa_single_sign_on_remove_flush_user_notice ($user)
{
	oa_single_sign_on_remove_user_notice_cookies();
	oa_single_sign_on_remove_user_notice ($user);

}

/**
 * Marks a notice as having been displayed.
 */
function oa_single_sign_on_mark_user_notice_displayed ($user)
{
	// Verify user object.
	if (is_object ($user) && $user instanceof WP_User && ! empty ($user->ID))
	{
		// Current notices.
		$old_notices = get_option ('oa_single_sign_on_notices');
		if ( ! is_array ($old_notices))
		{
			$old_notices = array ();
		}

		// New notices
		$new_notices = array ();
		foreach ($old_notices AS $notice)
		{
			if (isset ($notice['userid']) && $notice['userid'] == $user->ID)
			{
				$notice['displayed'] = 1;
			}

			// Add
			$new_notices[] = $notice;
		}

		// Save notices
		update_option ('oa_single_sign_on_notices', $new_notices);
	}
}

/**
 * Return the current user from the notices.
 */
function oa_single_sign_on_get_user_notice ($only_non_displayed)
{
	if (isset ($_COOKIE) && is_array ($_COOKIE) && isset ($_COOKIE['oa_sso_notice']))
	{
		// Read notices
		$notices = get_option ('oa_single_sign_on_notices');

		// Check format.
		if (is_array ($notices))
		{
			// Read hash
			$hash = $_COOKIE['oa_sso_notice'];

			// Lookup
			foreach ($notices AS $notice)
			{
				if (isset ($notice['hash']) && $notice['hash'] == $hash)
				{
					$user_notice= $notice;
				}
			}

			// Do we have to display a notice?
			if (isset ($user_notice))
			{
				// Check if it's valid
				if (is_array ($user_notice) && isset ($user_notice['userid']) && isset ($user_notice['expires']))
				{
					// Not  expired and not yet displayed
					if ($user_notice['expires'] > time ())
					{
						// Return only non-displayed notices?
						if ( ! $only_non_displayed || empty ($user_notice['displayed']))
						{
							// Read user.
							$user = get_user_by ('ID', $user_notice['userid']);

							// Verify user object.
							if (is_object ($user) && $user instanceof WP_User && ! empty ($user->ID))
							{
								return $user;
							}
						}
					}
				}
			}
		}
	}
}

/**
 * Prefills the login.
 */
function oa_single_sign_prefill_login()
{
	global $user_login;

	// Read settings.
	$ext_settings = oa_single_sign_on_get_settings ();

	// Make sure it's enabled.
	if ($ext_settings['accounts_remind'] == 'enabled')
	{
		// Read user from notice.
		$user = oa_single_sign_on_get_user_notice (false);

		// Verify user object.
		if (is_object ($user) && $user instanceof WP_User && ! empty ($user->ID))
		{
			$user_login = $user->user_login;
		}
	}
}
add_action('login_head', 'oa_single_sign_prefill_login');

/**
 * Add the CSS required by notices
 **/
function oa_single_sign_on_add_site_css ()
{
	if (!wp_style_is ('oa_single_sign_on_site_css', 'registered'))
	{
		wp_register_style ('oa_single_sign_on_site_css', OA_SINGLE_SIGN_ON_PLUGIN_URL . "/assets/css/site.css");
	}

	if (did_action ('wp_print_styles'))
	{
		wp_print_styles ('oa_single_sign_on_site_css');
	}
	else
	{
		wp_enqueue_style ('oa_single_sign_on_site_css');
	}
}

/**
 * Displays a notice if the user is recognized.
 */
function oa_single_sign_display_user_notice ()
{
	// Read settings.
	$ext_settings = oa_single_sign_on_get_settings ();

	// Make sure it's enabled.
	if ($ext_settings['accounts_remind'] == 'enabled')
	{
		// Read user from notice.
		$user = oa_single_sign_on_get_user_notice (true);

		// Verify user object.
		if (is_object ($user) && $user instanceof WP_User && ! empty ($user->ID))
		{
			// Mark user notice as displayed.
			oa_single_sign_on_mark_user_notice_displayed ($user);

			// Add CSS
			oa_single_sign_on_add_site_css ();

			// Login url.
			$login_url = wp_login_url();
			$cancel_url = oa_single_sign_on_get_current_url ();

			?>
				<div id="oa_single_sign_on_overlay"></div>
					<div id="oa_single_sign_on_modal">
						<div class="oa_single_sign_on_modal_outer">
							<div class="oa_single_sign_on_modal_inner">
				 				<div class="oa_single_sign_on_modal_title">
				 						<?php
											 _e ('Welcome Back!', 'oa_single_sign_on');
										 ?>
								</div>
								<div class="oa_single_sign_on_modal_body">
				 					<div class="oa_single_sign_on_modal_notice">
				 						<?php
				 							printf (__ ('You already seem to have registered an account with the username %s. Would you like to login now?', 'oa_single_sign_on'), '<span class="oa_single_sign_on_login">'.$user->user_login.'</span>');
				 						?>
				 					</div>
									<div class="oa_single_sign_on_modal_buttons">
										<a href="<?php echo esc_url ($login_url); ?>" class="oa_single_sign_on_modal_button" id="oa_single_sign_on_modal_button_login"><?php _e ('Login', 'oa_single_sign_on'); ?></a>
										<a href="<?php echo esc_url ($cancel_url); ?>" class="oa_single_sign_on_modal_button" id="oa_single_sign_on_modal_button_cancel"><?php _e ('Cancel', 'oa_single_sign_on'); ?></a>
									</div>
								</div>
							</div>
						</div>
					</div>
			<?php
		}
	}
}
add_action ('wp_footer', 'oa_single_sign_display_user_notice');
add_action ('admin_footer', 'oa_single_sign_display_user_notice');
