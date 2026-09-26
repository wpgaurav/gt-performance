<?php
namespace GTPerformance\Tests\Unit;
use PHPUnit\Framework\TestCase;
final class SettingsPublicationTest extends TestCase {
	/** @dataProvider failures */
	public function test_failed_publication_rejects_settings_and_disables_available_runtime(string $failure,string $entry): void {
		$r=$this->scenario($failure,$entry);
		self::assertFalse($r['success']);self::assertTrue($r['database_unchanged']);self::assertTrue($r['error']);
		self::assertSame($failure==='read-only'?true:null,$r['runtime_enabled']);
		self::assertSame($failure==='read-only',$r['redis_present']);
		if($entry==='admin') self::assertTrue($r['admin_error']);
	}
	public static function failures(): array {return [['page','api'],['redis','api'],['read-only','api'],['page','admin'],['redis','admin'],['read-only','admin']];}
	public function test_database_failure_restores_previous_runtime(): void {
		$r=$this->scenario('database','api');self::assertFalse($r['success']);self::assertTrue($r['database_unchanged']);self::assertTrue($r['runtime_enabled']);self::assertTrue($r['error']);
	}
	public function test_an_unchanged_database_result_can_still_publish_runtime(): void {
		$r=$this->scenario('same-db','api');self::assertTrue($r['success']);self::assertFalse($r['runtime_enabled']);self::assertFalse($r['error']);
	}
	private function scenario(string $mode,string $entry): array {
		return json_decode((string)shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/Fixtures/settings-publication.php').' '.escapeshellarg($mode).' '.escapeshellarg($entry)),true,512,JSON_THROW_ON_ERROR);
	}
}
