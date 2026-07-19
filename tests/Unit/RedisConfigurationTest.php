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
				'prefix'             => 'gtp:test:',
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
		define( 'GTP_REDIS_HOST', 'redis.internal' );
		define( 'GTP_REDIS_DATABASE', 6 );
		define( 'GTP_REDIS_USERNAME', 'wordpress' );
		define( 'GTP_REDIS_PASSWORD', 'constant-secret' );
		define( 'GTP_REDIS_TLS', true );

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
		define( 'GTP_REDIS_ENABLED', false );
		define( 'GTP_REDIS_HOST', 'redis.internal' );

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
}
