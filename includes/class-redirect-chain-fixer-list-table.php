<?php
/**
 * The Needs review, Fixed and Ignored lists on the plugin's page.
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List of fix rows with one status.
 */
final class Redirect_Chain_Fixer_List_Table extends WP_List_Table {

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Status shown: pending, fixed or ignored.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Tab the list is on, for row action links.
	 *
	 * @var string
	 */
	private $tab;

	/**
	 * Constructor.
	 *
	 * @param string $status Status to list.
	 * @param string $tab    Tab it's shown on.
	 */
	public function __construct( $status, $tab ) {
		$this->status = $status;
		$this->tab    = $tab;

		parent::__construct(
			array(
				'singular' => 'redirect-chain-fix',
				'plural'   => 'redirect-chain-fixes',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		$target = array(
			Redirect_Chain_Fixer_DB::PENDING => __( 'Change to', 'redirect-chain-fixer' ),
			Redirect_Chain_Fixer_DB::FIXED   => __( 'Changed to', 'redirect-chain-fixer' ),
			Redirect_Chain_Fixer_DB::IGNORED => __( 'Would change to', 'redirect-chain-fixer' ),
		);
		$date   = array(
			Redirect_Chain_Fixer_DB::PENDING => __( 'Found', 'redirect-chain-fixer' ),
			Redirect_Chain_Fixer_DB::FIXED   => __( 'Fixed', 'redirect-chain-fixer' ),
			Redirect_Chain_Fixer_DB::IGNORED => __( 'Ignored', 'redirect-chain-fixer' ),
		);

		return array(
			'cb'     => '<input type="checkbox">',
			'url'    => __( 'Link', 'redirect-chain-fixer' ),
			'target' => $target[ $this->status ],
			'post'   => __( 'In', 'redirect-chain-fixer' ),
			'date'   => $date[ $this->status ],
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array( 'date' => array( 'date', true ) );
	}

	/**
	 * Bulk actions for this list.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		switch ( $this->status ) {
			case Redirect_Chain_Fixer_DB::PENDING:
				return array(
					'approve' => __( 'Approve', 'redirect-chain-fixer' ),
					'ignore'  => __( 'Ignore', 'redirect-chain-fixer' ),
				);
			case Redirect_Chain_Fixer_DB::FIXED:
				return array( 'undo' => __( 'Undo', 'redirect-chain-fixer' ) );
			default:
				return array( 'restore' => __( 'Restore', 'redirect-chain-fixer' ) );
		}
	}

	/**
	 * Load the rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$order  = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'asc' : 'desc';
		// phpcs:enable

		$result = Redirect_Chain_Fixer_DB::query_fixes( $this->status, $search, self::PER_PAGE, $this->get_pagenum(), $order );

		$this->items           = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'url' );

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message when the list is empty.
	 *
	 * @return void
	 */
	public function no_items() {
		$messages = array(
			Redirect_Chain_Fixer_DB::PENDING => array( 'circle-check', __( 'Nothing to review. When a scan finds an external link that redirects, it shows up here for you to approve.', 'redirect-chain-fixer' ) ),
			Redirect_Chain_Fixer_DB::FIXED   => array( 'history', __( 'No links have been changed yet. Every change the plugin makes is listed here, and can be undone.', 'redirect-chain-fixer' ) ),
			Redirect_Chain_Fixer_DB::IGNORED => array( 'eye-off', __( 'No ignored links. Links you ignore or undo are listed here and left alone by future scans.', 'redirect-chain-fixer' ) ),
		);

		$message = $messages[ $this->status ];

		echo '<span class="rcf-empty">' . Redirect_Chain_Fixer_Icons::get( $message[0], 18 ) . esc_html( $message[1] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="rcf-cb-%1$d">%2$s</label><input type="checkbox" id="rcf-cb-%1$d" name="ids[]" value="%1$d">',
			(int) $item['id'],
			esc_html__( 'Select link', 'redirect-chain-fixer' )
		);
	}

	/**
	 * Link column: internal/external badge, the old link and its redirects.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_url( $item ) {
		$internal = 'internal' === $item['scope'];
		$chain    = json_decode( (string) $item['chain'], true );
		$chain    = is_array( $chain ) ? $chain : array();
		$hops     = max( 1, count( $chain ) - 1 );

		$badge = sprintf(
			'<span class="rcf-badge rcf-badge-%1$s">%2$s%3$s</span>',
			$internal ? 'internal' : 'external',
			Redirect_Chain_Fixer_Icons::get( $internal ? 'house' : 'globe', 13 ),
			$internal ? esc_html__( 'Internal', 'redirect-chain-fixer' ) : esc_html__( 'External', 'redirect-chain-fixer' )
		);

		$hops_label = sprintf(
			/* translators: %s: number of redirects. */
			_n( '%s redirect', '%s redirects', $hops, 'redirect-chain-fixer' ),
			number_format_i18n( $hops )
		);

		$out  = '<div class="rcf-link-meta">' . $badge;
		$out .= '<span class="rcf-badge' . ( $hops > 1 ? ' rcf-badge-chain' : '' ) . '">' . Redirect_Chain_Fixer_Icons::get( $hops > 1 ? 'waypoints' : 'corner-down-right', 13 ) . esc_html( $hops_label ) . '</span></div>';
		$out .= '<code class="rcf-url rcf-url-old">' . self::breakable( $item['url'] ) . '</code>';

		if ( $chain ) {
			$out .= '<details class="rcf-chain"><summary>' . esc_html__( 'Show each step', 'redirect-chain-fixer' ) . '</summary><ol>';
			foreach ( $chain as $step ) {
				$code  = isset( $step[0] ) ? (int) $step[0] : 0;
				$url   = isset( $step[1] ) ? (string) $step[1] : '';
				$class = $code >= 300 && $code < 400 ? 'redirect' : ( $code >= 200 && $code < 300 ? 'ok' : 'warn' );
				$out  .= '<li><span class="rcf-code rcf-code-' . $class . '">' . ( $code ? (int) $code : '&ndash;' ) . '</span><span class="rcf-step-url">' . self::breakable( $url ) . '</span></li>';
			}
			$out .= '</ol></details>';
		}

		return $out . $this->row_actions( $this->actions( $item ) );
	}

	/**
	 * Row actions for a row.
	 *
	 * @param array $item Row.
	 * @return array<string,string>
	 */
	private function actions( $item ) {
		$id      = (int) $item['id'];
		$actions = array();

		$link = function ( $action, $label, $icon, $class = '' ) use ( $id ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'page'       => Redirect_Chain_Fixer_Admin::PAGE,
						'tab'        => $this->tab,
						'rcf_action' => $action,
						'id'         => $id,
					),
					admin_url( 'admin.php' )
				),
				'redirect_chain_fixer_' . $action . '_' . $id
			);

