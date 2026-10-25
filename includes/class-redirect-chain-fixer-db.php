<?php
/**
 * The plugin's two tables and every query that touches them.
 *
 * - urls:  one row per checked URL, so a link used on 200 posts is only
 *          requested once per scan.
 * - fixes: one row per redirecting link in a post. It's the review queue
 *          (pending), the change log (fixed) and the skip list (ignored).
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access.
 */
final class Redirect_Chain_Fixer_DB {

	/**
	 * Bump when the table definitions change; install() runs again on the
	 * next load (updates don't fire the activation hook).
	 */
	const VERSION = '1';

	/**
	 * Option holding the installed table version.
	 */
	const VERSION_OPTION = 'redirect_chain_fixer_db_version';

	/**
	 * Fix statuses.
	 */
	const PENDING = 'pending';
	const FIXED   = 'fixed';
	const IGNORED = 'ignored';

	/**
	 * URL check cache table name.
	 *
	 * @return string
	 */
	public static function urls_table() {
		global $wpdb;

		return $wpdb->prefix . 'redirect_chain_fixer_urls';
	}

	/**
	 * Fixes table name.
	 *
	 * @return string
	 */
	public static function fixes_table() {
		global $wpdb;

		return $wpdb->prefix . 'redirect_chain_fixer_fixes';
	}

