<?php
/**
 * Scans posts for redirecting links and fixes them.
 *
 * Work happens in short batches, in the background on WP-Cron and in the
 * foreground while the admin page's "Scan now" is running. Each batch first
 * handles posts saved since the last batch (the queue), then carries on with
 * the full scan if one is running. A lock keeps two batches from running at
 * once.
 *
 * Internal links are fixed straight away (unless that's turned off). Every
 * other fix waits in the review queue until someone approves it. Each change
 * is logged, can be undone, and saves a revision of the post.
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner and fixer.
 */
final class Redirect_Chain_Fixer_Scanner {

	/**
	 * Option holding the full scan's progress.
	 */
	const STATE_OPTION = 'redirect_chain_fixer_scan';

	/**
	 * Option holding IDs of posts saved since the last batch.
	 */
	const QUEUE_OPTION = 'redirect_chain_fixer_queue';

	/**
	 * Option used as the batch lock.
	 */
	const LOCK_OPTION = 'redirect_chain_fixer_lock';

	/**
	 * Cron hooks.
	 */
	const BATCH_HOOK = 'redirect_chain_fixer_batch';
	const DAILY_HOOK = 'redirect_chain_fixer_daily';

	/**
	 * Seconds a batch may run in the background.
	 */
	const CRON_BUDGET = 20;

	/**
	 * Seconds before a lock is considered abandoned (a batch that crashed).
	 */
	const LOCK_TTL = 120;

	/**
	 * More queued posts than this starts a full scan instead (bulk edits, imports).
	 */
	const QUEUE_MAX = 200;

	/**
	 * Post statuses that are scanned.
	 */
	const STATUSES = array( 'publish', 'future' );

	/**
	 * Plugin instance.
	 *
	 * @var Redirect_Chain_Fixer_Plugin
	 */
	private $plugin;

	/**
	 * True while the plugin itself is saving a post, so it doesn't queue it again.
	 *
	 * @var bool
	 */
	private $saving = false;

	/**
	 * When the current batch has to stop (microtime).
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Whether the current batch has made at least one request, so a batch
	 * always gets something done even if one URL is slow.
	 *
	 * @var bool
	 */
	private $requested = false;

	/**
	 * Constructor.
	 *
	 * @param Redirect_Chain_Fixer_Plugin $plugin Plugin instance.
	 */
	public function __construct( Redirect_Chain_Fixer_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( self::BATCH_HOOK, array( $this, 'cron_batch' ) );
		add_action( self::DAILY_HOOK, array( $this, 'daily' ) );
		add_action( 'save_post', array( $this, 'queue_post' ), 20, 2 );
		add_action( 'deleted_post', array( 'Redirect_Chain_Fixer_DB', 'delete_post_fixes' ) );
	}

	// Scan state ---------------------------------------------------------------

	/**
	 * Default scan state.
	 *
	 * @return array<string,int|string>
	 */
	private static function default_state() {
		return array(
			'status'   => 'idle', // idle or running.
			'last_id'  => 0,      // Highest post ID scanned so far.
			'total'    => 0,      // Posts to scan.
			'done'     => 0,      // Posts scanned.
			'started'  => 0,      // Unix time the scan started.
			'finished' => 0,      // Unix time the last scan finished.
			'fixed'    => 0,      // Links fixed automatically this scan.
			'review'   => 0,      // New fixes waiting for review this scan.
		);
	}

	/**
	 * The full scan's progress, read fresh from the database.
	 *
	 * @return array<string,int|string>
	 */
	public static function get_state() {
		wp_cache_delete( self::STATE_OPTION, 'options' );

		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args( is_array( $state ) ? $state : array(), self::default_state() );
	}

	/**
	 * Save the full scan's progress.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Start a full scan of every post (or restart the one that's running).
	 *
	 * @return array New state.
	 */
	public static function start() {
		$state = array_merge(
			self::get_state(),
			array(
				'status'  => 'running',
				'last_id' => 0,
				'total'   => self::count_posts(),
				'done'    => 0,
				'started' => time(),
				'fixed'   => 0,
				'review'  => 0,
			)
		);

		self::save_state( $state );

		// The full scan covers every post, so its totals include the ones
		// waiting in the queue.
		update_option( self::QUEUE_OPTION, array(), false );

		self::schedule_batch( 0 );

		return $state;
	}

