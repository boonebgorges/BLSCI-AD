<?php

/**
 * Allows backward compatibility with pre-AD login names
 */

class BLSCI_AD_Username_Backpat {
	var $user;
	
	function __construct() {
		global $AD_Integration_plugin;
		
		// Intercept requests before they get to wp_authenticate_username_password		
		remove_filter( 'authenticate', 'wp_authenticate_username_password', 20, 3 );
		add_filter( 'authenticate', array( $this, 'maybe_swap_username' ), 10, 3 );
	
		remove_filter( 'authenticate', array( $AD_Integration_plugin, 'authenticate' ) );
	}
	
	function maybe_swap_username( $user, $username, $password ) {
		global $wpdb, $AD_Integration_plugin;
		
		if ( is_a( $user, 'WP_User' ) )
			return $user;
		
		// try getting the raw userdata first
		$userdata = get_userdatabylogin( $username );
		
		if ( !$userdata ) {
			// Look for deprecated username
			$maybe_user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = 'blsci_deprecated_wp_user_login'" ) );
			
			if ( $maybe_user_id )
				$userdata = get_userdata( $maybe_user_id );
		}
		
		// Now we have to reproduce all of the core spammer checks
		if ( !$userdata )
			return new WP_Error('invalid_username', sprintf(__('<strong>ERROR</strong>: Invalid username. <a href="%s" title="Password Lost and Found">Lost your password</a>?'), site_url('wp-login.php?action=lostpassword', 'login')));
	
		if ( is_multisite() ) {
			// Is user marked as spam?
			if ( 1 == $userdata->spam)
				return new WP_Error('invalid_username', __('<strong>ERROR</strong>: Your account has been marked as a spammer.'));
	
			// Is a user's blog marked as spam?
			if ( !is_super_admin( $userdata->ID ) && isset($userdata->primary_blog) ) {
				$details = get_blog_details( $userdata->primary_blog );
				if ( is_object( $details ) && $details->spam == 1 )
					return new WP_Error('blog_suspended', __('Site Suspended.'));
			}
		}
	
		$userdata = apply_filters('wp_authenticate_user', $userdata, $password);
		if ( is_wp_error($userdata) )
			return $userdata;
	
		$user =  new WP_User($userdata->ID);
				
		if ( !wp_check_password($password, $userdata->user_pass, $userdata->ID) ) {
			
			if ( !$user = $AD_Integration_plugin->ad_authenticate( $user, $userdata->user_login, $password ) )
				return new WP_Error( 'incorrect_password', sprintf( __( '<strong>ERROR</strong>: The password you entered for the username <strong>%1$s</strong> is incorrect. <a href="%2$s" title="Password Lost and Found">Lost your password</a>?' ), $userdata->user_logun, site_url( 'wp-login.php?action=lostpassword', 'login' ) ) );
			
		}

		
		//var_dump( $AD_Integration_plugin ); die();
		return $user;		
	}


}
$BLSCI_AD_Username_Backpat = new BLSCI_AD_Username_Backpat;

if ( !function_exists( 'get_userdatabylogin' ) ) :
	function get_userdatabylogin($user_login) {
		$userdata = get_user_by('login', $user_login);

		// Try without the email suffix
		if ( !$userdata ) {
			if ( false !== strpos( $user_login, '@' ) ) {
				$user_login_a = explode( '@', $user_login );
				$userdata = get_user_by( 'login', $user_login_a[0] );
			}
		}
		
		// Next, try to add the email suffix(es)
		if ( !$userdata ) {
			// No need to do this if there is an @ in the user login
			if ( false === strpos( $user_login, '@' ) ) {
				$suffixes = get_site_option( 'AD_Integration_account_suffix' );
				
				if ( $suffixes ) {
					$suffixes = explode( ';', $suffixes );
					foreach ( (array)$suffixes as $suffix ) {
						$user_login_maybe = $user_login . $suffix;
						
						if ( $userdata = get_user_by( 'login', $user_login_maybe ) )
							break;
					}
				}
			}
		}
		
		return $userdata;
	}
endif;

?>