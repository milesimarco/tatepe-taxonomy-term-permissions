<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The rules are kept unless the user asked to delete them in the plugin settings.
if ( get_option( 'tatepe_delete_on_uninstall' ) ) {
	delete_option( 'tatepe_roles' );
	delete_option( 'tatepe_users' );
	delete_option( 'tatepe_delete_on_uninstall' );
}
