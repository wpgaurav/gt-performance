<?php
/**
 * Frontend optimization pipeline.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Logger;
use GTPerformance\Optimization\Css\UnusedCssOptimizer;

final class OptimizationModule implements Module {
	private UnusedCssOptimizer $css;
	private MediaOptimizer $media;
	private JavaScriptOptimizer $javascript;
	private ImageVariantGenerator $images;
	private FontOptimizer $fonts;
	private EmbedOptimizer $embeds;

	public function __construct( Logger $logger ) {
		$this->css        = new UnusedCssOptimizer( $logger );
		$this->media      = new MediaOptimizer();
		$this->javascript = new JavaScriptOptimizer();
		$this->images     = new ImageVariantGenerator( $logger );
		$this->fonts      = new FontOptimizer( $logger );
		$this->embeds     = new EmbedOptimizer();
	}

	public function register(): void {
		add_filter( 'gt_performance_html', array( $this, 'optimize' ), 10 );
		add_filter( 'wp_generate_attachment_metadata', array( $this->images, 'generate' ), 20, 2 );
	}

	public function optimize( string $html ): string {
		$html = $this->css->optimize( $html );
		$html = $this->javascript->optimize( $html );
		$html = $this->media->optimize( $html );
		$html = $this->images->rewriteHtml( $html );
		$html = $this->fonts->optimize( $html );
		$html = $this->embeds->optimize( $html );

		return apply_filters( 'gt_performance_optimized_html', $html );
	}
}
