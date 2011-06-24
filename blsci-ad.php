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

// Include the relevant pieces
include( ADBB_INCLUDES_PATH . 'plugin-fix.php' ); // Fixes for the AD Integration plugin


?>