<?php
/**
 * Plugin Name:       Redirect Chain Fixer
 * Plugin URI:        https://www.contentpowered.com
 * Description:       Finds links in your posts and pages that pass through redirects and points them straight at the final URL. Internal links are fixed automatically; external ones wait for your approval.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Content Powered
 * Author URI:        https://www.contentpowered.com/wordpress-plugins/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       redirect-chain-fixer
 * Update URI:        https://github.com/jmsprsns/Redirect-Chain-Fixer
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'ABSPATH' ) || exit;

// Version and repo are read from the header above, so a release only needs
// "Version" bumped and the GitHub repo lives in one place ("Update URI").
// The Update URI header also stops WordPress.org offering a same-slug plugin
// as an "update" that would overwrite this one.
$redirect_chain_fixer_headers = get_file_data(
	__FILE__,
	array(
		'version' => 'Version',
		'repo'    => 'Update URI',
	)
);

define( 'REDIRECT_CHAIN_FIXER_VERSION', $redirect_chain_fixer_headers['version'] );
define( 'REDIRECT_CHAIN_FIXER_REPO', $redirect_chain_fixer_headers['repo'] );
define( 'REDIRECT_CHAIN_FIXER_FILE', __FILE__ );
define( 'REDIRECT_CHAIN_FIXER_DIR', plugin_dir_path( __FILE__ ) );
define( 'REDIRECT_CHAIN_FIXER_URL', plugin_dir_url( __FILE__ ) );

unset( $redirect_chain_fixer_headers );

require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-icons.php';
require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-updater.php';
require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-menu.php';
require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-db.php';
require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-checker.php';
require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-content.php';
require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-scanner.php';
require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-admin.php';
require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-plugin.php';

// Built right away, outside any hook, so WP-Cron background updates and
// remote management tools see new releases too (not only logged-in admins).
Redirect_Chain_Fixer_Updater::init();

/**
 * Boot the plugin once all other plugins have loaded.
 *
 * @return Redirect_Chain_Fixer_Plugin
 */
function redirect_chain_fixer_plugin() {
	return Redirect_Chain_Fixer_Plugin::instance();
}
add_action( 'plugins_loaded', 'redirect_chain_fixer_plugin' );

/**
 * Set up on activation: create the tables, schedule the daily check and,
 * the first time only, start a full scan in the background.
 *
 * @param bool $network_wide Whether the plugin is being network activated.
 * @return void
 */
function redirect_chain_fixer_activate( $network_wide = false ) {
	// Network activation only runs this on the main site; the others set
	// themselves up on their next load (see Redirect_Chain_Fixer_Plugin).
	unset( $network_wide );

	Redirect_Chain_Fixer_DB::install();
	Redirect_Chain_Fixer_Scanner::schedule_daily();

	$state = Redirect_Chain_Fixer_Scanner::get_state();
	if ( empty( $state['finished'] ) && 'running' !== $state['status'] ) {
		Redirect_Chain_Fixer_Scanner::start();
	}
}
register_activation_hook( REDIRECT_CHAIN_FIXER_FILE, 'redirect_chain_fixer_activate' );

/**
 * Clean up when the plugin is deactivated.
 *
 * Settings, the change log and the review queue stay put (uninstall.php
 * removes them). Only the scheduled events are cleared.
 *
 * @return void
 */
function redirect_chain_fixer_deactivate() {
	Redirect_Chain_Fixer_Scanner::unschedule();
}
register_deactivation_hook( REDIRECT_CHAIN_FIXER_FILE, 'redirect_chain_fixer_deactivate' );
