<?php

/**
 * This file contains fixes for the plugin Active Directory Integration, version 1.1.1
 * See http://blog.ecw.de/wp-ad-integration and 
 * http://wordpress.org/extend/plugins/active-directory-integration/
 * 
 * @package BLSCI-AD
 * @since 1.0
 */

// Make sure that AD Integration is up and running. An imperfect check (would prefer an action)
$ad_integration_plugin_path = WP_PLUGIN_DIR . '/active-directory-integration/';
if ( !file_exists( $ad_integration_plugin_path . 'ad-integration.php' ) )
	return;

if ( !class_exists( 'ADIntegrationPlugin' ) )
	return;

class BLSCI_AD_Fix extends ADIntegrationPlugin {
	var $wp_errors = array();

	/**
	 * Overrides the default constructor, to implement WP 3.1 network fixes
	 */
	function __construct() {
	
		global $wp_version, $wpmu_version, $wpdb, $wpmuBaseTablePrefix;
		$wpmu_version = $wp_version;

		if (!defined('IS_WPMU')) {
			define( 'IS_WPMU', is_multisite() );
		}
		
		// define folder constant
		if (!defined('ADINTEGRATION_FOLDER')) {  
			define('ADINTEGRATION_FOLDER', basename(dirname(__FILE__)));
		}
	
		$this->setLogFile(dirname(__FILE__).'/adi.log'); 
		
		$this->errors = new WP_Error();
		

		// Load Options
		$this->_load_options();
		
		// Generate authcode if necessary
		if (strlen($this->_bulkimport_authcode) < 20) {
			$this->_generate_authcode();
		}
		
		if (isset($_GET['activate']) and $_GET['activate'] == 'true') {
			add_action('init', array(&$this, 'initialize_options'));
		}
		
		add_action('admin_init', array(&$this, 'register_adi_settings'));
		
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array(&$this, 'add_options_page' ) );
		
		if ( is_multisite() ) {
			add_action( 'admin_menu', array( &$this, 'remove_non_ms_menu' ), 1000 );
		}
		
		add_filter('contextual_help', array(&$this, 'contextual_help'), 10, 2);
		
		// DO WE HAVE LDAP SUPPORT?
		if (function_exists('ldap_connect')) {
			
			// WP 2.8 and above?
			if (version_compare($wp_version, '2.8', '>=')) {
				add_filter('authenticate', array(&$this, 'authenticate'), 10, 3);
			} else {
				add_action('wp_authenticate', array(&$this, 'authenticate'), 10, 2);
			}
			
			add_action('lost_password', array(&$this, 'disable_function'));
			add_action('retrieve_password', array(&$this, 'disable_function'));
			add_action('password_reset', array(&$this, 'disable_function'));
		    add_action('admin_print_styles', array(&$this, 'load_styles'));
		    add_action('admin_print_scripts', array(&$this, 'load_scripts'));
		    
		    // Sync Back?
		    if ($this->_syncback === true) {
		    	
				//add_action('profile_update', array(&$this, 'profile_update'));
				add_action('personal_options_update', array(&$this, 'profile_update'));
				add_action('edit_user_profile_update', array(&$this, 'profile_update'));
		    }
			
			add_filter('check_password', array(&$this, 'override_password_check'), 10, 4);
			
			
			// Is local password change disallowed?
			if (!$this->_enable_password_change) {
				
				// disable password fields
				add_filter('show_password_fields', array(&$this, 'disable_password_fields'));
				
				// generate a random password for manually added users 
				add_action('check_passwords', array(&$this, 'generate_password'), 10, 3);
			}
			 			
			if (!class_exists('adLDAP')) {
				require 'ad_ldap/adLDAP.php';
			}
		}
		
		// Adding AD attributes to profile page
		if ($this->_show_attributes) {
			add_action( 'edit_user_profile', array(&$this, 'show_AD_attributes'));
			add_action( 'show_user_profile', array(&$this, 'show_AD_attributes'));
		}
		
