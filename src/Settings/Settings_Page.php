<?php
namespace Odavent\Events\Settings;

use Odavent\Events\Api\Client;
use Odavent\Events\Api\Result;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Render\Assets;

/**
 * Settings > Odavent: connection settings, a connection test and a cache purge.
 *
 * The site key is never printed back: the page shows Options::masked_key() only, and the key
 * input is never pre-filled. Everything is escaped on output.
 */
class Settings_Page {

	const SLUG          = 'odavent-events';
	const GROUP         = 'odavent_events';
	const CAPABILITY    = 'manage_options';
	const ACTION_TEST   = 'odavent_events_test_connection';
	const ACTION_CLEAR  = 'odavent_events_clear_cache';
	const FLASH_PREFIX  = 'odavent_events_notice_';
	const FLASH_TTL     = 60;
	/** Seconds the settings page waits for the events list: it must stay usable when Odavent is slow. */
	const LIST_TIMEOUT  = 3;
	/** Below this text-on-accent contrast ratio (WCAG AA for normal text) saving shows a warning. */
	const MIN_CONTRAST  = 4.5;
	const APPEARANCE_ID = 'odavent_events_appearance';
	const ADMIN_HANDLE  = 'odavent-events-admin-appearance';

	/** @var Options */
	private $options;

	/** @var Client */
	private $client;

	/** @var Event_Repository */
	private $repo;

	/** @var Result|null Events list, fetched at most once per page render. */
	private $events = null;

	/** @var bool Whether sanitize() already ran in this request. */
	private $sanitized = false;

	/** @var string|false|null The page's hook suffix from add_options_page() (false when it was not added). */
	private $hook_suffix = null;

	public function __construct( Options $options, Client $client, Event_Repository $repo ) {
		$this->options = $options;
		$this->client  = $client;
		$this->repo    = $repo;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		// Before the Settings API (priority 10): the option must exist, without autoload, before WordPress creates it.
		add_action( 'admin_init', array( $this->options, 'ensure_exists' ), 5 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_' . self::ACTION_TEST, array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR, array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ODAVENT_EVENTS_FILE ), array( $this, 'action_links' ) );
	}

	public function add_menu(): void {
		$this->hook_suffix = add_options_page(
			__( 'Odavent Events', 'odavent-events' ),
			__( 'Odavent Events', 'odavent-events' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Adds a Settings link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'odavent-events' ) . '</a>' );

		return $links;
	}

	public function register_settings(): void {
		// No 'default': update_option() treats an option whose stored value equals the registered default
		// as missing and re-adds it with add_option() and no autoload argument (autoloaded: `yes`, or `auto`
		// since WordPress 6.6). ensure_exists() stores array(), so a default of array() would autoload the
		// site key on the first save.
		register_setting(
			self::GROUP,
			Options::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
			)
		);

		add_settings_section( 'odavent_events_connection', __( 'Connection', 'odavent-events' ), '__return_false', self::SLUG );
		add_settings_field( 'odavent_events_base_url', __( 'Odavent URL', 'odavent-events' ), array( $this, 'render_base_url' ), self::SLUG, 'odavent_events_connection', array( 'label_for' => 'odavent_events_base_url' ) );
		add_settings_field( 'odavent_events_site_key', __( 'Site key', 'odavent-events' ), array( $this, 'render_site_key' ), self::SLUG, 'odavent_events_connection', array( 'label_for' => 'odavent_events_site_key' ) );
		add_settings_field( 'odavent_events_default_event', __( 'Default event', 'odavent-events' ), array( $this, 'render_default_event' ), self::SLUG, 'odavent_events_connection', array( 'label_for' => 'odavent_events_default_event' ) );

		add_settings_section( 'odavent_events_display', __( 'Display and cache', 'odavent-events' ), '__return_false', self::SLUG );
		add_settings_field( 'odavent_events_cache_ttl', __( 'Cache lifetime', 'odavent-events' ), array( $this, 'render_cache_ttl' ), self::SLUG, 'odavent_events_display', array( 'label_for' => 'odavent_events_cache_ttl' ) );
		add_settings_field( 'odavent_events_load_css', __( 'Default styles', 'odavent-events' ), array( $this, 'render_load_css' ), self::SLUG, 'odavent_events_display', array( 'label_for' => 'odavent_events_load_css' ) );

		$section = self::APPEARANCE_ID;
		add_settings_section( $section, __( 'Appearance', 'odavent-events' ), array( $this, 'render_appearance_section' ), self::SLUG );
		add_settings_field( $section . '_accent', __( 'Accent colour', 'odavent-events' ), array( $this, 'render_accent' ), self::SLUG, $section, array( 'label_for' => $section . '_accent' ) );
		add_settings_field( $section . '_contrast', __( 'Text on accent', 'odavent-events' ), array( $this, 'render_contrast' ), self::SLUG, $section, array( 'label_for' => $section . '_contrast_auto' ) );
		add_settings_field( $section . '_text', __( 'Text colour', 'odavent-events' ), array( $this, 'render_text_color' ), self::SLUG, $section, array( 'label_for' => $section . '_text' ) );
		add_settings_field( $section . '_border', __( 'Border colour', 'odavent-events' ), array( $this, 'render_border' ), self::SLUG, $section, array( 'label_for' => $section . '_border' ) );
		add_settings_field( $section . '_radius', __( 'Corner radius', 'odavent-events' ), array( $this, 'render_radius' ), self::SLUG, $section, array( 'label_for' => $section . '_radius' ) );
		add_settings_field( $section . '_spacing', __( 'Spacing', 'odavent-events' ), array( $this, 'render_spacing' ), self::SLUG, $section, array( 'label_for' => $section . '_spacing' ) );
		add_settings_field( $section . '_button_style', __( 'Button style', 'odavent-events' ), array( $this, 'render_button_style' ), self::SLUG, $section, array( 'label_for' => $section . '_button_style' ) );
		add_settings_field( $section . '_preview', __( 'Preview', 'odavent-events' ), array( $this, 'render_preview' ), self::SLUG, $section );
	}

