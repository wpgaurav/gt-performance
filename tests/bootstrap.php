<?php
/**
 * PHPUnit bootstrap.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'GTPERF_VERSION' ) ) {
	define( 'GTPERF_VERSION', 'test' );
}

if ( ! defined( 'GTPERF_DIR' ) ) {
	define( 'GTPERF_DIR', dirname( __DIR__ ) );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/gt-performance-wordpress/' );
}

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'GTPERF_BASENAME' ) ) {
	define( 'GTPERF_BASENAME', 'gt-performance/gt-performance.php' );
}

if ( ! function_exists( 'gtperf_test_site_transients' ) ) {
	/**
	 * Shared store behind the site-transient stubs.
	 *
	 * @return array<string, mixed>
	 */
	function &gtperf_test_site_transients(): array {
		static $store = array();

		return $store;
	}

	function get_site_transient( string $transient ): mixed {
		$store = &gtperf_test_site_transients();

		return $store[ $transient ] ?? false;
	}

	function set_site_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
		unset( $expiration );
		$store               = &gtperf_test_site_transients();
		$store[ $transient ] = $value;

		return true;
	}

	function delete_site_transient( string $transient ): bool {
		$store = &gtperf_test_site_transients();
		unset( $store[ $transient ] );

		$GLOBALS['gtperf_test_transient_deletions'][] = $transient;

		// Stands in for any listener on the generic deleted_site_transient /
		// deleted_option hooks that refreshes the plugin update cache, which is
		// what re-enters the deletion hook on a live site.
		$listener = $GLOBALS['gtperf_test_deleted_site_transient_listener'] ?? null;
		if ( is_callable( $listener ) ) {
			$listener( $transient );
		}

		return true;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0o777, true );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			wp_rand_int(),
			wp_rand_int(),
			wp_rand_int(),
			wp_rand_int() & 0x0fff | 0x4000,
			wp_rand_int() & 0x3fff | 0x8000,
			wp_rand_int(),
			wp_rand_int(),
			wp_rand_int()
		);
	}

	function wp_rand_int(): int {
		return random_int( 0, 0xffff );
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private readonly string $code = '',
			private readonly string $message = '',
		) {
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	class WP_CLI {
		/** @var list<string> */
		public static array $successes = array();

		/** @var list<string> */
		public static array $lines = array();

		/** @var list<string> */
		public static array $logs = array();

		public static function error( string $message ): void {
			throw new RuntimeException( $message );
		}

		public static function success( string $message ): void {
			self::$successes[] = $message;
		}

		public static function line( string $message ): void {
			self::$lines[] = $message;
		}

		public static function log( string $message ): void {
			self::$logs[] = $message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['gtperf_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['gtperf_test_is_multisite'] ?? false );
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	function get_site_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['gtperf_test_site_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, mixed $value, bool $autoload = false ): bool {
		unset( $autoload );
		$GLOBALS['gtperf_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$callbacks = $GLOBALS['gtperf_test_filters'][ $hook ] ?? array();
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['gtperf_test_actions'][] = array(
			'hook' => $hook,
			'args' => $args,
		);
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( mixed $key, string $group = '', bool $deprecated = false ): bool {
		unset( $deprecated );
		$GLOBALS['gtperf_test_cache_deletions'][] = array( $key, $group );

		return true;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		$sanitized = filter_var( $url, FILTER_SANITIZE_URL );

		return is_string( $sanitized ) ? $sanitized : '';
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) ?? '';
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? '';
		}

		return trim( $text );
	}
}

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( string $path ): bool {
		return is_writable( $path );
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return 'https://example.com' . $path;
	}
}

if ( ! function_exists( 'content_url' ) ) {
	function content_url( string $path = '' ): string {
		return 'https://example.com/wp-content/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl(): bool {
		return true;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_safe_remote_get' ) ) {
	/**
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_safe_remote_get( string $url, array $args = array() ): array|WP_Error {
		$GLOBALS['gtperf_test_http_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);

		return $GLOBALS['gtperf_test_http_response'] ?? array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'content-type' => 'text/css' ),
			'body'     => '.remote{}',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/** @param array<string, mixed> $response Response. */
	function wp_remote_retrieve_header( array $response, string $header ): string {
		return (string) ( $response['headers'][ strtolower( $header ) ] ?? '' );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	/**
	 * @param array<string, mixed> $args Request arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	function wp_remote_request( string $url, array $args ): array|WP_Error {
		$GLOBALS['gtperf_test_http_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);

		return $GLOBALS['gtperf_test_http_response'] ?? array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"success":true,"result":{}}',
		);
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/** @param array<string, mixed> $response Response. */
	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/** @param array<string, mixed> $response Response. */
	function wp_remote_retrieve_body( array $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

// Paths::cacheRoot() derives every cache directory from WP_CONTENT_DIR. Point it
// at a scratch directory so filesystem-backed tests never touch a real site.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	$gtperf_test_content = sys_get_temp_dir() . '/gt-performance-tests-' . getmypid();
	if ( ! is_dir( $gtperf_test_content ) ) {
		mkdir( $gtperf_test_content, 0o777, true );
	}
	define( 'WP_CONTENT_DIR', $gtperf_test_content );
}

if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'gt-performance-test-auth-key' );
}
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
	define( 'SECURE_AUTH_SALT', 'gt-performance-test-secure-auth-salt' );
}
