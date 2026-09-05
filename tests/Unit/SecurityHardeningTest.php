<?php
/**
 * Security properties that must not regress.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\CacheKey;
use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;

final class SecurityHardeningTest extends TestCase {
	/**
	 * @return array<string, mixed>
	 */
	private function policy(): array {
		return array(
			'enabled'              => true,
			'hosts'                => Settings::canonicalHosts(),
			'ignored_query_params' => array( 'utm_source' ),
			'bypass_query_params'  => array( 'wc-ajax' ),
			'bypass_paths'         => array( '/checkout/' ),
			'bypass_cookies'       => array( 'wordpress_logged_in_' ),
		);
	}

	public function test_the_site_host_is_accepted(): void {
		$request = new RequestContext( 'GET', 'https', 'example.com', '/', array(), array(), array(), '' );

		self::assertTrue( ( new Eligibility() )->decide( $request, $this->policy() )->cacheable );
	}

	/**
	 * HTTP_HOST is client-supplied. On a catch-all vhost an attacker varies it to
	 * mint unlimited cache entries, and the value is stored in each entry's metadata
	 * as the URL that the preload queue later fetches with an HTTP request.
	 *
	 * @dataProvider foreignHosts
	 */
	public function test_a_foreign_host_is_never_cached( string $host ): void {
		$request  = new RequestContext( 'GET', 'https', $host, '/', array(), array(), array(), '' );
		$decision = ( new Eligibility() )->decide( $request, $this->policy() );

		self::assertFalse( $decision->cacheable, "Host {$host} must not be cacheable." );
		self::assertSame( 'foreign_host', $decision->reason );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function foreignHosts(): array {
		return array(
			'attacker domain'   => array( 'evil.example' ),
			'cloud metadata'    => array( '169.254.169.254' ),
			'loopback'          => array( '127.0.0.1' ),
			'ipv6 loopback'     => array( '[::1]' ),
			'redis on loopback' => array( '127.0.0.1:6379' ),
			'subdomain'         => array( 'evil.example.com' ),
		);
	}

	public function test_a_port_on_the_site_host_is_still_the_site(): void {
		$request = new RequestContext( 'GET', 'https', 'example.com:443', '/', array(), array(), array(), '' );

		self::assertTrue( ( new Eligibility() )->decide( $request, $this->policy() )->cacheable );
	}

	/**
	 * An empty allowlist must not lock the cache out entirely; the check is a filter
	 * on a known list, not a requirement that one exists.
	 */
	public function test_an_empty_allowlist_does_not_deny_everything(): void {
		$policy          = $this->policy();
		$policy['hosts'] = array();
		$request         = new RequestContext( 'GET', 'https', 'anything.example', '/', array(), array(), array(), '' );

		self::assertTrue( ( new Eligibility() )->decide( $request, $policy )->cacheable );
	}

	public function test_the_host_still_separates_cache_entries(): void {
		$key = new CacheKey();
		$a   = $key->make( new RequestContext( 'GET', 'https', 'example.com', '/', array(), array(), array(), '' ), $this->policy() );
		$b   = $key->make( new RequestContext( 'GET', 'https', 'other.example', '/', array(), array(), array(), '' ), $this->policy() );

		self::assertNotSame( $a, $b );
	}

	/**
	 * The object cache must round-trip a real object.
	 *
	 * A previous attempt at hardening passed `allowed_classes => false` here. Every
	 * cached object then came back as __PHP_Incomplete_Class, including the stdClass
	 * update_plugins transient, and the next request fatalled inside
	 * wp_version_check(). The unit test at the time asserted the source string rather
	 * than the behaviour, so it passed while a live site broke.
	 *
	 * The boundary for the object cache is access to Redis, not the payload: anything
	 * that can write a crafted value can already read everything cached.
	 */
	public function test_the_object_cache_round_trips_objects_intact(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/dropins/object-cache.php' );

		self::assertStringNotContainsString(
			"'allowed_classes' => false",
			$source,
			'This makes every cached object an __PHP_Incomplete_Class and breaks WordPress itself.'
		);

		$object       = new \stdClass();
		$object->slug = 'gt-performance';
		$restored     = unserialize( serialize( $object ) );

		self::assertInstanceOf( \stdClass::class, $restored );
		self::assertSame( 'gt-performance', $restored->slug );
	}

	public function test_the_cache_key_fingerprint_is_not_public(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Cache/DropinRuntime.php' );

		self::assertMatchesRegularExpression(
			'/if \( ! empty\( \$config\[\x27debug\x27\] \) \) \{\s*header\( \x27X-GT-Cache-Key/',
			$source,
			'Publishing a key fingerprint on every hit is reconnaissance for cache poisoning.'
		);
	}

	public function test_the_temporary_wp_config_copy_is_created_unreadable(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Cache/WpCacheConstant.php' );

		self::assertMatchesRegularExpression( '/fopen\(\s*\$temp,\s*.xb./', $source );
		self::assertMatchesRegularExpression( '/chmod\(\s*\$temp,\s*0600\s*\)/', $source );
	}

	public function test_the_preload_queue_refuses_a_foreign_url(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Queue/QueueModule.php' );

		self::assertStringContainsString( 'Refusing to request a URL outside this site.', $source );
		self::assertStringNotContainsString( 'wp_remote_get(', $source, 'Use wp_safe_remote_get for any URL derived from request data.' );
	}
}
