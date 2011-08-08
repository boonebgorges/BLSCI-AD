<?php

if ( class_exists( 'adLDAP' ) ) :

class BLSCI_adLDAP extends adLDAP {	
	public function find_user_by( $data, $method ) {
		$method_by = '';
		
		switch ( $method ) {
			case 'name' :
			case 'cn' :
				$method_by = 'cn';
				break;
				
			case 'mail' :
			case 'email' :
				$method_by = 'mail';
				break;
		}
		
		if ( !$method )
			return false;
	
		// Perform the search
		$filter = "(&(objectClass=user)(samaccounttype=" . ADLDAP_NORMAL_ACCOUNT . ")(objectCategory=person)(". $method_by . "=". $data . "))";
		$fields = array( 
			"samaccountname",
			"displayname",
		);
		
		$sr = ldap_search( $this->_conn, $this->_base_dn, $filter, $fields );
		$entries = ldap_get_entries( $this->_conn, $sr );
	
		$include_desc = true;
	
		$users_array = array();
		for ($i=0; $i<$entries["count"]; $i++){
		    if ($include_desc && strlen($entries[$i]["displayname"][0])>0){
			$users_array[ $entries[$i]["samaccountname"][0] ] = $entries[$i]["displayname"][0];
		    } elseif ($include_desc){
			$users_array[ $entries[$i]["samaccountname"][0] ] = $entries[$i]["samaccountname"][0];
		    } else {
			array_push($users_array, $entries[$i]["samaccountname"][0]);
		    }
		}
		
		asort( $users_array );
		
		return( $users_array );
	}
	
	public function find_user_by_name( $name ) {
		return $this->find_user_by( $name, 'cn' );
	}
	
	public function find_user_by_email( $email ) {
		$user = $this->find_user_by( $email, 'mail' );
	
		// Compatibility with older style Baruch email addresses
		if ( empty( $user ) && false !== strpos( $email, '_' ) ) {
			$email = str_replace( '_', '.', $email );
			$user = $this->find_user_by( $email, 'mail' );
		}
		
		return $user;
	}
}

endif;

?>