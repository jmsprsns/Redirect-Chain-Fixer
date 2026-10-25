<?php
/**
 * Follows a URL's redirects one hop at a time to find where it really ends up.
 *
 * Every request goes through wp_safe_remote_head()/get() with redirects
 * turned off, so WordPress validates each hop before it's requested: http or
 * https only, ports 80, 443 and 8080, and no private, loopback or reserved IP
 * addresses (except this site's own host). A link in a post can't be used to
 * probe the server's internal network, even through a redirect.
 *
 * Only permanent redirects (301, 308) count. A temporary redirect (302, 303,
 * 307) is treated as the end of the line, because it says nothing about
 * where the page lives now: login walls, maintenance pages and geo
 * redirects are all temporary.
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirect checker.
 */
final class Redirect_Chain_Fixer_Checker {

	/**
	 * Most redirects to follow before calling it a loop.
	 */
	const MAX_HOPS = 10;

	/**
	 * Seconds to wait for each request.
	 */
	const TIMEOUT = 7;

	/**
	 * Result statuses.
	 */
	const OK      = 'ok';      // No permanent redirect: nothing to fix.
	const FIXABLE = 'fixable'; // One or more permanent redirects to a page that loads.
	const BROKEN  = 'broken';  // Redirects to an error page, or loops.
	const ERROR   = 'error';   // Couldn't be checked (timeout, DNS, blocked).

	/**
	 * Follow a URL's redirects.
	 *
	 * @param string $url Absolute http(s) URL without a fragment.
	 * @return array{status: string, target: string, code: int, chain: array}
	 *               target is the URL to link to instead (FIXABLE only), code the
	 *               final HTTP status, chain the hops as [code, url] pairs.
	 */
	public static function check( $url ) {
		$chain   = array();
		$current = $url;
		$seen    = array();
		$code    = 0;

		for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
			if ( isset( $seen[ $current ] ) ) {
				return self::result( self::BROKEN, '', 0, $chain );
			}
			$seen[ $current ] = true;

			$response = self::request( $current );

			if ( is_wp_error( $response ) ) {
				return self::result( self::ERROR, '', 0, $chain );
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$location = self::location( $response, $current );

			if ( ( 301 === $code || 308 === $code ) && '' !== $location ) {
				$chain[] = array( $code, $current );
				$current = $location;
				continue;
			}

			break;
		}

		if ( ! $chain ) {
			return self::result( self::OK, '', $code, $chain );
		}

		// Still redirecting after MAX_HOPS: a loop or a runaway chain.
		if ( 301 === $code || 308 === $code ) {
			return self::result( self::BROKEN, '', $code, $chain );
		}

		// The page at the end has to exist. 401 and 403 count: the page is
		// there, it's just not open to the checker (members-only pages, bot
		// protection). A temporary redirect counts too; linking to it still
		// skips every permanent hop before it.
		$reachable = ( $code >= 200 && $code < 400 ) || 401 === $code || 403 === $code;

		return self::result( $reachable ? self::FIXABLE : self::BROKEN, $reachable ? $current : '', $code, $chain );
	}

	/**
	 * One request, without following redirects. HEAD first; GET if the
	 * server refuses or fumbles HEAD (some answer 403, 404 or 405 to it).
	 *
	 * @param string $url URL.
	 * @return array|WP_Error
	 */
	private static function request( $url ) {
		if ( ! self::is_allowed_port( $url ) ) {
			return new WP_Error( 'redirect_chain_fixer_port', 'Other ports on this site are not checked.' );
		}

		$internal = self::is_internal( $url );

		$args = array(
			'redirection'         => 0,
			'timeout'             => self::TIMEOUT,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 65536,
			'user-agent'          => 'Mozilla/5.0 (compatible; RedirectChainFixer/' . REDIRECT_CHAIN_FIXER_VERSION . '; +' . home_url( '/' ) . ')',
			'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8' ),
		);

