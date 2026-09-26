<?php
namespace GTPerformance\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AtomicPublicationTest extends TestCase {
	/** @dataProvider failures */
	public function test_failures_preserve_live_php_and_clean_up(string $target,string $mode): void {
		$report=$this->scenario($target,$mode);
		self::assertFalse($report['success']);
		self::assertTrue($report['preserved']);
		self::assertSame([],$report['left']);
		if($mode==='permissions-fail') self::assertNull($report['write']);
	}
	public static function failures(): array {
		$cases=[];
		foreach(['config','page','redis'] as $target) foreach(['permissions-fail','short-write','flush-fail','close-fail','rename-fail'] as $mode) $cases[$target.' '.$mode]=[$target,$mode];
		return $cases;
	}
	public function test_temporary_config_is_private_php_and_does_not_output_credentials(): void {
		$report=$this->scenario('config','success');
		self::assertTrue($report['success']);
		self::assertSame(['suffix'=>'php','permissions'=>0600,'empty'=>true],$report['write']);
		self::assertSame('',$report['temporary_output']);
		self::assertSame([],$report['left']);
	}
	private function scenario(string $target,string $mode): array {
		$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Fixtures/atomic-publication.php').' '.escapeshellarg($mode).' '.escapeshellarg($target);
		return json_decode((string)shell_exec($cmd),true,512,JSON_THROW_ON_ERROR);
	}
}
