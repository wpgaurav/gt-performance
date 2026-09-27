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
use GTPerformance\Optimization\BufferedAssets;

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

	/**
	 * A fresh value on every build request. Hosts that rewrite the origin's no-store
	 * into a cacheable response (Hostinger's Site Optimizer does) let Cloudflare keep
	 * the build URL, and every later build of that page got the stored copy: GT never
	 * ran, no report was written, and the job failed until the copy expired.
	 */
	public const GENERATOR_RUN_PARAM = 'gtperf_css_run';

	/** Set once this request's build token was checked and taken off the request. */
	private static bool $claimed = false;

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
			$scripted             = new ScriptClasses();
			$keep                 = $scripted->selectorsForRequest();
			$pattern              = $scripted->patternForRequest();
			if ( '' !== $pattern ) {
				$safelist[] = $pattern;
			}
			$safelist             = apply_filters( 'gt_performance_css_safelist', $safelist, $url );
			$preserveDynamicStates = (bool) Settings::get( 'css.keep_dynamic_states', true );
			// Each run of consolidated stylesheets is pruned and replaced where it stood,
			// so stylesheets left in place keep their position in the cascade.
			$markers = array_map( 'strval', (array) $collected['markers'] );
			if ( count( $markers ) !== count( $stylesheets ) ) {
				throw new \RuntimeException( 'The consolidated stylesheets could not be located in the page.' );
			}
			$byMarker = array_combine( $markers, $stylesheets );
			$groups   = array();
			foreach ( $collected['runs'] as $run ) {
				$groups[] = array(
					'first' => $run[0],
					'css'   => array_map( static fn( string $marker ): string => $byMarker[ $marker ], $run ),
				);
			}
			$prune = fn( string $segment ): array => array_map(
				fn( array $group ): string => $this->pruner->pruneMany( $group['css'], $document, $segment, $safelist, $preserveDynamicStates, $keep ),
				$groups
			);

			$used = $prune( 'used' );
			if ( '' === trim( implode( '', $used ) ) ) {
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

			$outputs    = array();
			$placements = array();
			$fallback   = '';
			$measured   = array();
			$critical   = array();
			$remaining  = array();
			if ( 'hybrid' === $mode ) {
				$critical  = $prune( 'critical' );
				$remaining = $prune( 'remaining' );
				$budget    = (int) Settings::get( 'css.critical_budget', 14336 );
				// Kept so the report can say how far over the limit a page was.
				$measured = array(
					'critical_bytes'  => strlen( implode( '', $critical ) ),
					'critical_budget' => $budget,
				);
				if ( $measured['critical_bytes'] > $budget ) {
					$fallback = 'critical_budget_exceeded';
				}
			}

			foreach ( $groups as $index => $group ) {
				if ( 'inline' === $mode ) {
					$parts = array( array( 'inline', $used[ $index ], 'used' ) );
				} elseif ( 'hybrid' === $mode && '' === $fallback ) {
					$parts = array( array( 'inline', $critical[ $index ], 'critical' ), array( 'file', $remaining[ $index ], 'remaining' ) );
				} else {
					$parts = array( array( 'file', $used[ $index ], 'used' ) );
				}

				$markup = '';
				foreach ( $parts as [ $delivery, $groupCss, $kind ] ) {
					if ( '' === trim( $groupCss ) ) {
						continue;
					}
					[ $tag, $meta ] = 'inline' === $delivery ? $this->inlineTag( $groupCss, $kind ) : $this->fileTag( $groupCss, $kind );
					$markup        .= $tag;
					$outputs[]      = $meta;
				}
				$placements[ $group['first'] ] = $markup;
			}

			$output = $this->replaceStylesheets( $html, $markers, $placements );
			if ( '' === trim( $output ) ) {
				throw new \RuntimeException( 'The optimized HTML result was empty.' );
			}

			$this->rememberOutput( $reuseKey, $markers, $placements, $outputs );

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
				) + $measured
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
			BufferedAssets::style( $kind, $css ),
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
			BufferedAssets::style( $kind, '', (string) $artifact['url'] ),
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
		if ( self::$claimed ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence only; the value is a shared secret checked below.
		$token = isset( $_GET[ self::GENERATOR_PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::GENERATOR_PARAM ] ) ) : '';

		return '' !== $token && hash_equals( self::generatorToken(), $token );
	}

	/**
	 * Take the build token off the request before WordPress routes it.
	 *
	 * Pages print their own URL: the comment form's cancel-reply link, login
	 * redirects, pagination. With the token still in it, the HTML a build was made
	 * from never matched what a visitor gets, so the build was never reused and no
	 * page with a comment form was ever served its CSS. The parameter itself stays
	 * on the wire: it is what makes the page cache and the edge bypass this request.
	 */
	public static function claimGeneratorRequest(): void {
		if ( ! self::isGeneratorRequest() ) {
			return;
		}
		self::$claimed = true;
		unset( $_GET[ self::GENERATOR_PARAM ], $_REQUEST[ self::GENERATOR_PARAM ], $_GET[ self::GENERATOR_RUN_PARAM ], $_REQUEST[ self::GENERATOR_RUN_PARAM ] );

		$strip = static fn( string $query ): string => trim( (string) preg_replace( '/(?:^|&)(?:' . self::GENERATOR_PARAM . '|' . self::GENERATOR_RUN_PARAM . ')=[^&]*/', '', $query ), '&' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- Rewritten in place, never output: the same value minus the build token.
		if ( isset( $_SERVER['QUERY_STRING'] ) ) {
			$_SERVER['QUERY_STRING'] = $strip( (string) $_SERVER['QUERY_STRING'] );
		}
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$parts                  = explode( '?', (string) $_SERVER['REQUEST_URI'], 2 );
			$query                  = $strip( $parts[1] ?? '' );
			$_SERVER['REQUEST_URI'] = $parts[0] . ( '' === $query ? '' : '?' . $query );
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
	}

	/** Remove only the authenticated build parameter; preserve all safety inputs. */
	public static function publicRequest( RequestContext $request ): RequestContext {
		if ( ! self::isGeneratorRequest() ) {
			return $request;
		}
		$query = $request->query;
		unset( $query[ self::GENERATOR_PARAM ], $query[ self::GENERATOR_RUN_PARAM ] );
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
			add_query_arg(
				array(
					self::GENERATOR_PARAM     => self::generatorToken(),
					self::GENERATOR_RUN_PARAM => wp_generate_password( 12, false ),
				),
				$url
			),
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => \GTPerformance\Queue\JobLease::headers(),
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
			GTPERF_VERSION . '|' . $this->requestUrl() . '|' . $mode . '|'
			. (int) Settings::get( 'generation', 1 ) . '|'
			. (string) get_option( 'gtperf_css_revision', 1 ) . '|'
			. (string) get_transient( 'gtperf_css_url_revision_' . hash( 'sha256', $this->requestUrl() ) ) . '|' . self::stableMarkup( $html )
		);
	}

	/**
	 * The markup that decides which rules match, without what changes on every render.
	 *
	 * Akismet puts a random number in every comment form, and inline scripts, comments
	 * and CSP nonces carry tokens and timestamps. None of it changes which selectors
	 * match, but while it was part of the key a visitor's page never equalled the one
	 * its build saw: pages with a comment form were never served their CSS, and every
	 * uncached visit queued another build.
	 */
	private static function stableMarkup( string $html ): string {
		$html = (string) preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '<script></script>', $html );
		$html = (string) preg_replace( '#<!--.*?-->#s', '', $html );
		$html = (string) preg_replace( '#\snonce\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html );

		return (string) preg_replace_callback(
			'#<input\b[^>]*>#i',
			static fn( array $input ): string => 1 === preg_match( '#\stype\s*=\s*["\']?hidden["\'\s/>]#i', $input[0] )
				? (string) preg_replace( '#\svalue\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $input[0] )
				: $input[0],
			$html
		);
	}

	/**
	 * Serve a previously generated artifact for an identical page shape.
	 */
	private function reusableOutput( string $html, string $key ): ?string {
		$cached = get_transient( self::REUSE_PREFIX . $key );
		if ( ! is_array( $cached ) || ! isset( $cached['placements'], $cached['markers'] ) || ! is_array( $cached['placements'] ) ) {
			return null;
		}

		foreach ( (array) ( $cached['files'] ?? array() ) as $file ) {
			// An artifact reclaimed by garbage collection must not be linked again.
			if ( ! is_file( (string) $file ) ) {
				delete_transient( self::REUSE_PREFIX . $key );

				return null;
			}
		}

		return $this->replaceStylesheets( $html, array_map( 'strval', (array) $cached['markers'] ), array_map( 'strval', $cached['placements'] ) );
	}

	/**
	 * @param list<string>               $markers    Consolidated stylesheet stamps.
	 * @param array<string, string>      $placements Generated markup keyed by the stamp it replaces.
	 * @param list<array<string, mixed>> $outputs    Generated artifacts.
	 */
	private function rememberOutput( string $key, array $markers, array $placements, array $outputs ): void {
		$files = array();
		foreach ( $outputs as $output ) {
			if ( isset( $output['path'] ) && '' !== (string) $output['path'] ) {
				$files[] = (string) $output['path'];
			}
		}

		set_transient(
			self::REUSE_PREFIX . $key,
			array(
				'placements' => $placements,
				'markers'    => $markers,
				'files'      => $files,
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
	 * Replace the consolidated stylesheets: each run's generated markup takes the
	 * place of its first stylesheet, and the rest of the run is removed.
	 *
	 * @param list<string>          $markers    Stamps of the tags that were consolidated.
	 * @param array<string, string> $placements Generated markup keyed by the stamp it replaces.
	 */
	private function replaceStylesheets( string $html, array $markers, array $placements ): string {
		$marked = $this->markCandidates( $html );

		foreach ( $markers as $marker ) {
			$attribute   = preg_quote( StylesheetCollector::MARKER . '="' . $marker . '"', '#' );
			$replacement = static fn(): string => $placements[ $marker ] ?? '';

			// A <style> element carries its CSS as content; a <link> is void.
			$marked = (string) preg_replace_callback( '#<style\b[^>]*' . $attribute . '[^>]*>.*?</style\s*>#is', $replacement, $marked, 1, $replaced );
			if ( 0 === $replaced ) {
				$marked = (string) preg_replace_callback( '#<link\b[^>]*' . $attribute . '[^>]*>#is', $replacement, $marked, 1 );
			}
		}

		// Anything still stamped was left in place, so take the stamp back off.
		return (string) preg_replace( '#\s' . preg_quote( StylesheetCollector::MARKER, '#' ) . '="\d+"#i', '', $marked );
	}

	private function requestUrl(): string {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = is_string( $path ) ? $path : '/';

		return esc_url_raw( remove_query_arg( array( self::GENERATOR_PARAM, self::GENERATOR_RUN_PARAM, 'gtperf_css_preview' ), home_url( $path ) ) );
	}

	private function duration( float $started ): int {
		return max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );
	}
}
