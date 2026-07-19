<?php
/**
 * Redis runtime configuration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Redis;

use GTPerformance\Core\SecretCipher;

final class Configuration {
	/**
	 * WordPress configuration constant names keyed by runtime setting.
	 *
	 * @return array<string, string>
	 */
	public function constants(): array {
		return array(
			'enabled'            => 'GTP_REDIS_ENABLED',
			'host'               => 'GTP_REDIS_HOST',
			'port'               => 'GTP_REDIS_PORT',
			'database'           => 'GTP_REDIS_DATABASE',
			'username'           => 'GTP_REDIS_USERNAME',
			'password'           => 'GTP_REDIS_PASSWORD',
			'tls'                => 'GTP_REDIS_TLS',
			'persistent'         => 'GTP_REDIS_PERSISTENT',
			'prefix'             => 'GTP_REDIS_PREFIX',
			'connection_timeout' => 'GTP_REDIS_TIMEOUT',
			'read_timeout'       => 'GTP_REDIS_READ_TIMEOUT',
		);
	}

	/**
	 * Till Krüss Redis Object Cache constants supported as compatible fallbacks.
	 *
	 * @return array<string, string>
	 */
	public function compatibleConstants(): array {
		return array(
			'host'               => 'WP_REDIS_HOST',
			'port'               => 'WP_REDIS_PORT',
			'database'           => 'WP_REDIS_DATABASE',
			'password'           => 'WP_REDIS_PASSWORD',
			'prefix'             => 'WP_REDIS_PREFIX',
			'connection_timeout' => 'WP_REDIS_TIMEOUT',
			'read_timeout'       => 'WP_REDIS_READ_TIMEOUT',
		);
	}

	/**
	 * @param array<string, mixed> $settings Saved Redis settings.
	 * @return array<string, bool|float|int|string>
	 */
	public function runtime( array $settings ): array {
		$password = ( new SecretCipher( 'redis' ) )->decrypt(
			(string) ( $settings['password'] ?? '' )
		);

		return array(
			'enabled'            => (bool) ( $settings['enabled'] ?? false ),
			'host'               => (string) ( $settings['host'] ?? '127.0.0.1' ),
			'port'               => (int) ( $settings['port'] ?? 6379 ),
			'database'           => (int) ( $settings['database'] ?? 0 ),
			'username'           => (string) ( $settings['username'] ?? '' ),
			'password'           => $password,
			'tls'                => (bool) ( $settings['tls'] ?? false ),
			'persistent'         => (bool) ( $settings['persistent'] ?? true ),
			'prefix'             => (string) ( $settings['prefix'] ?? '' ),
			'connection_timeout' => (float) ( $settings['connection_timeout'] ?? 0.5 ),
			'read_timeout'       => (float) ( $settings['read_timeout'] ?? 0.5 ),
		);
	}

	/**
	 * Apply compatible and GT Performance constants defined in wp-config.php.
	 *
	 * GTP_REDIS_* constants take precedence over WP_REDIS_* constants, which
	 * take precedence over saved settings.
	 *
	 * @param array<string, bool|float|int|string> $config Redis configuration.
	 * @return array<string, bool|float|int|string>
	 */
	public function constantOverrides( array $config ): array {
		$config           = $this->compatibleOverrides( $config );
		$standardDisabled = defined( 'WP_REDIS_DISABLED' ) && (bool) constant( 'WP_REDIS_DISABLED' );

		foreach ( $this->constants() as $key => $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );
			if ( is_bool( $value ) || is_float( $value ) || is_int( $value ) || is_string( $value ) ) {
				$config[ $key ] = $value;
			}
		}

		if ( ! defined( 'GTP_REDIS_ENABLED' ) ) {
			if ( $standardDisabled ) {
				$config['enabled'] = false;
			} elseif ( defined( 'GTP_REDIS_HOST' ) || defined( 'WP_REDIS_HOST' ) || defined( 'WP_REDIS_PATH' ) ) {
				$config['enabled'] = true;
			}
		}

		return $config;
	}

	/**
	 * @param array<string, bool|float|int|string> $config Redis configuration.
	 * @return array<string, bool|float|int|string>
	 */
	private function compatibleOverrides( array $config ): array {
		foreach ( $this->compatibleConstants() as $key => $constant ) {
			if ( ! defined( $constant ) || 'password' === $key ) {
				continue;
			}

			$value = constant( $constant );
			if ( is_bool( $value ) || is_float( $value ) || is_int( $value ) || is_string( $value ) ) {
				$config[ $key ] = $value;
			}
		}

		if ( defined( 'WP_REDIS_PASSWORD' ) ) {
			$password = constant( 'WP_REDIS_PASSWORD' );
			if ( is_array( $password ) ) {
				$credentials        = array_values( $password );
				$config['username'] = isset( $credentials[0] ) && is_scalar( $credentials[0] ) ? (string) $credentials[0] : '';
				$config['password'] = isset( $credentials[1] ) && is_scalar( $credentials[1] ) ? (string) $credentials[1] : '';
			} elseif ( is_scalar( $password ) ) {
				$config['password'] = (string) $password;
			}
		}

		$scheme = defined( 'WP_REDIS_SCHEME' ) ? strtolower( (string) constant( 'WP_REDIS_SCHEME' ) ) : '';
		if ( defined( 'WP_REDIS_PATH' ) && ( 'unix' === $scheme || ! defined( 'WP_REDIS_HOST' ) ) ) {
			$config['host'] = (string) constant( 'WP_REDIS_PATH' );
			$config['port'] = 0;
		}
		if ( '' !== $scheme ) {
			$config['tls'] = in_array( $scheme, array( 'tls', 'rediss', 'ssl' ), true );
		}

		if ( ! defined( 'WP_REDIS_PREFIX' ) && defined( 'WP_CACHE_KEY_SALT' ) ) {
			$config['prefix'] = (string) constant( 'WP_CACHE_KEY_SALT' );
		}

		return $config;
	}
}
