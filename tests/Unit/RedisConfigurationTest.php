<?php
/**
 * Redis credential configuration tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\SecretCipher;
use GTPerformance\Redis\Configuration;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class RedisConfigurationTest extends TestCase {
	public function test_runtime_configuration_decrypts_saved_password(): void {
		$password = ( new SecretCipher( 'redis' ) )->encrypt( 'redis-secret' );
		$config   = ( new Configuration() )->runtime(
			array(
				'enabled'            => true,
				'host'               => 'cache.internal',
				'port'               => 6380,
				'database'           => 4,
				'username'           => 'wordpress',
				'password'           => $password,
				'tls'                => true,
				'persistent'         => false,
				'prefix'             => 'gtperf:test:',
				'connection_timeout' => 0.7,
				'read_timeout'       => 0.8,
			)
		);

		self::assertTrue( $config['enabled'] );
		self::assertSame( 'cache.internal', $config['host'] );
		self::assertSame( 'redis-secret', $config['password'] );
		self::assertTrue( $config['tls'] );
		self::assertFalse( $config['persistent'] );
	}

	#[RunInSeparateProcess]
	public function test_wp_config_constants_override_saved_redis_settings(): void {
		define( 'GTPERF_REDIS_HOST', 'redis.internal' );
		define( 'GTPERF_REDIS_DATABASE', 6 );
		define( 'GTPERF_REDIS_USERNAME', 'wordpress' );
		define( 'GTPERF_REDIS_PASSWORD', 'constant-secret' );
		define( 'GTPERF_REDIS_TLS', true );

		$config = ( new Configuration() )->constantOverrides(
			array(
				'enabled'            => false,
				'host'               => '127.0.0.1',
				'port'               => 6379,
				'database'           => 0,
				'username'           => '',
				'password'           => '',
				'tls'                => false,
				'persistent'         => true,
				'prefix'             => '',
				'connection_timeout' => 0.5,
				'read_timeout'       => 0.5,
			)
		);

		self::assertTrue( $config['enabled'] );
		self::assertSame( 'redis.internal', $config['host'] );
		self::assertSame( 6, $config['database'] );
		self::assertSame( 'wordpress', $config['username'] );
		self::assertSame( 'constant-secret', $config['password'] );
		self::assertTrue( $config['tls'] );
	}

	#[RunInSeparateProcess]
	public function test_enabled_constant_can_explicitly_disable_a_configured_host(): void {
		define( 'GTPERF_REDIS_ENABLED', false );
		define( 'GTPERF_REDIS_HOST', 'redis.internal' );

		$config = ( new Configuration() )->constantOverrides(
			( new Configuration() )->runtime(
				array(
					'enabled' => true,
				)
			)
		);

		self::assertFalse( $config['enabled'] );
		self::assertSame( 'redis.internal', $config['host'] );
	}

	#[RunInSeparateProcess]
	public function test_till_kruss_constants_configure_redis_without_duplicate_gtp_constants(): void {
		define( 'WP_REDIS_HOST', 'shared-redis.internal' );
		define( 'WP_REDIS_PORT', 6380 );
		define( 'WP_REDIS_DATABASE', 7 );
		define( 'WP_REDIS_PASSWORD', array( 'cache-user', 'cache-secret' ) );
		define( 'WP_REDIS_PREFIX', 'shared-site:' );
		define( 'WP_REDIS_TIMEOUT', 0.4 );
		define( 'WP_REDIS_READ_TIMEOUT', 0.6 );
		define( 'WP_REDIS_SCHEME', 'tls' );

		$config = ( new Configuration() )->constantOverrides(
			( new Configuration() )->runtime( array() )
		);

		self::assertTrue( $config['enabled'] );
		self::assertSame( 'shared-redis.internal', $config['host'] );
		self::assertSame( 6380, $config['port'] );
		self::assertSame( 7, $config['database'] );
		self::assertSame( 'cache-user', $config['username'] );
		self::assertSame( 'cache-secret', $config['password'] );
		self::assertSame( 'shared-site:', $config['prefix'] );
		self::assertSame( 0.4, $config['connection_timeout'] );
		self::assertSame( 0.6, $config['read_timeout'] );
		self::assertTrue( $config['tls'] );
	}

	#[RunInSeparateProcess]
	public function test_till_kruss_unix_socket_and_emergency_disable_are_honored(): void {
		define( 'WP_REDIS_SCHEME', 'unix' );
		define( 'WP_REDIS_PATH', '/run/redis/redis.sock' );
		define( 'WP_REDIS_DISABLED', true );

		$config = ( new Configuration() )->constantOverrides(
			( new Configuration() )->runtime(
				array(
					'enabled' => true,
					'host'    => 'saved.internal',
					'port'    => 6379,
				)
			)
		);

		self::assertFalse( $config['enabled'] );
		self::assertSame( '/run/redis/redis.sock', $config['host'] );
		self::assertSame( 0, $config['port'] );
		self::assertFalse( $config['tls'] );
	}

	#[RunInSeparateProcess]
	public function test_gtp_constants_take_precedence_over_till_kruss_constants(): void {
		define( 'WP_REDIS_HOST', 'shared-redis.internal' );
		define( 'WP_REDIS_DATABASE', 3 );
		define( 'WP_REDIS_PASSWORD', array( 'shared-user', 'shared-secret' ) );
		define( 'GTPERF_REDIS_HOST', 'gtp-redis.internal' );
		define( 'GTPERF_REDIS_DATABASE', 9 );
		define( 'GTPERF_REDIS_USERNAME', 'gtp-user' );
		define( 'GTPERF_REDIS_PASSWORD', 'gtp-secret' );

		$config = ( new Configuration() )->constantOverrides(
			( new Configuration() )->runtime( array() )
		);

		self::assertSame( 'gtp-redis.internal', $config['host'] );
		self::assertSame( 9, $config['database'] );
		self::assertSame( 'gtp-user', $config['username'] );
		self::assertSame( 'gtp-secret', $config['password'] );
	}
}