	/**
	 * `admin_enqueue_scripts`: the colour picker, the preview script and the plugin stylesheet (so the preview
	 * uses the real styles), on this settings page only. Nothing when "Load default styles" is off: the
	 * Appearance fields are disabled and there is no preview.
	 *
	 * @param mixed $hook_suffix The current admin page.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( ! is_string( $this->hook_suffix ) || '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}
		if ( ! $this->options->load_css() ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		// Registered on `init` in wp-admin when "Load default styles" is on, with the saved Appearance CSS.
		wp_enqueue_style( Assets::STYLE_HANDLE );
		wp_enqueue_style( self::ADMIN_HANDLE, plugins_url( 'assets/css/admin-appearance.css', ODAVENT_EVENTS_FILE ), array( 'wp-color-picker', Assets::STYLE_HANDLE ), ODAVENT_EVENTS_VERSION );
		wp_enqueue_script( self::ADMIN_HANDLE, plugins_url( 'assets/js/admin-appearance.js', ODAVENT_EVENTS_FILE ), array( 'jquery', 'wp-color-picker' ), ODAVENT_EVENTS_VERSION, true );
	}

	/**
	 * Settings API sanitize callback.
	 *
	 * WordPress runs it twice on the first save of an option that does not exist yet, so only the
	 * first pass of a request raises settings errors.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ): array {
		$report          = ! $this->sanitized;
		$this->sanitized = true;

		$input   = is_array( $input ) ? $input : array();
		$current = $this->options->get();

		// The wp-config.php constant owns the key: its field is disabled, so a submitted key is a hand-made POST.
		if ( $this->options->is_key_from_constant() ) {
			unset( $input['site_key'] );
		}

		// "Automatic" text on accent is stored as no colour; the manual picker is only hidden, so it still submits.
		if ( isset( $input['appearance'] ) && is_array( $input['appearance'] ) && ! empty( $input['appearance']['contrast_auto'] ) ) {
			$input['appearance']['contrast'] = '';
		}

		$out = $this->options->sanitize( $input, $current, $report );

		// A warning, not a refusal: the organizer may know better (a large-text design, a brand colour).
		// Only for Appearance values submitted with the styles on: disabled fields are not sent.
		if ( $report && isset( $input['appearance'] ) && is_array( $input['appearance'] ) && $out['load_css'] ) {
			$this->warn_low_contrast( $out['appearance'] );
		}

		// Another key or address means another connection: drop the editor's cached list (another organization's
		// events) and what the old connection left behind (rejected-key and contract notices, the breaker, the
		// key routes disabled after a 401/403), so the new key is tried on the next page view.
		if ( $out['site_key'] !== $current['site_key'] || $out['base_url'] !== $current['base_url'] ) {
			delete_transient( Event_Repository::LIST_TRANSIENT );
			delete_transient( Event_Repository::INVALID_KEY_NOTICE );
			delete_transient( Event_Repository::CONTRACT_NOTICE );
			delete_transient( Event_Repository::BACKOFF );
			$this->options->set_connection_disabled( false );
		}

		return $out;
	}

	/**
	 * Adds a settings warning when the text-on-accent pair is hard to read. No accent of the organizer's own
	 * means the stylesheet's (or the theme's) pair, which is not this screen's to judge.
	 *
	 * @param array $appearance Validated Appearance values.
	 */
	private function warn_low_contrast( array $appearance ): void {
		if ( '' === $appearance['accent'] ) {
			return;
		}

		$ratio = $this->appearance_ratio( $appearance );
		if ( $ratio >= self::MIN_CONTRAST ) {
			return;
		}

		add_settings_error(
			Options::SETTINGS_ERROR_NAME,
			self::APPEARANCE_ID . '_contrast',
			sprintf(
				/* translators: 1: contrast ratio such as 3.20, 2: the recommended minimum, 4.5. */
				__( 'Your colours were saved, but the text on the accent colour has a contrast ratio of %1$s:1, below the recommended %2$s:1. Buttons may be hard to read; choose another text colour or turn on Automatic.', 'odavent-events' ),
				number_format_i18n( $ratio, 2 ),
				number_format_i18n( self::MIN_CONTRAST, 1 )
			),
			'warning'
		);
	}

