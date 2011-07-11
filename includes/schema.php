<?php

/**
 * Sets up the post type
 */
class BLSCI_AD_Schema {
	function __construct() {
		// Don't run this on a blog other than the root
		if ( 1 != get_current_blog_id() )
			return;
			
		add_action( 'init', array( &$this, 'register_post_type' ) );
	}
	
	function register_post_type() {		
		$args = array(
			'public' 	=> false,
			'show_in_menu'  => false,
			'query_var' 	=> false
		);
		
		register_post_type( 'blsci_ad_migration', $args );
	}

}
$blsci_ad_schema = new BLSCI_AD_Schema;

/**
 * The migration object. Used to keep track of migrations and how they went
 */
class BLSCI_AD_Migration {
	var $wp_user_id;
	var $ad_username;
	var $wp_display_name;
	var $date_registered;
	var $date_last_active;
	var $date_attempted;
	var $migration_status;
	
	var $post_id = false;

	function __construct( $args ) {
		$defaults = array(
			'wp_user_id' 	    => false,
			'ad_username'	    => false,
			'wp_display_name'   => false,
			'date_registered'   => false,
			'date_last_active'  => false,
			'date_attempted'    => time(),
			'migration_status'  => 'success'
		);
		
		$r = wp_parse_args( $args, $defaults );
		extract( $r );
		
		// We need a WP user id to continue
		if ( !$wp_user_id )
			return;
		
		foreach( $r as $key => $value ) {
			$this->{$key} = $value;		
		}
	}
	
	function mark_as_success() {
		// Make sure that the migration status is a success (is by default - double check)
		$this->migration_status = 'success';
		$this->save();
	}
	
	function mark_as_failure( $reason = 'no_user_found' ) {
		$this->migration_status = $reason;
		$this->save();
	}
	
	function save() {
		// Check to see if there's already a record for this migration
		$check_post = new WP_Query( array(
			'author'      => $this->wp_user_id,
			'post_type'   => 'blsci_ad_migration',
			'post_status' => 'publish'
		) );
		
		if ( $check_post->have_posts() ) {
			while ( $check_post->have_posts() ) {
				$check_post->the_post();
				$this->post_id = get_the_ID();
			}
		} else {
			$this->post_id = false;
		}
		
		// Set up the arguments to create the WP post
		
		// Format the date_attempted for WP posts
		if ( !is_int( $this->date_attempted ) ) {
			$this->date_attempted = strtotime( $this->date_attempted );
		}
		$post_date = date( "Y-m-d H:i:s", $this->date_attempted );
		
		// Make sure we have a display name, which will be used as the title and content
		if ( !$this->wp_display_name ) {
			$userdata = get_userdata( $this->wp_user_id );
			$this->wp_display_name = $userdata->display_name;
		}
		
		$save_args = array(
			'post_author'	=> $this->wp_user_id,
			'post_type'	=> 'blsci_ad_migration',
			'post_date'	=> $post_date,
			'post_title'	=> $this->wp_display_name,
			'post_content'	=> $this->wp_display_name,
			'post_status'	=> 'publish'
		);
		
		// Save the WP post (insert or update)
		if ( $this->post_id ) {
			$save_args['ID'] = $this->post_id;
			$this->post_id   = wp_update_post( $save_args );
		} else {
			$this->post_id   = wp_insert_post( $save_args );
		}
		
		// If there was a failure, bail
		if ( !$this->post_id || is_wp_error( $this->post_id ) )
			return false;
		
		// Now update the necessary postmeta
		update_post_meta( $this->post_id, 'blsci_migration_status', $this->migration_status );
		
		if ( $this->ad_username )		
			update_post_meta( $this->post_id, 'blsci_ad_username', $this->ad_username );

		$last_activity = get_user_meta( $this->wp_user_id, 'last_activity', true );
		update_post_meta( $this->post_id, 'blsci_last_activity', $last_activity );
		
		if ( !$this->date_registered ) {
			if ( empty( $usermeta ) ) {
				$userdata = get_userdata( $this->wp_user_id );
			}
			
			$this->date_registered = $userdata->user_registered;
		}
		
		update_post_meta( $this->post_id, 'blsci_date_registered', $this->date_registered );
	}
}

?>