	// Scheduling ---------------------------------------------------------------

	/**
	 * Make sure the daily check is scheduled.
	 *
	 * @return void
	 */
	public static function schedule_daily() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Schedule the next batch, unless one is already due sooner.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function schedule_batch( $delay ) {
		$when = time() + (int) $delay;
		$next = wp_next_scheduled( self::BATCH_HOOK );

		if ( $next && $next <= $when ) {
			return;
		}

		if ( $next ) {
			wp_unschedule_event( $next, self::BATCH_HOOK );
		}

		wp_schedule_single_event( $when, self::BATCH_HOOK );
	}

	/**
	 * Clear every scheduled event (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
		wp_clear_scheduled_hook( self::DAILY_HOOK );
	}

	/**
	 * Daily: start the automatic rescan when it's due, restart a scan whose
	 * batches stopped being scheduled, and trim the URL cache.
	 *
	 * @return void
	 */
	public function daily() {
		Redirect_Chain_Fixer_DB::prune_checks( time() - 30 * DAY_IN_SECONDS );

		$state = self::get_state();

		if ( 'running' === $state['status'] ) {
			self::schedule_batch( 0 );
			return;
		}

		$days = $this->plugin->rescan_days();

		if ( $days && time() - (int) $state['finished'] >= $days * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			self::start();
		}
	}

	/**
	 * Cron callback: run one background batch.
	 *
	 * @return void
	 */
	public function cron_batch() {
		$this->run( self::CRON_BUDGET );
	}

	/**
	 * Queue a post for a scan when it's saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function queue_post( $post_id, $post ) {
		if ( $this->saving || ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, self::STATUSES, true ) || ! in_array( $post->post_type, $this->plugin->post_types(), true ) ) {
			return;
		}

		$queue = self::get_queue();

		if ( ! in_array( (int) $post_id, $queue, true ) ) {
			$queue[] = (int) $post_id;
		}

		// A bulk edit or an import: one full scan beats hundreds of queued posts.
		if ( count( $queue ) > self::QUEUE_MAX ) {
			$queue = array();
			if ( 'running' !== self::get_state()['status'] ) {
				self::start();
			}
		}

		update_option( self::QUEUE_OPTION, $queue, false );

		// Give the editor a moment to finish saving (meta boxes, a quick second save).
		self::schedule_batch( 30 );
	}

	/**
	 * IDs of posts waiting for a scan.
	 *
	 * @return int[]
	 */
	private static function get_queue() {
		wp_cache_delete( self::QUEUE_OPTION, 'options' );

		return array_values( array_map( 'intval', (array) get_option( self::QUEUE_OPTION, array() ) ) );
	}

	// Batches -----------------------------------------------------------------

	/**
	 * Run one batch: queued posts first, then the full scan.
	 *
	 * @param int $budget Seconds to spend.
	 * @return array Scan state afterwards.
	 */
	public function run( $budget ) {
		if ( ! $this->lock() ) {
			return self::get_state();
		}

		$this->deadline  = microtime( true ) + max( 1, (int) $budget );
		$this->requested = false;

		$deferred = $this->run_queue();
		$state    = $this->run_scan();

		$this->unlock();

		if ( 'running' === $state['status'] || count( self::get_queue() ) > count( $deferred ) ) {
			self::schedule_batch( 0 );
		} elseif ( $deferred ) {
			// Only posts someone is editing are left: look again in a few minutes.
			self::schedule_batch( 5 * MINUTE_IN_SECONDS );
		}

		return $state;
	}

	/**
	 * Scan the posts saved since the last batch.
	 *
	 * @return int[] Posts left in the queue because they're open in the editor.
	 */
	private function run_queue() {
		$queue    = self::get_queue();
		$original = $queue;
		$deferred = array();
		$since    = time() - DAY_IN_SECONDS;

		while ( $queue && ! $this->out_of_time() ) {
			$post_id = array_shift( $queue );
			$result  = $this->scan_post( $post_id, $since );

			if ( 'timeout' === $result['status'] ) {
				array_unshift( $queue, $post_id );
				break;
			}

			if ( 'locked' === $result['status'] ) {
				$deferred[] = $post_id;
			}
		}

		// Keep what's left, plus anything queued while this batch ran.
		$added = array_diff( self::get_queue(), $original );
		$left  = array_values( array_unique( array_merge( $deferred, $queue, $added ) ) );
		update_option( self::QUEUE_OPTION, $left, false );

		return $deferred;
	}

