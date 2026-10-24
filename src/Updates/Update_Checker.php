<?php
namespace Odavent\Events\Updates;

use Odavent\Events\Api\Client;

/**
 * Self-hosted update checker: offers new releases of this plugin through the normal WordPress
 * update screen, from a pinned package source.
 *
 * This class makes WordPress download and INSTALL a zip as plugin code, so it does not trust the
 * Odavent endpoint blindly. A release is accepted only when every field passes validation, and the
 * package URL must start with PACKAGE_PREFIX (the project's GitHub release downloads). The only
 * way to move that prefix is the `odavent_events_update_package_prefix` filter, which itself must
 * return a well-formed https:// prefix or the constant is used. A release that fails validation is
 * treated exactly like a failed fetch: cached as a failure for an hour and never offered.
 *
 * What is NOT verified: the zip's content (no checksum or signature is published yet). The package
 * path is tied to the claimed version (.../v{version}/{file}.zip), so the endpoint cannot label an
 * older release's zip as a newer version; it can still choose which file of that release is served.
 *
 * Version policy: only a strictly greater version is offered (never a downgrade). A pre-release
 * ("1.2.0-beta") is offered only to a site that already runs a pre-release, so a stable site never
 * lands on a beta; a stable release is offered to everyone.
 *
 * Updates are best effort: no method throws and a failure is silent, cached for an hour.
 */
class Update_Checker {

	/** Where release packages must come from (overridable only through the prefix filter). */
	const PACKAGE_PREFIX = 'https://github.com/mdjaman/odavent-wordpress/releases/download/';

	/** Repository page; also the plugin's `Update URI` header value and the update entry id. */
	const REPO_URL   = 'https://github.com/mdjaman/odavent-wordpress';
	const UPDATE_URI = self::REPO_URL;

	const VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$/D';
	const SLUG       = 'odavent-events';
	const PLUGIN     = 'odavent-events/odavent-events.php';
	const TRANSIENT  = 'odavent_events_update';

	const SUCCESS_TTL = 43200; // 12 hours.
	const FAILURE_TTL = 3600;  // 1 hour.

	const MAX_URL_LENGTH     = 2048;
	const MAX_VERSION_LENGTH = 64;

	/** @var Client */
	private $client;

	/** @var string */
	private $installed;

	/** @var bool Whether this request already went to the endpoint (a forced check refreshes once). */
	private $refreshed = false;