	/**
	 * Contrast ratio of the effective accent and text-on-accent pair (automatic when no manual colour).
	 *
	 * @param array $appearance Validated Appearance values with an accent.
	 */
	private function appearance_ratio( array $appearance ): float {
		$contrast = '' !== $appearance['contrast'] ? $appearance['contrast'] : Appearance::auto_contrast( $appearance['accent'] );

		return Appearance::contrast_ratio( $appearance['accent'], $contrast );
	}

	// ---- admin-post handlers ------------------------------------------------------------------

	public function handle_test_connection(): void {
		$this->authorize( self::ACTION_TEST );

		if ( ! $this->options->has_key() ) {
			// No call: there is nothing to test, and "rejected" would blame a key that was never sent.
			$this->flash( 'error', $this->options->is_key_from_constant() ? $this->malformed_constant_text() : __( 'Enter a site key first.', 'odavent-events' ) );
			$this->redirect_back();
			return;
		}

		$result = $this->client->list_events();

		if ( $result->ok ) {
			$this->options->set_connection_disabled( false );
			delete_transient( Event_Repository::INVALID_KEY_NOTICE );
			delete_transient( Event_Repository::CONTRACT_NOTICE );
			$count = count( $result->data );
			$this->flash(
				'success',
				sprintf(
					/* translators: %d: number of events. */
					_n( 'Connected: %d event found.', 'Connected: %d events found.', $count, 'odavent-events' ),
					$count
				)
			);
		} elseif ( Result::INVALID_KEY === $result->code ) {
			$this->flash( 'error', __( 'The site key was rejected.', 'odavent-events' ) );
		} elseif ( Result::PLAN_REQUIRED === $result->code ) {
			$this->options->set_connection_disabled( true );
			$this->flash( 'warning', $this->plan_required_text() );
		} else {
			$this->flash( 'error', __( 'Could not reach Odavent. Check the URL and try again.', 'odavent-events' ) );
		}

		$this->redirect_back();
	}

