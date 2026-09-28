<?php
/**
 * Read-only GT Performance abilities.
 *
 * Each one returns saved or local evidence. None purges, warms, rewrites rules,
 * refreshes a provider, or contacts an AI service; those are explicit
 * operations elsewhere. Every successful result uses one envelope, so clients
 * never infer success from a transport response alone.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Abilities;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\Preloader;
use GTPerformance\Core\Database;
use GTPerformance\Core\Logger;
use GTPerformance\Core\PublicSettings;
use GTPerformance\Core\Settings;
use GTPerformance\Diagnostics\CacheInspector;
use GTPerformance\Diagnostics\HealthReport;
use GTPerformance\Diagnostics\PurgeReceiptRepository;
use GTPerformance\Optimization\Css\ReportRepository;
use GTPerformance\Queue\JobRepository;
use GTPerformance\Queue\QueueModule;

final class ReadAbilities {
	public const SCHEMA_VERSION = 1;

	public const ACTIVITY_OPTION = 'gt_performance_agent_activity';

	private const MAX_PAGE = 100;

	private const DEFAULT_PAGE = 20;

	/**
	 * Ability name => registration arguments, without category or exposure meta.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$none = self::input( array() );

		return array(
			'gt-performance/get-status'          => array(
				'label'            => __( 'Get GT Performance status', 'gt-performance' ),
				'description'      => __( 'Plugin, WordPress and PHP versions; schema and queue readiness; which caching and optimization modules are on; MCP, Abilities and AI Client availability; and when the queue runner and cache warming last reported. Reads saved state only.', 'gt-performance' ),
				'input_schema'     => $none,
				'output_schema'    => self::envelope( array( 'type' => 'object' ) ),
				'execute_callback' => array( self::class, 'status' ),
			),
			'gt-performance/explain-url'         => array(
				'label'            => __( 'Explain a URL', 'gt-performance' ),
				'description'      => __( 'Whether a URL from this site is cacheable and why, its cache key, the stored origin page (fresh, stale, expired or missing, with byte size and times), and whether the managed Cloudflare rule agrees with the origin. Does not request the URL.', 'gt-performance' ),
				'input_schema'     => self::input(
					array(
						'url'     => array(
							'type'        => 'string',
							'description' => __( 'Absolute http(s) URL on this site.', 'gt-performance' ),
							'minLength'   => 8,
							'maxLength'   => 2048,
						),
						'variant' => array(
							'type'        => 'string',
							'description' => __( 'Cache variant to inspect. "mobile" matters only when a separate mobile cache is on.', 'gt-performance' ),
							'enum'        => array( 'public', 'mobile' ),
							'default'     => 'public',
						),
					),
					array( 'url' )
				),
				'output_schema'    => self::envelope( array( 'type' => 'object' ) ),
				'execute_callback' => array( self::class, 'explainUrl' ),
			),
			'gt-performance/get-health'          => array(
				'label'            => __( 'Get health report', 'gt-performance' ),
				'description'      => __( 'The redacted health report: queue backlog and age, runner heartbeat, WP-Cron, storage, drop-ins, edge ownership, configuration publication, purge verification, unused-CSS failures, and cache warming. Each check names its source (live or saved) and observation time. Requests no pages.', 'gt-performance' ),
				'input_schema'     => $none,
				'output_schema'    => self::envelope(
					array(
						'type'       => 'object',
						'properties' => array(
							'overall' => array( 'type' => 'string' ),
							'checks'  => array( 'type' => 'array' ),
						),
					)
				),
				'execute_callback' => array( self::class, 'health' ),
			),
			'gt-performance/list-jobs'           => array(
				'label'            => __( 'List background jobs', 'gt-performance' ),
				'description'      => __( 'Recent background queue jobs, newest first, with status, attempts, timing and redacted errors. Payloads are omitted. Page with the returned next_cursor.', 'gt-performance' ),
				'input_schema'     => self::input(
					array(
						'status' => array(
							'type' => 'string',
							'enum' => array( 'pending', 'running', 'failed', 'complete', 'cancelled' ),
						),
						'type'   => array(
							'type'      => 'string',
							'pattern'   => '^[a-z0-9_]+$',
							'maxLength' => 64,
						),
						'cursor' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => __( 'next_cursor from the previous page.', 'gt-performance' ),
						),
						'limit'  => self::limit(),
					)
				),
				'output_schema'    => self::envelope( self::page() ),
				'execute_callback' => array( self::class, 'listJobs' ),
			),
			'gt-performance/get-css-report'      => array(
				'label'            => __( 'Get unused CSS report', 'gt-performance' ),
				'description'      => __( 'The stored unused-CSS report for one URL in the current CSS mode: status (including stale), sizes before and after, and any error. Does not generate CSS.', 'gt-performance' ),
				'input_schema'     => self::input(
					array(
						'url' => array(
							'type'      => 'string',
							'minLength' => 8,
							'maxLength' => 2048,
						),
					),
					array( 'url' )
				),
				'output_schema'    => self::envelope( array( 'type' => 'object' ) ),
				'execute_callback' => array( self::class, 'cssReport' ),
			),
			'gt-performance/get-settings'        => array(
				'label'            => __( 'Get settings', 'gt-performance' ),
				'description'      => __( 'Current non-secret settings and their hash. Credentials, account emails, Redis host and authentication, and hosting identifiers are never included. Optionally limit to one section.', 'gt-performance' ),
				'input_schema'     => self::input(
					array(
						'section' => array(
							'type' => 'string',
							'enum' => PublicSettings::sections(),
						),
					)
				),
				'output_schema'    => self::envelope( array( 'type' => 'object' ) ),
				'execute_callback' => array( self::class, 'settings' ),
			),
			'gt-performance/list-purge-receipts' => array(
				'label'            => __( 'List purge receipts', 'gt-performance' ),
				'description'      => __( 'Saved purge-verification receipts, newest first: what a purge removed at the origin and what the public response looked like afterwards. Running a new verification is not part of this ability.', 'gt-performance' ),
				'input_schema'     => self::input(
					array(
						'cursor' => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'limit'  => self::limit(),
					)
				),
				'output_schema'    => self::envelope( self::page() ),
				'execute_callback' => array( self::class, 'purgeReceipts' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public static function status( array $input = array() ): array {
		unset( $input );
		self::touch( 'get-status' );
		$ready = Database::queueReady();
		$beat  = (int) get_option( QueueModule::HEARTBEAT_OPTION, 0 );
		$warm  = $ready ? ( new CacheWarmer( new Logger() ) )->summary() : null;

		return self::result(
			array(
				'versions'     => array(
					'plugin'    => GTPERF_VERSION,
					'wordpress' => (string) get_bloginfo( 'version' ),
					'php'       => PHP_VERSION,
				),
				'schema'       => array(
					'version'     => (string) get_option( 'gt_performance_schema_version', '' ),
					'expected'    => Database::SCHEMA_VERSION,
					'queue_ready' => $ready,
					'engine'      => Database::isSqlite() ? 'sqlite' : 'mysql',
				),
				'modules'      => array(
					'page_cache'       => (bool) Settings::get( 'cache.enabled', true ),
					'optimize_only'    => Settings::optimizeOnly(),
					'cache_warming'    => (bool) Settings::get( 'cache.preload', true ),
					'separate_mobile'  => (bool) Settings::get( 'cache.separate_mobile', false ),
					'unused_css'       => (bool) Settings::get( 'css.enabled', false ),
					'javascript_defer' => (bool) Settings::get( 'javascript.defer', false ),
					'javascript_delay' => (bool) Settings::get( 'javascript.delay', false ),
					'lazy_load'        => (bool) Settings::get( 'media.lazy_load', true ),
					'local_fonts'      => (bool) Settings::get( 'fonts.self_host_google', false ),
					'cdn'              => (bool) Settings::get( 'cdn.enabled', false ),
					'cloudflare'       => (bool) Settings::get( 'cloudflare.enabled', false ),
					'xcloud'           => (bool) Settings::get( 'xcloud.enabled', false ),
					'redis'            => (bool) Settings::get( 'redis.enabled', false ),
					'database_cleanup' => (bool) Settings::get( 'database.enabled', false ),
				),
				'integrations' => Integration::readiness(),
				'agents'       => array( 'mode' => Permissions::mode() ),
				'sources'      => array(
					'queue_heartbeat'  => $beat > 0 ? gmdate( 'c', $beat ) : null,
					'warm_run_state'   => null === $warm ? null : (string) $warm['state'],
					'warm_run_updated' => null === $warm ? null : gmdate( 'c', (int) $warm['updated_at'] ),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function explainUrl( array $input ): array|\WP_Error {
		self::touch( 'explain-url' );
		$agent  = 'mobile' === ( $input['variant'] ?? 'public' ) ? Preloader::MOBILE_AGENT . GTPERF_VERSION : '';
		$report = ( new CacheInspector() )->inspect( (string) $input['url'], $agent );
		if ( is_wp_error( $report ) ) {
			return $report;
		}
		$warnings = array();
		if ( 'mobile' === ( $input['variant'] ?? 'public' ) && ! (bool) Settings::get( 'cache.separate_mobile', false ) ) {
			$warnings[] = 'A separate mobile cache is off, so the mobile variant shares the public entry.';
		}

		return self::result( $report, $warnings );
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public static function health( array $input = array() ): array {
		unset( $input );
		self::touch( 'get-health' );
		$report = HealthReport::redact( ( new HealthReport() )->build() );

		return self::result(
			array(
				'overall'  => HealthReport::overall( $report['checks'] ),
				'versions' => $report['versions'],
				'checks'   => $report['checks'],
			)
		);
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public static function listJobs( array $input ): array {
		self::touch( 'list-jobs' );
		if ( ! Database::queueReady() ) {
			return self::result( self::pageData( array(), 0 ), array( 'The queue schema upgrade is incomplete; no jobs are listed until it finishes.' ) );
		}
		$limit = (int) ( $input['limit'] ?? self::DEFAULT_PAGE );
		$items = ( new JobRepository() )->list( (string) ( $input['status'] ?? '' ), $limit, (string) ( $input['type'] ?? '' ), (int) ( $input['cursor'] ?? 0 ) );
		$items = array_map(
			static function ( array $job ): array {
				$job['id']               = (int) $job['id'];
				$job['priority']         = (int) $job['priority'];
				$job['attempts']         = (int) $job['attempts'];
				$job['cancel_requested'] = (bool) $job['cancel_requested'];
				return $job;
			},
			$items
		);
		$last = end( $items );

		return self::result( self::pageData( $items, count( $items ) === $limit && is_array( $last ) ? (int) $last['id'] : 0 ) );
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function cssReport( array $input ): array|\WP_Error {
		self::touch( 'get-css-report' );
		$url = esc_url_raw( (string) $input['url'] );
		if ( ! self::sameSite( $url ) ) {
			return new \WP_Error( 'gtperf_invalid_url', __( 'Use a URL from this WordPress site.', 'gt-performance' ), array( 'status' => 400 ) );
		}
		$mode     = (string) Settings::get( 'css.mode', 'file' );
		$reports  = new ReportRepository();
		$row      = $reports->find( $url, $mode );
		$warnings = (bool) Settings::get( 'css.enabled', false ) ? array() : array( 'Unused CSS optimization is off; stored reports are not being served.' );
		if ( null === $row ) {
			return self::result(
				array(
					'url'    => $url,
					'mode'   => $mode,
					'status' => 'missing',
				),
				$warnings
			);
		}

		$metadata = (array) $row['metadata'];
		$outputs  = (array) ( $metadata['outputs'] ?? array() );
		$summary  = array();
		foreach ( $metadata as $key => $value ) {
			if ( is_scalar( $value ) && ! str_contains( (string) $key, 'path' ) && 'reuse_key' !== $key ) {
				$summary[ (string) $key ] = is_string( $value ) ? Logger::redact( $value ) : $value;
			}
		}
		$summary['outputs']       = count( $outputs );
		$summary['outputs_bytes'] = array_sum( array_map( static fn ( $output ): int => is_array( $output ) ? (int) ( $output['bytes'] ?? 0 ) : 0, $outputs ) );

		return self::result(
			array(
				'url'          => $url,
				'mode'         => $mode,
				'status'       => $reports->effectiveStatus( $row ),
				'created_at'   => (string) $row['created_at'],
				'last_used_at' => (string) $row['last_used_at'],
				'metadata'     => $summary,
			),
			$warnings
		);
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public static function settings( array $input = array() ): array {
		self::touch( 'get-settings' );
		$view    = PublicSettings::view();
		$section = (string) ( $input['section'] ?? '' );
		$data    = '' === $section ? $view : array(
			'generation' => $view['generation'],
			$section     => $view[ $section ] ?? array(),
		);

		return self::result( $data, array(), PublicSettings::hash( $view ) );
	}

	/**
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>
	 */
	public static function purgeReceipts( array $input ): array {
		self::touch( 'list-purge-receipts' );
		$offset = (int) ( $input['cursor'] ?? 0 );
		$limit  = (int) ( $input['limit'] ?? self::DEFAULT_PAGE );
		$all    = ( new PurgeReceiptRepository() )->recent( 50 );
		$items  = array_slice( $all, $offset, $limit );
		array_walk_recursive(
			$items,
			static function ( mixed &$value ): void {
				if ( is_string( $value ) ) {
					$value = Logger::redact( $value );
				}
			}
		);

		return self::result( self::pageData( array_values( $items ), $offset + $limit < count( $all ) ? $offset + $limit : 0 ) );
	}

	/**
	 * The shared envelope, for abilities defined elsewhere.
	 *
	 * @param array<string, mixed> $data     Payload.
	 * @param list<string>         $warnings Bounded warnings.
	 * @return array<string, mixed>
	 */
	public static function wrap( array $data, array $warnings = array() ): array {
		return self::result( $data, $warnings );
	}

	/**
	 * @param array<string, mixed> $data     Payload.
	 * @param list<string>         $warnings Bounded warnings.
	 * @return array<string, mixed>
	 */
	private static function result( array $data, array $warnings = array(), string $settingsHash = '' ): array {
		$result = array(
			'schema_version' => self::SCHEMA_VERSION,
			'site_id'        => get_current_blog_id(),
			'observed_at'    => gmdate( 'c' ),
			'data'           => $data,
			'warnings'       => array_slice( $warnings, 0, 10 ),
		);
		if ( '' !== $settingsHash ) {
			$result['settings_hash'] = $settingsHash;
		}

		return $result;
	}

	/**
	 * @param list<array<string, mixed>> $items Items.
	 * @return array<string, mixed>
	 */
	private static function pageData( array $items, int $next ): array {
		return array(
			'items'       => $items,
			'next_cursor' => $next > 0 ? $next : null,
		);
	}

	/**
	 * Last agent call, shown in the connection panel. One bounded option row.
	 */
	private static function touch( string $ability ): void {
		update_option(
			self::ACTIVITY_OPTION,
			array(
				'ability' => $ability,
				'user_id' => get_current_user_id(),
				'at'      => time(),
			),
			false
		);
	}

	private static function sameSite( string $url ): bool {
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return '' !== $host && in_array( $host, Settings::canonicalHosts(), true ) && in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * @param array<string, array<string, mixed>> $properties Properties.
	 * @param list<string>                        $required   Required keys.
	 * @return array<string, mixed>
	 */
	private static function input( array $properties, array $required = array() ): array {
		// A null input normalizes to the default, so clients may omit arguments.
		$schema = array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'default'              => array(),
		);
		if ( array() !== $properties ) {
			$schema['properties'] = $properties;
		}
		if ( array() !== $required ) {
			$schema['required'] = $required;
			unset( $schema['default'] );
		}

		return $schema;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function limit(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => self::MAX_PAGE,
			'default' => self::DEFAULT_PAGE,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function page(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'items', 'next_cursor' ),
			'properties' => array(
				'items'       => array( 'type' => 'array' ),
				'next_cursor' => array( 'type' => array( 'integer', 'null' ) ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $data Data schema.
	 * @return array<string, mixed>
	 */
	private static function envelope( array $data ): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'schema_version', 'site_id', 'observed_at', 'data', 'warnings' ),
			'properties' => array(
				'schema_version' => array( 'type' => 'integer' ),
				'site_id'        => array( 'type' => 'integer' ),
				'observed_at'    => array( 'type' => 'string' ),
				'settings_hash'  => array( 'type' => 'string' ),
				'data'           => $data,
				'warnings'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}
}
