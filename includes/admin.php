<?php

/**
 * Network admin panel and functions
 */

/**
 * Adds the network admin panel
 * 
 * @package BLSCI-AD
 * @since 1.0
 */
function blsciad_add_network_panel() {
	add_submenu_page( 'settings.php', __( 'BLSCI AD', 'blsci-ad' ), __( 'BLSCI AD', 'blsci-ad' ), 'edit_users', 'blsci-ad', 'blsciad_network_panel_render' );
}
add_action( 'network_admin_menu', 'blsciad_add_network_panel' );

/**
 * Renders the admin panel
 * 
 * @package BLSCI-AD
 * @since 1.0
 */
function blsciad_network_panel_render() {
	
	$form_action_base = add_query_arg( 'page', 'blsci-ad', network_admin_url( 'settings.php' ) );
	
	?>
	
	<div class="wrap">
	
	<h2><?php _e( 'BLSCI AD Tools', 'blsci-ad' ) ?></h2>
	
	<?php if ( !empty( $error ) ) : ?>
	<div id="message" class="error">
		<p><?php echo $error ?></p>
	</div>
	<?php endif ?>
	
	<form method="post" action="<?php echo $form_action_base ?>">
	
	<h3>WP &rarr; AD User Migration</h3>
	
	<?php
	
	// Are we trying to start the migration script?
	if ( isset( $_POST['blsci-migrate'] ) ) {
		// Check to see whether the confirmation checkbox is checked
		if ( isset( $_POST['blsci-confirm'] ) ) {
			
			// Did you mean to do this?
			check_admin_referer( 'blsciad-migrate' );
			
			// Attempt to bind the AD server
			global $AD_Integration_plugin;
			
			$AD_Integration_plugin->login( $_POST['blsci-ad-username'], $_POST['blsci-ad-password'] );
			
			// Start the migration
			blsciad_migrate_step();
			return;
		} else {
			// Post a message
			$error = 'You must check the "OK, I understand..." box before continuing.';
		}
	}
	
	// Are we in the middle of a migration?
	if ( isset( $_GET['blsci_action'] ) && 'migrate' == $_GET['blsci_action'] ) {
		blsciad_migrate_step();
		return;
	}
	
	?>
	
	<p>This script will help you to migrate your existing WP users to Active Directory. It works like this:</p>
	
	<ul>
		<li>For each WP user, we ping your AD server to see whether a user exists by the same email address.
		<li>If a corresponding AD user exists, the AD username is collected, and the user_login and user_nicename of the WP user are changed to match the AD username (if necessary - there may be cases where they are the same and don't need to be changed).</li>
		<li>The old WP user_login and user_nicename will be stored as usermeta, to allow users to continue to log in using their old username if so desired. (Note that, even in the case where old usernames can be used, the AD password must always be used once AD authentication has been switched on.)</li>
		<li>When a user cannot be found by exact email match, the plugin will attempt to do a lookup by first name - last name. Exact and near matches will be presented to you at the end of the process, so that you can decide whether to migrate them as well.</li>
		<li>At the end of the process, you will see a list of WP users for whom corresponding AD users could not be found. These users will have to be migrated manually.</li>
		<li>For all WP users, whether successfully migrated or not, metadata will be stored that will keep track of whether the AD migration was successful. This should help you with bookkeeping and troubleshooting.</li> 
	</ul>
	
	<p>When you click "Migrate", the migration process will begin. There is no "undo". Be sure that you have read, and that you understand, the notes above.</p>
	
	<p>
		<label for="blsci-confirm"><input type="checkbox" name="blsci-confirm" /> OK, I understand what I'm doing</label>
	</p>
	
	<p>
		The plugin needs to use a set of valid AD credentials to do its work. Please enter a working username and password below before hitting Migrate.<br />
		<label for="blsci-ad-username"><input type="text" name="blsci-ad-username" /> Username</label><br />
		<label for="blsci-ad-password"><input type="password" name="blsci-ad-password" /> Password</label>
	</p>
	
	<p>
		<?php wp_nonce_field( 'blsciad-migrate' ) ?>
		<input type="submit" name="blsci-migrate" value="Migrate" />
	</p>
	
	</form>
	
	</div>
	
	<?php
}

function blsciad_migrate_step() {
	global $wpdb;
	
	// The URL base used to concatenate refresh URLs
	$migration_step_base = add_query_arg( array( 'page' => 'blsci-ad', 'blsci_action' => 'migrate' ), network_admin_url( 'settings.php' ) );

	// We'll need the total user count so that we know when to stop looping
	$total_users = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->users WHERE ID != 1" ) );
	
	// Get the start and end numbers
	$per_page = 5;
	$start    = isset( $_GET['start'] ) ? $_GET['start'] : 1;
	$end 	  = $start + $per_page;
	
	// Is this the last page?
	if ( $end >= $total_users ) {
		// We're headed home
		$migration_step_base = add_query_arg( 'status', 'finished', remove_query_arg( 'blsci_action', $migration_step_base ) );
		
		// Set the end number to the total number of users
		$end = $total_users;
		
		// So we can access it later if need be
		$is_last_page = true;
	}
	
	/**
	 * Get the users to migrate. These will be users who meet the following criteria:
	 *   1) They have no blsci_results usermeta
	 *   2) Their user_id != 1 (this user is always WP authenticated)
	 * I could probably do this with WP_User_Query but it would take a manual filter, so eff it
	 */
	$users_sql = $wpdb->prepare( "SELECT ID FROM $wpdb->users WHERE $wpdb->users.ID != 1 AND NOT EXISTS (SELECT * FROM $wpdb->usermeta WHERE $wpdb->usermeta.user_id = $wpdb->users.ID AND $wpdb->usermeta.meta_key = 'blsci_results') LIMIT 0, %d", $per_page );
	
	$users = $wpdb->get_col( $users_sql );
	
	foreach( (array)$users as $user_id ) {
		blsciad_migrate_user( $user_id );
	}
	
	// Do the refresh javascript
	$url = $migration_step_base;

	?>	
	<script type='text/javascript'>
		<!--
		function nextpage() {
			location.href = "<?php echo $url ?>";
		}
		setTimeout( "nextpage()", 3000 );
		//-->
	</script>
	<?php
	
	echo $migration_step_base;
}

function blsciad_migrate_user( $user_id ) {	
	global $AD_Integration_plugin;
	
	if ( !$user_id )
		return;
	
	// Never migrate the main site admin
	if ( 1 == $user_id )
		return;

	$ad_user = false;
	
	// Pull up the userdata so that we can get the email address
	$user = get_userdata( $user_id );
	
	// Try to find a user with this email address
	$ad_user = $AD_Integration_plugin->adldap->find_user_by_email( $user->user_email );
	
	// Couldn't find one. Let's look for exact matches by name
	if ( !$ad_user ) {		
		if ( $ad_user = $AD_Integration_plugin->adldap->find_user_by_email( $user->display_name ) ) {
			$found_method = 'name';
		}
	} else {
		$found_method = 'email';
	}
	
	// If we haven't found an AD user by now, we're out of options.
	if ( !$ad_user ) {
		$AD_Integration_plugin->log_api_error( $user, 'not_found' );
		return;
	}	
	
	// Now that we've got an AD user name, let's do some conversion
	
	// Get the AD username from the returned data
	$ad_user_values = array_values( $ad_user );
	$ad_username = $ad_user_values[0];
	
	// If the AD username is the same as the WP username, we don't need to change anything,
	// though we do have to mark the user as successfully transfered
	$migration_args = array(
		'wp_user_id' 	    => $user_id,
		'ad_username'	    => $ad_username,
		'wp_display_name'   => $user->display_name,
		'date_registered'   => false,
		'date_last_active'  => false,
		'date_attempted'    => time(),
		'migration_status'  => 'success'
	);
	
	$migration = new BLSCI_AD_Migration( $migration_args );
	$migration->mark_as_success();
	
	var_dump( $migration );
	
	var_dump( $ad_username );
	
	var_dump( $ad_user );
	var_dump( $found_method );
        die();
}

?>