			return sprintf( '<a href="%1$s" class="rcf-action %2$s">%3$s%4$s</a>', esc_url( $url ), esc_attr( $class ), Redirect_Chain_Fixer_Icons::get( $icon, 14 ), esc_html( $label ) );
		};

		switch ( $this->status ) {
			case Redirect_Chain_Fixer_DB::PENDING:
				$actions['approve'] = $link( 'approve', __( 'Approve', 'redirect-chain-fixer' ), 'check', 'rcf-action-approve' );
				$actions['ignore']  = $link( 'ignore', __( 'Ignore', 'redirect-chain-fixer' ), 'eye-off' );
				break;
			case Redirect_Chain_Fixer_DB::FIXED:
				if ( Redirect_Chain_Fixer_Scanner::can_undo( $item ) ) {
					$actions['undo'] = $link( 'undo', __( 'Undo', 'redirect-chain-fixer' ), 'undo-2' );
				} elseif ( ! empty( $item['post_type'] ) ) {
					$actions['revisions'] = sprintf(
						'<a href="%1$s">%2$s%3$s</a>',
						esc_url( $this->revisions_url( (int) $item['post_id'] ) ),
						Redirect_Chain_Fixer_Icons::get( 'history', 14 ),
						esc_html__( 'Post edited since: view revisions', 'redirect-chain-fixer' )
					);
				}
				break;
			default:
				$actions['restore'] = $link( 'restore', __( 'Restore', 'redirect-chain-fixer' ), 'rotate-ccw' );
		}

		if ( '/' !== substr( $item['url'], 0, 1 ) || '//' === substr( $item['url'], 0, 2 ) ) {
			$actions['open'] = sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s%3$s</a>',
				esc_url( $item['url'] ),
				Redirect_Chain_Fixer_Icons::get( 'external-link', 14 ),
				esc_html__( 'Open old link', 'redirect-chain-fixer' )
			);
		}

		return $actions;
	}

	/**
	 * The post's latest revision, to compare and restore from, or its edit screen.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function revisions_url( $post_id ) {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);

		if ( $revisions ) {
			return admin_url( 'revision.php?revision=' . (int) reset( $revisions ) );
		}

		$edit = get_edit_post_link( $post_id, 'raw' );

		return $edit ? $edit : admin_url();
	}

	/**
	 * Target column: the new link, with warnings worth a second look.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_target( $item ) {
		$out = '<div class="rcf-target">' . Redirect_Chain_Fixer_Icons::get( 'arrow-right', 16, 'rcf-target-arrow' ) . '<a class="rcf-url rcf-url-new" href="' . esc_url( $item['target'] ) . '" target="_blank" rel="noopener noreferrer">' . self::breakable( $item['target'] ) . '</a></div>';

		$warnings = $this->warnings( $item );
		if ( $warnings ) {
			$out .= '<ul class="rcf-warnings">';
			foreach ( $warnings as $warning ) {
				$out .= '<li>' . Redirect_Chain_Fixer_Icons::get( $warning[0], 14 ) . '<span>' . esc_html( $warning[1] ) . '</span></li>';
			}
			$out .= '</ul>';
		}

		return $out;
	}

	/**
	 * Reasons to look twice before approving.
	 *
	 * @param array $item Row.
	 * @return array<int,array{0: string, 1: string}> Icon and message pairs.
	 */
	private function warnings( $item ) {
		if ( 'internal' === $item['scope'] ) {
			return array();
		}

		$warnings = array();
		$chain    = json_decode( (string) $item['chain'], true );
		$final    = is_array( $chain ) && $chain ? end( $chain ) : array();
		$code     = isset( $final[0] ) ? (int) $final[0] : 0;
		$old_path = trim( (string) wp_parse_url( $item['url'], PHP_URL_PATH ), '/' );
		$new_path = trim( (string) wp_parse_url( $item['target'], PHP_URL_PATH ), '/' );
		$new_qs   = (string) wp_parse_url( $item['target'], PHP_URL_QUERY );

		if ( '' !== $old_path && '' === $new_path && '' === $new_qs ) {
			$warnings[] = array( 'house', __( 'Lands on the homepage. The old page may have been removed.', 'redirect-chain-fixer' ) );
		}

		if ( Redirect_Chain_Fixer_Checker::host( $item['url'] ) !== Redirect_Chain_Fixer_Checker::host( $item['target'] ) ) {
			$warnings[] = array( 'globe', __( 'Moves to a different website.', 'redirect-chain-fixer' ) );
		}

		if ( 401 === $code || 403 === $code ) {
			/* translators: %d: HTTP status code. */
			$warnings[] = array( 'shield-alert', sprintf( __( 'The new page turned the checker away (%d). Open it to make sure it works.', 'redirect-chain-fixer' ), $code ) );
		} elseif ( $code >= 300 && $code < 400 ) {
			/* translators: %d: HTTP status code. */
			$warnings[] = array( 'triangle-alert', sprintf( __( 'The new page redirects again temporarily (%d).', 'redirect-chain-fixer' ), $code ) );
		}

		return $warnings;
	}

	/**
	 * Post column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_post( $item ) {
		$post_id = (int) $item['post_id'];

		if ( empty( $item['post_type'] ) ) {
			return '<span class="rcf-muted">' . esc_html__( '(deleted)', 'redirect-chain-fixer' ) . '</span>';
		}

		$title  = '' !== (string) $item['post_title'] ? $item['post_title'] : __( '(no title)', 'redirect-chain-fixer' );
		$object = get_post_type_object( $item['post_type'] );
		$type   = $object ? $object->labels->singular_name : $item['post_type'];
		$edit   = get_edit_post_link( $post_id );

		$out = $edit
			? '<a class="rcf-post-title" href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>'
			: '<span class="rcf-post-title">' . esc_html( $title ) . '</span>';

		$out .= '<span class="rcf-post-meta">' . esc_html( $type );

		$view = get_permalink( $post_id );
		if ( $view ) {
			$out .= ' &middot; <a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'redirect-chain-fixer' ) . '</a>';
		}

		return $out . '</span>';
	}

	/**
	 * Date column: when, and how.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_date( $item ) {
		$time = (int) $item['updated'];

		$out = sprintf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			/* translators: %s: time ago, e.g. "2 hours". */
			esc_html( sprintf( __( '%s ago', 'redirect-chain-fixer' ), human_time_diff( $time ) ) )
		);

		$labels = array(
			'auto'     => array( 'sparkles', __( 'Automatically', 'redirect-chain-fixer' ) ),
			'approved' => array( 'check', __( 'Approved', 'redirect-chain-fixer' ) ),
			'ignored'  => array( 'eye-off', __( 'Ignored', 'redirect-chain-fixer' ) ),
			'undone'   => array( 'undo-2', __( 'Undone', 'redirect-chain-fixer' ) ),
		);

		if ( Redirect_Chain_Fixer_DB::PENDING !== $this->status && isset( $labels[ $item['method'] ] ) ) {
			$label = $labels[ $item['method'] ][1];
			$user  = (int) $item['user_id'] ? get_userdata( (int) $item['user_id'] ) : false;

			if ( $user ) {
				/* translators: 1: "Approved", "Undone" or "Ignored", 2: user's display name. */
				$label = sprintf( __( '%1$s by %2$s', 'redirect-chain-fixer' ), $label, $user->display_name );
			}

			$out .= '<span class="rcf-method rcf-method-' . esc_attr( $item['method'] ) . '">' . Redirect_Chain_Fixer_Icons::get( $labels[ $item['method'] ][0], 13 ) . esc_html( $label ) . '</span>';
		}

		return $out;
	}

	/**
	 * A URL, escaped, with line-break chances after its slashes and
	 * separators, so long links wrap at sensible places.
	 *
	 * @param string $url URL.
	 * @return string HTML.
	 */
	private static function breakable( $url ) {
		// Split first, then escape each piece, so "&" never becomes "&<wbr>amp;".
		$parts = preg_split( '#([/?&=]+)#', (string) $url, -1, PREG_SPLIT_DELIM_CAPTURE );
		$html  = '';

		foreach ( (array) $parts as $i => $part ) {
			$html .= esc_html( $part ) . ( $i % 2 ? '<wbr>' : '' );
		}

		return $html;
	}

	/**
	 * Fallback for columns without their own method.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}
}
