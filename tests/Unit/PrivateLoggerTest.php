<?php
namespace GTPerformance\Tests\Unit;
use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;
final class PrivateLoggerTest extends TestCase {
	protected function setUp(): void { $GLOBALS['gtperf_test_options']=[]; }
	public function test_diagnostics_are_bounded_redacted_database_records(): void {
		$settings=Settings::defaults();$settings['debug']=true;update_option(Settings::OPTION,$settings);
		$logger=new Logger();
		for($i=0;$i<105;++$i) $logger->log('error','Failed https://user:pass@example.org/file?nonce=sensitive',['password'=>'secret','error'=>'token=sensitive','url'=>'https://example.org/a?secret=private']);
		$entries=get_option(Logger::OPTION);self::assertCount(100,$entries);
		$raw=json_encode($entries);self::assertStringNotContainsString('sensitive',$raw);self::assertStringNotContainsString('user:pass',$raw);self::assertStringNotContainsString('private',$raw);
		self::assertSame('[redacted]',$entries[0]['context']['password']);
		self::assertFileDoesNotExist(Paths::logs().'/gt-performance.log');
	}
	public function test_existing_plaintext_and_rotated_logs_are_removed(): void {
		is_dir(Paths::logs())||mkdir(Paths::logs(),0777,true);
		foreach(['gt-performance.log','gt-performance.log.1'] as $name) file_put_contents(Paths::logs().'/'.$name,'private');
		self::assertTrue(Logger::removeLegacyFiles());
		self::assertFileDoesNotExist(Paths::logs().'/gt-performance.log');self::assertFileDoesNotExist(Paths::logs().'/gt-performance.log.1');
	}
	public function test_debug_off_writes_nothing(): void {(new Logger())->log('error','fixture');self::assertFalse(get_option(Logger::OPTION,false));}
}
