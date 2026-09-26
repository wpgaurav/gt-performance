<?php
/** Downloaded bytes must never choose an executable output extension. */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Logger;
use GTPerformance\Core\Paths;
use GTPerformance\Optimization\FontOptimizer;
use PHPUnit\Framework\TestCase;

final class FontArtifactTest extends TestCase {
	/** @dataProvider fontPayloads */
	public function test_only_font_headers_can_be_written_and_url_extensions_are_ignored( string $body, ?string $extension ): void {
		$url = 'https://fonts.googleapis.com/css2?family=Review' . uniqid();
		$fontUrl = 'https://fonts.gstatic.com/review/font.php';
		$css = '@font-face{font-family:Review;src:url(' . $fontUrl . ')}';
		$GLOBALS['gtperf_test_http_responses'] = array(
			$url => array( 'response' => array( 'code' => 200 ), 'body' => $css ),
			$fontUrl => array( 'response' => array( 'code' => 200 ), 'body' => $body ),
		);
		$directory = Paths::assets() . '/fonts';
		$cssFile = $directory . '/' . hash( 'sha256', $url ) . '.css';
		$fontFile = $directory . '/' . hash( 'sha256', $body ) . '.' . ( $extension ?? 'php' );
		try {
			( new FontOptimizer( new Logger() ) )->localizeQueued( array( 'url' => $url ) );
			$stored = file_get_contents( $cssFile );
			self::assertFileDoesNotExist( $directory . '/' . hash( 'sha256', $body ) . '.php' );
			if ( null === $extension ) {
				self::assertStringContainsString( $fontUrl, $stored );
			} else {
				self::assertSame( $body, file_get_contents( $fontFile ) );
				self::assertStringContainsString( basename( $fontFile ), $stored );
				self::assertStringNotContainsString( $fontUrl, $stored );
			}
		} finally {
			unset( $GLOBALS['gtperf_test_http_responses'] );
			is_file( $cssFile ) && unlink( $cssFile );
			is_file( $fontFile ) && unlink( $fontFile );
		}
	}

	/** @return array<string, array{string, ?string}> */
	public static function fontPayloads(): array {
		return array(
			'woff2' => array( 'wOF2' . str_repeat( "\0", 44 ), 'woff2' ),
			'woff' => array( 'wOFF' . str_repeat( "\0", 40 ), 'woff' ),
			'opentype' => array( 'OTTO' . str_repeat( "\0", 20 ), 'otf' ),
			'truetype' => array( "\0\1\0\0" . str_repeat( "\0", 20 ), 'ttf' ),
			'php' => array( '<?php echo "Do not write me";', null ),
			'javascript' => array( 'window.bad = function() {};', null ),
			'html' => array( '<html><body>upstream error</body></html>', null ),
			'truncated' => array( 'wOF2', null ),
		);
	}
}
