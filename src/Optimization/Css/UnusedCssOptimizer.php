<?php
/**
 * Server-side unused CSS optimizer and delivery modes.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization\Css;

use GTPerformance\Core\Logger;
use GTPerformance\Cache\RequestContext;
use GTPerformance\Core\Settings;

final class UnusedCssOptimizer {
	public function __construct(
		private readonly Logger $logger,
		private readonly StylesheetCollector $collector = new StylesheetCollector(),
		private readonly CssPruner $pruner = new CssPruner(),
		private readonly ArtifactStore $artifacts = new ArtifactStore(),
		private readonly ReportRepository $reports = new ReportRepository(),
	) {
	}

	/**
	 * Whether the unused-CSS engine may run.
	 *
	 * Off by default: it rewrites the stylesheets of a live site, which deserves a
	 * deliberate decision rather than a default. GTPERF_UNUSED_CSS force-enables it
	 * for a staging environment where changing the option is inconvenient.
	 */
	public static function available(): bool {
		if ( defined( 'GTPERF_UNUSED_CSS' ) && GTPERF_UNUSED_CSS ) {
			return true;
		}

		return (bool) Settings::get( 'css.enabled', false );
	}

	/**
	 * Milliseconds the background generator may spend on one page.
	 *
	 * Only the generator's loopback request reaches the pruner, so this bounds a
	 * queue worker rather than a visitor. It exists so one pathological stylesheet
	 * cannot occupy the queue indefinitely.
	 */
	private const TIME_BUDGET_MS = 20000;

	/** Transient prefix for reusable generated markup. */
	private const REUSE_PREFIX = 'gtperf_css_reuse_';

	public const JOB_TYPE = 'generate_css';
	public const JOB_HOOK = 'gt_performance_job_generate_css';

	/** Query parameter marking the generator's own loopback request. */
	public const GENERATOR_PARAM = 'gtperf_css_build';

	public function optimize( string $html ): string {
		if ( ! self::available() ) {
			return $html;
		}

		$mode        = (string) Settings::get( 'css.mode', 'file' );
		$url         = $this->requestUrl();
		$preview     = isset( $_GET['gtperf_css_preview'] )
			&& current_user_can( 'manage_options' )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['gtperf_css_preview'] ) ), 'gtperf_css_preview' );
		$rollout     = (int) Settings::get( 'css.rollout_percent', 100 );
		if ( ! ( new Rollout() )->allows( $url, $rollout, (bool) $preview ) ) {
			return $html;
		}
		// Pruning is CPU-bound and measured multiple seconds on a real page, so it
		// never happens in a visitor's request. Either an artifact for this page shape
		// already exists and is applied, or one is queued and this response goes out
		// unchanged. The generator's own loopback request is the only caller that
		// actually builds one.
		$reuseKey = $this->reuseKey( $html, $mode );
		$reused   = self::isGeneratorRequest() ? null : $this->reusableOutput( $html, $reuseKey );
		if ( null !== $reused ) {
			return $reused;
		}

		if ( ! self::isGeneratorRequest() ) {
			$this->requestGeneration( $url );

			return $html;
		}

		$fingerprint = $this->reports->begin( $url, $mode );
		$started     = microtime( true );
		$previous = libxml_use_internal_errors( true );

		try {
			// Stamp every candidate in the HTML string first, then parse a document from
			// the stamped markup. The document is only ever read: matching CSS selectors
			// needs a real DOM, but serialising one back out lowercases inline-SVG
			// camelCase and entity-encodes all non-ASCII. Replacement happens on the
			// string, keyed by the stamps.
			$marked   = $this->markCandidates( $html );
			$document = $this->readOnlyDocument( $marked );
			if ( null === $document ) {
				throw new \RuntimeException( 'The HTML document could not be parsed.' );
			}

			$stylesheetExclusions = array_map(
				'strval',
				(array) Settings::get( 'css.excluded_stylesheets', array() )
			);
			$stylesheetExclusions = array_values(
				array_filter(
					array_map(
						'strval',
						(array) apply_filters(
							'gt_performance_css_stylesheet_exclusions',
							$stylesheetExclusions,
							$url
						)
					)
				)
			);
			$collected             = $this->collector->collect( $document, $stylesheetExclusions );
			if ( ! $collected['stylesheets'] ) {
				$this->reports->complete(
					$fingerprint,
					$mode,
					'skipped',
					'',
					array(
						'url'         => $url,
						'stylesheets' => 0,
						'reason'      => 'No eligible stylesheets were found.',
						'duration_ms' => $this->duration( $started ),
					)
				);
				return $html;
			}

			$stylesheets = array();
			foreach ( $collected['stylesheets'] as $stylesheet ) {
				// Only wrap when the media query actually narrows anything. Wrapping
				// `media="all"` in `@media all { … }` is a no-op for the cascade but not
				// for `@import`, which browsers ignore unless it is at the top of the
				// sheet — so every imported stylesheet silently vanished from the page.
				$stylesheets[] = 'all' === strtolower( trim( $stylesheet->media ) )
					? "\n" . $stylesheet->css . "\n"
					: "\n@media " . $stylesheet->media . " {\n" . $this->withoutImports( $stylesheet->css ) . "\n}\n";
			}
			$css = implode( '', $stylesheets );

			$safelist             = array_map( 'strval', (array) Settings::get( 'css.safelist', array() ) );
			$safelist             = apply_filters( 'gt_performance_css_safelist', $safelist, $url );
			$preserveDynamicStates = (bool) Settings::get( 'css.keep_dynamic_states', true );
			$used                 = $this->pruner->pruneMany( $stylesheets, $document, 'used', $safelist, $preserveDynamicStates );
			if ( '' === trim( $used ) ) {
				throw new \RuntimeException( 'The used CSS result was empty.' );
			}

			if ( $this->duration( $started ) > self::TIME_BUDGET_MS ) {
				$this->reports->complete(
					$fingerprint,
					$mode,
					'skipped',
					'',
					array(
						'url'         => $url,
						'reason'      => 'Pruning exceeded the request time budget.',
						'duration_ms' => $this->duration( $started ),
					)
				);

				return $html;
			}

			$outputs  = array();
			$injected = '';
			$fallback = '';
			if ( 'inline' === $mode ) {
				[ $markup, $meta ] = $this->inlineTag( $used, 'used' );
				$injected         .= $markup;
				$outputs[]         = $meta;
			} elseif ( 'hybrid' === $mode ) {
				$critical  = $this->pruner->pruneMany( $stylesheets, $document, 'critical', $safelist, $preserveDynamicStates );
				$remaining = $this->pruner->pruneMany( $stylesheets, $document, 'remaining', $safelist, $preserveDynamicStates );
				$budget    = (int) Settings::get( 'css.critical_budget', 14336 );

				if ( strlen( $critical ) > $budget ) {
					[ $markup, $meta ] = $this->fileTag( $used, 'used' );
					$injected         .= $markup;
					$outputs[]         = $meta;
					$fallback          = 'critical_budget_exceeded';
				} else {
					if ( '' !== trim( $critical ) ) {
						[ $markup, $meta ] = $this->inlineTag( $critical, 'critical' );
						$injected         .= $markup;
						$outputs[]         = $meta;
					}
					if ( '' !== trim( $remaining ) ) {
						[ $markup, $meta ] = $this->fileTag( $remaining, 'remaining' );
						$injected         .= $markup;
						$outputs[]         = $meta;
					}
				}
			} else {
				[ $markup, $meta ] = $this->fileTag( $used, 'used' );
				$injected         .= $markup;
				$outputs[]         = $meta;
			}

			$markers = array_map( 'strval', (array) $collected['markers'] );
			$output  = $this->replaceStylesheets( $html, $markers, $injected );
			if ( '' === trim( $output ) ) {
				throw new \RuntimeException( 'The optimized HTML result was empty.' );
			}

			$this->rememberOutput( $reuseKey, $markers, $injected, $outputs );

			$files = array_values(
				array_filter(
					$outputs,
					static fn( array $item ): bool => 'file' === $item['delivery']
				)
			);
			$this->reports->complete(
				$fingerprint,
				$mode,
				'ready',
				isset( $files[0]['path'] ) ? (string) $files[0]['path'] : '',
				array(
					'url'             => $url,
					'reuse_key'       => $reuseKey,
					'stylesheets'     => count( $collected['stylesheets'] ),
					'original_bytes'  => strlen( $css ),
					'generated_bytes' => array_sum( array_column( $outputs, 'bytes' ) ),
					'outputs'         => $outputs,
					'fallback'        => $fallback,
					'duration_ms'     => $this->duration( $started ),
				)
			);

			return $output;
		} catch ( \Throwable $throwable ) {
			$this->reports->fail( $fingerprint, $mode, $url, $throwable->getMessage() );
			$this->logger->log( 'error', 'Unused CSS optimization failed; original HTML returned', array( 'error' => $throwable->getMessage() ) );

			return $html;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
	}

	/**
	 * Inline the generated CSS.
	 *
	 * @return array{0:string,1:array{delivery:string,kind:string,bytes:int}}
	 */
	private function inlineTag( string $css, string $kind ): array {
		return array(
			'<style data-gt-performance="' . esc_attr( $kind ) . '">' . $css . '</style>',
			array(
				'delivery' => 'inline',
				'kind'     => $kind,
				'bytes'    => strlen( $css ),
			),
		);
	}

	/**
	 * Write the generated CSS to a content-hashed file and link it.
	 *
	 * No integrity/crossorigin: the artifact is same-origin, written atomically and
	 * already content-hashed in its filename, so SRI adds nothing. It also forced the
	 * link cross-origin, and a pull zone without Access-Control-Allow-Origin then made
	 * the browser drop the stylesheet and render the page unstyled.
	 *
	 * @return array{0:string,1:array{delivery:string,kind:string,bytes:int,path:string,url:string}}
	 */
	private function fileTag( string $css, string $kind ): array {
		$artifact = $this->artifacts->write( $css, $kind );

		return array(
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- This replaces stylesheets already in the buffered HTML; the enqueue phase is long past.
			'<link rel="stylesheet" href="' . esc_url( (string) $artifact['url'] ) . '" data-gt-performance="' . esc_attr( $kind ) . '">',
			array(
				'delivery' => 'file',
				'kind'     => $kind,
				'bytes'    => strlen( $css ),
				'path'     => (string) $artifact['path'],
				'url'      => (string) $artifact['url'],
			),
		);
	}

	/**
	 * Whether this request is the generator building an artifact.
	 */
	public static function isGeneratorRequest(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence only; the value is a shared secret checked below.
		$token = isset( $_GET[ self::GENERATOR_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::GENERATOR_PARAM ] ) ) : '';

		return '' !== $token && hash_equals( self::generatorToken(), $token );
	}

	/** Remove only the authenticated build parameter; preserve all safety inputs. */
	public static function publicRequest( RequestContext $request ): RequestContext {
		if ( ! self::isGeneratorRequest() ) {
			return $request;
		}
		$query = $request->query;
		unset( $query[ self::GENERATOR_PARAM ] );
		return new RequestContext( $request->method, $request->scheme, $request->host, $request->path, $query, $request->cookies, $request->headers, $request->userAgent );
	}

	/**
	 * A per-site token so only this plugin can ask for the expensive path.
	 */
	private static function generatorToken(): string {
		return substr( hash_hmac( 'sha256', 'gtperf-css-generator', wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Queue a background build for this URL, at most one in flight per page.
	 */
	private function requestGeneration( string $url ): void {
		$lock = 'gtperf_css_build_' . hash( 'sha256', $url );
		if ( false !== get_transient( $lock ) ) {
			return;
		}

		set_transient( $lock, 1, 10 * MINUTE_IN_SECONDS );

		/**
		 * Ask the queue to generate used CSS for a URL.
		 *
		 * @param string $url Page URL.
		 */
		do_action( 'gt_performance_enqueue_css', $url );
	}

	/**
	 * Background worker: render the page once and store its generated CSS.
	 *
	 * @param array<string, mixed> $payload Job payload.
	 */
	public function generateQueued( array $payload ): void {
		$url = (string) ( $payload['url'] ?? '' );
		if ( '' === $url ) {
			return;
		}

		$mode = (string) Settings::get( 'css.mode', 'file' );
		$fingerprint = $this->reports->begin( $url, $mode );
		if ( ! Maintenance::enabled() || ! Maintenance::eligible( $url ) ) {
			$this->reports->complete(
				$fingerprint,
				$mode,
				'skipped',
				'',
				array(
					'url' => $url,
					'reason' => 'Generation is paused or this URL is excluded by the current settings.',
				)
			);
			delete_transient( 'gtperf_css_build_' . hash( 'sha256', $url ) );
			return;
		}
		$response = wp_safe_remote_get(
			add_query_arg( self::GENERATOR_PARAM, self::generatorToken(), $url ),
			array(
				'timeout'     => 30,
				'redirection' => 2,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {

			$error = is_wp_error( $response ) ? $response->get_error_message() : 'CSS generation returned HTTP ' . wp_remote_retrieve_response_code( $response ) . '.';
			$this->reports->fail( hash( 'sha256', $url . '|' . Settings::get( 'css.mode', 'file' ) ), (string) Settings::get( 'css.mode', 'file' ), $url, $error );
			throw new \RuntimeException( 'CSS generation request failed. See the CSS report for details.' );
		}

		$report = $this->reports->find( $url, (string) Settings::get( 'css.mode', 'file' ) );
		if ( null === $report || ! in_array( $report['status'], array( 'ready', 'skipped' ), true ) ) {
			if ( null === $report || 'failed' !== $report['status'] ) {
				$this->reports->fail( hash( 'sha256', $url . '|' . Settings::get( 'css.mode', 'file' ) ), (string) Settings::get( 'css.mode', 'file' ), $url, 'No completed build. Check the page-cache drop-in, exclusions, and loopback access.' );
			}
			throw new \RuntimeException( 'The page returned without a completed CSS report. Check cache setup, exclusions, and loopback requests.' );
		}
		if ( 'ready' === $report['status'] ) {
			( new \GTPerformance\Cache\Purger() )->purgeUrl( $url );
		}
		delete_transient( 'gtperf_css_build_' . hash( 'sha256', $url ) );
	}

	/**
	 * Reuse only the same URL, selector inputs and current CSS revisions.
	 */
	private function reuseKey( string $html, string $mode ): string {
		// Attribute values, IDs and DOM relationships affect selector matching too.
		// Keep reuse scoped to the URL and exact markup to avoid cross-page pruning.
		return hash(
			'sha256',
			$this->requestUrl() . '|' . $mode . '|'
			. (int) Settings::get( 'generation', 1 ) . '|'
			. (string) get_option( 'gtperf_css_revision', 1 ) . '|'
			. (string) get_transient( 'gtperf_css_url_revision_' . hash( 'sha256', $this->requestUrl() ) ) . '|' . $html
		);
	}

	/**
	 * Serve a previously generated artifact for an identical page shape.
	 */
	private function reusableOutput( string $html, string $key ): ?string {
		$cached = get_transient( self::REUSE_PREFIX . $key );
		if ( ! is_array( $cached ) || ! isset( $cached['markup'], $cached['markers'] ) ) {
			return null;
		}

		foreach ( (array) ( $cached['files'] ?? array() ) as $file ) {
			// An artifact reclaimed by garbage collection must not be linked again.
			if ( ! is_file( (string) $file ) ) {
				delete_transient( self::REUSE_PREFIX . $key );

				return null;
			}
		}

		return $this->replaceStylesheets( $html, array_map( 'strval', (array) $cached['markers'] ), (string) $cached['markup'] );
	}

	/**
	 * @param list<string>               $markers Consolidated stylesheet stamps.
	 * @param list<array<string, mixed>> $outputs Generated artifacts.
	 */
	private function rememberOutput( string $key, array $markers, string $markup, array $outputs ): void {
		$files = array();
		foreach ( $outputs as $output ) {
			if ( isset( $output['path'] ) && '' !== (string) $output['path'] ) {
				$files[] = (string) $output['path'];
			}
		}

		set_transient(
			self::REUSE_PREFIX . $key,
			array(
				'markup'  => $markup,
				'markers' => $markers,
				'files'   => $files,
			),
			DAY_IN_SECONDS
		);
	}

	/**
	 * Drop @import rules that are about to be wrapped in a media block.
	 *
	 * They cannot survive there, and leaving them in place produces CSS a browser
	 * treats as invalid from that point on. A narrowed media query is rare enough
	 * that losing an import inside one is better than corrupting the block.
	 */
	private function withoutImports( string $css ): string {
		return (string) preg_replace( '#@import\s+[^;]+;#i', '', $css );
	}

	/**
	 * Stamp every stylesheet candidate so it can be found again in the string.
	 *
	 * WP_HTML_Tag_Processor only ever rewrites the attributes it is asked to, so this
	 * pass leaves the rest of the document byte-identical.
	 */
	private function markCandidates( string $html ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$index     = 0;

		while ( $processor->next_tag() ) {
			$tag = (string) $processor->get_tag();
			if ( 'LINK' !== $tag && 'STYLE' !== $tag ) {
				continue;
			}
			if ( null !== $processor->get_attribute( 'data-gt-performance' ) ) {
				continue;
			}
			if ( 'LINK' === $tag && ! str_contains( strtolower( (string) $processor->get_attribute( 'rel' ) ), 'stylesheet' ) ) {
				continue;
			}

			$processor->set_attribute( StylesheetCollector::MARKER, (string) $index );
			++$index;
		}

		return 0 === $index ? $html : $processor->get_updated_html();
	}

	/**
	 * Parse a document for selector matching only. It is never serialised back out.
	 */
	private function readOnlyDocument( string $html ): ?\DOMDocument {
		$document = new \DOMDocument( '1.0', 'UTF-8' );

		return $document->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		) ? $document : null;
	}

	/**
	 * Remove the stylesheets that were consolidated and insert the generated ones.
	 *
	 * @param list<string> $markers Stamps of the tags that were consolidated.
	 */
	private function replaceStylesheets( string $html, array $markers, string $injected ): string {
		$marked = $this->markCandidates( $html );

		foreach ( $markers as $marker ) {
			$attribute = preg_quote( StylesheetCollector::MARKER . '="' . $marker . '"', '#' );

			// A <style> element carries its CSS as content; a <link> is void.
			$marked = (string) preg_replace( '#<style\b[^>]*' . $attribute . '[^>]*>.*?</style\s*>#is', '', $marked, 1 );
			$marked = (string) preg_replace( '#<link\b[^>]*' . $attribute . '[^>]*>#is', '', $marked, 1 );
		}

		// Anything still stamped was left in place, so take the stamp back off.
		$marked = (string) preg_replace( '#\s' . preg_quote( StylesheetCollector::MARKER, '#' ) . '="\d+"#i', '', $marked );

		$position = strripos( $marked, '</head>' );

		return false === $position
			? $marked . $injected
			: substr( $marked, 0, $position ) . $injected . substr( $marked, $position );
	}

	private function requestUrl(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = is_string( $path ) ? $path : '/';

		return esc_url_raw( remove_query_arg( array( self::GENERATOR_PARAM, 'gtperf_css_preview' ), home_url( $path ) ) );
	}

	private function duration( float $started ): int {
		return max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}
}
