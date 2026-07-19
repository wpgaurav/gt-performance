<?php
/**
 * Diagnostic request context tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Cache\RequestContext;
use PHPUnit\Framework\TestCase;

final class RequestContextTest extends TestCase {
	public function testFromUrlNormalizesRequestParts(): void {
		$request = RequestContext::fromUrl( 'https://EXAMPLE.com//shop/item/?utm_source=test&view=grid' );

		self::assertNotNull( $request );
		self::assertSame( 'https', $request->scheme );
		self::assertSame( 'example.com', $request->host );
		self::assertSame( '/shop/item/', $request->path );
		self::assertSame( array( 'utm_source' => 'test', 'view' => 'grid' ), $request->query );
	}
}
