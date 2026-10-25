<?php
/**
 * Runs when the plugin is deleted from the Plugins screen: remove its data.
 *
 * Posts keep the links the plugin fixed; only the plugin's own tables,
 * settings and scheduled events go.
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove the plugin's data from the current site.
 *
 * @return void
 */
function redirect_chain_fixer_uninstall_site() {
	global $wpdb;

	foreach ( array( 'redirect_chain_fixer_urls', 'redirect_chain_fixer_fixes' ) as $redirect_chain_fixer_table ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $redirect_chain_fixer_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Fixed table names.
	}

	foreach ( array( 'settings', 'db_version', 'scan', 'queue', 'lock' ) as $redirect_chain_fixer_option ) {
		delete_option( 'redirect_chain_fixer_' . $redirect_chain_fixer_option );
	}

	wp_clear_scheduled_hook( 'redirect_chain_fixer_batch' );
	wp_clear_scheduled_hook( 'redirect_chain_fixer_daily' );
}

if ( is_multisite() ) {
	$redirect_chain_fixer_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $redirect_chain_fixer_sites as $redirect_chain_fixer_site ) {
		switch_to_blog( $redirect_chain_fixer_site );
		redirect_chain_fixer_uninstall_site();
		restore_current_blog();
	}
} else {
	redirect_chain_fixer_uninstall_site();
}

// Plugin Update Checker's cached update state.
delete_site_option( 'external_updates-redirect-chain-fixer' );