		// Requests back to this site get the same SSL leniency WordPress's own
		// loopback requests do (local and staging sites often have self-signed
		// certificates).
		if ( $internal ) {
			$args['sslverify'] = apply_filters( 'https_local_ssl_verify', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.
		}

		/**
		 * Filters the arguments for each request the checker makes.
		 *
		 * The plugin always re-applies redirection = 0 and reject_unsafe_urls =
		 * true afterwards, so this can't turn the safety checks off.
		 *
		 * @param array  $args HTTP API arguments.
		 * @param string $url  URL being requested.
		 */
		$args = (array) apply_filters( 'redirect_chain_fixer_request_args', $args, $url );

		$args['redirection']        = 0;
		$args['reject_unsafe_urls'] = true;

		$response = wp_safe_remote_head( $url, $args );

		if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 400 ) {
			return $response;
		}

		$get = wp_safe_remote_get( $url, $args );

		// A timeout or DNS failure on HEAD won't go better on GET; keep whichever
		// answered.
		return is_wp_error( $get ) && ! is_wp_error( $response ) ? $response : $get;
	}

	/**
	 * The absolute URL a redirect points to, or '' if there's no usable one.
	 *
	 * @param array  $response HTTP response.
	 * @param string $base     URL that was requested, for relative Location headers.
	 * @return string
	 */
	private static function location( $response, $base ) {
		$location = wp_remote_retrieve_header( $response, 'location' );

		if ( is_array( $location ) ) {
			$location = reset( $location );
		}

		$location = trim( (string) $location );

		if ( '' === $location ) {
			return '';
		}

		$absolute = WP_Http::make_absolute_url( $location, $base );

		return self::is_http_url( $absolute ) ? self::strip_fragment( $absolute ) : '';
	}

	/**
	 * Build a result array.
	 *
	 * @param string $status Status constant.
	 * @param string $target URL to link to instead.
	 * @param int    $code   Final HTTP status.
	 * @param array  $chain  Hops followed.
	 * @return array
	 */
	private static function result( $status, $target, $code, array $chain ) {
		return array(
			'status' => $status,
			'target' => $target,
			'code'   => (int) $code,
			'chain'  => $chain,
		);
	}

	// URL helpers ------------------------------------------------------------

	/**
	 * Whether a URL is an absolute http(s) URL WordPress would accept.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_http_url( $url ) {
		$url = (string) $url;

		if ( strlen( $url ) > 2000 || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		return is_string( $host ) && '' !== $host && esc_url_raw( $url, array( 'http', 'https' ) ) !== '';
	}

	/**
	 * A URL's host, lower-cased and without "www.", for comparing sites.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );

		return is_string( $host ) ? preg_replace( '/^www\./', '', strtolower( $host ) ) : '';
	}

	/**
	 * Whether a URL is on this site (http or https, with or without www).
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	public static function is_internal( $url ) {
		$host = self::host( $url );

		return '' !== $host && self::host( home_url() ) === $host;
	}

	/**
	 * Whether a URL's port is one the checker may use.
	 *
	 * WordPress lets requests to the site's own host through on ports 80,
	 * 443 and 8080 even when that host is a local address. Links back to
	 * this site are only checked on the port the site itself uses, so a link
	 * (or another site's redirect) can't be used to reach some other service
	 * running on the same server.
	 *
	 * @param string $url Absolute URL.
	 * @return bool
	 */
	private static function is_allowed_port( $url ) {
		$host      = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! is_string( $host ) || ! is_string( $home_host ) || strtolower( $host ) !== strtolower( $home_host ) ) {
			return true; // Other hosts: WordPress's own checks apply.
		}

		return self::port( $url ) === self::port( home_url() );
	}

	/**
	 * A URL's port, filling in the scheme's default.
	 *
	 * @param string $url Absolute URL.
	 * @return int
	 */
	private static function port( $url ) {
		$port = wp_parse_url( $url, PHP_URL_PORT );

		if ( $port ) {
			return (int) $port;
		}

		return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ? 443 : 80;
	}

	/**
	 * A URL without its #fragment.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function strip_fragment( $url ) {
		$hash = strpos( (string) $url, '#' );

		return false === $hash ? (string) $url : substr( $url, 0, $hash );
	}

	/**
	 * A URL's #fragment, including the "#", or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function fragment( $url ) {
		$hash = strpos( (string) $url, '#' );

		return false === $hash ? '' : substr( $url, $hash );
	}
}
