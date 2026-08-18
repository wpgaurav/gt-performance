<?php
/**
 * Staged Cloudflare connection diagnosis.
 *
 * Every stage of connecting can fail for a different reason, and a single
 * "Cloudflare rejected the request" notice cannot tell them apart. This walks the
 * stages in order and reports which one broke and what Cloudflare said about it.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cloudflare;

use GTPerformance\Core\Settings;
use GTPerformance\XCloud\EdgeOwnership;

final class ConnectionDiagnostics {
	public const OPTION = 'gt_performance_cloudflare_diagnostics';

	private const PASS = 'pass';
	private const FAIL = 'fail';
	private const WARN = 'warn';
	private const SKIP = 'skip';

	/**
	 * Run every stage and persist the result.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$report = array(
			'checked_at' => current_time( 'mysql', true ),
			'steps'      => $this->steps(),
		);

		$report['ok']      = ! $this->firstFailure( $report['steps'] );
		$report['summary'] = $this->summarize( $report['steps'] );
		update_option( self::OPTION, $report, false );

		return $report;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function last(): ?array {
		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function steps(): array {
		$settings   = Settings::all();
		$cloudflare = (array) ( $settings['cloudflare'] ?? array() );
		$factory    = new ClientFactory();
		$steps      = array();

		$steps[] = $this->step(
			'enabled',
			__( 'Integration enabled', 'gt-performance' ),
			empty( $cloudflare['enabled'] ) ? self::WARN : self::PASS,
			empty( $cloudflare['enabled'] )
				? __( 'The Cloudflare integration is switched off, so purges and rule syncs will not run.', 'gt-performance' )
				: __( 'Enabled.', 'gt-performance' )
		);

		if ( ( new EdgeOwnership() )->xcloudOwnsEdge() ) {
			$steps[] = $this->step(
				'edge_owner',
				__( 'Edge ownership', 'gt-performance' ),
				self::FAIL,
				__( 'xCloud Cloudflare Enterprise currently owns the edge, so GT Performance will not touch Cloudflare. Disable one of the two.', 'gt-performance' )
			);

			return $steps;
		}

		$steps[] = $this->step( 'edge_owner', __( 'Edge ownership', 'gt-performance' ), self::PASS, __( 'GT Performance owns the direct Cloudflare connection.', 'gt-performance' ) );

		$client = $factory->create( $settings );
		if ( is_wp_error( $client ) ) {
			$steps[] = $this->step( 'credentials', __( 'Credentials', 'gt-performance' ), self::FAIL, $client->get_error_message() );

			return $steps;
		}

		$mode    = $client->mode();
		$steps[] = $this->step(
			'credentials',
			__( 'Credentials', 'gt-performance' ),
			self::PASS,
			'global' === $mode
				? __( 'Global API Key and account email are readable.', 'gt-performance' )
				: __( 'Scoped API token is readable.', 'gt-performance' )
		);

		$identity = 'global' === $mode
			? $client->request( 'GET', 'user' )
			: $client->request( 'GET', 'user/tokens/verify' );
		if ( is_wp_error( $identity ) ) {
			$steps[] = $this->step( 'authentication', __( 'Authentication', 'gt-performance' ), self::FAIL, $this->explain( $identity ) );

			return $steps;
		}

		$tokenStatus = (string) ( $identity['result']['status'] ?? '' );
		if ( 'token' === $mode && '' !== $tokenStatus && 'active' !== $tokenStatus ) {
			$steps[] = $this->step(
				'authentication',
				__( 'Authentication', 'gt-performance' ),
				self::FAIL,
				sprintf(
					/* translators: %s: Cloudflare token status such as "expired". */
					__( 'Cloudflare reports this API token as %s. Issue a new token.', 'gt-performance' ),
					$tokenStatus
				)
			);

			return $steps;
		}

		$steps[] = $this->step( 'authentication', __( 'Authentication', 'gt-performance' ), self::PASS, __( 'Cloudflare accepted these credentials.', 'gt-performance' ) );

		$domain = $factory->domain( $settings );
		$zoneId = trim( (string) ( $cloudflare['zone_id'] ?? '' ) );
		$zone   = null;
		if ( '' === $zoneId ) {
			$zone = $client->zoneByName( $domain );
			if ( is_wp_error( $zone ) ) {
				$steps[] = $this->step( 'zone', __( 'Zone lookup', 'gt-performance' ), self::FAIL, $this->explain( $zone ) );

				return $steps;
			}
			$zoneId = (string) ( $zone['id'] ?? '' );
		} else {
			$zone = $client->request( 'GET', 'zones/' . rawurlencode( $zoneId ) );
			if ( is_wp_error( $zone ) ) {
				$steps[] = $this->step(
					'zone',
					__( 'Zone lookup', 'gt-performance' ),
					self::FAIL,
					__( 'The saved Zone ID could not be read.', 'gt-performance' ) . ' ' . $this->explain( $zone )
				);

				return $steps;
			}
			$zone = (array) ( $zone['result'] ?? array() );
		}

		$steps[] = $this->step(
			'zone',
			__( 'Zone lookup', 'gt-performance' ),
			self::PASS,
			sprintf(
				/* translators: 1: zone name, 2: Cloudflare plan name. */
				__( 'Matched %1$s on the %2$s plan.', 'gt-performance' ),
				(string) ( $zone['name'] ?? $domain ),
				(string) ( $zone['plan']['name'] ?? __( 'unknown', 'gt-performance' ) )
			)
		);

		$entrypoint = $client->request( 'GET', 'zones/' . rawurlencode( $zoneId ) . '/rulesets/phases/http_request_cache_settings/entrypoint' );
		$status     = is_wp_error( $entrypoint ) ? (int) ( ( (array) $entrypoint->get_error_data() )['status'] ?? 0 ) : 200;
		if ( is_wp_error( $entrypoint ) && 404 !== $status ) {
			$steps[] = $this->step(
				'cache_rules_read',
				__( 'Cache rules read', 'gt-performance' ),
				self::FAIL,
				$this->explain( $entrypoint ) . ' ' . $this->permissionHint( $status ),
			);

			return $steps;
		}

		// A 404 leaves $entrypoint as a WP_Error, which is an object and cannot be
		// indexed. Everything derived from the response has to be read inside this
		// branch rather than at the point of use.
		$managed   = null;
		$total     = 0;
		$rulesetId = '';
		if ( ! is_wp_error( $entrypoint ) ) {
			$rulesetId = (string) ( $entrypoint['result']['id'] ?? '' );
			$rules     = array_values( array_filter( (array) ( $entrypoint['result']['rules'] ?? array() ), 'is_array' ) );
			$total     = count( $rules );
			foreach ( $rules as $rule ) {
				if ( RuleCompiler::MANAGED_RULE_REF === (string) ( $rule['ref'] ?? '' ) ) {
					$managed = $rule;
				}
			}
		}

		$steps[] = $this->step(
			'cache_rules_read',
			__( 'Cache rules read', 'gt-performance' ),
			self::PASS,
			404 === $status
				? __( 'This zone has no cache ruleset yet. GT Performance will create one.', 'gt-performance' )
				: sprintf(
					/* translators: %d: number of cache rules in the zone. */
					_n( 'Read %d cache rule in this zone.', 'Read %d cache rules in this zone.', $total, 'gt-performance' ),
					$total
				)
		);

		$steps[] = $this->writeProbe( $client, $zoneId, $rulesetId, $managed );

		return $steps;
	}

	/**
	 * Confirm the credentials can actually edit cache rules.
	 *
	 * Reading a ruleset is granted by permissions that do not include writing one,
	 * so a read-only check reports a healthy connection right up until the sync
	 * fails. This rewrites the managed rule with the bytes Cloudflare already has,
	 * which changes nothing but proves the write path.
	 *
	 * @param array<string, mixed>|null $managed Existing managed rule.
	 * @return array<string, mixed>
	 */
	private function writeProbe( ApiClient $client, string $zoneId, string $rulesetId, ?array $managed ): array {
		if ( null === $managed || '' === $rulesetId || '' === (string) ( $managed['id'] ?? '' ) ) {
			return $this->step(
				'cache_rules_write',
				__( 'Cache rules write', 'gt-performance' ),
				self::SKIP,
				__( 'No managed rule exists yet, so the write permission can only be confirmed by running a sync.', 'gt-performance' )
			);
		}

		$identical = array(
			'ref'               => (string) ( $managed['ref'] ?? '' ),
			'description'       => (string) ( $managed['description'] ?? '' ),
			'expression'        => (string) ( $managed['expression'] ?? '' ),
			'action'            => (string) ( $managed['action'] ?? '' ),
			'action_parameters' => (array) ( $managed['action_parameters'] ?? array() ),
			'enabled'           => (bool) ( $managed['enabled'] ?? true ),
		);

		$result = $client->request(
			'PATCH',
			'zones/' . rawurlencode( $zoneId ) . '/rulesets/' . rawurlencode( $rulesetId ) . '/rules/' . rawurlencode( (string) $managed['id'] ),
			$identical
		);

		if ( is_wp_error( $result ) ) {
			$status = (int) ( ( (array) $result->get_error_data() )['status'] ?? 0 );

			return $this->step(
				'cache_rules_write',
				__( 'Cache rules write', 'gt-performance' ),
				self::FAIL,
				$this->explain( $result ) . ' ' . $this->permissionHint( $status )
			);
		}

		return $this->step(
			'cache_rules_write',
			__( 'Cache rules write', 'gt-performance' ),
			self::PASS,
			__( 'Rewrote the managed rule with its own contents, so cache rule edits are permitted.', 'gt-performance' )
		);
	}

	private function permissionHint( int $status ): string {
		if ( 403 !== $status && 401 !== $status && 400 !== $status ) {
			return '';
		}

		return __( 'This is what a missing permission looks like: the API token needs Zone → Cache Rules → Edit (permission group "Cache Settings Write") in addition to Zone Read and Cache Purge.', 'gt-performance' );
	}

	private function explain( \WP_Error $error ): string {
		$data   = (array) $error->get_error_data();
		$method = (string) ( $data['method'] ?? '' );
		$path   = (string) ( $data['path'] ?? '' );
		$where  = ( '' !== $method && '' !== $path )
			? sprintf( ' [%s %s]', $method, $this->shortenPath( $path ) )
			: '';

		return $error->get_error_message() . $where;
	}

	/**
	 * Keep zone and ruleset identifiers out of the message while still naming the endpoint.
	 */
	private function shortenPath( string $path ): string {
		$path = preg_replace( '/[0-9a-f]{32}/i', '…', $path ) ?? $path;

		return substr( $path, 0, 120 );
	}

	/**
	 * @param list<array<string, mixed>> $steps Steps.
	 */
	private function summarize( array $steps ): string {
		$failed = $this->firstFailure( $steps );
		if ( null === $failed ) {
			return __( 'Cloudflare is reachable and GT Performance can read and write cache rules.', 'gt-performance' );
		}

		return sprintf(
			/* translators: 1: name of the stage that failed, 2: reason. */
			__( 'Blocked at "%1$s": %2$s', 'gt-performance' ),
			(string) $failed['label'],
			(string) $failed['detail']
		);
	}

	/**
	 * @param list<array<string, mixed>> $steps Steps.
	 * @return array<string, mixed>|null
	 */
	private function firstFailure( array $steps ): ?array {
		foreach ( $steps as $step ) {
			if ( self::FAIL === ( $step['status'] ?? '' ) ) {
				return $step;
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function step( string $key, string $label, string $status, string $detail ): array {
		return array(
			'key'    => $key,
			'label'  => $label,
			'status' => $status,
			'detail' => trim( $detail ),
		);
	}
}
