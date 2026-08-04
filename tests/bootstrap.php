<?php
/**
 * PHPUnit bootstrap.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'GTP_VERSION' ) ) {
	define( 'GTP_VERSION', 'test' );
}

if ( ! defined( 'GTP_DIR' ) ) {
	define( 'GTP_DIR', dirname( __DIR__ ) );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/gt-performance-wordpress/' );
}

if ( ! defined( 'MB_IN_BYTES' ) ) {
	define( 'MB_IN_BYTES', 1024 * 1024 );
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
		return $GLOBALS['gtp_test_options'][ $name ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $name, mixed $value, bool $autoload = false ): bool {
		unset( $autoload );
		$GLOBALS['gtp_test_options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$callbacks = $GLOBALS['gtp_test_filters'][ $hook ] ?? array();
		foreach ( $callbacks as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( mixed $key, string $group = '', bool $deprecated = false ): bool {
		unset( $deprecated );
		$GLOBALS['gtp_test_cache_deletions'][] = array( $key, $group );

		return true;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		$sanitized = filter_var( $url, FILTER_SANITIZE_URL );

		return is_string( $sanitized ) ? $sanitized : '';
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
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
		$GLOBALS['gtp_test_http_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);

		return $GLOBALS['gtp_test_http_response'] ?? array(
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
		$GLOBALS['gtp_test_http_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);

		return $GLOBALS['gtp_test_http_response'] ?? array(
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
	$gtp_test_content = sys_get_temp_dir() . '/gt-performance-tests-' . getmypid();
	if ( ! is_dir( $gtp_test_content ) ) {
		mkdir( $gtp_test_content, 0o777, true );
	}
	define( 'WP_CONTENT_DIR', $gtp_test_content );
}

if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'gt-performance-test-auth-key' );
}
if ( ! defined( 'SECURE_AUTH_SALT' ) ) {
	define( 'SECURE_AUTH_SALT', 'gt-performance-test-secure-auth-salt' );
}
