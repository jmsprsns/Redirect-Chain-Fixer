<?php
/**
 * Admin side: the Content Powered > Redirect Chain Fixer page and the Plugins-screen link.
 *
 * @package Redirect_Chain_Fixer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller.
 */
final class Redirect_Chain_Fixer_Admin {

	/**
	 * Page slug, also used as the settings group.
	 */
	const PAGE = 'redirect-chain-fixer';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'route';

	/**
	 * Capability needed for everything on the page.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Tabs: id => Lucide icon.
	 */
	const TABS = array(
		'review'   => 'inbox',
		'fixed'    => 'circle-check',
		'ignored'  => 'eye-off',
		'settings' => 'settings-2',
	);

	/**
	 * Fix status each list tab shows.
	 */
	const TAB_STATUS = array(
		'review'  => Redirect_Chain_Fixer_DB::PENDING,
		'fixed'   => Redirect_Chain_Fixer_DB::FIXED,
		'ignored' => Redirect_Chain_Fixer_DB::IGNORED,
	);

	/**
	 * Actions a row or a bulk selection can take, and the tab each belongs to.
	 */
	const ACTIONS = array(
		'approve' => 'review',
		'ignore'  => 'review',
		'undo'    => 'fixed',
		'restore' => 'ignored',
	);

	/**
	 * Plugin instance.
	 *
	 * @var Redirect_Chain_Fixer_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * The list on the current tab.
	 *
	 * @var Redirect_Chain_Fixer_List_Table|null
	 */
	private $table = null;

