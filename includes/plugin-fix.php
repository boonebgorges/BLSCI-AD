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

class BLSCI_AD_Fix extends ADIntegrationPlugin {

	/**
	 * Overrides the default constructor, to implement WP 3.1 network fixes
	 */
	function __construct() {
		global $wp_version, $wpmu_version, $wpdb, $wpmuBaseTablePrefix;

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
				add_submenu_page( 'settings.php', __( 'Active Directory Integration' ), __( 'Active Directory Integration' ), 'manage_options', $ad_integration_plugin_path . 'ad-integration.php', array(&$this, '_display_options_page') );
			}
		} else {
			// WordPress Standard
			if ( function_exists( 'add_options_page' ) ) {
				add_options_page( 'Active Directory Integration', 'Active Directory Integration', 'manage_options', $ad_integration_plugin_path . 'ad-integration.php', array(&$this, '_display_options_page') );
			}
		}
	}
}

// Important! Instantiates the new class, overriding the previous plugin
$AD_Integration_plugin = new BLSCI_AD_Fix;


// Provide the deprecated is_site_admin()
if ( !function_exists( 'is_site_admin' ) ) :
	function is_site_admin() {
		return is_super_admin();
	}
endif;

?>