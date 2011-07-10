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
	if ( !$user_id )
		return;
	
	// Never migrate the main site admin
	if ( 1 == $user_id )
		return;
	
	$user = get_userdata( $user_id );
	var_dump( $user );
	
	$adapi = new BLSCI_adLDAP;
	var_dump( $adapi ); die();
}

?>