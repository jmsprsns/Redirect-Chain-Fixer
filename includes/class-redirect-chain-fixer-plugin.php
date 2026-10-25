<?php
/**
 * Main plugin orchestrator: owns the settings and boots the scanner and admin.
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Redirect_Chain_Fixer_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'redirect_chain_fixer_settings';

	/**
	 * Single shared instance.
	 *
	 * @var Redirect_Chain_Fixer_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Scanner instance.
	 *
	 * @var Redirect_Chain_Fixer_Scanner
	 */
	private $scanner;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Redirect_Chain_Fixer_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		// Updates and network activation don't run the activation hook, so
		// the tables and the daily check are set up here when they're missing.
		if ( is_admin() || wp_doing_cron() ) {
			Redirect_Chain_Fixer_DB::maybe_install();
			Redirect_Chain_Fixer_Scanner::schedule_daily();
		}

		$this->scanner = new Redirect_Chain_Fixer_Scanner( $this );

		if ( is_admin() ) {
			new Redirect_Chain_Fixer_Admin( $this );
		}
	}

	/**
	 * The scanner.
	 *
	 * @return Redirect_Chain_Fixer_Scanner
	 */
	public function scanner() {
		return $this->scanner;
	}

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'auto_fix'       => 1,
			'check_external' => 1,
			'post_types'     => array( 'post', 'page' ),
			'rescan'         => 'weekly',
			'skip_hosts'     => '',
		);
	}

	/**
	 * Choices for the automatic rescan setting: value => days between scans.
	 *
	 * @return array<string,int>
	 */
	public static function rescan_choices() {
		return array(
			'daily'   => 1,
			'weekly'  => 7,
			'monthly' => 30,
			'off'     => 0,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Content types that get scanned: the chosen ones that still exist.
	 *
	 * @return string[]
	 */
	public function post_types() {
		return array_values( array_filter( (array) $this->get_setting( 'post_types' ), 'post_type_exists' ) );
	}

	/**
	 * Days between automatic rescans (0 when they're off).
	 *
	 * @return int
	 */
	public function rescan_days() {
		$choices = self::rescan_choices();
		$rescan  = (string) $this->get_setting( 'rescan' );

		return isset( $choices[ $rescan ] ) ? $choices[ $rescan ] : 7;
	}

	/**
	 * Domains whose links are never checked, from the settings.
	 *
	 * @return string[] Lower-case hosts without "www.".
	 */
	public function skip_hosts() {
		$lines = preg_split( '/[\s,]+/', strtolower( (string) $this->get_setting( 'skip_hosts' ) ) );

		return array_values( array_filter( array_map( array( __CLASS__, 'clean_host' ), (array) $lines ) ) );
	}

	/**
	 * Whether a URL is on one of the skipped domains (or a subdomain of one).
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public function is_skipped_host( $url ) {
		$host = Redirect_Chain_Fixer_Checker::host( $url );

		foreach ( $this->skip_hosts() as $skip ) {
			if ( $host === $skip || substr( $host, -strlen( '.' . $skip ) ) === '.' . $skip ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A domain as typed (or a whole URL) reduced to a bare host: "https://www.Example.com/x" => "example.com".
	 *
	 * @param string $value Domain or URL.
	 * @return string '' if it isn't a usable host.
	 */
	public static function clean_host( $value ) {
		$value = trim( strtolower( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( false === strpos( $value, '//' ) ) {
			$value = 'http://' . $value;
		}

		$host = wp_parse_url( $value, PHP_URL_HOST );
		$host = is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : '';

		return preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host ) ? $host : '';
	}
}