	/**
	 * Create or upgrade the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$urls    = self::urls_table();
		$fixes   = self::fixes_table();

		// dbDelta is picky: two spaces after PRIMARY KEY, one field per line.
		dbDelta(
			"CREATE TABLE {$urls} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(32) NOT NULL,
			url text NOT NULL,
			status varchar(20) NOT NULL DEFAULT '',
			target text NOT NULL,
			code smallint(5) unsigned NOT NULL DEFAULT 0,
			chain text NOT NULL,
			checked int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY checked (checked)
			) {$charset};
			CREATE TABLE {$fixes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			url_hash char(32) NOT NULL,
			url text NOT NULL,
			target text NOT NULL,
			chain text NOT NULL,
			places text NOT NULL,
			content_md5 char(32) NOT NULL DEFAULT '',
			scope varchar(10) NOT NULL DEFAULT '',
			status varchar(10) NOT NULL DEFAULT '',
			method varchar(10) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created int(10) unsigned NOT NULL DEFAULT 0,
			updated int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY post_url (post_id,url_hash),
			KEY status (status,updated)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Install or upgrade the tables if they're missing or out of date.
	 *
	 * @return void
	 */
	public static function maybe_install() {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	/**
	 * Key for a URL: md5 of the exact string.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function hash( $url ) {
		return md5( (string) $url );
	}

	// URL check cache --------------------------------------------------------

	/**
	 * A cached check result, if it's newer than $since.
	 *
	 * @param string $url   Absolute URL without its fragment.
	 * @param int    $since Unix time; older results are ignored.
	 * @return array|null Result in the shape Redirect_Chain_Fixer_Checker::check() returns.
	 */
	public static function get_check( $url, $since ) {
		global $wpdb;

		$table = self::urls_table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT status, target, code, chain FROM {$table} WHERE url_hash = %s AND checked >= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
				self::hash( $url ),
				(int) $since
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		$chain = json_decode( (string) $row['chain'], true );

		return array(
			'status' => (string) $row['status'],
			'target' => (string) $row['target'],
			'code'   => (int) $row['code'],
			'chain'  => is_array( $chain ) ? $chain : array(),
		);
	}

	/**
	 * Store a check result.
	 *
	 * @param string $url    Absolute URL without its fragment.
	 * @param array  $result Result from Redirect_Chain_Fixer_Checker::check().
	 * @return void
	 */
	public static function save_check( $url, array $result ) {
		global $wpdb;

		$table = self::urls_table();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (url_hash, url, status, target, code, chain, checked) VALUES (%s, %s, %s, %s, %d, %s, %d)
				ON DUPLICATE KEY UPDATE status = VALUES(status), target = VALUES(target), code = VALUES(code), chain = VALUES(chain), checked = VALUES(checked)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
				self::hash( $url ),
				$url,
				$result['status'],
				$result['target'],
				(int) $result['code'],
				(string) wp_json_encode( $result['chain'] ),
				time()
			)
		);
	}

	/**
	 * Number of URLs checked.
	 *
	 * @return int
	 */
	public static function count_checked() {
		global $wpdb;

		$table = self::urls_table();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	}

	/**
	 * Forget check results older than $before, so the cache can't grow forever.
	 *
	 * @param int $before Unix time.
	 * @return void
	 */
	public static function prune_checks( $before ) {
		global $wpdb;

		$table = self::urls_table();

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE checked < %d", (int) $before ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
	}

	// Fixes ---------------------------------------------------------------------

	/**
	 * Every fix row for a post, keyed by the link's hash.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,array>
	 */
	public static function get_post_fixes( $post_id ) {
		global $wpdb;

		$table = self::fixes_table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", (int) $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
			ARRAY_A
		);

		$fixes = array();
		foreach ( (array) $rows as $row ) {
			$fixes[ $row['url_hash'] ] = $row;
		}

		return $fixes;
	}

	/**
	 * One fix row.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function get_fix( $id ) {
		global $wpdb;

		$table = self::fixes_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.

		return $row ? $row : null;
	}

	/**
	 * Add or replace the row for a link in a post.
	 *
	 * @param array $fix post_id, url, target, chain (array), scope, status, and
	 *                   for applied fixes method, user_id, places (array) and
	 *                   content_md5 (the post content's md5 after the change).
	 * @return void
	 */
	public static function save_fix( array $fix ) {
		global $wpdb;

		$table = self::fixes_table();
		$now   = time();

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT INTO {$table} (post_id, url_hash, url, target, chain, places, content_md5, scope, status, method, user_id, created, updated) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %d, %d)
				ON DUPLICATE KEY UPDATE target = VALUES(target), chain = VALUES(chain), places = VALUES(places), content_md5 = VALUES(content_md5), scope = VALUES(scope), status = VALUES(status), method = VALUES(method), user_id = VALUES(user_id), updated = VALUES(updated)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.
				(int) $fix['post_id'],
				self::hash( $fix['url'] ),
				$fix['url'],
				$fix['target'],
				(string) wp_json_encode( isset( $fix['chain'] ) ? $fix['chain'] : array() ),
				(string) wp_json_encode( isset( $fix['places'] ) ? $fix['places'] : array() ),
				isset( $fix['content_md5'] ) ? $fix['content_md5'] : '',
				$fix['scope'],
				$fix['status'],
				isset( $fix['method'] ) ? $fix['method'] : '',
				isset( $fix['user_id'] ) ? (int) $fix['user_id'] : 0,
				$now,
				$now
			)
		);
	}

	/**
	 * Record that a review item has been applied.
	 *
	 * @param int    $id      Row ID.
	 * @param string $method  "approved".
	 * @param int    $user_id Who approved it.
	 * @param array  $places  Where the link was replaced (see Redirect_Chain_Fixer_Content::replace()).
	 * @param string $md5     md5 of the post content after the change.
	 * @return void
	 */
	public static function mark_fixed( $id, $method, $user_id, array $places, $md5 ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::fixes_table(),
			array(
				'status'      => self::FIXED,
				'method'      => $method,
				'user_id'     => (int) $user_id,
				'places'      => (string) wp_json_encode( $places ),
				'content_md5' => $md5,
				'updated'     => time(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * After the plugin saves a post again, carry its earlier fixes over to the
	 * new content. Only link values changed, so their places still hold.
	 * Fixes recorded against content someone has edited since are left as
	 * they are, and can no longer be undone automatically.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old_md5 md5 of the content before the save.
	 * @param string $new_md5 md5 of the content after it.
	 * @return void
	 */
	public static function rebase( $post_id, $old_md5, $new_md5 ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::fixes_table(),
			array( 'content_md5' => $new_md5 ),
			array(
				'post_id'     => (int) $post_id,
				'status'      => self::FIXED,
				'content_md5' => $old_md5,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Change a row's status.
	 *
	 * @param int    $id      Row ID.
	 * @param string $status  New status.
	 * @param string $method  How it got there ("auto", "approved", "undone", ...).
	 * @param int    $user_id Who did it (0 for the scanner).
	 * @return void
	 */
	public static function set_status( $id, $status, $method = '', $user_id = 0 ) {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::fixes_table(),
			array(
				'status'  => $status,
				'method'  => $method,
				'user_id' => (int) $user_id,
				'updated' => time(),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Delete fix rows by ID.
	 *
	 * @param int[] $ids Row IDs.
	 * @return void
	 */
	public static function delete_fixes( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return;
		}

		$table = self::fixes_table();
		$in    = implode( ',', $ids ); // Integers only, from absint().

		$wpdb->query( "DELETE FROM {$table} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name and absint'd IDs.
	}

	/**
	 * Delete every row for a post (when the post itself is deleted).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_post_fixes( $post_id ) {
		global $wpdb;

		$wpdb->delete( self::fixes_table(), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Row counts by status, and of fixed rows by how they were fixed.
	 *
	 * @return array<string,int> pending, fixed, ignored, auto, approved.
	 */
	public static function counts() {
		global $wpdb;

		$table  = self::fixes_table();
		$counts = array(
			self::PENDING => 0,
			self::FIXED   => 0,
			self::IGNORED => 0,
			'auto'        => 0,
			'approved'    => 0,
		);

		$rows = $wpdb->get_results( "SELECT status, method, COUNT(*) AS n FROM {$table} GROUP BY status, method", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name.

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] += (int) $row['n'];
			}
			if ( self::FIXED === $row['status'] && isset( $counts[ $row['method'] ] ) ) {
				$counts[ $row['method'] ] += (int) $row['n'];
			}
		}

		return $counts;
	}

	/**
	 * A page of fix rows for the admin list.
	 *
	 * @param string $status   pending, fixed or ignored.
	 * @param string $search   Text to look for in the link or its target.
	 * @param int    $per_page Rows per page.
	 * @param int    $page     1-based page number.
	 * @param string $order    "asc" or "desc" (by last change).
	 * @return array{rows: array, total: int}
	 */
	public static function query_fixes( $status, $search, $per_page, $page, $order = 'desc' ) {
		global $wpdb;

		$table = self::fixes_table();
		$where = 'f.status = %s';
		$args  = array( $status );

		if ( '' !== $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where = 'f.status = %s AND (f.url LIKE %s OR f.target LIKE %s OR p.post_title LIKE %s)';
			$args  = array( $status, $like, $like, $like );
		}

		$order  = 'asc' === $order ? 'ASC' : 'DESC';
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table names, a fixed WHERE with placeholders and a whitelisted ORDER.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} f LEFT JOIN {$wpdb->posts} p ON p.ID = f.post_id WHERE {$where}", $args ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, p.post_title, p.post_type, p.post_status, MD5(p.post_content) AS post_md5 FROM {$table} f LEFT JOIN {$wpdb->posts} p ON p.ID = f.post_id WHERE {$where} ORDER BY f.updated {$order}, f.id {$order} LIMIT %d OFFSET %d",
				array_merge( $args, array( (int) $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Drop both tables (uninstall only).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::urls_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::fixes_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name.

		delete_option( self::VERSION_OPTION );
	}
}
