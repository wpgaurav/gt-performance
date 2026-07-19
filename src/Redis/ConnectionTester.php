<?php
/**
 * Bounded Redis connection test.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Redis;

use GTPerformance\Core\Settings;

final class ConnectionTester {
	public function test(): bool|\WP_Error {
		if ( ! class_exists( '\\Redis' ) ) {
			return new \WP_Error( 'gtp_redis_extension', __( 'The PHP Redis extension is not installed.', 'gt-performance' ) );
		}

		$configuration = new Configuration();
		$config        = $configuration->runtime( (array) Settings::get( 'redis', array() ) );
		$config        = $configuration->constantOverrides( $config );
		if ( ! (bool) $config['enabled'] ) {
			return new \WP_Error( 'gtp_redis_disabled', __( 'Enable Redis object caching and save the settings first.', 'gt-performance' ) );
		}

		try {
			$redis = $this->connect( $config );
			if ( ! $redis->ping() ) {
				return new \WP_Error( 'gtp_redis_ping', __( 'Redis accepted the connection but did not answer the health check.', 'gt-performance' ) );
			}
			$redis->close();
		} catch ( \Throwable ) {
			return new \WP_Error( 'gtp_redis_connect', __( 'Redis could not be reached with the saved credentials.', 'gt-performance' ) );
		}

		return true;
	}

	/**
	 * @param array<string, bool|float|int|string> $config Redis configuration.
	 */
	private function connect( array $config ): \Redis {
		$redis      = new \Redis();
		$host       = (string) $config['host'];
		$host       = (bool) $config['tls'] && ! str_contains( $host, '://' ) ? 'tls://' . $host : $host;
		$port       = (int) $config['port'];
		$timeout    = (float) $config['connection_timeout'];
		$persistent = (bool) $config['persistent'];
		$connected  = $persistent
			? $redis->pconnect( $host, $port, $timeout, 'gt-performance' )
			: $redis->connect( $host, $port, $timeout );

		if ( ! $connected ) {
			throw new \RuntimeException( 'Redis connection failed.' );
		}

		$redis->setOption( \Redis::OPT_READ_TIMEOUT, (float) $config['read_timeout'] );
		$username = (string) $config['username'];
		$password = (string) $config['password'];
		if ( '' !== $password || '' !== $username ) {
			$authenticated = '' !== $username
				? $redis->auth( array( $username, $password ) )
				: $redis->auth( $password );
			if ( ! $authenticated ) {
				throw new \RuntimeException( 'Redis authentication failed.' );
			}
		}
		if ( ! $redis->select( (int) $config['database'] ) ) {
			throw new \RuntimeException( 'Redis database selection failed.' );
		}

		return $redis;
	}
}
