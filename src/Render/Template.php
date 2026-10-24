<?php
namespace Odavent\Events\Render;

/**
 * Renders a template file with a set of variables and returns the output.
 *
 * Lookup order: {child theme}/odavent/{name}.php, {parent theme}/odavent/{name}.php, then the plugin's
 * templates/{name}.php. Names are plain slugs: a name can never reach outside those folders.
 */
final class Template {

	/** @var string|null Tests only. */
	private static $plugin_dir = null;

	/**
	 * @internal Tests only: where the built-in templates are looked up.
	 *
	 * @param string|null $dir Directory holding {name}.php, or null for the plugin's templates/.
	 */
	public static function override_plugin_dir( ?string $dir ): void {
		self::$plugin_dir = $dir;
	}

	/**
	 * Filters: `odavent_events_template` (string $path, string $name) may swap the file and
	 * `odavent_events_view_data` (array $vars, string $name) the variables.
	 *
	 * The path from `odavent_events_template` is trusted developer input: never pass a request-derived
	 * value (a `phar://` path would be touched by is_file() and a path outside the theme would be included).
	 *
	 * @param string $name Template name: lowercase letters, digits and dashes.
	 * @param array  $vars Variables made available to the template.
	 * @return string The output, or '' when the name is invalid or no template exists.
	 */
	public static function render( string $name, array $vars = array() ): string {
		if ( ! preg_match( '/^[a-z0-9-]+\z/', $name ) ) {
			return '';
		}

		$path = apply_filters( 'odavent_events_template', self::locate( $name ), $name );
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$filtered = apply_filters( 'odavent_events_view_data', $vars, $name );
		if ( is_array( $filtered ) ) {
			$vars = $filtered;
		}

		return self::run( $path, self::usable_vars( $vars ) );
	}

	/**
	 * The first existing template file for a name, or ''.
	 *
	 * @param string $name Validated template name.
	 */
	private static function locate( string $name ): string {
		$file       = $name . '.php';
		$candidates = array(
			rtrim( (string) get_stylesheet_directory(), '/\\' ) . '/odavent/' . $file,
			rtrim( (string) get_template_directory(), '/\\' ) . '/odavent/' . $file,
			( null !== self::$plugin_dir ? rtrim( self::$plugin_dir, '/\\' ) : rtrim( dirname( __DIR__, 2 ), '/\\' ) . '/templates' ) . '/' . $file,
		);

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Keeps variables that can be extracted safely: valid names, not `this`, not starting with "__".
	 *
	 * @param array $vars Candidate variables.
	 */
	private static function usable_vars( array $vars ): array {
		$usable = array();
		foreach ( $vars as $key => $value ) {
			if ( is_string( $key ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_]*\z/', $key ) && 'this' !== $key && 0 !== strpos( $key, '__' ) ) {
				$usable[ $key ] = $value;
			}
		}

		return $usable;
	}

	/**
	 * Includes the file inside a static closure: no $this, and the only variables are the template's own.
	 *
	 * @param string $file Template file.
	 * @param array  $vars Variables for the template.
	 */
	private static function run( string $file, array $vars ): string {
		$render = static function () use ( $file, $vars ) {
			$__odv_file = $file;
			$__odv_vars = $vars;
			unset( $file, $vars );

			// EXTR_SKIP: a variable can never replace the two locals above (names starting "__" are filtered out too).
			extract( $__odv_vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
			unset( $__odv_vars );

			include $__odv_file;
		};

		ob_start();
		try {
			$render();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}

		return (string) ob_get_clean();
	}
}