	/**
	 * @param Client      $client    Odavent API client.
	 * @param string|null $installed Installed version; defaults to the plugin header version.
	 */
	public function __construct( Client $client, ?string $installed = null ) {
		$this->client    = $client;
		$this->installed = null !== $installed ? $installed : ( defined( 'ODAVENT_EVENTS_VERSION' ) ? ODAVENT_EVENTS_VERSION : '0' );
	}

	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
	}

	/**
	 * The validated latest release, from cache when possible; null when there is none.
	 *
	 * Cached 12 h in a SITE transient (the release is the same for a whole multisite network, so it
	 * is fetched once per network, not once per subsite); a failure (unreachable endpoint or a release that fails validation) is cached 1 h.
	 * A failure is stored as array( 'failed' => true ): a literal `false` cannot be told apart from
	 * "nothing cached" by get_transient().
	 *
	 * @return array{version: string, download_url: string, requires: ?string, requires_php: ?string, tested: ?string, changelog_url: ?string}|null
	 */
	public function fetch(): ?array {
		$cached = get_site_transient( self::TRANSIENT );

		if ( false !== $cached && ! $this->force_requested() ) {
			if ( is_array( $cached ) && ! empty( $cached['failed'] ) ) {
				return null;
			}

			// Re-validated on read: a value stored under an older prefix rule is not trusted.
			$release = is_array( $cached ) ? $this->validate( $cached ) : null;
			if ( null !== $release ) {
				return $release;
			}
		}

		$this->refreshed = true;
		$result          = $this->client->plugin_update();
		$release         = $result->ok ? $this->validate( $result->data ) : null;

		if ( null === $release ) {
			set_site_transient( self::TRANSIENT, array( 'failed' => true ), self::FAILURE_TTL );

			return null;
		}

		set_site_transient( self::TRANSIENT, $release, self::SUCCESS_TTL );

		return $release;
	}

	/**
	 * Filter for pre_set_site_transient_update_plugins.
	 *
	 * Anything that is not an object (core can pass false) is returned unchanged. Other plugins'
	 * entries are never touched; this plugin's own entry is always rebuilt from the validated
	 * release, so no other source can provide an update for it.
	 *
	 * @param mixed $transient The update_plugins site transient value.
	 * @return mixed
	 */
	public function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release   = $this->fetch();
		$response  = ( isset( $transient->response ) && is_array( $transient->response ) ) ? $transient->response : array();
		$no_update = ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) ? $transient->no_update : array();

		unset( $response[ self::PLUGIN ], $no_update[ self::PLUGIN ] );

		if ( null !== $release ) {
			// The version on disk right now, not the constant of this (possibly stale) process: right
			// after an upgrade core rebuilds this transient in the same request, with a fresh `checked`
			// list, and the just-installed version must not be offered again.
			$installed = $this->live_version( $transient );

			if ( $this->is_offerable( $release, $installed ) ) {
				$response[ self::PLUGIN ] = $this->update_entry( $release );
			} else {
				$no_update[ self::PLUGIN ] = $this->no_update_entry( $release, $installed );
			}
		}

		$transient->response  = $response;
		$transient->no_update = $no_update;

		return $transient;
	}

	/**
	 * Filter for plugins_api: the "View details" popup. Only answers for this plugin.
	 *
	 * @param mixed $result Result so far (false when nothing answered yet).
	 * @param mixed $action API action.
	 * @param mixed $args   Request arguments (an object with the slug).
	 * @return mixed
	 */
	public function plugin_info( $result, $action = '', $args = null ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$release = $this->fetch();
		if ( null === $release ) {
			return $result;
		}

		$info           = new \stdClass();
		$info->name     = 'Odavent Events';
		$info->slug     = self::SLUG;
		$info->version  = $release['version'];
		$info->author   = 'Odavent';
		$info->homepage = self::REPO_URL;
		foreach ( array( 'requires', 'requires_php', 'tested' ) as $field ) {
			if ( null !== $release[ $field ] ) {
				$info->$field = $release[ $field ];
			}
		}

		// No download link for a release that would not be offered as an update (older or equal). The version on
		// disk comes from core's update_plugins transient when it lists one, as in inject(): right after an
		// upgrade this process still holds the old constant, and the just-installed version must not be offered.
		$installed = $this->live_version( get_site_transient( 'update_plugins' ) );
		if ( $this->is_offerable( $release, $installed ) ) {
			$info->download_link = $release['download_url'];
		}

		$info->sections = array(
			'description' => esc_html__( 'Show live speakers, agenda, sponsors and tickets from your Odavent events on your own WordPress site.', 'odavent-events' ),
		);
		if ( null !== $release['changelog_url'] ) {
			$info->sections['changelog'] = '<a href="' . esc_url( $release['changelog_url'] ) . '">' . esc_html__( 'View the changelog', 'odavent-events' ) . '</a>';
		}

		return $info;
	}

	/**
	 * Core's update-core screen sets ?force-check when someone presses "Check again"; honoured only
	 * for users who may update plugins, and only once per request.
	 */
	private function force_requested(): bool {
		return ! $this->refreshed && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * The installed version according to the transient's `checked` list (built from the plugin
	 * header on disk), when it is a well-formed version; otherwise the version this process loaded.
	 *
	 * @param mixed $transient The update_plugins transient (anything that is not an object is ignored).
	 */
	private function live_version( $transient ): string {
		$checked = is_object( $transient ) && isset( $transient->checked ) ? $transient->checked : null;
		$version = is_array( $checked ) && isset( $checked[ self::PLUGIN ] ) ? $checked[ self::PLUGIN ] : null;

		if ( is_string( $version ) && strlen( $version ) <= self::MAX_VERSION_LENGTH && 1 === preg_match( self::VERSION_PATTERN, $version ) ) {
			return $version;
		}

		return $this->installed;
	}

	private function is_offerable( array $release, string $installed ): bool {
		if ( ! version_compare( $release['version'], $installed, '>' ) ) {
			return false;
		}

		return ! $this->is_prerelease( $release['version'] ) || $this->is_prerelease( $installed );
	}

	private function is_prerelease( string $version ): bool {
		return false !== strpos( $version, '-' );
	}

	private function update_entry( array $release ): \stdClass {
		$entry              = $this->base_entry( $release );
		$entry->new_version = $release['version'];
		$entry->package     = $release['download_url'];
		foreach ( array( 'requires', 'requires_php', 'tested' ) as $field ) {
			if ( null !== $release[ $field ] ) {
				$entry->$field = $release[ $field ];
			}
		}

		return $entry;
	}

	private function no_update_entry( array $release, string $installed ): \stdClass {
		$entry              = $this->base_entry( $release );
		$entry->new_version = $installed;
		$entry->package     = '';

		return $entry;
	}

	private function base_entry( array $release ): \stdClass {
		$entry         = new \stdClass();
		$entry->id     = self::UPDATE_URI;
		$entry->slug   = self::SLUG;
		$entry->plugin = self::PLUGIN;
		$entry->url    = null !== $release['changelog_url'] ? $release['changelog_url'] : self::REPO_URL;

		return $entry;
	}

	/**
	 * The release with every field validated, or null when it must not be offered.
	 *
	 * Required: version and a package URL under the pinned prefix. Optional fields that fail
	 * validation are dropped (set to null) without refusing the release.
	 *
	 * @param array $data Raw release fields.
	 * @return array|null
	 */
	private function validate( array $data ): ?array {
		$version = $data['version'] ?? null;
		if ( ! is_string( $version )
			|| strlen( $version ) > self::MAX_VERSION_LENGTH
			|| 1 !== preg_match( self::VERSION_PATTERN, $version ) ) {
			return null;
		}

		$download_url = $data['download_url'] ?? null;
		if ( ! $this->is_trusted_package_url( $download_url, $version ) ) {
			return null;
		}

		return array(
			'version'       => $version,
			'download_url'  => $download_url,
			'requires'      => $this->dotted_version( $data['requires'] ?? null ),
			'requires_php'  => $this->dotted_version( $data['requires_php'] ?? null ),
			'tested'        => $this->dotted_version( $data['tested'] ?? null ),
			'changelog_url' => $this->changelog_url( $data['changelog_url'] ?? null ),
		);
	}

	/**
	 * Exactly PREFIX . ('v'|'V'|'') . VERSION . '/' . FILE.zip. The server derives the version from
	 * the release tag (leading v/V stripped), so tying the path to the claimed version stops a
	 * compromised endpoint from labelling an older release's zip as a newer version. FILE is a plain
	 * name (no further slashes, no ":" or "%"), so there is no whitespace, control character, quote,
	 * backslash, query, fragment, "@" userinfo or dot-dot segment either.
	 *
	 * @param mixed  $url     Candidate package URL.
	 * @param string $version The (validated) version the release claims.
	 */
	private function is_trusted_package_url( $url, string $version ): bool {
		if ( ! is_string( $url ) || strlen( $url ) > self::MAX_URL_LENGTH ) {
			return false;
		}

		$prefix = $this->package_prefix();
		if ( strlen( $url ) <= strlen( $prefix ) || 0 !== strncmp( $url, $prefix, strlen( $prefix ) ) ) {
			return false;
		}

		$rest = substr( $url, strlen( $prefix ) );

		return 1 === preg_match( '#^[vV]?' . preg_quote( $version, '#' ) . '/[A-Za-z0-9._-]+\.zip$#D', $rest )
			&& false === strpos( $rest, '..' );
	}

	/**
	 * The pinned prefix: the constant unless the filter returns a well-formed https:// prefix (host,
	 * optional port, at least one path segment, trailing slash).
	 */
	private function package_prefix(): string {
		$prefix = apply_filters( 'odavent_events_update_package_prefix', self::PACKAGE_PREFIX );

		if ( is_string( $prefix )
			&& strlen( $prefix ) <= self::MAX_URL_LENGTH
			&& false === strpos( $prefix, '..' )
			&& 1 === preg_match( '#^https://[A-Za-z0-9.-]+(:[0-9]+)?/([A-Za-z0-9._~%+-]+/)+$#D', $prefix ) ) {
			return $prefix;
		}

		return self::PACKAGE_PREFIX;
	}

	/**
	 * @param mixed $url Candidate changelog URL.
	 */
	private function changelog_url( $url ): ?string {
		if ( ! is_string( $url )
			|| strlen( $url ) > self::MAX_URL_LENGTH
			|| 0 !== strncmp( $url, 'https://github.com/', 19 )
			|| 1 !== preg_match( '#^[A-Za-z0-9._~:/%+?=&,@\#-]+$#D', $url )
			|| false !== strpos( $url, '..' ) ) {
			return null;
		}

		return $url;
	}

	/**
	 * @param mixed $value Candidate "6.1" / "7.4" / "6.6.2" style version.
	 */
	private function dotted_version( $value ): ?string {
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]+(\.[0-9]+){0,2}$/D', $value ) ) {
			return $value;
		}

		return null;
	}
}