		$this->_all_user_attributes = $this->_get_user_attributes();
	}
	
	function remove_non_ms_menu() {
		remove_submenu_page( 'tools.php', 'active-directory-integration' );
		
		global $menu, $admin_page_hooks, $submenu;
	//	var_dump( $menu, $admin_page_hooks, $submenu );
	}
	
	
	/**
	 * Add the options page in a WP 3.1+ compatible way
	 *
	 * @package BLSCI-AD
	 * @since 1.0
	 */
	function add_options_page() {
		global $ad_integration_plugin_path;
		
		if ( is_multisite() && is_super_admin()) {
			// Network mode
			if ( function_exists( 'add_submenu_page' ) ) {
				add_submenu_page( 'settings.php', __( 'Active Directory Integration' ), __( 'Active Directory Integration' ), 'manage_options', $ad_integration_plugin_path . 'ad-integration.php', array(&$this, 'display_options_page') );
			}
		} else {
			// WordPress Standard
			if ( function_exists( 'add_options_page' ) ) {
				add_options_page( 'Active Directory Integration', 'Active Directory Integration', 'manage_options', $ad_integration_plugin_path . 'ad-integration.php', array(&$this, 'display_options_page') );
			}
		}
	}
	
	/**
	 * Save options in a non-stupid way, which actually works
	 *
	 * @package BLSCI-AD
	 * @since 1.0
	 */
	protected function _save_wpmu_options($arrPost) {
		
 		if ( is_multisite() ) {
 			// Different settings are passed on each page, so we have to be specific
 			$all_options = array(
 				'ADI-server-settings' => array(
 					'AD_Integration_domain_controllers',
 					'AD_Integration_port',
 					'AD_Integration_use_tls',
 					'AD_Integration_network_timeout',
 					'AD_Integration_base_dn',
 				),
 				'ADI-user-settings' => array(
 					'AD_Integration_account_suffix',
 					'AD_Integration_append_suffix_to_new_users',
 					'AD_Integration_auto_create_user',
 					'AD_Integration_auto_update_user',
 					'AD_Integration_auto_update_description',
 					'AD_Integration_default_email_domain',
 					'AD_Integration_duplicate_email_prevention',
 					'AD_Integration_display_name',
 					'AD_Integration_enable_password_change',
 					'AD_Integration_no_random_password',
 					'AD_Integration_auto_update_password'
 				),
 				'ADI-auth-settings' => array(
 					'AD_Integration_authorize_by_group',
 					'AD_Integration_authorization_group',
 					'AD_Integration_role_equivalent_groups'
 				),
 				'ADI-security-settings' => array(
 					'AD_Integration_max_login_attempts',
 					'AD_Integration_block_time',
 					'AD_Integration_user_notification',
 					'AD_Integration_admin_notification',
 					'AD_Integration_admin_email'
 				),
 				'ADI-usermeta-settings' => array(
 					'AD_Integration_additional_user_attributes',
 					'AD_Integration_usermeta_empty_overwrite',
 					'AD_Integration_show_attributes',
 					'AD_Integration_attributes_to_show',
 					'AD_Integration_syncback',
 					'AD_Integration_syncback_use_global_user',
 					'AD_Integration_syncback_global_user',
 					'AD_Integration_syncback_global_pwd'
 				),
 				'ADI-bulkimport-settings' => array(
 					'AD_Integration_bulkimport_enabled',
 					'AD_Integration_bulkimport_new_authcode',
 					'AD_Integration_bulkimport_security_groups',
 					'AD_Integration_bulkimport_user',
 					'AD_Integration_bulkimport_pwd'
 				)
 			);
 					
 			$this_page = $arrPost['option_page'];
 				
			foreach ( $all_options[$this_page] as $option ) {
				$val = false;
				
				if ( isset( $arrPost[$option] ) ) {
					$val = $arrPost[$option];
				}
				
				// Update if possible; otherwise delete
				if ( $val ) {
					update_site_option( $option, $val );
				} else {
					delete_site_option( $option );
				}
			}
			
			// let's load the new values
			$this->_load_options();
		}
	}
	
	function login( $username, $password ) {
		$this->adldap = @new BLSCI_adLDAP( array(
			"base_dn" => $this->_base_dn, 
			"domain_controllers" => explode(';', $this->_domain_controllers),
			"ad_port" => $this->_port, // AD port
			"use_tls" => $this->_use_tls, // secure?
			"network_timeout" => $this->_network_timeout, // network timeout
			"ad_username" => $username,
			"ad_password" => $password
		) );
	}
	
	function log_api_error( $wp_user, $error_data ) {
		$this->wp_errors[] = array(
			'wp_user'    => $wp_user,
			'error_data' => $error_data
		);
	}
}

// Important! Instantiates the new class, overriding the previous plugin
add_action( 'init', create_function( '', 'global $AD_Integration_plugin; $AD_Integration_plugin = new BLSCI_AD_Fix;' ), 99 );

/**
 * An epic hack.
 *
 * This function catches form requests sent by the plugin to the wrong place (options.php). It then
 * reassembles the original $_POST parameters (ie the new settings), and uses wp_remote_post() to
 * save the settings to the correct page. (The cookies are there so that you don't get a 500 when
 * you try to access the admin pages as an unauthenticated post.) Then the user is redirected back
 * to the original dashboard panel.
 *
 * It feels so wrong, yet it feels so right.
 *
 * @package BLSCI AD
 * @since 1.0
 */
function blsciad_options_save() {
	global $wp_query, $wp;
	
	// Catch attempts to send requests to the plugin page
	if ( isset( $wp_query->query['pagename'] ) && 'wp-admin/network/options.php' == $wp_query->query['pagename'] ) {

		// Triple-check that this is our request
		if ( !empty( $_POST['option_page'] ) && 'ADI-server-settings' != $_POST['option_page'] )
			return;

		// Send to the correct place
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options.php' );
		$redirect = add_query_arg( 'page', 'active-directory-integration/ad-integration.php', $base );
		
		$cookies = array();
		foreach( $_COOKIE as $key => $value ) {
			$cookies[] = new WP_Http_Cookie( array( 'name' => $key, 'value' => $value ) );
		}
		
		$args = array( 'body' => $_POST, 'cookies' => $cookies );
		
		$test = wp_remote_post( $redirect, $args );
		
		wp_redirect( $redirect );
		
	}
}
add_action( 'wp', 'blsciad_options_save', 1 );

function blsciad_test_catch() {
	global $wp_query, $ad_integration_plugin_path;
	
	if ( isset( $wp_query->query['pagename'] ) && 'wp-content/mu-plugins/active-directory-integration/test.php' == $wp_query->query['pagename'] ) {
		include ( ADBB_INCLUDES_PATH . 'test.php' );
		die();
	}
}
add_action( 'wp', 'blsciad_test_catch' );

// Provide the deprecated is_site_admin()
if ( !function_exists( 'is_site_admin' ) ) :
	function is_site_admin() {
		return is_super_admin();
	}
endif;

?>
