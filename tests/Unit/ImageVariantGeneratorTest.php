<?php
/**
 * Image variant queue tests.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Settings;
use GTPerformance\Optimization\ImageVariantGenerator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImageVariantGeneratorTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['gtperf_test_actions'] = array();
		$GLOBALS['gtperf_test_filters'] = array();
		$GLOBALS['gtperf_test_options'] = array( Settings::OPTION => Settings::defaults() );
	}

	public function testUploadMetadataReturnsImmediatelyAndQueuesAttachment(): void {
		$settings                              = Settings::defaults();
		$settings['media']['optimize_uploads'] = true;
		$GLOBALS['gtperf_test_options']           = array( Settings::OPTION => $settings );
		$metadata                              = array(
			'file'  => '2026/08/example.png',
			'width' => 1254,
		);

		$result = ( new ImageVariantGenerator( new Logger() ) )->enqueue( $metadata, 42 );

		self::assertSame( $metadata, $result );
		self::assertSame(
			array(
				array(
					'hook' => ImageVariantGenerator::ENQUEUE_HOOK,
					'args' => array( 42, 'full' ),
				),
			),
			$GLOBALS['gtperf_test_actions']
		);
	}

	public function testUploadQueuesOneJobPerUniqueImageFile(): void {
		$settings                              = Settings::defaults();
		$settings['media']['optimize_uploads'] = true;
		$GLOBALS['gtperf_test_options']           = array( Settings::OPTION => $settings );
		$metadata                              = array(
			'file'  => '2026/08/example.png',
			'sizes' => array(
				'medium'       => array( 'file' => 'example-800x600.png' ),
				'medium_large' => array( 'file' => 'example-800x600.png' ),
				'large'        => array( 'file' => 'example-1024x768.png' ),
			),
		);

		( new ImageVariantGenerator( new Logger() ) )->enqueue( $metadata, 42 );

		self::assertSame(
			array(
				array(
					'hook' => ImageVariantGenerator::ENQUEUE_HOOK,
					'args' => array( 42, 'full' ),
				),
				array(
					'hook' => ImageVariantGenerator::ENQUEUE_HOOK,
					'args' => array( 42, 'medium' ),
				),
				array(
					'hook' => ImageVariantGenerator::ENQUEUE_HOOK,
					'args' => array( 42, 'large' ),
				),
			),
			$GLOBALS['gtperf_test_actions']
		);
	}

	public function testDisabledUploadOptimizationDoesNotQueueAttachment(): void {
		$metadata = array( 'file' => '2026/08/example.png' );

		$result = ( new ImageVariantGenerator( new Logger() ) )->enqueue( $metadata, 42 );

		self::assertSame( $metadata, $result );
		self::assertSame( array(), $GLOBALS['gtperf_test_actions'] );
	}

	public function testCompatibilityFilterCanYieldVariantGeneration(): void {
		$settings                              = Settings::defaults();
		$settings['media']['optimize_uploads'] = true;
		$GLOBALS['gtperf_test_options']           = array( Settings::OPTION => $settings );
		$GLOBALS['gtperf_test_filters']['gt_performance_generate_image_variants'][] = static fn(): bool => false;
		$metadata = array( 'file' => '2026/08/example.png' );

		$result = ( new ImageVariantGenerator( new Logger() ) )->enqueue( $metadata, 42 );

		self::assertSame( $metadata, $result );
		self::assertSame( array(), $GLOBALS['gtperf_test_actions'] );
	}

	public function testQueuedJobRequiresAnAttachmentId(): void {
		$settings                              = Settings::defaults();
		$settings['media']['optimize_uploads'] = true;
		$GLOBALS['gtperf_test_options']           = array( Settings::OPTION => $settings );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Missing image attachment ID.' );

		( new ImageVariantGenerator( new Logger() ) )->generateQueued( array() );
	}
}
