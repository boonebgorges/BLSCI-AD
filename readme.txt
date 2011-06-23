The way the AD plugin checks for WPMU is outdated. You'll need to declare MU/MS/Network Mode manually, by putting the following in your wp-config.php file (somewhere above "That's all..."):
   define( 'IS_WPMU', true );
