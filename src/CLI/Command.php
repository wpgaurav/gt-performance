<?php
/**
 * GT Performance WP-CLI commands.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\CLI;

use GTPerformance\Cache\CacheWarmer;
use GTPerformance\Cache\DropinInstaller;
use GTPerformance\Cache\Purger;
use GTPerformance\Cache\WpCacheConstant;
use GTPerformance\Cloudflare\ClientFactory;
use GTPerformance\Cloudflare\RuleManager;
use GTPerformance\Core\Paths;
use GTPerformance\Core\Settings;
use GTPerformance\Database\Cleaner;
use GTPerformance\Diagnostics\CacheInspector;
use GTPerformance\Diagnostics\CronHealth;
use GTPerformance\Diagnostics\HealthReport;
use GTPerformance\Diagnostics\PurgeVerifier;
use GTPerformance\Queue\QueueModule;
use GTPerformance\Redis\ObjectCacheInstaller;
use GTPerformance\XCloud\EdgeOwnership;
use GTPerformance\XCloud\SiteService;

final class Command {
	/**
	 * Show environment and integration health.
	 *
	 * Exits with status 1 when any check fails, so monitoring and CI can act on
	 * it. Warnings, such as an integration that is simply not in use, exit 0.
	 */
	public function doctor(): void {
		$checks = array(
			array(
				'check'  => 'PHP',
				'value'  => PHP_VERSION,
				'status' => version_compare( PHP_VERSION, '8.1', '>=' ) ? 'pass' : 'fail',
			),
			array(
				'check'  => 'WordPress',
				'value'  => get_bloginfo( 'version' ),
				'status' => version_compare( get_bloginfo( 'version' ), '6.6', '>=' ) ? 'pass' : 'fail',
			),
			array(
				'check'  => 'Cache directory',
				'value'  => Paths::cacheRoot(),
				'status' => wp_is_writable( Paths::cacheRoot() ) ? 'pass' : 'fail',
			),
			array(
				'check'  => 'Page drop-in',
				'value'  => ( new DropinInstaller() )->status(),
				'status' => 'owned' === ( new DropinInstaller() )->status() ? 'pass' : 'warning',
			),
			array(
				'check'  => 'WP_CACHE',
				'value'  => ( new WpCacheConstant() )->status(),
				'status' => 'enabled' === ( new WpCacheConstant() )->status() ? 'pass' : 'warning',
			),
			array(
				'check'  => 'Redis drop-in',
				'value'  => ( new ObjectCacheInstaller() )->status(),
				'status' => 'owned' === ( new ObjectCacheInstaller() )->status() ? 'pass' : 'warning',
			),
			array(
				'check'  => 'Cloudflare',
				'value'  => Settings::get( 'cloudflare.enabled', false ) ? 'enabled' : 'disabled',
				'status' => Settings::get( 'cloudflare.enabled', false ) ? 'pass' : 'warning',
			),
			array(
				'check'  => 'xCloud',
				'value'  => Settings::get( 'xcloud.enabled', false ) ? 'enabled' : 'disabled',
				'status' => ( new EdgeOwnership() )->hasDirectCloudflareConflict() ? 'warning' : 'pass',
			),
			( new CronHealth() )->check(),
		);

		\WP_CLI\Utils\format_items( 'table', $checks, array( 'check', 'value', 'status' ) );
		self::haltOnFailure( $checks );
	}

	/**
	 * End with a non-zero status when any check failed.
	 *
	 * @param list<array<string, mixed>> $checks Checks with a status of pass, info, warning, or fail.
	 */
	private static function haltOnFailure( array $checks ): void {
		if ( 'fail' === HealthReport::overall( $checks ) ) {
			\WP_CLI::halt( 1 );
		}
	}

	/**
	 * Export, compare, import, and restore non-secret settings.
	 *
	 * Credentials, site identity, Cloudflare/xCloud identifiers, Redis, and agent
	 * access are never exported, imported, or restored. Restoring changes local
	 * settings only; run `wp gt-performance cloudflare sync` afterwards when asked.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : export, history, diff, import, or restore.
	 *
	 * [<target>]
	 * : Revision ID (or unique prefix) for diff/restore, or an export file for diff/import.
	 *
	 * [--file=<path>]
	 * : Where export writes JSON. Prints to stdout when omitted.
	 *
	 * [--expected-hash=<hash>]
	 * : Refuse import/restore unless current settings still have this hash.
	 *
	 * [--dry-run]
	 * : Show what import/restore would change without changing it.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function config( array $args, array $assocArgs ): void {
		$action = $this->action( $args, '', array( 'export', 'history', 'diff', 'import', 'restore' ), 'config' );
		if ( null === $action ) {
			return;
		}
		$service   = new \GTPerformance\Configuration\ConfigurationService();
		$revisions = new \GTPerformance\Configuration\RevisionRepository();
		$target    = (string) ( $args[1] ?? '' );

		if ( 'export' === $action ) {
			$json = (string) wp_json_encode( $service->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( empty( $assocArgs['file'] ) ) {
				\WP_CLI::line( $json );
				return;
			}
			if ( false === file_put_contents( (string) $assocArgs['file'], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Operator-chosen CLI output path.
				\WP_CLI::error( 'Could not write ' . $assocArgs['file'] );
				return;
			}
			\WP_CLI::success( 'Settings exported to ' . $assocArgs['file'] );
			return;
		}

		if ( 'history' === $action ) {
			$current = \GTPerformance\Configuration\ConfigurationService::portable( \GTPerformance\Core\PublicSettings::view() );
			$rows    = array();
			foreach ( $revisions->all() as $revision ) {
				$rows[] = array(
					'id'      => $revision['id'],
					'saved'   => gmdate( 'Y-m-d H:i:s', $revision['at'] ) . ' UTC',
					'source'  => $revision['source'],
					'user'    => (string) $revision['user_id'],
					'changes' => (string) count( \GTPerformance\Configuration\Diff::between( $current, \GTPerformance\Configuration\ConfigurationService::portable( $revision['settings'] ) ) ),
				);
			}
			\WP_CLI::log( 'Current settings hash: ' . $service->currentHash() );
			array() === $rows ? \WP_CLI::log( 'No settings revisions recorded yet.' ) : \WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'saved', 'source', 'user', 'changes' ) );
			return;
		}

		$portable = $this->configTarget( $action, $target, $service, $revisions );
		if ( null === $portable ) {
			return;
		}
		$expected = (string) ( $assocArgs['expected-hash'] ?? '' );
		if ( 'diff' === $action || ! empty( $assocArgs['dry-run'] ) ) {
			$preview = $service->preview( $portable );
			$this->printConfigChanges( $preview['changes'], $preview['external'] );
			\WP_CLI::log( 'Current settings hash: ' . $preview['settings_hash'] );
			return;
		}

		$result = $service->apply( $portable, $expected, $action );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		$this->printConfigChanges( $result['changes'], $result['external'] );
		\WP_CLI::success( $result['applied'] ? 'Settings ' . ( 'import' === $action ? 'imported' : 'restored' ) . '. New settings hash: ' . $result['settings_hash'] : 'Nothing to change.' );
	}

	/**
	 * @return array<string, mixed>|null Portable values, or null after reporting an error.
	 */
	private function configTarget( string $action, string $target, \GTPerformance\Configuration\ConfigurationService $service, \GTPerformance\Configuration\RevisionRepository $revisions ): ?array {
		if ( '' === $target ) {
			\WP_CLI::error( 'Name a revision ID or an export file.' );
			return null;
		}
		if ( 'import' === $action || is_file( $target ) ) {
			if ( ! is_readable( $target ) ) {
				\WP_CLI::error( 'Cannot read ' . $target );
				return null;
			}
			$parsed = $service->parseImport( json_decode( (string) file_get_contents( $target ), true ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Operator-chosen local file.
			if ( is_wp_error( $parsed ) ) {
				\WP_CLI::error( $parsed->get_error_message() );
				return null;
			}
			return $parsed;
		}
		$matches = array_values( array_filter( $revisions->all(), static fn ( array $revision ): bool => str_starts_with( $revision['id'], $target ) ) );
		if ( 1 !== count( $matches ) ) {
			\WP_CLI::error( array() === $matches ? 'No revision matches ' . $target . '.' : 'Revision prefix ' . $target . ' is ambiguous.' );
			return null;
		}
		return \GTPerformance\Configuration\ConfigurationService::portable( $matches[0]['settings'] );
	}

	/**
	 * @param list<array{path:string,from:mixed,to:mixed}> $changes  Changes.
	 * @param array<string, string>                        $external External follow-ups.
	 */
	private function printConfigChanges( array $changes, array $external ): void {
		if ( array() === $changes ) {
			\WP_CLI::log( 'No differences.' );
		} else {
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					static fn ( array $change ): array => array(
						'setting' => $change['path'],
						'current' => (string) wp_json_encode( $change['from'] ),
						'new'     => (string) wp_json_encode( $change['to'] ),
					),
					$changes
				),
				array( 'setting', 'current', 'new' )
			);
		}
		if ( isset( $external['cloudflare'] ) ) {
			\WP_CLI::warning( 'Only local settings change. Run `wp gt-performance cloudflare sync` to apply cache changes to the managed Cloudflare rule.' );
		}
	}

	/**
	 * Review assistant operations and settings proposals.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list, proposals, apply, or reject. Defaults to list.
	 *
	 * [<id>]
	 * : Proposal ID for apply or reject.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function operations( array $args ): void {
		$action = $this->action( $args, 'list', array( 'list', 'proposals', 'apply', 'reject' ), 'operations' );
		if ( null === $action ) {
			return;
		}
		if ( ! \GTPerformance\Core\Database::queueReady() ) {
			\WP_CLI::error( 'The schema upgrade is incomplete. Repeat this command to continue it.' );
			return;
		}
		$repo = new \GTPerformance\Operations\OperationRepository();
		if ( in_array( $action, array( 'list', 'proposals' ), true ) ) {
			$rows = 'proposals' === $action ? $repo->list( 'proposed', 50, \GTPerformance\Operations\ProposalService::OPERATION ) : $repo->list( '', 50 );
			if ( array() === $rows ) {
				\WP_CLI::log( 'proposals' === $action ? 'No open proposals.' : 'No operations recorded.' );
				return;
			}
			\WP_CLI\Utils\format_items(
				'table',
				array_map(
					static fn ( array $row ): array => array(
						'id'        => $row['id'],
						'operation' => $row['operation'],
						'state'     => $row['status'],
						'target'    => $row['target_summary'],
						'user'      => $row['actor'],
						'created'   => $row['created_at'],
						'changes'   => 'propose_settings' === $row['operation'] ? implode( '; ', array_map( static fn ( array $c ): string => $c['path'] . ': ' . wp_json_encode( $c['from'] ) . ' -> ' . wp_json_encode( $c['to'] ), \GTPerformance\Operations\ProposalService::diff( $row ) ) ) : '',
					),
					$rows
				),
				array( 'id', 'operation', 'state', 'target', 'user', 'created', 'changes' )
			);
			return;
		}
		$id       = absint( $args[1] ?? 0 );
		$proposal = new \GTPerformance\Operations\ProposalService();
		if ( 'reject' === $action ) {
			$proposal->reject( $id ) ? \WP_CLI::success( "Proposal {$id} rejected." ) : \WP_CLI::error( "Proposal {$id} is not open." );
			return;
		}
		$result = $proposal->apply( $id );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
			return;
		}
		$this->printConfigChanges( $result['changes'], $result['external'] );
		\WP_CLI::success( "Proposal {$id} applied." );
	}

	/**
	 * Show whether external assistants can reach GT Performance abilities.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status. Defaults to status.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function abilities( array $args ): void {
		if ( null === $this->action( $args, 'status', array( 'status' ), 'abilities' ) ) {
			return;
		}
		$ready = \GTPerformance\Abilities\Integration::readiness();
		$rows  = array(
			array(
				'check' => 'Abilities API',
				'value' => $ready['abilities_api'] ? 'available' : 'unavailable (WordPress 6.9+ required)',
			),
			array(
				'check' => 'Agent access',
				'value' => \GTPerformance\Abilities\Permissions::mode(),
			),
			array(
				'check' => 'MCP Adapter',
				'value' => '' === $ready['mcp_adapter'] ? 'not active' : $ready['mcp_adapter'] . ( version_compare( $ready['mcp_adapter'], \GTPerformance\Abilities\Integration::QUALIFIED_ADAPTER, '==' ) ? '' : ' (qualified: ' . \GTPerformance\Abilities\Integration::QUALIFIED_ADAPTER . ')' ),
			),
			array(
				'check' => 'MCP endpoint',
				'value' => '' === $ready['mcp_endpoint'] ? '-' : $ready['mcp_endpoint'],
			),
			array(
				'check' => 'Abilities',
				'value' => implode( ', ', \GTPerformance\Abilities\Integration::abilities() ),
			),
		);
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'value' ) );
	}

	/**
	 * Report queue, cron, storage, drop-in, configuration, purge, CSS, and warming health.
	 *
	 * Reads saved and local evidence only; it requests no pages. Exits with
	 * status 1 when any check fails; warnings exit 0.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json. JSON is the redacted support export.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function health( array $args, array $assocArgs ): void {
		unset( $args );
		$format = (string) ( $assocArgs['format'] ?? 'table' );
		if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
			\WP_CLI::error( 'Use --format=table or --format=json.' );
			return;
		}

		$report = ( new HealthReport() )->build();
		if ( 'json' === $format ) {
			\WP_CLI::line( (string) wp_json_encode( HealthReport::redact( $report ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		} else {
			\WP_CLI\Utils\format_items( 'table', $report['checks'], array( 'label', 'status', 'value', 'source' ) );
		}
		self::haltOnFailure( $report['checks'] );
	}

	/**
	 * Manage page cache.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, purge, warm, warm-status, preview, install-dropin, explain, or verify. Defaults to status.
	 *
	 * [--page-url=<url>]
	 * : Target URL for purge, explain, or verify. Defaults to the home page for explain and verify.
	 *
	 * [--post=<id>]
	 * : Post to preview for `cache preview`.
	 *
	 * [--membership]
	 * : Preview a publication, withdrawal, or term change instead of a content edit.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function cache( array $args, array $assocArgs ): void {
		$action = $this->action(
			$args,
			'status',
			array( 'status', 'purge', 'warm', 'warm-status', 'preview', 'install-dropin', 'explain', 'verify' ),
			'cache'
		);
		if ( null === $action ) {
			return;
		}
		if ( $this->pageUrlRequested( $assocArgs ) && ! in_array( $action, array( 'purge', 'explain', 'verify' ), true ) ) {
			\WP_CLI::error( '--page-url is supported only by cache purge, explain, and verify.' );
			return;
		}

		if ( 'install-dropin' === $action ) {
			$result = ( new DropinInstaller() )->install();
			is_wp_error( $result ) ? \WP_CLI::error( $result->get_error_message() ) : \WP_CLI::success( 'Page-cache drop-in installed.' );
			return;
		}
		if ( 'warm' === $action ) {
			if ( ! \GTPerformance\Core\Database::queueReady() ) {
				\WP_CLI::error( 'The queue schema upgrade is incomplete. Open GT Performance Tools or repeat this command to continue the migration.' );
				return;
			}
			$job = ( new CacheWarmer( new \GTPerformance\Core\Logger() ) )->queue();
			\WP_CLI::success( "Warm run queued as job {$job}. Discovery and preloads run through `wp gt-performance queue run` or cron; check progress with `wp gt-performance cache warm-status`." );
			return;
		}
		if ( 'preview' === $action ) {
			$postId = absint( $assocArgs['post'] ?? 0 );
			if ( $postId <= 0 ) {
				\WP_CLI::error( 'Use cache preview --post=<id> [--membership].' );
				return;
			}
			$preview = ( new \GTPerformance\Cache\PurgePreview() )->forPost( $postId, ! empty( $assocArgs['membership'] ) );
			if ( is_wp_error( $preview ) ) {
				\WP_CLI::error( $preview->get_error_message() );
				return;
			}
			\WP_CLI::log( sprintf( 'Policy: %s. Change: %s. %s', $preview['policy'], $preview['change'], $preview['purge_all'] ? 'Everything would be purged.' : count( $preview['urls'] ) . ' URL(s) would be purged.' ) );
			if ( array() !== $preview['urls'] ) {
				\WP_CLI\Utils\format_items(
					'table',
					array_map(
						static fn ( array $item ): array => array(
							'url'     => $item['url'],
							'reasons' => implode( '; ', $item['reasons'] ),
						),
						$preview['urls']
					),
					array( 'url', 'reasons' )
				);
			}
			\WP_CLI::log( sprintf( 'Indexed pages: %d. %s', $preview['completeness']['indexed_pages'], $preview['completeness']['note'] ) );
			return;
		}
		if ( 'warm-status' === $action ) {
			$run = \GTPerformance\Core\Database::queueReady() ? ( new CacheWarmer( new \GTPerformance\Core\Logger() ) )->summary() : null;
			if ( null === $run ) {
				\WP_CLI::log( 'No warm run has been recorded.' );
				return;
			}
			$rows = array(
				array(
					'field' => 'state',
					'value' => (string) $run['state'],
				),
				array(
					'field' => 'sources',
					'value' => implode( ', ', array_map( 'strval', (array) $run['sources'] ) ),
				),
				array(
					'field' => 'warnings',
					'value' => implode( ', ', array_map( 'strval', (array) $run['warnings'] ) ),
				),
			);
			foreach ( array( 'sitemap', 'url' ) as $kind ) {
				foreach ( (array) $run['targets'][ $kind ] as $status => $count ) {
					$rows[] = array(
						'field' => $kind . ' ' . $status,
						'value' => (string) $count,
					);
				}
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'field', 'value' ) );
			return;
		}
		if ( 'purge' === $action ) {
			$url = $this->pageUrl( $assocArgs );
			if ( null === $url ) {
				return;
			}
			if ( '' !== $url ) {
				( new Purger() )->purgeUrl( $url );
			} else {
				( new Purger() )->purgeAll();
			}
			$edgeResult = ( new Purger() )->flushEdge();
			if ( is_wp_error( $edgeResult ) ) {
				\WP_CLI::error( 'Local page cache cleared; Cloudflare purge failed: ' . $edgeResult->get_error_message() );
				return;
			}
			\WP_CLI::success( 'Cache purge completed.' );
			return;
		}
		if ( 'explain' === $action || 'verify' === $action ) {
			$url = $this->pageUrl( $assocArgs );
			if ( null === $url ) {
				return;
			}
			$url = '' !== $url ? $url : home_url( '/' );
			$result = 'verify' === $action
				? ( new PurgeVerifier() )->verify( $url )
				: ( new CacheInspector() )->inspect( $url );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
			}
			\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		if ( 'status' === $action ) {
			\WP_CLI::log( 'enabled=' . ( Settings::get( 'cache.enabled', false ) ? 'yes' : 'no' ) );
			\WP_CLI::log( 'dropin=' . ( new DropinInstaller() )->status() );
			return;
		}
	}

	/**
	 * Use page-url because WP-CLI reserves --url for multisite context selection.
	 * Keep the old key as a programmatic fallback for callers invoking the method.
	 *
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	private function pageUrl( array $assocArgs ): ?string {
		if ( ! $this->pageUrlRequested( $assocArgs ) ) {
			return '';
		}

		$value  = esc_url_raw( trim( (string) ( $assocArgs['page-url'] ?? $assocArgs['url'] ?? '' ) ) );
		$scheme = strtolower( (string) wp_parse_url( $value, PHP_URL_SCHEME ) );
		$host   = (string) wp_parse_url( $value, PHP_URL_HOST );
		if ( '' === $value || ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			\WP_CLI::error( 'Use --page-url with a complete HTTP or HTTPS URL.' );
			return null;
		}

		// Verify fetches the URL, and purges hand it to Cloudflare and xCloud, so an
		// address on another site must never get that far.
		$hosts = Settings::canonicalHosts();
		if ( ! in_array( strtolower( $host ), $hosts, true ) ) {
			\WP_CLI::error( sprintf( '--page-url must be on this site (%s). Add other hostnames with the gt_performance_canonical_hosts filter.', implode( ', ', $hosts ) ) );
			return null;
		}

		return $value;
	}

	/**
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	private function pageUrlRequested( array $assocArgs ): bool {
		return array_key_exists( 'page-url', $assocArgs ) || array_key_exists( 'url', $assocArgs );
	}

	/**
	 * Inspect and control queued jobs.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, list, run, pause, resume, retry, or cancel. Defaults to run.
	 *
	 * [--limit=<number>]
	 * : Positive maximum number of jobs to process or list.
	 *
	 * [--status=<status>]
	 * : Filter listed jobs by pending, running, failed, complete, or cancelled.
	 *
	 * [--id=<id>]
	 * : Job ID for retry or cancel.
	 *
	 * @param list<string>          $args Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function queue( array $args, array $assocArgs ): void {
		$action = $this->action(
			$args,
			'run',
			array( 'status', 'list', 'run', 'pause', 'resume', 'retry', 'cancel' ),
			'queue'
		);
		if ( null === $action ) {
			return;
		}

		$queue = new QueueModule( new \GTPerformance\Core\Logger() );
		if ( in_array( $action, array( 'run', 'retry' ), true ) && ! \GTPerformance\Core\Database::queueReady() ) {
			\WP_CLI::error( 'The queue schema upgrade is incomplete. Open GT Performance Tools or repeat this command to continue the migration.' );
			return;
		}
		if ( 'status' === $action ) {
			$counts = $queue->status();
			$rows   = array();
			foreach ( $counts as $check => $value ) {
				$rows[] = array(
					'check' => (string) $check,
					'value' => is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value,
				);
			}
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'value' ) );
			return;
		}

		if ( 'list' === $action ) {
			$limit  = $this->positiveLimit( $assocArgs, 20 );
			$status = sanitize_key( (string) ( $assocArgs['status'] ?? '' ) );
			if ( '' !== $status && ! in_array( $status, array( 'pending', 'running', 'failed', 'complete', 'cancelled' ), true ) ) {
				\WP_CLI::error( 'Use --status with pending, running, failed, complete, or cancelled.' );
				return;
			}
			$jobs = $queue->jobs( $status, null === $limit ? 20 : $limit );
			if ( array() === $jobs ) {
				\WP_CLI::log( 'No matching jobs.' );
				return;
			}
			\WP_CLI\Utils\format_items( 'table', $jobs, array( 'id', 'type', 'status', 'attempts', 'available_at', 'last_error' ) );
			return;
		}

		if ( 'pause' === $action ) {
			$queue->pause();
			\WP_CLI::success( 'Optional queue work paused. Cache invalidation continues.' );
			return;
		}
		if ( 'resume' === $action ) {
			$queue->resume();
			\WP_CLI::success( 'Queue resumed.' );
			return;
		}
		if ( 'retry' === $action || 'cancel' === $action ) {
			$id = filter_var( $assocArgs['id'] ?? null, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
			if ( false === $id ) {
				\WP_CLI::error( 'Use --id with the job ID.' );
				return;
			}
			$done = 'retry' === $action ? $queue->retry( (int) $id ) : $queue->cancel( (int) $id );
			$done ? \WP_CLI::success( 'retry' === $action ? "Job {$id} queued for retry." : "Job {$id} cancellation recorded." ) : \WP_CLI::error( 'That job cannot be changed.' );
			return;
		}

		$limit = $this->positiveLimit( $assocArgs, 20 );
		if ( null === $limit ) {
			return;
		}

		$count = $queue->run( $limit );
		\WP_CLI::success( "Processed {$count} job(s)." );
	}

	/**
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	private function positiveLimit( array $assocArgs, int $default ): ?int {
		if ( ! array_key_exists( 'limit', $assocArgs ) ) {
			return $default;
		}

		$limit = filter_var(
			$assocArgs['limit'],
			FILTER_VALIDATE_INT,
			array( 'options' => array( 'min_range' => 1 ) )
		);
		if ( false === $limit ) {
			\WP_CLI::error( 'Use --limit with a positive whole number.' );
			return null;
		}

		return (int) $limit;
	}

	/**
	 * Manage Cloudflare.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, plan, sync, or purge. Defaults to status.
	 *
	 * [--page-url=<url>]
	 * : Purge one exact URL instead of the entire Cloudflare zone cache.
	 *
	 * @param list<string>          $args      Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function cloudflare( array $args, array $assocArgs ): void {
		$action = $this->action( $args, 'status', array( 'status', 'plan', 'sync', 'purge' ), 'Cloudflare' );
		if ( null === $action ) {
			return;
		}
		if ( $this->pageUrlRequested( $assocArgs ) && 'purge' !== $action ) {
			\WP_CLI::error( '--page-url is supported only by cloudflare purge.' );
			return;
		}

		$settings = Settings::all();
		$before   = $settings;
		if ( 'status' === $action ) {
			$domain = ( new ClientFactory() )->domain( $settings );
			\WP_CLI::log( 'enabled=' . ( $settings['cloudflare']['enabled'] ? 'yes' : 'no' ) );
			\WP_CLI::log( 'auth=' . (string) $settings['cloudflare']['auth_mode'] );
			\WP_CLI::log( 'domain=' . ( '' !== $domain ? $domain : 'missing' ) );
			\WP_CLI::log( 'zone=' . ( $settings['cloudflare']['zone_id'] ? 'configured' : 'missing' ) );
			return;
		}
		if ( 'sync' === $action && ( new EdgeOwnership() )->xcloudOwnsEdge() ) {
			\WP_CLI::error( 'xCloud Cloudflare Enterprise is active. Disable one edge owner before synchronizing a direct Cloudflare cache rule.' );
			return;
		}

		$factory = new ClientFactory();
		$client  = $factory->create( $settings );
		if ( is_wp_error( $client ) ) {
			\WP_CLI::error( $client->get_error_message() );
		}

		$zoneId = (string) $settings['cloudflare']['zone_id'];
		if ( '' === $zoneId ) {
			$zone = $client->zoneByName( $factory->domain( $settings ) );
			if ( is_wp_error( $zone ) ) {
				\WP_CLI::error( $zone->get_error_message() );
			}
			$zoneId = (string) $zone['id'];
		}

		if ( 'purge' === $action ) {
			$url = $this->pageUrl( $assocArgs );
			if ( null === $url ) {
				return;
			}
			$result = '' !== $url
				? $client->purgeUrls( $zoneId, array( $url ), (bool) $settings['cache']['separate_mobile'] )
				: $client->purgeEverything( $zoneId );
			if ( is_wp_error( $result ) ) {
				\WP_CLI::error( $result->get_error_message() );
				return;
			}
			\WP_CLI::success( '' !== $url ? 'Cloudflare URL purge completed.' : 'Cloudflare full purge completed.' );
			return;
		}

		$cache = apply_filters( 'gt_performance_cache_policy', (array) $settings['cache'] );
		if ( 'plan' === $action ) {
			$plan = ( new RuleManager( $client ) )->preview( $zoneId, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $cache );
			if ( is_wp_error( $plan ) ) {
				\WP_CLI::error( $plan->get_error_message() );
			}
			\WP_CLI::line( (string) wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$result = ( new RuleManager( $client ) )->sync( $zoneId, (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ), $cache );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		$settings['cloudflare']['enabled']    = true;
		$settings['cloudflare']['zone_id']    = $zoneId;
		$settings['cloudflare']['drift_hash'] = hash( 'sha256', (string) wp_json_encode( $cache ) );
		if ( ! Settings::saveChanges( $before, $settings ) ) {
			\WP_CLI::error( Settings::configurationError() );
			return;
		}
		\WP_CLI::success( 'Cloudflare rule synchronized.' );
	}

	/**
	 * Manage the xCloud hosting cache integration.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, refresh, or purge. Defaults to status.
	 *
	 * `purge` selects the narrowest operation for the last refreshed cache
	 * state. Enterprise purge fails closed because xCloud's current Public API
	 * does not expose a token-authenticated mutation for that add-on.
	 *
	 * @param list<string> $args Positional arguments.
	 */
	public function xcloud( array $args ): void {
		$action = $this->action( $args, 'status', array( 'status', 'refresh', 'purge' ), 'xCloud' );
		if ( null === $action ) {
			return;
		}

		$settings = Settings::all();
		$before   = $settings;
		if ( 'status' === $action ) {
			$xcloud = (array) $settings['xcloud'];
			\WP_CLI::log( 'enabled=' . ( $xcloud['enabled'] ? 'yes' : 'no' ) );
			\WP_CLI::log( 'domain=' . ( $xcloud['domain'] ? $xcloud['domain'] : 'home-domain' ) );
			\WP_CLI::log( 'site=' . ( $xcloud['site_uuid'] ? 'configured' : 'missing' ) );
			\WP_CLI::log( 'page_cache=' . ( $xcloud['page_cache_enabled'] ? 'enabled' : 'disabled' ) );
			\WP_CLI::log( 'cloudflare_enterprise=' . ( $xcloud['enterprise_available'] ? 'active' : 'not-detected' ) );
			\WP_CLI::log( 'free_edge_cache=' . ( $xcloud['free_edge_cache_enabled'] ? 'enabled' : 'disabled' ) );
			\WP_CLI::log( 'enterprise_12h=' . (int) $xcloud['enterprise_edge_requests'] . '/' . (int) $xcloud['enterprise_requests'] . ' (' . (float) $xcloud['enterprise_hit_percent'] . '%)' );
			\WP_CLI::log( 'checked_at=' . ( $xcloud['checked_at'] ? $xcloud['checked_at'] : 'never' ) );
			return;
		}

		$service = new SiteService();
		if ( 'refresh' === $action ) {
			$status = $service->refresh( $settings );
			if ( is_wp_error( $status ) ) {
				\WP_CLI::error( $status->get_error_message() );
			}

			foreach (
				array(
					'site_uuid',
					'server_id',
					'site_id',
					'domain',
					'dashboard_url',
					'stack',
					'page_cache_enabled',
					'page_cache_source',
					'redis_enabled',
					'object_cache_pro',
					'free_edge_cache_enabled',
					'enterprise_available',
					'enterprise_requests',
					'enterprise_edge_requests',
					'enterprise_hit_percent',
					'checked_at',
				) as $key
			) {
				$settings['xcloud'][ $key ] = $status[ $key ];
			}
			$settings['xcloud']['enabled'] = true;
			if ( ! Settings::saveChanges( $before, $settings ) ) {
				\WP_CLI::error( Settings::configurationError() );
				return;
			}
			\WP_CLI::line( (string) wp_json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		$result = $service->purgeAutomatic();
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		\WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		\WP_CLI::success( 'xCloud cache purge accepted.' );
	}

	/**
	 * Preview or execute database cleanup.
	 *
	 * Runs the tasks saved on the Database tab. Revisions keep the newest
	 * "Scheduled revisions to retain" per post, as a scheduled run does, so a
	 * server cron calling this matches the built-in schedule.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : preview or run. Defaults to preview.
	 *
	 * [--all-revisions]
	 * : With run, delete every revision, as the Run cleanup button does.
	 *
	 * @param list<string>          $args      Positional arguments.
	 * @param array<string, string> $assocArgs Named arguments.
	 */
	public function database( array $args, array $assocArgs = array() ): void {
		$action = $this->action( $args, 'preview', array( 'preview', 'run' ), 'database' );
		if ( null === $action ) {
			return;
		}
		$allRevisions = array_key_exists( 'all-revisions', $assocArgs );
		if ( $allRevisions && 'run' !== $action ) {
			\WP_CLI::error( '--all-revisions is supported only by database run.' );
			return;
		}

		$cleaner = new Cleaner();
		$result  = 'run' === $action ? $cleaner->run( null, ! $allRevisions ) : $cleaner->preview();
		$rows    = array();
		foreach ( $result as $type => $count ) {
			$rows[] = array(
				'type'  => $type,
				'count' => $count,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'count' ) );
	}

	/**
	 * Resolve and validate a command-family action before constructing services
	 * or performing work. Invalid actions must never fall through to a default
	 * operation, especially for mutating commands such as Cloudflare sync.
	 *
	 * @param list<string> $args    Positional arguments.
	 * @param list<string> $allowed Allowed actions.
	 */
	private function action( array $args, string $default, array $allowed, string $family ): ?string {
		$action = strtolower( trim( (string) ( $args[0] ?? $default ) ) );
		if ( in_array( $action, $allowed, true ) ) {
			return $action;
		}

		$last = array_pop( $allowed );
		if ( count( $allowed ) > 1 ) {
			$choices = implode( ', ', $allowed ) . ', or ' . $last;
		} elseif ( $allowed ) {
			$choices = $allowed[0] . ' or ' . $last;
		} else {
			$choices = (string) $last;
		}
		\WP_CLI::error( sprintf( 'Unknown %s action. Use %s.', $family, $choices ) );
		return null;
	}
}