	/**
	 * Carry on with the full scan, if one is running.
	 *
	 * @return array Scan state.
	 */
	private function run_scan() {
		$state = self::get_state();

		if ( 'running' !== $state['status'] ) {
			return $state;
		}

		$started = (int) $state['started'];
		$saved   = microtime( true );

		while ( ! $this->out_of_time() ) {
			$ids = $this->next_posts( (int) $state['last_id'], 20 );

			if ( ! $ids ) {
				$state['status']   = 'idle';
				$state['finished'] = time();
				$state['done']     = max( (int) $state['done'], (int) $state['total'] );
				break;
			}

			foreach ( $ids as $post_id ) {
				$result = $this->scan_post( $post_id, $started );

				if ( 'timeout' === $result['status'] ) {
					break 2;
				}

				if ( 'locked' === $result['status'] ) {
					$this->add_to_queue( $post_id );
				}

				$state['last_id'] = $post_id;
				$state['done']    = (int) $state['done'] + 1;
				$state['fixed']   = (int) $state['fixed'] + $result['fixed'];
				$state['review']  = (int) $state['review'] + $result['review'];

				if ( $this->out_of_time() ) {
					break 2;
				}

				// Save progress as it goes, so the progress bar moves while a
				// background batch is running.
				if ( microtime( true ) - $saved >= 1 ) {
					if ( ! self::save_progress( $state, $started ) ) {
						return self::get_state();
					}
					$saved = microtime( true );
				}
			}
		}

		self::save_progress( $state, $started );

		return $state;
	}

	/**
	 * Save the scan's progress, unless "Scan now" has started a new scan
	 * since this batch began (the new one wins).
	 *
	 * @param array $state   State.
	 * @param int   $started When the scan this batch is working on started.
	 * @return bool Whether it was saved.
	 */
	private static function save_progress( array $state, $started ) {
		if ( (int) self::get_state()['started'] !== (int) $started ) {
			return false;
		}

		self::save_state( $state );

		return true;
	}

	/**
	 * Whether the batch should stop.
	 *
	 * @return bool
	 */
	private function out_of_time() {
		return microtime( true ) >= $this->deadline;
	}