	public function handle_clear_cache(): void {
		$this->authorize( self::ACTION_CLEAR );

		$this->repo->purge_all();
		$this->flash( 'success', __( 'Cache cleared.', 'odavent-events' ) );

		$this->redirect_back();
	}

	/**
	 * Capability first, then the nonce (check_admin_referer ends the request itself when it fails).
	 */
	private function authorize( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'odavent-events' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Stores a one-time notice for the current user, shown on the next render of the page.
	 */
	private function flash( string $type, string $message ): void {
		set_transient(
			self::FLASH_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			self::FLASH_TTL
		);
	}

	/**
	 * Seam for tests: sends the user back to the settings page and ends the request.
	 */
	protected function redirect_back(): void {
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::SLUG ) );
		exit;
	}

	// ---- rendering -------------------------------------------------------------------------------------

	public function render_page(): void {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Odavent Events', 'odavent-events' ) . '</h1>';

		// No settings_errors() here: for pages under Settings, wp-admin/options-head.php already prints them
		// (and "Settings saved."); a second call shows every notice twice.
		$this->render_notices();

		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Tools', 'odavent-events' ) . '</h2>';
		echo '<p>' . esc_html__( 'Test the connection with the saved settings, or clear everything this plugin has cached.', 'odavent-events' ) . '</p>';
		$this->render_action_form( self::ACTION_TEST, __( 'Test connection', 'odavent-events' ) );
		$this->render_action_form( self::ACTION_CLEAR, __( 'Clear cache', 'odavent-events' ) );
		echo '</div>';
	}

	private function render_action_form( string $action, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private function render_notices(): void {
		$flash_key = self::FLASH_PREFIX . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( false !== $flash ) {
			delete_transient( $flash_key );
		}
		if ( is_array( $flash ) && isset( $flash['message'] ) && is_string( $flash['message'] ) ) {
			$type = isset( $flash['type'] ) && in_array( $flash['type'], array( 'success', 'warning', 'error' ), true ) ? $flash['type'] : 'info';
			$this->notice( $type, $flash['message'] );
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$minutes = max( 1, (int) round( $this->options->cache_ttl() / 60 ) );
			$this->notice(
				'warning',
				sprintf(
					/* translators: %d: number of minutes. */
					_n(
						'Live updates need pretty permalinks; pages refresh every %d minute instead.',
						'Live updates need pretty permalinks; pages refresh every %d minutes instead.',
						$minutes,
						'odavent-events'
					),
					$minutes
				)
			);
		}

		$key_rejected = false !== get_transient( Event_Repository::INVALID_KEY_NOTICE );

		// A rejected key disables the key routes too (published events keep showing): that is not a plan matter.
		if ( $this->options->is_connection_disabled() && ! $key_rejected ) {
			$this->notice( 'warning', $this->plan_required_text() );
		}

		if ( $key_rejected ) {
			$this->notice( 'error', __( 'Your Odavent site key was rejected; check it below.', 'odavent-events' ) );
		}

		if ( false !== get_transient( Event_Repository::CONTRACT_NOTICE ) ) {
			$this->notice( 'warning', __( 'Odavent has a newer data format than this plugin supports; update the Odavent plugin.', 'odavent-events' ) );
		}
	}

	private function notice( string $type, string $message ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}

	private function malformed_constant_text(): string {
		return __( 'ODAVENT_SITE_KEY in wp-config.php is not a valid site key (it starts with odv_wp_), so it is ignored.', 'odavent-events' );
	}

	private function plan_required_text(): string {
		return __( 'Your Odavent plan no longer includes the WordPress plugin; published events keep showing.', 'odavent-events' );
	}

	public function render_base_url(): void {
		printf(
			'<input type="url" class="regular-text" id="odavent_events_base_url" name="%s[base_url]" value="%s" placeholder="https://odavent.com">',
			esc_attr( Options::OPTION ),
			esc_attr( $this->options->base_url() )
		);
	}

	public function render_site_key(): void {
		$from_constant = $this->options->is_key_from_constant();

		// Never pre-filled: the field only ever submits a new key.
		printf(
			'<input type="password" class="regular-text" id="odavent_events_site_key" name="%s[site_key]" autocomplete="off" placeholder="odv_wp_…"%s>',
			esc_attr( Options::OPTION ),
			$from_constant ? ' disabled' : ''
		);

		if ( $this->options->has_key() ) {
			echo '<p class="description"><code>' . esc_html( $this->options->masked_key() ) . '</code> ';
			echo esc_html( $from_constant ? __( 'Defined in wp-config.php', 'odavent-events' ) : __( 'A key is stored', 'odavent-events' ) );
			echo '</p>';
		} elseif ( $from_constant ) {
			echo '<p class="description">' . esc_html( $this->malformed_constant_text() ) . '</p>';
		}

		if ( ! $from_constant ) {
			echo '<p class="description">' . esc_html__( 'Leave blank to keep the stored key. Find your site key in your Odavent organization settings.', 'odavent-events' ) . '</p>';
		}
	}

	public function render_default_event(): void {
		$current = $this->options->default_event_id();
		$name    = Options::OPTION . '[default_event_id]';
		$result  = $this->events_result();

		if ( null === $result || ! $result->ok ) {
			printf(
				'<input type="number" min="0" class="small-text" id="odavent_events_default_event" name="%s" value="%d">',
				esc_attr( $name ),
				(int) $current
			);
			echo '<p class="description">' . esc_html__( 'Could not load your events from Odavent, so enter the event ID. 0 means none.', 'odavent-events' ) . '</p>';
			return;
		}

		printf( '<select id="odavent_events_default_event" name="%s">', esc_attr( $name ) );
		echo '<option value="0">' . esc_html__( 'None', 'odavent-events' ) . '</option>';
		foreach ( $result->data as $row ) {
			if ( ! is_array( $row ) || ! is_int( $row['id'] ?? null ) || ! is_string( $row['name'] ?? null ) ) {
				continue;
			}
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $row['id'],
				$row['id'] === $current ? ' selected' : '',
				esc_html( $row['name'] )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used by blocks and shortcodes that do not name an event.', 'odavent-events' ) . '</p>';
	}

	public function render_cache_ttl(): void {
		printf(
			'<input type="number" min="%d" max="%d" step="1" class="small-text" id="odavent_events_cache_ttl" name="%s[cache_ttl]" value="%d"> ',
			(int) Options::MIN_CACHE_TTL,
			(int) Options::MAX_CACHE_TTL,
			esc_attr( Options::OPTION ),
			(int) $this->options->cache_ttl()
		);
		echo esc_html__( 'seconds (60 to 86400)', 'odavent-events' );
	}

	public function render_load_css(): void {
		printf(
			'<label><input type="checkbox" id="odavent_events_load_css" name="%s[load_css]" value="1"%s> %s</label>',
			esc_attr( Options::OPTION ),
			$this->options->load_css() ? ' checked' : '',
			esc_html__( 'Load the default styles for Odavent blocks', 'odavent-events' )
		);
	}

	// ---- appearance fields -------------------------------------------------------------------------------

	public function render_appearance_section(): void {
		if ( ! $this->options->load_css() ) {
			echo '<p class="description">' . esc_html__( 'Turn on Default styles to use these settings.', 'odavent-events' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Colours and shapes of the Odavent blocks on your site. Empty fields keep the default styles; your theme\'s own CSS still wins.', 'odavent-events' ) . '</p>';
	}

	public function render_accent(): void {
		$this->color_input( 'accent' );
		$this->description( __( 'Buttons, links and highlights. Empty keeps the default indigo.', 'odavent-events' ) );
	}

	public function render_contrast(): void {
		$automatic = '' === $this->options->appearance()['contrast'];

		printf(
			'<label><input type="checkbox" id="%1$s_contrast_auto" name="%2$s[appearance][contrast_auto]" value="1"%3$s%4$s> %5$s</label>',
			esc_attr( self::APPEARANCE_ID ),
			esc_attr( Options::OPTION ),
			$automatic ? ' checked' : '',
			$this->disabled_attr(),
			esc_html__( 'Automatic (black or white, whichever reads better on the accent colour)', 'odavent-events' )
		);
		printf( '<div id="%s_contrast_manual"%s>', esc_attr( self::APPEARANCE_ID ), $automatic ? ' hidden' : '' );
		$this->color_input( 'contrast' );
		echo '</div>';
	}

	public function render_text_color(): void {
		$this->color_input( 'text' );
		$this->description( __( 'Empty uses your theme\'s text colour.', 'odavent-events' ) );
	}

	public function render_border(): void {
		$this->color_input( 'border' );
		$this->description( __( 'Card and divider lines. Empty keeps the default light grey.', 'odavent-events' ) );
	}

	public function render_radius(): void {
		$radius = $this->options->appearance()['radius'];

		printf(
			'<input type="number" min="%1$d" max="%2$d" step="1" class="small-text" id="%3$s_radius" name="%4$s[appearance][radius]" value="%5$s"%6$s> ',
			(int) Appearance::MIN_RADIUS,
			(int) Appearance::MAX_RADIUS,
			esc_attr( self::APPEARANCE_ID ),
			esc_attr( Options::OPTION ),
			'' === $radius ? '' : esc_attr( (string) (int) $radius ),
			$this->disabled_attr()
		);
		echo esc_html__( 'pixels (0 to 24; empty keeps the default)', 'odavent-events' );
	}

	public function render_spacing(): void {
		$this->select(
			'spacing',
			array(
				''            => __( 'Unchanged', 'odavent-events' ),
				'compact'     => __( 'Compact', 'odavent-events' ),
				'comfortable' => __( 'Comfortable', 'odavent-events' ),
				'roomy'       => __( 'Roomy', 'odavent-events' ),
			)
		);
	}

	public function render_button_style(): void {
		$this->select(
			'button_style',
			array(
				'filled'  => __( 'Filled', 'odavent-events' ),
				'outline' => __( 'Outline', 'odavent-events' ),
			)
		);
	}

	/**
	 * A button, a ticket card and a speaker card with the plugin's real classes, styled by its stylesheet and
	 * updated by assets/js/admin-appearance.js. Nothing when "Load default styles" is off.
	 */
	public function render_preview(): void {
		if ( ! $this->options->load_css() ) {
			return;
		}

		$appearance = $this->options->appearance();
		$has_ratio  = '' !== $appearance['accent'];
		$ratio      = $has_ratio ? $this->appearance_ratio( $appearance ) : 0.0;
		/* translators: %s: contrast ratio such as 4.50. */
		$label   = __( 'Text on accent contrast: %s:1', 'odavent-events' );
		$decimal = isset( $GLOBALS['wp_locale'] ) && is_object( $GLOBALS['wp_locale'] ) && isset( $GLOBALS['wp_locale']->number_format['decimal_point'] )
			? (string) $GLOBALS['wp_locale']->number_format['decimal_point']
			: '.';
		$gaps    = wp_json_encode( Appearance::GAPS );

		printf(
			'<div class="odv-appearance-preview odv-appearance-preview--%1$s" data-ratio-label="%2$s" data-decimal="%3$s" data-gaps="%4$s">',
			'outline' === $appearance['button_style'] ? 'outline' : 'filled',
			esc_attr( $label ),
			esc_attr( $decimal ),
			esc_attr( is_string( $gaps ) ? $gaps : '{}' )
		);

		echo '<div class="odv-register"><span class="odv-register__button">' . esc_html__( 'Register', 'odavent-events' ) . '</span>';
		echo '<p class="odv-register__notice">' . esc_html__( 'Registration and payment are handled securely by Odavent.', 'odavent-events' ) . '</p></div>';

		echo '<div class="odv-appearance-preview__cards">';
		echo '<div class="odv-tickets"><ul class="odv-tickets__list"><li class="odv-tickets__item">';
		echo '<h3 class="odv-tickets__name">' . esc_html__( 'Standard ticket', 'odavent-events' ) . '</h3>';
		echo '<p class="odv-tickets__price">25 EUR</p>';
		/* translators: %s: number of places left for a ticket. */
		echo '<p class="odv-tickets__status">' . esc_html( sprintf( _n( '%s spot left', '%s spots left', 12, 'odavent-events' ), number_format_i18n( 12 ) ) ) . '</p>';
		echo '<span class="odv-tickets__register">' . esc_html__( 'Register', 'odavent-events' ) . '</span>';
		echo '</li></ul></div>';

		echo '<div class="odv-speakers"><ul class="odv-speakers__list odv-speakers__list--single"><li class="odv-speakers__item">';
		echo '<span class="odv-speakers__photo odv-speakers__photo--placeholder" aria-hidden="true">AK</span>';
		// Sample content: a name is not translated.
		echo '<h3 class="odv-speakers__name">Ama Kouassi</h3>';
		echo '<p class="odv-speakers__role">' . esc_html__( 'Head of Product', 'odavent-events' ) . '</p>';
		echo '</li></ul></div>';
		echo '</div>';

		printf(
			'<p class="odv-appearance-preview__ratio"%s>%s</p>',
			$has_ratio ? '' : ' hidden',
			esc_html( $has_ratio ? sprintf( $label, number_format_i18n( $ratio, 2 ) ) : '' )
		);
		printf(
			'<p class="odv-appearance-preview__warning"%s>%s</p>',
			$has_ratio && $ratio < self::MIN_CONTRAST ? '' : ' hidden',
			esc_html__( 'Below 4.5:1: button text may be hard to read.', 'odavent-events' )
		);
		echo '</div>';
	}

	/**
	 * A colour field for the WordPress colour picker (a plain text input without it).
	 */
	private function color_input( string $field ): void {
		printf(
			'<input type="text" class="odv-color-field" id="%1$s_%2$s" name="%3$s[appearance][%2$s]" value="%4$s" maxlength="7" placeholder="#rrggbb" autocomplete="off" spellcheck="false"%5$s>',
			esc_attr( self::APPEARANCE_ID ),
			esc_attr( $field ),
			esc_attr( Options::OPTION ),
			esc_attr( (string) $this->options->appearance()[ $field ] ),
			$this->disabled_attr()
		);
	}

	/**
	 * @param string   $field   Appearance key.
	 * @param string[] $choices Value => label.
	 */
	private function select( string $field, array $choices ): void {
		$current = (string) $this->options->appearance()[ $field ];

		printf(
			'<select id="%1$s_%2$s" name="%3$s[appearance][%2$s]"%4$s>',
			esc_attr( self::APPEARANCE_ID ),
			esc_attr( $field ),
			esc_attr( Options::OPTION ),
			$this->disabled_attr()
		);
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				(string) $value === $current ? ' selected' : '',
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	private function description( string $text ): void {
		if ( $this->options->load_css() ) {
			echo '<p class="description">' . esc_html( $text ) . '</p>';
		}
	}

	/**
	 * " disabled" while "Load default styles" is off. Disabled controls are not submitted, so saving keeps the
	 * stored Appearance (Options::sanitize() keeps what is stored when the key is absent).
	 */
	private function disabled_attr(): string {
		return $this->options->load_css() ? '' : ' disabled';
	}

	/**
	 * The key's events for the default-event select, fetched at most once per render. Null when
	 * there is no usable key (no call is made) so the page never waits on Odavent for nothing.
	 */
	private function events_result(): ?Result {
		// Nothing to ask with, or Odavent is known to be failing right now: do not wait on it for a dropdown.
		if ( ! $this->options->has_key() || $this->options->is_connection_disabled() || $this->repo->is_backing_off() ) {
			return null;
		}

		if ( null === $this->events ) {
			$this->events = $this->client->list_events( self::LIST_TIMEOUT );
		}

		return $this->events;
	}
}
