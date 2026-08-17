<?php
/**
 * Redis object-cache drop-in regression tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ObjectCacheDropinTest extends TestCase {
	public function testSuccessfulSetWritesThroughToTheRequestLocalCache(): void {
		self::assertTrue( $this->scenario( 'write-through' ) );
	}

	public function testRecreatedOptionIsNotMaskedByAStaleNotoptionsEntry(): void {
		self::assertTrue( $this->scenario( 'notoptions' ) );
	}

	public function testForceGetBypassesTheRequestLocalValue(): void {
		self::assertTrue( $this->scenario( 'force' ) );
	}

	public function testAddAndReplaceUseConditionalWritesWithExpiry(): void {
		self::assertTrue( $this->scenario( 'conditional-options' ) );
	}

	public function testCronOptionAdvancesWithinTheSameRequest(): void {
		self::assertTrue( $this->scenario( 'cron-advancement' ) );
	}

	/**
	 * delete_site_transient() fires the generic deleted_site_transient hook only
	 * when the delete reported success. Answering true for a key that was never
	 * stored makes every repeat deletion dispatch that hook again, so a listener
	 * on it never stops being re-triggered.
	 */
	public function testDeleteReportsFalseWhenNothingWasStored(): void {
		self::assertTrue( $this->scenario( 'delete-reports-absence' ) );
	}

	public function testTwoProcessesAttemptingAddProduceExactlyOneWinner(): void {
		$store = tempnam( sys_get_temp_dir(), 'gtp-object-cache-race-' );
		self::assertIsString( $store );
		file_put_contents( $store, '{"reads":0,"values":{}}' );

		$fixture = dirname( __DIR__ ) . '/Fixtures/object-cache-scenario.php';
		$dropin  = dirname( __DIR__, 2 ) . '/dropins/object-cache.php';
		// Disable loaded extensions so the fixture can provide its deterministic
		// Redis test double even on CI workers with PhpRedis installed.
		$command = array( PHP_BINARY, '-n', $fixture, 'add-worker', $dropin, $store );
		$spec    = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$first  = proc_open( $command, $spec, $firstPipes );
		$second = proc_open( $command, $spec, $secondPipes );
		self::assertIsResource( $first );
		self::assertIsResource( $second );

		fclose( $firstPipes[0] );
		fclose( $secondPipes[0] );
		$outputs = array(
			trim( (string) stream_get_contents( $firstPipes[1] ) ),
			trim( (string) stream_get_contents( $secondPipes[1] ) ),
		);
		$errors = array(
			trim( (string) stream_get_contents( $firstPipes[2] ) ),
			trim( (string) stream_get_contents( $secondPipes[2] ) ),
		);
		foreach ( array( $firstPipes, $secondPipes ) as $pipes ) {
			fclose( $pipes[1] );
			fclose( $pipes[2] );
		}
		$statuses = array( proc_close( $first ), proc_close( $second ) );
		@unlink( $store );

		self::assertSame( array( 0, 0 ), $statuses, implode( "\n", $errors ) );
		sort( $outputs );
		self::assertSame( array( '0', '1' ), $outputs );
	}

	private function scenario( string $scenario ): bool {
		$fixture = dirname( __DIR__ ) . '/Fixtures/object-cache-scenario.php';
		$dropin  = dirname( __DIR__, 2 ) . '/dropins/object-cache.php';
		$command = sprintf(
			'%s -n %s %s %s',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg( $fixture ),
			escapeshellarg( $scenario ),
			escapeshellarg( $dropin )
		);
		exec( $command, $output, $status );
		$result = json_decode( implode( "\n", $output ), true );

		return 0 === $status && is_array( $result ) && true === ( $result['ok'] ?? false );
	}
}