	/**
	 * Constructor.
	 *
	 * @param Redirect_Chain_Fixer_Plugin $plugin Plugin instance.
	 */
	public function __construct( Redirect_Chain_Fixer_Plugin $plugin ) {
		$this->plugin = $plugin;

		Redirect_Chain_Fixer_Menu::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_redirect_chain_fixer_scan', array( $this, 'ajax_scan' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( REDIRECT_CHAIN_FIXER_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url( 'settings' ) ),
			esc_html__( 'Settings', 'redirect-chain-fixer' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * URL of a tab on the page.
	 *
	 * @param string $tab  Tab ID.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( $tab = 'review', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = Redirect_Chain_Fixer_Icons::get( self::ICON, 17, 'redirect-chain-fixer-menu-icon' );
		$menu_title = $icon . '<span class="redirect-chain-fixer-menu-label">' . esc_html__( 'Redirect Chain Fixer', 'redirect-chain-fixer' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Redirect_Chain_Fixer_Menu::SLUG,
			__( 'Redirect Chain Fixer', 'redirect-chain-fixer' ),
			$menu_title,
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'load_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
			add_filter( 'admin_body_class', array( $this, 'body_class' ) );
			add_filter( 'screen_options_show_screen', array( $this, 'hide_screen_options' ), 10, 2 );
		}
	}

	/**
	 * No Screen Options tab: the list's columns are all needed, and the tab
	 * would sit on top of the page header.
	 *
	 * @param bool      $show   Whether to show the tab.
	 * @param WP_Screen $screen Current screen.
	 * @return bool
	 */
	public function hide_screen_options( $show, $screen ) {
		return $screen instanceof WP_Screen && $screen->id === $this->hook_suffix ? false : $show;
	}

	/**
	 * Mark the page's <body>, so its header can run edge to edge.
	 *
	 * @param string $classes Space-separated body classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && $screen->id === $this->hook_suffix ? $classes . ' rcf-page' : $classes;
	}

	/**
	 * The tab being viewed.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'review'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return array_key_exists( $tab, self::TABS ) ? $tab : 'review';
	}

	// Actions -----------------------------------------------------------------

	/**
	 * Before the page is drawn: handle actions, then set up the list.
	 *
	 * @return void
	 */
	public function load_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab = $this->current_tab();

		if ( array_key_exists( $tab, self::TAB_STATUS ) ) {
			require_once REDIRECT_CHAIN_FIXER_DIR . 'includes/class-redirect-chain-fixer-list-table.php';
			$this->table = new Redirect_Chain_Fixer_List_Table( self::TAB_STATUS[ $tab ], $tab );
		}

		$this->handle_scan_request();
		$this->handle_row_action();
		$this->handle_bulk_action( $tab );

		// Like core's list screens: drop the nonce and referer a plain search
		// or page change leaves in the address bar.
		if ( $this->table && ! empty( $_GET['_wp_http_referer'] ) && isset( $_SERVER['REQUEST_URI'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( remove_query_arg( array( '_wp_http_referer', '_wpnonce', 'action', 'action2' ), esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) );
			exit;
		}

		if ( $this->table ) {
			$this->table->prepare_items();
		}
	}

	/**
	 * "Scan now" without JavaScript: start a scan and let WP-Cron run it.
	 *
	 * @return void
	 */
	private function handle_scan_request() {
		if ( empty( $_POST['rcf_scan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked below.
			return;
		}

		check_admin_referer( 'redirect_chain_fixer_scan' );

		Redirect_Chain_Fixer_Scanner::start();

		$this->redirect( $this->current_tab(), array( 'rcf_notice' => 'scan' ) );
	}

	/**
	 * A row action link: approve, ignore, undo or restore one row.
	 *
	 * @return void
	 */
	private function handle_row_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Checked below.
		if ( empty( $_GET['rcf_action'] ) || empty( $_GET['id'] ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['rcf_action'] ) );
		$id     = absint( $_GET['id'] );
		// phpcs:enable

		if ( ! array_key_exists( $action, self::ACTIONS ) ) {
			return;
		}

		check_admin_referer( 'redirect_chain_fixer_' . $action . '_' . $id );

		$this->perform( $action, array( $id ) );
	}

	/**
	 * A bulk action from the list.
	 *
	 * @param string $tab Current tab.
	 * @return void
	 */
	private function handle_bulk_action( $tab ) {
		if ( ! $this->table ) {
			return;
		}

		$action = $this->table->current_action();

		if ( ! $action || ! array_key_exists( $action, self::ACTIONS ) || self::ACTIONS[ $action ] !== $tab ) {
			return;
		}

		check_admin_referer( 'bulk-redirect-chain-fixes' );

		$ids = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) : array();
		$ids = array_filter( $ids );

		if ( ! $ids ) {
			$this->redirect( $tab );
		}

		$this->perform( $action, $ids );
	}

	/**
	 * Run an action on some rows, then go back to the list with a notice.
	 *
	 * @param string $action approve, ignore, undo or restore.
	 * @param int[]  $ids    Row IDs.
	 * @return void
	 */
	private function perform( $action, array $ids ) {
		$scanner = $this->plugin->scanner();
		$user_id = get_current_user_id();
		$counts  = array(
			'done'   => 0,
			'gone'   => 0,
			'edited' => 0,
			'failed' => 0,
		);

		switch ( $action ) {
			case 'approve':
				$counts = array_merge( $counts, $scanner->approve( $ids, $user_id ) );
				break;
			case 'undo':
				$counts = array_merge( $counts, $scanner->undo( $ids, $user_id ) );
				break;
			case 'ignore':
				$counts['done'] = $scanner->ignore( $ids, $user_id );
				break;
			case 'restore':
				$counts['done'] = $scanner->restore( $ids );
				break;
		}

		$this->redirect(
			self::ACTIONS[ $action ],
			array(
				'rcf_notice' => $action,
				'rcf_done'   => $counts['done'],
				'rcf_gone'   => $counts['gone'],
				'rcf_edited' => $counts['edited'],
				'rcf_failed' => $counts['failed'],
			)
		);
	}

	/**
	 * Redirect back to a tab and stop.
	 *
	 * @param string $tab  Tab ID.
	 * @param array  $args Query arguments for the notice.
	 * @return void
	 */
	private function redirect( $tab, array $args = array() ) {
		wp_safe_redirect( self::url( $tab, array_filter( $args ) ) );
		exit;
	}

	/**
	 * AJAX: start the scan and/or run one short batch, for "Scan now".
	 *
	 * @return void
	 */
	public function ajax_scan() {
		check_ajax_referer( 'redirect_chain_fixer_scan', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'redirect-chain-fixer' ) ), 403 );
		}

		// Starting answers straight away, so the progress bar appears at once;
		// the batches follow in the next requests.
		if ( isset( $_POST['step'] ) && 'start' === sanitize_key( wp_unslash( $_POST['step'] ) ) ) {
			$state = Redirect_Chain_Fixer_Scanner::start();
		} else {
			$state = $this->plugin->scanner()->run( 3 );
		}

		wp_send_json_success(
			array(
				'running' => 'running' === $state['status'],
				'percent' => $this->percent( $state ),
				'status'  => $this->scan_status_text( $state ),
			)
		);
	}

