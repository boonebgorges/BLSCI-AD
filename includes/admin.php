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
	$plugin_page = add_submenu_page( 'settings.php', __( 'BLSCI AD', 'blsci-ad' ), __( 'BLSCI AD', 'blsci-ad' ), 'edit_users', 'blsci-ad', 'blsciad_network_panel_render' );
	
	add_action( "admin_print_styles-$plugin_page", 'blsciad_admin_styles' );
}
add_action( 'network_admin_menu', 'blsciad_add_network_panel' );

function blsciad_admin_styles() {
	wp_enqueue_style( 'blsci-admin-css', WP_PLUGIN_URL . '/blsci-ad/includes/css/admin-css.css' );
}

/**
 * Renders the admin panel
 * 
 * @package BLSCI-AD
 * @since 1.0
 */
function blsciad_network_panel_render() {
	$form_action_base = add_query_arg( 'page', 'blsci-ad', network_admin_url( 'settings.php' ) );
	
	$subpage = isset( $_GET['subpage' ] ) ? $_GET['subpage'] : 'migration-logs';
	
	?>
	
	<div class="wrap">
	
	<h2><?php _e( 'BLSCI AD Tools', 'blsci-ad' ) ?></h2>
	
	<ul class="ia-tabs">
    		<li<?php if ( 'migration-logs' == $subpage ) : ?> class="current"<?php endif ?>>
    			<a href="<?php echo add_query_arg( 'subpage', 'migration-logs', $form_action_base ) ?>"><?php _e( 'Migration Logs', 'blsci-ad' ) ?></a>
    		</li>
    		
    		<li<?php if ( 'migrate' == $subpage ) : ?> class="current"<?php endif ?>>
    			<a href="<?php echo add_query_arg( 'subpage', 'migrate', $form_action_base ) ?>"><?php _e( 'Migrate Users', 'blsci-ad' ) ?></a>
    		</li>
    	</ul>
    
	<?php if ( !empty( $error ) ) : ?>
	<div id="message" class="error">
		<p><?php echo $error ?></p>
	</div>
	<?php endif ?>
	
	<?php if ( 'migration-logs' == $subpage ) : ?>
		
		<?php if ( isset( $_GET['blsci_action'] ) && 'change_username' == $_GET['blsci_action'] ) : ?>
			<?php blsciad_username_edit_render() ?>
		<?php elseif ( isset( $_GET['blsci_action'] ) && 'resetall' == $_GET['blsci_action'] ) : ?>
			<?php blsciad_reset_all_logs() ?>
		<?php else : ?>
			<?php blsci_logs_render() ?>
		<?php endif ?>
	
	<?php else : ?>
		
		<form method="post" action="<?php echo add_query_arg( 'subpage', 'migrate', $form_action_base ) ?>">
		
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

	<?php endif ?>
	
	</div>
	
	<?php
}

