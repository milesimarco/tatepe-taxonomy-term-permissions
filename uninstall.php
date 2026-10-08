<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The rules are kept unless the user asked to delete them in the plugin settings.
if ( get_option( 'taxonomy_permissions_delete_on_uninstall' ) ) {
	delete_option( 'taxonomy_permissions_roles' );
	delete_option( 'taxonomy_permissions_users' );
	delete_option( 'taxonomy_permissions_delete_on_uninstall' );
}