	/**
	 * Add a post to the queue (used for posts skipped because they're open in the editor).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function add_to_queue( $post_id ) {
		$queue = self::get_queue();

		if ( ! in_array( (int) $post_id, $queue, true ) ) {
			$queue[] = (int) $post_id;
			update_option( self::QUEUE_OPTION, $queue, false );
		}
	}

	/**
	 * Take the batch lock. An atomic insert, like core's upgrader lock, so two
	 * batches can't both get it.
	 *
	 * @return bool Whether this batch holds the lock.
	 */
	private function lock() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, (string) time() ) );

		if ( ! $taken ) {
			$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );

			if ( $held > time() - self::LOCK_TTL ) {
				return false;
			}

			// Abandoned by a batch that died: take it over, unless another batch just did.
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), self::LOCK_OPTION, (string) $held ) );
		}
		// phpcs:enable

		wp_cache_delete( self::LOCK_OPTION, 'options' );

		return (bool) $taken;
	}

	/**
	 * Release the batch lock.
	 *
	 * @return void
	 */
	private function unlock() {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( self::LOCK_OPTION, 'options' );
	}

	// Posts -------------------------------------------------------------------

	/**
	 * SQL for the post types and statuses that get scanned.
	 *
	 * @return string Prepared WHERE conditions.
	 */
	private static function posts_where() {
		global $wpdb;

		$types    = redirect_chain_fixer_plugin()->post_types();
		$types    = $types ? $types : array( '' );
		$statuses = self::STATUSES;

		return $wpdb->prepare(
			'post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built to match.
			array_merge( $types, $statuses )
		);
	}

	/**
	 * Number of posts a full scan covers.
	 *
	 * @return int
	 */
	private static function count_posts() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . self::posts_where() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in posts_where().
	}

	/**
	 * The next posts to scan, by ID.
	 *
	 * @param int $after_id Last post ID scanned.
	 * @param int $limit    How many.
	 * @return int[]
	 */
	private function next_posts( $after_id, $limit ) {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND " . self::posts_where() . ' ORDER BY ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in posts_where().
				(int) $after_id,
				(int) $limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Whether someone has the post open in the editor right now.
	 *
	 * Same test as core's wp_check_post_lock(), which isn't loaded during cron.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private function is_being_edited( WP_Post $post ) {
		$lock = explode( ':', (string) get_post_meta( $post->ID, '_edit_lock', true ) );
		$time = isset( $lock[0] ) ? (int) $lock[0] : 0;

		/** This filter is documented in wp-admin/includes/post.php */
		$window = apply_filters( 'wp_check_post_lock_window', 150 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter.

		return $time && $time > time() - (int) $window;
	}

	/**
	 * Check every link in a post, fix the internal ones and queue the rest for review.
	 *
	 * @param int $post_id Post ID.
	 * @param int $since   Reuse URL checks made after this Unix time.
	 * @return array{status: string, fixed: int, review: int} status is done,
	 *               skipped, locked (open in the editor) or timeout (the batch ran
	 *               out of time; scan the post again next batch).
	 */
	private function scan_post( $post_id, $since ) {
		$result = array(
			'status' => 'skipped',
			'fixed'  => 0,
			'review' => 0,
		);

		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_status, self::STATUSES, true ) || ! in_array( $post->post_type, $this->plugin->post_types(), true ) ) {
			return $result;
		}

		if ( $this->is_being_edited( $post ) ) {
			$result['status'] = 'locked';
			return $result;
		}

		$auto_fix = (bool) $this->plugin->get_setting( 'auto_fix' );
		$existing = Redirect_Chain_Fixer_DB::get_post_fixes( $post->ID );
		$auto     = array();
		$auto_log = array();
		$pending  = array();

		foreach ( Redirect_Chain_Fixer_Content::links( $post->post_content ) as $link ) {
			$hash = Redirect_Chain_Fixer_DB::hash( $link );

			if ( isset( $existing[ $hash ] ) && Redirect_Chain_Fixer_DB::IGNORED === $existing[ $hash ]['status'] ) {
				continue;
			}

			$fix = $this->find_fix( $link, $post, $since );

			if ( null === $fix ) {
				continue;
			}

			if ( 'timeout' === $fix ) {
				$result['status'] = 'timeout';
				return $result;
			}

			$fix['post_id'] = $post->ID;

			if ( 'internal' === $fix['scope'] && $auto_fix ) {
				$auto[ $link ] = $fix['target'];
				$auto_log[]    = $fix;
				continue;
			}

			$pending[ $hash ] = true;

			$known = isset( $existing[ $hash ] ) ? $existing[ $hash ] : null;
			if ( ! $known || Redirect_Chain_Fixer_DB::PENDING !== $known['status'] || $known['target'] !== $fix['target'] ) {
				Redirect_Chain_Fixer_DB::save_fix( $fix + array( 'status' => Redirect_Chain_Fixer_DB::PENDING ) );
				$result['review'] += $known && Redirect_Chain_Fixer_DB::PENDING === $known['status'] ? 0 : 1;
			}
		}

		// Drop review items whose link has been edited out or no longer redirects.
		$stale = array();
		foreach ( $existing as $hash => $row ) {
			if ( Redirect_Chain_Fixer_DB::PENDING === $row['status'] && ! isset( $pending[ $hash ] ) ) {
				$stale[] = (int) $row['id'];
			}
		}
		Redirect_Chain_Fixer_DB::delete_fixes( $stale );

		if ( $auto ) {
			$places  = array();
			$content = Redirect_Chain_Fixer_Content::replace( $post->post_content, $auto, $places );
			$md5     = $content !== $post->post_content ? $this->commit( $post, $content ) : '';

			if ( '' !== $md5 ) {
				foreach ( $auto_log as $fix ) {
					if ( isset( $places[ $fix['url'] ] ) ) {
						Redirect_Chain_Fixer_DB::save_fix(
							$fix + array(
								'status'      => Redirect_Chain_Fixer_DB::FIXED,
								'method'      => 'auto',
								'places'      => $places[ $fix['url'] ],
								'content_md5' => $md5,
							)
						);
						$result['fixed']++;
					}
				}
			}
		}

		$result['status'] = 'done';

		return $result;
	}

	/**
	 * Work out whether a link should change, and to what.
	 *
	 * @param string  $link  Link as written in the post.
	 * @param WP_Post $post  Post it's in (relative links are relative to it).
	 * @param int     $since Reuse URL checks made after this Unix time.
	 * @return array|string|null The fix (url, target, chain, scope), "timeout"
	 *                           if there was no time left to check it, or null
	 *                           to leave the link alone.
	 */
	private function find_fix( $link, WP_Post $post, $since ) {
		$absolute = $this->absolute( $link, $post );

		if ( '' === $absolute ) {
			return null;
		}

		$internal = Redirect_Chain_Fixer_Checker::is_internal( $absolute );

		if ( $internal ) {
			// Admin and login links answer differently to the checker than to a
			// logged-in visitor.
			if ( preg_match( '#/(wp-admin|wp-login\.php)#i', (string) wp_parse_url( $absolute, PHP_URL_PATH ) ) ) {
				return null;
			}
		} elseif ( ! $this->plugin->get_setting( 'check_external' ) || $this->plugin->is_skipped_host( $absolute ) ) {
			return null;
		}

		$check = $this->check( Redirect_Chain_Fixer_Checker::strip_fragment( $absolute ), $since );

		if ( null === $check ) {
			return 'timeout';
		}

		if ( Redirect_Chain_Fixer_Checker::FIXABLE !== $check['status'] ) {
			return null;
		}

		// An internal link that leads to another site is almost always on
		// purpose (cloaked affiliate links, /go/ links, download trackers).
		if ( $internal && ! Redirect_Chain_Fixer_Checker::is_internal( $check['target'] ) ) {
			return null;
		}

		$target = $this->output_link( $link, $check['target'] );

		if ( $target === $link ) {
			return null;
		}

		$chain   = $check['chain'];
		$chain[] = array( $check['code'], $check['target'] );

		return array(
			'url'    => $link,
			'target' => $target,
			'chain'  => $chain,
			'scope'  => $internal ? 'internal' : 'external',
		);
	}

	/**
	 * Check a URL, reusing a recent result when there is one.
	 *
	 * @param string $url   Absolute URL without a fragment.
	 * @param int    $since Reuse results from after this Unix time.
	 * @return array|null Null if the batch is out of time.
	 */
	private function check( $url, $since ) {
		$cached = Redirect_Chain_Fixer_DB::get_check( $url, $since );

		if ( $cached ) {
			return $cached;
		}

		if ( $this->requested && $this->out_of_time() ) {
			return null;
		}

		$this->requested = true;

		$result = Redirect_Chain_Fixer_Checker::check( $url );
		Redirect_Chain_Fixer_DB::save_check( $url, $result );

		return $result;
	}

	/**
	 * A link as an absolute http(s) URL, or '' for links that aren't checked
	 * (anchors, mailto:, tel:, javascript:, shortcodes, template tags).
	 *
	 * @param string  $link Link as written in the post.
	 * @param WP_Post $post Post it's in.
	 * @return string
	 */
	private function absolute( $link, WP_Post $post ) {
		if ( '' === $link || '#' === $link[0] || '?' === $link[0] || preg_match( '/[\[\]{}<>\s]/', $link ) ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $link ) ) {
			$absolute = $link;
		} elseif ( 0 === strpos( $link, '//' ) ) {
			$absolute = wp_parse_url( home_url(), PHP_URL_SCHEME ) . ':' . $link;
		} else {
			$base     = get_permalink( $post );
			$absolute = WP_Http::make_absolute_url( $link, $base ? $base : home_url( '/' ) );
		}

		return Redirect_Chain_Fixer_Checker::is_http_url( Redirect_Chain_Fixer_Checker::strip_fragment( $absolute ) ) ? $absolute : '';
	}

	/**
	 * The link to write in place of the old one: the target, keeping the old
	 * link's #fragment, and kept root-relative if the old link was ("/about/").
	 *
	 * @param string $link   Link as written in the post.
	 * @param string $target Absolute URL the link ends up at.
	 * @return string
	 */
	private function output_link( $link, $target ) {
		$fragment = Redirect_Chain_Fixer_Checker::fragment( $link );
		$new      = $target . $fragment;

		$is_root_relative = '/' === $link[0] && 0 !== strpos( $link, '//' );
		$home             = wp_parse_url( home_url() );
		$parts            = wp_parse_url( $new );

		if (
			$is_root_relative && is_array( $home ) && is_array( $parts )
			&& isset( $home['scheme'], $home['host'], $parts['scheme'], $parts['host'] )
			&& strtolower( $home['scheme'] ) === strtolower( $parts['scheme'] )
			&& strtolower( $home['host'] ) === strtolower( $parts['host'] )
			&& ( isset( $home['port'] ) ? $home['port'] : null ) === ( isset( $parts['port'] ) ? $parts['port'] : null )
		) {
			$new = ( isset( $parts['path'] ) ? $parts['path'] : '/' )
				. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' )
				. ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' );
		}

		$clean = esc_url_raw( $new, array( 'http', 'https' ) );

		return '' === $clean ? $link : $clean;
	}

	// Saving ------------------------------------------------------------------

	/**
	 * Save new content and carry the post's earlier fixes over to it.
	 *
	 * @param WP_Post $post    Post as it is now.
	 * @param string  $content New content.
	 * @return string md5 of the content as saved, or '' if it couldn't be saved.
	 */
	private function commit( WP_Post $post, $content ) {
		if ( ! $this->save_content( $post, $content ) ) {
			return '';
		}

		// Read back what was stored: filters on save can tidy the markup.
		$md5 = md5( (string) get_post_field( 'post_content', $post->ID, 'raw' ) );

		Redirect_Chain_Fixer_DB::rebase( $post->ID, md5( $post->post_content ), $md5 );

		return $md5;
	}

	/**
	 * Save new content for a post.
	 *
	 * Goes through wp_update_post(), so caches are cleared and a revision is
	 * saved, but:
	 * - the content isn't run through kses. Only link URLs have changed, and
	 *   in the background there's no logged-in user, so kses would strip
	 *   embeds and other markup an administrator was allowed to add.
	 * - the "last modified" date stays the same, because updating a link isn't
	 *   a content update (and a scan shouldn't touch every sitemap date).
	 * - a post with no revisions gets one of its old content first, so the
	 *   change can always be rolled back from the Revisions screen.
	 *
	 * @param WP_Post $post    Post as it is now.
	 * @param string  $content New content.
	 * @return bool
	 */
	private function save_content( WP_Post $post, $content ) {
		if ( function_exists( 'wp_revisions_enabled' ) && wp_revisions_enabled( $post ) && function_exists( '_wp_put_post_revision' ) ) {
			$revisions = wp_get_post_revisions(
				$post->ID,
				array(
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			if ( ! $revisions ) {
				_wp_put_post_revision( $post );
			}
		}

		$keep_dates = function ( $data, $postarr ) use ( $post ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === (int) $post->ID ) {
				$data['post_modified']     = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;
			}
			return $data;
		};

		$had_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		$this->saving = true;
		kses_remove_filters();
		add_filter( 'wp_insert_post_data', $keep_dates, 99, 2 );

		$result = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => $content,
				)
			),
			true
		);

		remove_filter( 'wp_insert_post_data', $keep_dates, 99 );
		if ( $had_kses ) {
			kses_init_filters();
		}
		$this->saving = false;

		return $result && ! is_wp_error( $result );
	}

	// Review actions ----------------------------------------------------------

	/**
	 * Fix rows with a given status, grouped by post.
	 *
	 * @param int[]  $ids    Row IDs.
	 * @param string $status Status the rows must have.
	 * @return array<int,array[]> Post ID => rows.
	 */
	private static function rows_by_post( array $ids, $status ) {
		$by_post = array();

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$row = Redirect_Chain_Fixer_DB::get_fix( $id );
			if ( $row && $status === $row['status'] ) {
				$by_post[ (int) $row['post_id'] ][] = $row;
			}
		}

		return $by_post;
	}

	/**
	 * Apply fixes from the review queue (one save per post).
	 *
	 * @param int[] $ids     Fix row IDs.
	 * @param int   $user_id Who approved them.
	 * @return array{done: int, gone: int, failed: int} gone counts links that
	 *               have been edited out of their post since the scan.
	 */
	public function approve( array $ids, $user_id ) {
		$counts = array(
			'done'   => 0,
			'gone'   => 0,
			'failed' => 0,
		);

		foreach ( self::rows_by_post( $ids, Redirect_Chain_Fixer_DB::PENDING ) as $post_id => $rows ) {
			$post = get_post( $post_id );

			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				$counts['failed'] += count( $rows );
				continue;
			}

			$map = array();
			foreach ( $rows as $row ) {
				$map[ $row['url'] ] = $row['target'];
			}

			$places  = array();
			$content = Redirect_Chain_Fixer_Content::replace( $post->post_content, $map, $places );
			$md5     = $content !== $post->post_content ? $this->commit( $post, $content ) : '';

			foreach ( $rows as $row ) {
				if ( ! isset( $places[ $row['url'] ] ) ) {
					Redirect_Chain_Fixer_DB::delete_fixes( array( $row['id'] ) );
					$counts['gone']++;
				} elseif ( '' === $md5 ) {
					$counts['failed']++;
				} else {
					Redirect_Chain_Fixer_DB::mark_fixed( $row['id'], 'approved', $user_id, $places[ $row['url'] ], $md5 );
					$counts['done']++;
				}
			}
		}

		return $counts;
	}

	/**
	 * Put fixed links back the way they were, and stop them being fixed again.
	 *
	 * Only the exact spots the fix changed are put back, and only if the post
	 * hasn't been edited since; otherwise the post's revisions are the way
	 * back.
	 *
	 * @param int[] $ids     Fix row IDs.
	 * @param int   $user_id Who undid them.
	 * @return array{done: int, edited: int, failed: int} edited counts fixes
	 *               left alone because their post has been edited since.
	 */
	public function undo( array $ids, $user_id ) {
		$counts = array(
			'done'   => 0,
			'edited' => 0,
			'failed' => 0,
		);

		foreach ( self::rows_by_post( $ids, Redirect_Chain_Fixer_DB::FIXED ) as $post_id => $rows ) {
			$post = get_post( $post_id );

			if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
				$counts['failed'] += count( $rows );
				continue;
			}

			$current = md5( $post->post_content );
			$content = $post->post_content;
			$undone  = array();

			foreach ( $rows as $row ) {
				$places   = json_decode( (string) $row['places'], true );
				$reverted = $row['content_md5'] === $current && is_array( $places )
					? Redirect_Chain_Fixer_Content::revert( $content, $row['target'], $row['url'], $places )
					: $content;

				if ( $reverted === $content ) {
					$counts['edited']++;
					continue;
				}

				$content  = $reverted;
				$undone[] = $row;
			}

			if ( ! $undone ) {
				continue;
			}

			if ( '' === $this->commit( $post, $content ) ) {
				$counts['failed'] += count( $undone );
				continue;
			}

			foreach ( $undone as $row ) {
				Redirect_Chain_Fixer_DB::set_status( $row['id'], Redirect_Chain_Fixer_DB::IGNORED, 'undone', $user_id );
				$counts['done']++;
			}
		}

		return $counts;
	}

	/**
	 * Whether a fixed row can still be undone automatically: its post hasn't
	 * been edited since.
	 *
	 * @param array $row Fix row, with post_md5 (md5 of the post's content now).
	 * @return bool
	 */
	public static function can_undo( array $row ) {
		return isset( $row['post_md5'] ) && '' !== (string) $row['content_md5'] && $row['post_md5'] === $row['content_md5'];
	}

	/**
	 * Skip fixes from the review queue (they won't be suggested again).
	 *
	 * @param int[] $ids     Fix row IDs.
	 * @param int   $user_id Who skipped them.
	 * @return int Rows changed.
	 */
	public function ignore( array $ids, $user_id ) {
		$changed = 0;

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$row = Redirect_Chain_Fixer_DB::get_fix( $id );
			if ( $row && Redirect_Chain_Fixer_DB::PENDING === $row['status'] ) {
				Redirect_Chain_Fixer_DB::set_status( $id, Redirect_Chain_Fixer_DB::IGNORED, 'ignored', $user_id );
				$changed++;
			}
		}

		return $changed;
	}

	/**
	 * Forget ignored links, so the next scan of their posts looks at them again.
	 *
	 * @param int[] $ids Fix row IDs.
	 * @return int Rows removed.
	 */
	public function restore( array $ids ) {
		$remove = array();

		foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
			$row = Redirect_Chain_Fixer_DB::get_fix( $id );
			if ( $row && Redirect_Chain_Fixer_DB::IGNORED === $row['status'] ) {
				$remove[] = $id;
				$this->add_to_queue( (int) $row['post_id'] );
			}
		}

		Redirect_Chain_Fixer_DB::delete_fixes( $remove );

		if ( $remove ) {
			self::schedule_batch( 0 );
		}

		return count( $remove );
	}
}
