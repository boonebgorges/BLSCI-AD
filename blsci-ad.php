<?php

/*
Plugin Name: AD tools for Blogs@Baruch
Plugin URI: http://blsci.baruch.cuny.edu
Description: Integration and migration tools for Active Directory. Built for Blogs@Baruch.
Author: Boone B Gorges
Author URI: http://boonebgorges.com
License: GPL3
Network: true
Version: 1.0
*/

// Define some helpful constants
define( 'ADBB_INSTALL_PATH', dirname( __FILE__ ) . '/' );
define( 'ADBB_INCLUDES_PATH', ADBB_INSTALL_PATH . 'includes/' );

// Load plugin files late, as they depend on the AD plugin
function blsci_ad_includes() {
	include( ADBB_INCLUDES_PATH . 'schema.php' ); // Sets up the custom post type, etc
	include( ADBB_INCLUDES_PATH . 'plugin-fix.php' ); // Fixes for the AD Integration plugin
	include( ADBB_INCLUDES_PATH . 'class-adldap.php' ); // The AD API class
	include( ADBB_INCLUDES_PATH . 'username-backpat.php' ); // Backpat for pre-AD usernames
}
add_action( 'plugins_loaded', 'blsci_ad_includes', 999 );

if ( is_network_admin() || is_admin() ) {
	include( ADBB_INCLUDES_PATH . 'admin.php' );
}

?>