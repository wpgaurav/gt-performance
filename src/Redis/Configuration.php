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
	 * Apply scalar constants defined in wp-config.php.
	 *
	 * GTP_REDIS_HOST alone is enough to opt in, unless GTP_REDIS_ENABLED is
	 * explicitly set.
	 *
	 * @param array<string, bool|float|int|string> $config Redis configuration.
	 * @return array<string, bool|float|int|string>
	 */
	public function constantOverrides( array $config ): array {
		foreach ( $this->constants() as $key => $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );
			if ( is_bool( $value ) || is_float( $value ) || is_int( $value ) || is_string( $value ) ) {
				$config[ $key ] = $value;
			}
		}

		if ( defined( 'GTP_REDIS_HOST' ) && ! defined( 'GTP_REDIS_ENABLED' ) ) {
			$config['enabled'] = true;
		}

		return $config;
	}
}