function blsciad_migrate_step() {
	global $wpdb;
	
	// The URL base used to concatenate refresh URLs
	$migration_step_base = add_query_arg( array( 'page' => 'blsci-ad', 'blsci_action' => 'migrate', 'subpage' => 'migrate' ), network_admin_url( 'settings.php' ) );

	/**
	 * Get the users to migrate. These will be users who meet the following criteria:
	 *   1) They have no logs available
	 *   2) Their user_id != 1 (this user is always WP authenticated)
	 * I could probably do this with WP_User_Query but it would take a manual filter, so eff it
	 */
	$already_users_sql = $wpdb->prepare( "SELECT post_author FROM $wpdb->posts WHERE post_type = 'blsci_ad_migration'");
	$already_users = $wpdb->get_col( $already_users_sql );
	
	$already_users_sql = implode( ',', $already_users );

	// We'll need the total user count so that we know when to stop looping
	//$total_users = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->users WHERE ID != 1 AND ID NOT IN  ($already_users_sql )" ) );
	
	$total_users = get_option( 'blsci_members_to_go' );
	
	// Get the start and end numbers
	$per_page = 5;
	$start    = isset( $_GET['start'] ) ? $_GET['start'] : 1;
	$end 	  = $start + $per_page;
	
	// on the first page, save the credentials
	if ( empty( $_GET['start'] ) ) {
		
		$total_users_sql = $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->users WHERE ID != 1" );
		
		if ( $already_users_sql ) {
			$total_users_sql .= " AND ID NOT IN  ($already_users_sql )";
		}
		
		$total_users = $wpdb->get_var( $total_users_sql );
		
		//$tud = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->users WHERE ID != 1 AND ID NOT IN ( $already_users_sql )" ) );
		//echo '<pre>';var_dump( $tud ); die();
		
		update_option( 'blsci_members_to_go', $total_users );
		update_option( 'blsciad_temp_creds', array( 'username' => $_POST['blsci-ad-username'], 'password' => $_POST['blsci-ad-password'] ) );
	}
	
	$already_users_clause = !empty( $already_users_sql ) ? "AND ID NOT IN ( " . $already_users_sql . " ) " : '';
	
	$users_sql = $wpdb->prepare( "SELECT ID FROM $wpdb->users WHERE ID != 1 $already_users_clause LIMIT 0, %d",  $per_page );
	
	$users = $wpdb->get_col( $users_sql );
	
	// Is this the last page?
	if ( empty( $users ) ) {
		// We're headed home
		$migration_step_base = add_query_arg( 'status', 'finished', remove_query_arg( 'blsci_action', $migration_step_base ) );
		
		// Set the end number to the total number of users
		$end = $total_users;
		
		// So we can access it later if need be
		$is_last_page = true;
		
		delete_option( 'blsci_members_to_go' );
	}

	// For testing with Luke's account
	// $users = array( 11 );
	
	// Attempt to bind the AD server
	global $AD_Integration_plugin;
	$creds = get_option( 'blsciad_temp_creds' );
	if ( empty( $creds ) )
		return;
		
	$AD_Integration_plugin->login( $creds['username'], $creds['password'] );
	
	
	echo '<p>' . sprintf( 'Currently migrating users %1$d through %2$d of %3$d. This page will automatically refresh in a few seconds.', (int)$start, (int)$end, (int)$total_users ) . '</p>';
	
	echo '<ul>';
	$counter = $start;
	foreach( (array)$users as $user_id ) {
		echo '<li>(' . $counter . ') User #' . $user_id . ': ';
		$migrate_status = blsciad_migrate_user( $user_id );
		echo ' ';
		switch ( $migrate_status ) {
			case 'not_found' :
				echo 'User not found in AD';
				break;
			case 'unknown_failure' :
				echo 'Unknown failure';
				break;
			case 'unchanged' :
				echo 'This user has already been migrated';
				break;
			case 'located_by_name' :
				echo 'We couldn\'t find a user whose email address matches, but we found a matching display name. The user has not been migrated, but the possible match has been recorded for you to look at later.';
				break;
			case 'success' :
			default :
				echo 'Success!';
				break;
		}
		echo '</li>';
		$counter++;
	}
	echo '</ul>';
	
	// Do the refresh javascript
	$url = add_query_arg( 'start', $start + $per_page, $migration_step_base );
	
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

function blsciad_migrate_user( $user_id, $new_username = false ) {	
	global $AD_Integration_plugin, $wpdb;
	
	if ( !$user_id )
		return;
	
	// Never migrate the main site admin
	if ( 1 == $user_id )
		return;

	$ad_user = false;	
	
	$migration_args = array(
		'wp_user_id' 	    => $user_id,
		'wp_display_name'   => $user->display_name,
		'date_registered'   => false,
		'date_last_active'  => false,
		'date_attempted'    => time(),
		'migration_status'  => 'success'
	);
	
	$migration = new BLSCI_AD_Migration( $migration_args );
	
	// Pull up the userdata so that we can get the email address
	$user = get_userdata( $user_id );
	
	if ( !$user ) {
		$AD_Integration_plugin->log_api_error( $user, 'not_found' );
		$migration->mark_as_failure();
		return 'not_found';
	}
	
	// This is a bit hackish. Echo the user display name and login
	echo '<strong>' . $user->display_name . ' (' . $user->user_login . ')</strong>';
	
	if ( !$new_username ) {
		// Try to find a user with this email address
		$ad_user = $AD_Integration_plugin->adldap->find_user_by_email( $user->user_email );
	}
	
	if ( !$new_username ) {
		// Couldn't find one. Let's look for exact matches by name
		if ( !$ad_user ) {		
			if ( $ad_user = $AD_Integration_plugin->adldap->find_user_by_email( $user->display_name ) ) {
				$found_method = 'name';
				$migration_args['migration_status'] = 'located_by_name';
				$migration->set_ad_username( $ad_username );
				$migration->mark_as_failure( 'located_by_name' );
				return 'located_by_name';
			}
		} else {
			$found_method = 'email';
		}
		
		// If we haven't found an AD user by now, we're out of options.
		if ( !$ad_user ) {
			$AD_Integration_plugin->log_api_error( $user, 'not_found' );
			$migration->mark_as_failure();
			return 'not_found';
		}	
		
		// Now that we've got an AD user name, let's do some conversion
		
		// Get the AD username from the returned data
		$ad_user_values = array_keys( $ad_user );
		$ad_username = $ad_user_values[0];
				
		// Get the rest of the userinfo
		$ad_userinfo = $AD_Integration_plugin->adldap->user_info( $ad_username );
		
		// Get the user email out of this info (this is their primary login for WP)
		$ad_email = isset( $ad_userinfo[0]['mail'][0] ) ? $ad_userinfo[0]['mail'][0] : false;
		
		$ad_accountname = isset( $ad_userinfo[0]['samaccountname'][0] ) ? strtolower( $ad_userinfo[0]['samaccountname'][0] ) : $ad_email;

	}
	
	// If the data was provided manually
	if ( $new_username ) {
		$ad_accountname = $new_username;
	}
	
	$migration->set_ad_username( $ad_accountname );
	
	// Stash the old WP username in a usermeta for later use. Only do this once.
	if ( !get_user_meta( $user_id, 'blsci_deprecated_wp_user_login', true ) ) {
		update_user_meta( $user_id, 'blsci_deprecated_wp_user_login', $user->user_login );
		update_user_meta( $user_id, 'blsci_deprecated_wp_user_nicename', $user->user_nicename );
	}
	
	// Check to see if the username needs changing
	if ( $user->user_login == $ad_accountname ) {
		$migration->mark_as_unchanged();
		return 'unchanged';
	}
	
	// Now we can do the migration itself
	$sql = $wpdb->prepare( "UPDATE {$wpdb->users} SET user_login = %s, user_nicename = %s WHERE ID = %d;", $ad_accountname, sanitize_title( $ad_accountname ), (int)$user_id );
	$result = $wpdb->query( $sql );
	
	// Record as a success or a failure
	if ( $result ) {
		$migration->mark_as_success();
	} else {
		$migration->mark_as_failure( 'unknown_failure' );
	}
	
	return $result ? 'success' : 'unknown_failure';
}

function blsciad_username_edit_render() {
	global $wpdb;
	
	$user_id = isset( $_GET['user_id'] ) ? (int)$_GET['user_id'] : 0;
	
	$userdata = get_userdata( $user_id );
	
	if ( isset( $_POST['username-edit-submit'] ) ) {
		check_admin_referer( 'blsciad_username_edit' );
		
		$new_username = isset( $_POST['username'] ) ? $_POST['username'] : '';
		
		$migrate = blsciad_migrate_user( $user_id, $new_username );
	
		// so it displays correctly
		$userdata->user_login = $new_username;
	}
	
	?>
	
	<h3><?php _e( 'Username Edit', 'blsci-ad' ) ?></h3>
	
	<?php if ( $userdata ) : ?>
		
		<form method="post" action="">
		
		<table class="form-table">
			<tr>
				<th scope="row">
					<?php _e( 'User ID', 'blsci-ad' ) ?>
				</th>
				
				<td>
					<?php echo esc_html( $userdata->ID ) ?>
				</td>
			</tr>
			
			<tr>
				<th scope="row">
					<?php _e( 'Current WP Username', 'blsci-ad' ) ?>
				</th>
				
				<td>
					<input name="username" value="<?php echo esc_html( $userdata->user_login ) ?>" />
				</td>
			</tr>
			
			<tr>
				<th scope="row">
					<?php _e( 'Pre-AD WP Username', 'blsci-ad' ) ?>
				</th>
				
				<td>
					<?php if ( isset( $userdata->blsci_deprecated_wp_user_login ) ) : ?>
						<?php echo esc_html( $userdata->blsci_deprecated_wp_user_login ) ?>
					<?php else : ?>
						<em><?php _e( '(none)', 'blsci-ad' ) ?></em>
					<?php endif ?>
				</td>
			</tr>
			
			<tr>
				<th scope="row">
					<?php _e( 'Email address', 'blsci-ad' ) ?>
				</th>
				
				<td>
					<?php echo esc_html( $userdata->user_email ) ?>
				</td>
			</tr>
			
			<tr>
				<th scope="row">
					<?php _e( 'Display name', 'blsci-ad' ) ?>
				</th>
				
				<td>
					<?php echo esc_html( $userdata->display_name ) ?>
				</td>
			</tr>
		</table>
		
		<br /><br />
		
		<?php wp_nonce_field( 'blsciad_username_edit' ) ?>
		<input type="submit" class="button-primary confirm" name="username-edit-submit" value="<?php _e( 'Submit', 'blsci-ad' ) ?>" />
		
		</form>
	
	<?php else : ?>
		
		<p><?php _e( 'No user found.', 'blsci-ad' ) ?></p> 
		
	<?php endif ?>
	
	<?php
}

function blsci_logs_render() {
	// Include the pagination and columns libraries
	include_once( ADBB_INSTALL_PATH . 'lib/boones-pagination.php' );
	include_once( ADBB_INSTALL_PATH . 'lib/boones-sortable-columns.php' );
	
	$pagination = new BBG_CPT_Pag();
	
	// Set the base URL for use in the row actions
	$base_url = add_query_arg( 'page', 'blsci-ad', network_admin_url( 'settings.php' ) );
	
	$cols = array(
		array(
			'name'		=> 'wp_username',
			'title'		=> __( 'WP Username', 'blsci-ad' ),
			'css_class'	=> 'wp-username',
			'is_default'	=> true
		),
		array(
			'name'		=> 'display_name',
			'title'		=> __( 'Display Name', 'blsci-ad' ),
			'css_class'	=> 'Display Name'
		),
		array(
			'name'		=> 'email',
			'title'		=> __( 'Email', 'blsci-ad' ),
			'css_class'	=> 'email'
		),
		array(
			'name'		=> 'ad_username',
			'title'		=> __( 'AD Username', 'blsci-ad' ),
			'css_class'	=> 'ad-username'
		),
		array(
			'name'		=> 'last_activity',
			'title'		=> __( 'Last Activity', 'blsci-ad' ),
			'css_class'	=> 'last-activity',
			'default_order'	=> 'desc'
		),
		array(
			'name'		=> 'date_registered',
			'title'		=> __( 'Date Registered', 'blsci-ad' ),
			'css_class'	=> 'date-registered',
			'default_order'	=> 'desc'
		),
		array(
			'name'		=> 'date_migrated',
			'title'		=> __( 'Date migrated (attempted)', 'blsci-ad' ),
			'css_class'	=> 'date-migrated'
		),
		array(
			'name'		=> 'migration_status',
			'title'		=> __( 'Status', 'blsci-ad' ),
			'css_class'	=> 'migration-status'
		)
	);
	
	$sortable    = new BBG_CPT_Sort( $cols );
	
	$args = array(
		'orderby'		=> $sortable->get_orderby,
		'order'			=> $sortable->get_order,
		'posts_per_page'	=> $pagination->get_per_page,
		'paged'			=> $pagination->get_paged, 
	);
	
	$filter = isset( $_GET['filter'] ) ? $_GET['filter'] : false;
	
	if ( $filter )
		$args['status'] = $filter;
	
	$migrations = new BLSCI_AD_Migration( $args ); 
	
	?>

	<h3>Migration Logs</h3>
	
	<p><?php _e( 'On this page, you can view the results of previous user migration attempts.', 'blsci-ad' ) ?></p>
	
	<p>You can reset all migration logs. Beware: there is no undo. <a class="button-secondary" href="<?php echo add_query_arg( 'blsci_action', 'resetall', $base_url ) ?>">Reset</a></p>
	
	<?php if ( $migrations->have_posts() ) : ?>
		
		<?php $pagination->setup_query( $migrations->migrations ) ?>
		
		<ul class="subsubsub">
			<li class="all">
				<a <?php if ( empty( $filter ) ) : ?>class="current" <?php endif ?>href="<?php echo $base_url ?>"><?php _e( 'All', 'blsci-ad' ) ?></a> | 
			</li>
			
			<li class="success">
				<a <?php if ( 'success' == $filter ) : ?>class="current" <?php endif ?>href="<?php echo add_query_arg( 'filter', 'success' ) ?>"><?php _e( 'Successful', 'blsci-ad' ) ?></a> |
			</li>
			
			<li class="failure">
				<a <?php if ( 'failure' == $filter ) : ?>class="current" <?php endif ?>href="<?php echo add_query_arg( 'filter', 'failure' ) ?>"><?php _e( 'Failed', 'blsci-ad' ) ?></a>
			</li>
		</ul>

		<div class="ia-admin-pagination">
			
			<div class="currently-viewing">
				<?php $pagination->currently_viewing_text() ?>
			</div>
			
			<div class="pag-links">
				<?php $pagination->paginate_links() ?>
			</div>
		</div>
		
		<table class="wp-list-table widefat ia-invite-list">
		
		<thead>
			<tr>
				<th scope="col" id="cb" class="check-column">
					<input type="checkbox" />
				</th>
				
				<?php if ( $sortable->have_columns() ) : while ( $sortable->have_columns() ) : $sortable->the_column() ?>
					<?php $sortable->the_column_th() ?>
				<?php endwhile; endif ?>
				
			</tr>
		</thead>
		
		<tbody>
		
		<?php while ( $migrations->have_posts() ) : $migrations->the_post() ?>
			<?php /* Get the userdata for later use */ ?>
			<?php $userdata = get_userdata( get_the_author_ID() ) ?>
			
			<tr>
				<th scope="row" class="check-column">
					<input type="checkbox" />
				</th>
				
				<td class="wp-username">
					<?php /* Here and elsewhere I will query data in the loop. Don't care much about efficiency because this is the Dashboard */ ?>
					<?php if ( $wp_username = get_user_meta( get_the_author_ID(), 'blsci_deprecated_wp_user_login', true ) ) : ?>
						<?php echo esc_html( $wp_username ) ?>
					<?php else : ?>
						<?php the_author_meta( 'user_login' ) ?>
					<?php endif ?>
					
					<div class="row-actions">
						<span class="edit"><a href="<?php echo add_query_arg( array( 'blsci_action' => 'change_username', 'user_id' => get_the_author_ID() ), $base_url ) ?>"><?php _e( 'Manually Edit Username', 'blsci-ad' ) ?></a></span>						
					</div>
				</td>
				
				<td class="display-name">
					<?php the_title() ?>
				</td>
				
				<td class="email">
					<?php the_author_meta( 'user_email' ) ?>
				</td>
				
				<td class="ad-username">
					<?php $maybe_success = get_post_meta( get_the_ID(), 'blsci_migration_status', true ) ?>
					
					<?php if ( 'success' == $maybe_success ) : ?>
						<?php the_author_meta( 'user_login' ) ?>
					<?php elseif ( 'unchanged' == $maybe_success ) : ?>
						<?php the_author_meta( 'user_login' ) ?>
					<?php elseif ( 'located_by_name' == $maybe_success ) : ?>
						<?php echo get_post_meta( get_the_ID(), 'blsci_ad_username', true ) ?> <span class="description"><?php _e( '(suggested but unchanged)', 'blsci-ad' ) ?></span>
					<?php else : ?>
						<span class="description"><?php _e( '(none)', 'blsci-ad' ) ?></span>
					<?php endif ?>
				</td>
				
				<td class="last-activity">
					<?php echo esc_html( get_user_meta( get_the_author_ID(), 'last_activity', true ) ) ?>
				</td>
				
				<td class="date-registered">
					<?php echo esc_html( $userdata->user_registered ) ?>
				</td>
				
				<td class="date-migrated">
					<?php echo esc_html( get_the_date( "Y-m-d H:i:s" ) ) ?>
				</td>
				
				<td class="migration-status">
					<?php $status = get_post_meta( get_the_ID(), 'blsci_migration_status', true ) ?>
					
					<?php echo esc_html( ucwords( str_replace( '_', ' ', $status ) ) ) ?>
				</td>
				
				
			</tr>
		<?php endwhile ?>
		
		</tbody>
	
		</table>
	<?php else : ?>
		<p><?php _e( 'You haven\'t attempted any user migrations yet.', 'blsci-ad' ) ?></p>
	<?php endif ?>
	
	<?php
}

function blsciad_reset_all_logs() {
	global $wpdb;
	?>
	
	<p>Resetting...</p>
	
	<?php
	
	// Call up all users
	$users = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->users WHERE ID != 1" ) );
	
	foreach ( $users as $user ) {
		$old_username 	  = get_user_meta( $user, 'blsci_deprecated_wp_user_login', true );
		$old_usernicename = get_user_meta( $user, 'blsci_deprecated_wp_user_nicename', true );
		
		$update_vals = array();
		
		if ( $old_username )
			$update_vals['user_login'] = $old_username;
		
		if ( $old_usernicename )
			$update_vals['user_nicename'] = $old_usernicename;
		
		// Reset the username/nicename
		if ( !empty( $update_vals ) ) {
			$wpdb->update( $wpdb->users, $update_vals, array( 'ID' => $user ) );
			
			// Delete the old metadata
			delete_user_meta( $user, 'blsci_deprecated_wp_user_login' );
			delete_user_meta( $user, 'blsci_deprecated_wp_user_nicename' );
		}
	}
	
	// Now, delete all logs
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE post_type = 'blsci_ad_migration'" ) );
	
	$url = add_query_arg( array( 'page' => 'blsci-ad' ), network_admin_url( 'admin.php' ) );
	
	?>
	
	<p>Done! <a href="<?php echo $url ?>">Go back</a></p>
	
	<?php
}

?>