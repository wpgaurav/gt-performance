<?php
/**
 * Settings changes an agent may propose and only an administrator may apply.
 *
 * A proposal is a typed patch over an allowlist, validated and diffed against
 * the settings hash the agent read, and stored for 15 minutes. It changes
 * nothing. An administrator reviews the diff in wp-admin and applies it through
 * ConfigurationService, which re-checks the hash under the settings lock. A
 * model-supplied "confirmed" flag authorizes nothing, and there is no remote
 * apply.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Operations;

use GTPerformance\Configuration\ConfigurationService;
use GTPerformance\Configuration\Diff;

final class ProposalService {
	public const OPERATION = 'propose_settings';

	public const TTL = 15 * MINUTE_IN_SECONDS;

	private const MAX_LIST = 50;

	/**
	 * Allowlisted paths and their JSON schema, mirroring Settings::sanitize().
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields(): array {
		$list = array(
			'type'     => 'array',
			'maxItems' => self::MAX_LIST,
			'items'    => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
		);

		return array(
			'cache.preload'             => array( 'type' => 'boolean' ),
			'cache.preload_max_urls'    => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 2000,
			),
			'javascript.defer'          => array( 'type' => 'boolean' ),
			'javascript.delay'          => array( 'type' => 'boolean' ),
			'javascript.delay_patterns' => $list,
			'javascript.exclusions'     => $list,
			'media.critical_images'     => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 10,
			),
			'css.rollout_percent'       => array(
				'type' => 'integer',
				'enum' => array( 0, 10, 25, 50, 100 ),
			),
			'css.safelist'              => $list,
		);
	}

	public function __construct(
		private readonly OperationRepository $operations = new OperationRepository(),
		private readonly ConfigurationService $configuration = new ConfigurationService(),
	) {
	}

	/**
	 * @param array<string, mixed> $changes Flat path => value, already schema-validated.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function propose( array $changes, string $expectedHash, string $requestId ): array|\WP_Error {
		$fields = self::fields();
		foreach ( array_keys( $changes ) as $path ) {
			if ( ! isset( $fields[ $path ] ) ) {
				return new \WP_Error( 'gtperf_proposal_field', __( 'That setting cannot be proposed remotely.', 'gt-performance' ), array( 'status' => 400 ) );
			}
		}
		if ( array() === $changes ) {
			return new \WP_Error( 'gtperf_proposal_empty', __( 'Propose at least one change.', 'gt-performance' ), array( 'status' => 400 ) );
		}
		ksort( $changes );

		$actor  = get_current_user_id();
		$replay = $this->operations->findByRequest( $actor, $requestId );
		if ( null === $replay && ! hash_equals( $this->configuration->currentHash(), $expectedHash ) ) {
			return new \WP_Error( 'gtperf_stale_settings', __( 'Settings changed since they were read. Read them again and base the proposal on the current hash.', 'gt-performance' ), array( 'status' => 409 ) );
		}
		if ( null === $replay && $this->operations->recentByActor( $actor ) >= OperationService::MAX_PER_MINUTE ) {
			return new \WP_Error( 'gtperf_rate_limited', __( 'Too many operations in the last minute.', 'gt-performance' ), array( 'status' => 429 ) );
		}

		$portable = self::portable( $changes );
		$preview  = $this->configuration->preview( $portable );
		if ( array() === $preview['changes'] ) {
			return new \WP_Error( 'gtperf_proposal_unchanged', __( 'Those values already match the current settings.', 'gt-performance' ), array( 'status' => 400 ) );
		}

		$created = $this->operations->create(
			$actor,
			$requestId,
			self::OPERATION,
			array(
				'changes'   => $changes,
				'base_hash' => $expectedHash,
			),
			implode( ', ', array_keys( $changes ) ),
			time() + self::TTL
		);
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		if ( $created['created'] ) {
			$this->operations->update(
				(int) $created['row']['id'],
				array(
					'status' => 'proposed',
					'result' => array(
						'diff'     => $preview['changes'],
						'external' => $preview['external'],
					),
				)
			);
		}
		$row = (array) $this->operations->find( (int) $created['row']['id'] );

		return OperationService::present( $row ) + array(
			'replayed'   => ! $created['created'],
			'expires_at' => (string) $row['expires_at'],
			'review'     => admin_url( 'admin.php?page=gt-performance&tab=ai#gtp-proposals' ),
		);
	}

	/**
	 * Apply a proposal. For wp-admin only: callers check the nonce and capability.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply( int $id ): array|\WP_Error {
		$row = $this->operations->find( $id );
		if ( null === $row || self::OPERATION !== $row['operation'] || 'proposed' !== $row['status'] ) {
			return new \WP_Error( 'gtperf_proposal_missing', __( 'That proposal is no longer open.', 'gt-performance' ), array( 'status' => 404 ) );
		}
		$expires = strtotime( (string) $row['expires_at'] . ' UTC' );
		if ( false === $expires || $expires < time() ) {
			$this->operations->update( $id, array( 'status' => 'expired' ), 'proposed' );
			return new \WP_Error( 'gtperf_proposal_expired', __( 'That proposal expired. Ask the assistant to propose it again against current settings.', 'gt-performance' ), array( 'status' => 410 ) );
		}

		$changes = (array) ( $row['payload']['changes'] ?? array() );
		$result  = $this->configuration->apply( self::portable( $changes ), (string) ( $row['payload']['base_hash'] ?? '' ), 'proposal' );
		if ( is_wp_error( $result ) ) {
			if ( 'gtperf_stale_settings' === $result->get_error_code() ) {
				$this->operations->update(
					$id,
					array(
						'status' => 'expired',
						'result' => array( 'reason' => 'stale_settings' ) + (array) $row['result'],
					),
					'proposed'
				);
			}
			return $result;
		}
		$this->operations->update(
			$id,
			array(
				'status' => 'applied',
				'result' => array(
					'diff'          => $result['changes'],
					'external'      => $result['external'],
					'applied_by'    => get_current_user_id(),
					'settings_hash' => $result['settings_hash'],
				),
			),
			'proposed'
		);

		return $result;
	}

	public function reject( int $id ): bool {
		return $this->operations->update(
			$id,
			array(
				'status' => 'rejected',
				'result' => array( 'rejected_by' => get_current_user_id() ),
			),
			'proposed'
		);
	}

	/**
	 * @param array<string, mixed> $changes Flat path => value.
	 * @return array<string, mixed>
	 */
	private static function portable( array $changes ): array {
		$portable = array();
		foreach ( $changes as $path => $value ) {
			list( $section, $key ) = explode( '.', (string) $path, 2 );
			$portable[ $section ][ $key ] = $value;
		}

		return $portable;
	}

	/**
	 * Proposal diffs for display: path => from/to.
	 *
	 * @param array<string, mixed> $row Operation row.
	 * @return list<array{path:string,from:mixed,to:mixed}>
	 */
	public static function diff( array $row ): array {
		return array_values( array_filter( (array) ( $row['result']['diff'] ?? array() ), static fn ( $change ): bool => is_array( $change ) && ! Diff::ignored( (string) ( $change['path'] ?? '' ), array( 'generation' ) ) ) );
	}
}
