<?php
namespace GTPerformance\Tests\Unit;
use PHPUnit\Framework\TestCase;
final class RedisBreakerTest extends TestCase {
	public function test_breaker_uses_only_owned_cache_files_and_safe_fallbacks(): void {
		$raw=shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Fixtures/redis-breaker.php'));
		$results=json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
		self::assertCount(6,$results);
		foreach($results as $case=>$passed) self::assertTrue($passed,$case);
	}
}