	// Settings ----------------------------------------------------------------

	/**
	 * Register the settings and their fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Redirect_Chain_Fixer_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Redirect_Chain_Fixer_Plugin::defaults(),
			)
		);

		add_settings_section( 'rcf_settings', __( 'Settings', 'redirect-chain-fixer' ), '__return_false', self::PAGE );

		$fields = array(
			'auto_fix'       => __( 'Internal links', 'redirect-chain-fixer' ),
			'check_external' => __( 'External links', 'redirect-chain-fixer' ),
			'post_types'     => __( 'Content to scan', 'redirect-chain-fixer' ),
			'rescan'         => __( 'Automatic rescans', 'redirect-chain-fixer' ),
			'skip_hosts'     => __( 'Skip these domains', 'redirect-chain-fixer' ),
		);

		foreach ( $fields as $key => $label ) {
			$args = 'skip_hosts' === $key ? array( 'label_for' => 'rcf-skip-hosts' ) : array();
			add_settings_field( 'rcf_' . $key, $label, array( $this, 'render_' . $key ), self::PAGE, 'rcf_settings', $args );
		}
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input   = is_array( $input ) ? $input : array();
		$choices = Redirect_Chain_Fixer_Plugin::rescan_choices();
		$types   = isset( $input['post_types'] ) ? array_map( 'sanitize_key', (array) $input['post_types'] ) : array();
		$hosts   = isset( $input['skip_hosts'] ) ? preg_split( '/[\s,]+/', (string) $input['skip_hosts'] ) : array();
		$rescan  = isset( $input['rescan'] ) ? sanitize_key( $input['rescan'] ) : '';

		return array(
			'auto_fix'       => empty( $input['auto_fix'] ) ? 0 : 1,
			'check_external' => empty( $input['check_external'] ) ? 0 : 1,
			'post_types'     => array_values( array_intersect( array_keys( self::scannable_post_types() ), $types ) ),
			'rescan'         => isset( $choices[ $rescan ] ) ? $rescan : 'weekly',
			'skip_hosts'     => implode( "\n", array_unique( array_filter( array_map( array( 'Redirect_Chain_Fixer_Plugin', 'clean_host' ), (array) $hosts ) ) ) ),
		);
	}

	/**
	 * Public content types that can be scanned: name => label.
	 *
	 * @return array<string,string>
	 */
	private static function scannable_post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name && post_type_supports( $type->name, 'editor' ) ) {
				$types[ $type->name ] = $type->labels->name;
			}
		}

		return $types;
	}

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function field_name( $key ) {
		return Redirect_Chain_Fixer_Plugin::OPTION . '[' . $key . ']';
	}

	/**
	 * Internal links setting.
	 *
	 * @return void
	 */
	public function render_auto_fix() {
		$this->render_checkbox(
			'auto_fix',
			__( 'Fix redirecting internal links automatically', 'redirect-chain-fixer' ),
			__( 'Links to your own site are pointed straight at the final page as soon as they\'re found. Turn this off to approve internal fixes by hand too.', 'redirect-chain-fixer' )
		);
	}

	/**
	 * External links setting.
	 *
	 * @return void
	 */
	public function render_check_external() {
		$this->render_checkbox(
			'check_external',
			__( 'Check links to other websites', 'redirect-chain-fixer' ),
			__( 'Fixes to external links always wait in Needs review for your approval. Turn this off and the plugin makes no requests to other sites.', 'redirect-chain-fixer' )
		);
	}

	/**
	 * A checkbox setting with help text.
	 *
	 * @param string $key         Setting key.
	 * @param string $label       Checkbox label.
	 * @param string $description Help text.
	 * @return void
	 */
	private function render_checkbox( $key, $label, $description ) {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::field_name( $key ) ),
			checked( 1, (int) $this->plugin->get_setting( $key ), false ),
			esc_html( $label ),
			esc_html( $description )
		);
	}

	/**
	 * Content types setting.
	 *
	 * @return void
	 */
	public function render_post_types() {
		$selected = (array) $this->plugin->get_setting( 'post_types' );
		?>
		<fieldset class="rcf-checks">
			<legend class="screen-reader-text"><?php esc_html_e( 'Content to scan', 'redirect-chain-fixer' ); ?></legend>
			<?php foreach ( self::scannable_post_types() as $name => $label ) : ?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( self::field_name( 'post_types' ) ); ?>[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $selected, true ) ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php esc_html_e( 'Published and scheduled items are scanned. Drafts are checked once they\'re published.', 'redirect-chain-fixer' ); ?></p>
		<?php
	}

	/**
	 * Rescan setting.
	 *
	 * @return void
	 */
	public function render_rescan() {
		$current = (string) $this->plugin->get_setting( 'rescan' );
		$labels  = array(
			'daily'   => __( 'Daily', 'redirect-chain-fixer' ),
			'weekly'  => __( 'Weekly', 'redirect-chain-fixer' ),
			'monthly' => __( 'Monthly', 'redirect-chain-fixer' ),
			'off'     => __( 'Never (only when I click Scan now)', 'redirect-chain-fixer' ),
		);
		?>
		<fieldset class="rcf-checks">
			<legend class="screen-reader-text"><?php esc_html_e( 'Automatic rescans', 'redirect-chain-fixer' ); ?></legend>
			<?php foreach ( $labels as $value => $label ) : ?>
				<label>
					<input type="radio" name="<?php echo esc_attr( self::field_name( 'rescan' ) ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?>>
					<?php echo esc_html( $label ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description"><?php esc_html_e( 'Pages you link to can start redirecting at any time, so everything is rechecked on this schedule. Posts are also checked a minute or so after each save.', 'redirect-chain-fixer' ); ?></p>
		<?php
	}

	/**
	 * Skipped domains setting.
	 *
	 * @return void
	 */
	public function render_skip_hosts() {
		printf(
			'<textarea id="rcf-skip-hosts" name="%1$s" rows="4" class="large-text code" placeholder="%2$s">%3$s</textarea><p class="description">%4$s</p>',
			esc_attr( self::field_name( 'skip_hosts' ) ),
			esc_attr( "amzn.to\nshareasale.com" ),
			esc_textarea( (string) $this->plugin->get_setting( 'skip_hosts' ) ),
			esc_html__( 'One per line. Links to these domains and their subdomains are never checked or changed: handy for affiliate links and link shorteners whose redirects are there on purpose.', 'redirect-chain-fixer' )
		);
	}

	// Assets ------------------------------------------------------------------

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="redirect-chain-fixer-menu-icon-style">'
			. '#adminmenu a:has(>.redirect-chain-fixer-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .redirect-chain-fixer-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .redirect-chain-fixer-menu-icon,#adminmenu a:hover .redirect-chain-fixer-menu-icon,#adminmenu a:focus .redirect-chain-fixer-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'redirect-chain-fixer-admin', REDIRECT_CHAIN_FIXER_URL . 'assets/admin.css', array(), REDIRECT_CHAIN_FIXER_VERSION );
		wp_enqueue_script( 'redirect-chain-fixer-admin', REDIRECT_CHAIN_FIXER_URL . 'assets/admin.js', array(), REDIRECT_CHAIN_FIXER_VERSION, true );

		wp_localize_script(
			'redirect-chain-fixer-admin',
			'redirect_chain_fixer_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'redirect_chain_fixer_scan' ),
				'error'   => __( 'The scan stopped responding. It will carry on in the background; reload the page to check on it.', 'redirect-chain-fixer' ),
			)
		);
	}

	// Page --------------------------------------------------------------------

	/**
	 * Translated tab label.
	 *
	 * @param string $id Tab ID.
	 * @return string
	 */
	private function tab_label( $id ) {
		$labels = array(
			'review'   => __( 'Needs review', 'redirect-chain-fixer' ),
			'fixed'    => __( 'Fixed', 'redirect-chain-fixer' ),
			'ignored'  => __( 'Ignored', 'redirect-chain-fixer' ),
			'settings' => __( 'Settings', 'redirect-chain-fixer' ),
		);

		return isset( $labels[ $id ] ) ? $labels[ $id ] : $id;
	}

	/**
	 * Render the page: a header with the tabs, then WordPress's two-column
	 * box layout (the list or settings on the left, the scan and status on
	 * the right).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab    = $this->current_tab();
		$counts = Redirect_Chain_Fixer_DB::counts();
		$badges = array(
			'review'  => $counts[ Redirect_Chain_Fixer_DB::PENDING ],
			'fixed'   => $counts[ Redirect_Chain_Fixer_DB::FIXED ],
			'ignored' => $counts[ Redirect_Chain_Fixer_DB::IGNORED ],
		);
		?>
		<div class="rcf-header">
			<div class="rcf-header-title">
				<span class="rcf-header-icon"><?php echo Redirect_Chain_Fixer_Icons::get( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="rcf-version"><?php echo esc_html( 'v' . REDIRECT_CHAIN_FIXER_VERSION ); ?></span>
			</div>
			<nav class="rcf-tabs" aria-label="<?php esc_attr_e( 'Redirect Chain Fixer sections', 'redirect-chain-fixer' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>" class="rcf-tab<?php echo $tab === $id ? ' is-active' : ''; ?>"<?php echo $tab === $id ? ' aria-current="page"' : ''; ?>>
						<?php echo Redirect_Chain_Fixer_Icons::get( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( $this->tab_label( $id ) ); ?>
						<?php if ( ! empty( $badges[ $id ] ) ) : ?>
							<span class="rcf-count<?php echo 'review' === $id ? ' rcf-count-alert' : ''; ?>"><?php echo esc_html( number_format_i18n( $badges[ $id ] ) ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap rcf-wrap">
			<hr class="wp-header-end">

			<?php
			// Pages outside the Settings menu have to show "Settings saved." themselves.
			settings_errors();
			$this->render_notice();
			?>

			<div id="poststuff">
				<div id="post-body" class="metabox-holder columns-2">
					<div id="post-body-content">
						<?php $this->render_stats( $counts ); ?>

						<?php
						if ( 'settings' === $tab ) {
							$this->render_settings();
						} else {
							$this->render_list( $tab );
						}
						?>
					</div>

					<div id="postbox-container-1" class="postbox-container">
						<div class="rcf-sidebar">
							<?php
							$this->render_scan_box();
							$this->render_how_box();
							$this->render_updates_box();
							?>
						</div>
					</div>
				</div>
				<br class="clear">
			</div>
		</div>
		<?php
	}

	/**
	 * The notice after an action.
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		$notice = isset( $_GET['rcf_notice'] ) ? sanitize_key( wp_unslash( $_GET['rcf_notice'] ) ) : '';
		$done   = isset( $_GET['rcf_done'] ) ? absint( $_GET['rcf_done'] ) : 0;
		$gone   = isset( $_GET['rcf_gone'] ) ? absint( $_GET['rcf_gone'] ) : 0;
		$edited = isset( $_GET['rcf_edited'] ) ? absint( $_GET['rcf_edited'] ) : 0;
		$failed = isset( $_GET['rcf_failed'] ) ? absint( $_GET['rcf_failed'] ) : 0;
		// phpcs:enable

		$messages = array();

		switch ( $notice ) {
			case 'scan':
				$messages[] = __( 'Scan started. It carries on in the background, so you can leave this page.', 'redirect-chain-fixer' );
				break;
			case 'approve':
				/* translators: %s: number of links. */
				$messages[] = sprintf( _n( '%s link updated.', '%s links updated.', $done, 'redirect-chain-fixer' ), number_format_i18n( $done ) );
				break;
			case 'undo':
				if ( $done || ! $edited ) {
					/* translators: %s: number of links. */
					$messages[] = sprintf( _n( '%s change undone. That link won\'t be changed again.', '%s changes undone. Those links won\'t be changed again.', $done, 'redirect-chain-fixer' ), number_format_i18n( $done ) );
				}
				if ( $edited ) {
					/* translators: %s: number of links. */
					$messages[] = sprintf( _n( '%s change was left as it is because its post has been edited since. Use the post\'s revisions to put it back.', '%s changes were left as they are because their posts have been edited since. Use the posts\' revisions to put them back.', $edited, 'redirect-chain-fixer' ), number_format_i18n( $edited ) );
				}
				break;
			case 'ignore':
				/* translators: %s: number of links. */
				$messages[] = sprintf( _n( '%s link ignored. It won\'t be suggested again.', '%s links ignored. They won\'t be suggested again.', $done, 'redirect-chain-fixer' ), number_format_i18n( $done ) );
				break;
			case 'restore':
				/* translators: %s: number of links. */
				$messages[] = sprintf( _n( '%s link restored. Its post will be checked again in a moment.', '%s links restored. Their posts will be checked again in a moment.', $done, 'redirect-chain-fixer' ), number_format_i18n( $done ) );
				break;
			default:
				return;
		}

		if ( 'approve' === $notice && $gone ) {
			/* translators: %s: number of links. */
			$messages[] = sprintf( _n( '%s link had already been edited out of its post, so it was taken off the list.', '%s links had already been edited out of their posts, so they were taken off the list.', $gone, 'redirect-chain-fixer' ), number_format_i18n( $gone ) );
		}

		if ( $failed ) {
			/* translators: %s: number of links. */
			$messages[] = sprintf( _n( '%s link couldn\'t be saved. Check that you can edit its post.', '%s links couldn\'t be saved. Check that you can edit their posts.', $failed, 'redirect-chain-fixer' ), number_format_i18n( $failed ) );
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			$failed || $edited ? 'warning' : 'success',
			esc_html( implode( ' ', $messages ) )
		);
	}

	/**
	 * The row of numbers above the list.
	 *
	 * @param array $counts Fix counts from Redirect_Chain_Fixer_DB::counts().
	 * @return void
	 */
	private function render_stats( array $counts ) {
		$state = Redirect_Chain_Fixer_Scanner::get_state();
		$stats = array(
			array( 'file-search', number_format_i18n( (int) $state['done'] ), __( 'Posts scanned', 'redirect-chain-fixer' ), '' ),
			array( 'link', number_format_i18n( Redirect_Chain_Fixer_DB::count_checked() ), __( 'Links checked', 'redirect-chain-fixer' ), '' ),
			array( 'sparkles', number_format_i18n( $counts[ Redirect_Chain_Fixer_DB::FIXED ] ), __( 'Links fixed', 'redirect-chain-fixer' ), 'good' ),
			array( 'inbox', number_format_i18n( $counts[ Redirect_Chain_Fixer_DB::PENDING ] ), __( 'Need review', 'redirect-chain-fixer' ), $counts[ Redirect_Chain_Fixer_DB::PENDING ] ? 'alert' : '' ),
		);
		?>
		<ul class="rcf-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<li class="rcf-stat<?php echo $stat[3] ? ' rcf-stat-' . esc_attr( $stat[3] ) : ''; ?>">
					<span class="rcf-stat-icon"><?php echo Redirect_Chain_Fixer_Icons::get( $stat[0], 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="rcf-stat-text">
						<strong><?php echo esc_html( $stat[1] ); ?></strong>
						<span><?php echo esc_html( $stat[2] ); ?></span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * A list tab: the table in a box.
	 *
	 * @param string $tab Tab ID.
	 * @return void
	 */
	private function render_list( $tab ) {
		if ( ! $this->table ) {
			return;
		}

		$intros = array(
			'review'  => __( 'External links that pass through permanent redirects. Approve a change to point the link straight at the page it ends up on, or ignore it to leave the link as it is.', 'redirect-chain-fixer' ),
			'fixed'   => __( 'Every link the plugin has changed. Undo puts a link back the way it was and stops it being changed again. Each change also saved a revision of its post.', 'redirect-chain-fixer' ),
			'ignored' => __( 'Links you ignored or undid. Scans leave these alone. Restore one to have its post checked again.', 'redirect-chain-fixer' ),
		);
		?>
		<div class="postbox rcf-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo Redirect_Chain_Fixer_Icons::get( self::TABS[ $tab ], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( $this->tab_label( $tab ) ); ?>
				</h2>
			</div>
			<div class="inside">
				<p class="rcf-intro"><?php echo esc_html( $intros[ $tab ] ); ?></p>
				<form method="get" class="rcf-list-form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
					<?php
					$this->table->search_box( __( 'Search links', 'redirect-chain-fixer' ), 'rcf-search' );
					$this->table->display();
					?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * The settings tab.
	 *
	 * @return void
	 */
	private function render_settings() {
		?>
		<div class="postbox rcf-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo Redirect_Chain_Fixer_Icons::get( 'settings-2', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Settings', 'redirect-chain-fixer' ); ?>
				</h2>
			</div>
			<div class="inside">
				<form action="options.php" method="post">
					<?php settings_fields( self::PAGE ); ?>
					<table class="form-table" role="presentation">
						<?php do_settings_fields( self::PAGE, 'rcf_settings' ); ?>
					</table>
					<?php submit_button( __( 'Save Changes', 'redirect-chain-fixer' ) ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Scan progress, 0-100.
	 *
	 * @param array $state Scan state.
	 * @return int
	 */
	private function percent( array $state ) {
		if ( 'running' !== $state['status'] ) {
			return 100;
		}

		return (int) $state['total'] ? min( 99, (int) floor( 100 * (int) $state['done'] / (int) $state['total'] ) ) : 0;
	}

	/**
	 * One line about the scan: its progress, or how the last one went.
	 *
	 * @param array $state Scan state.
	 * @return string Plain text.
	 */
	private function scan_status_text( array $state ) {
		if ( 'running' === $state['status'] ) {
			return sprintf(
				/* translators: 1: posts scanned, 2: posts to scan. */
				__( 'Scanning: %1$s of %2$s posts checked', 'redirect-chain-fixer' ),
				number_format_i18n( min( (int) $state['done'], (int) $state['total'] ) ),
				number_format_i18n( (int) $state['total'] )
			);
		}

		if ( ! (int) $state['finished'] ) {
			return __( 'No scan has run yet.', 'redirect-chain-fixer' );
		}

		return sprintf(
			/* translators: 1: time ago, e.g. "2 hours", 2: links fixed, 3: links newly waiting for review. */
			__( 'Last scan finished %1$s ago: %2$s links fixed, %3$s new to review.', 'redirect-chain-fixer' ),
			human_time_diff( (int) $state['finished'] ),
			number_format_i18n( (int) $state['fixed'] ),
			number_format_i18n( (int) $state['review'] )
		);
	}

	/**
	 * Sidebar: the scan's progress and the Scan now button.
	 *
	 * @return void
	 */
	private function render_scan_box() {
		$state   = Redirect_Chain_Fixer_Scanner::get_state();
		$running = 'running' === $state['status'];
		$days    = $this->plugin->rescan_days();

		if ( ! $days ) {
			$next = __( 'Automatic rescans are off.', 'redirect-chain-fixer' );
		} elseif ( $running || ! (int) $state['finished'] ) {
			$next = __( 'Then rescans automatically on your schedule.', 'redirect-chain-fixer' );
		} else {
			$due  = (int) $state['finished'] + $days * DAY_IN_SECONDS;
			$next = $due <= time()
				? __( 'Next automatic scan: today.', 'redirect-chain-fixer' )
				/* translators: %s: time until the next scan, e.g. "6 days". */
				: sprintf( __( 'Next automatic scan in %s.', 'redirect-chain-fixer' ), human_time_diff( $due ) );
		}
		?>
		<div class="postbox rcf-scan-box" data-rcf-scan data-running="<?php echo $running ? '1' : '0'; ?>">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo Redirect_Chain_Fixer_Icons::get( 'radar', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Scan', 'redirect-chain-fixer' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p class="rcf-scan-status" data-rcf-scan-status aria-live="polite"><?php echo esc_html( $this->scan_status_text( $state ) ); ?></p>
				<div class="rcf-progress"<?php echo $running ? '' : ' hidden'; ?> data-rcf-progress>
					<span style="width: <?php echo (int) $this->percent( $state ); ?>%"></span>
				</div>
				<form method="post" action="<?php echo esc_url( self::url( $this->current_tab() ) ); ?>">
					<?php wp_nonce_field( 'redirect_chain_fixer_scan' ); ?>
					<button type="submit" name="rcf_scan" value="1" class="button button-primary button-large rcf-scan-button" data-rcf-scan-button<?php disabled( $running ); ?>>
						<span class="rcf-scan-idle"><?php echo Redirect_Chain_Fixer_Icons::get( 'scan-search', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Scan now', 'redirect-chain-fixer' ); ?></span>
						<span class="rcf-scan-busy"><?php echo Redirect_Chain_Fixer_Icons::get( 'loader-circle', 16, 'rcf-spin' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Scanning…', 'redirect-chain-fixer' ); ?></span>
					</button>
				</form>
				<p class="rcf-scan-next"><?php echo Redirect_Chain_Fixer_Icons::get( 'timer', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $next ); ?></span></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Sidebar: what the plugin does, in four lines.
	 *
	 * @return void
	 */
	private function render_how_box() {
		$auto  = (bool) $this->plugin->get_setting( 'auto_fix' );
		$items = array(
			array( 'house', $auto ? __( 'Internal links are fixed automatically', 'redirect-chain-fixer' ) : __( 'Internal links wait for your approval', 'redirect-chain-fixer' ) ),
			array( 'globe', $this->plugin->get_setting( 'check_external' ) ? __( 'External links wait for your approval', 'redirect-chain-fixer' ) : __( 'External links aren\'t checked', 'redirect-chain-fixer' ) ),
			array( 'shield-check', __( 'Only permanent redirects (301, 308) to pages that load', 'redirect-chain-fixer' ) ),
			array( 'history', __( 'Every change is logged, saves a revision and can be undone', 'redirect-chain-fixer' ) ),
		);
		?>
		<div class="postbox">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo Redirect_Chain_Fixer_Icons::get( 'list-checks', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'How it works', 'redirect-chain-fixer' ); ?>
				</h2>
			</div>
			<div class="inside">
				<ul class="rcf-checklist">
					<?php foreach ( $items as $item ) : ?>
						<li><?php echo Redirect_Chain_Fixer_Icons::get( $item[0], 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php echo esc_html( $item[1] ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_box() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( REDIRECT_CHAIN_FIXER_REPO ) );
		$check_url  = Redirect_Chain_Fixer_Updater::check_now_url();
		?>
		<div class="postbox rcf-updates-box">
			<div class="postbox-header">
				<h2 class="hndle">
					<?php echo Redirect_Chain_Fixer_Icons::get( 'refresh-cw', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Updates', 'redirect-chain-fixer' ); ?>
				</h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'redirect-chain-fixer' ), '<strong>' . esc_html( REDIRECT_CHAIN_FIXER_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Redirect_Chain_Fixer_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'redirect-chain-fixer' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( REDIRECT_CHAIN_FIXER_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Redirect_Chain_Fixer_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'redirect-chain-fixer' ),
							'<a href="' . esc_url( REDIRECT_CHAIN_FIXER_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>REDIRECT_CHAIN_FIXER_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'redirect-chain-fixer' ),
							'<a href="' . esc_url( REDIRECT_CHAIN_FIXER_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php esc_html_e( 'Check for updates', 'redirect-chain-fixer' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
