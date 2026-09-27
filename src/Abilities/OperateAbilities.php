<?php
/**
 * Abilities that change something, and the two reads that accompany them.
 *
 * Mutations need agent access set to `operate` and a client-generated
 * request_id: repeating a request with the same ID returns the first result
 * instead of doing the work again, which makes retries after a timeout safe.
 * Nothing here applies settings; propose-settings only records a proposal for
 * an administrator to review in wp-admin.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Abilities;

use GTPerformance\Cache\PurgePreview;
use GTPerformance\Operations\OperationRepository;
use GTPerformance\Operations\OperationService;
use GTPerformance\Operations\ProposalService;

final class OperateAbilities {
	/**
	 * Name => arguments, plus `gtperf_mode` naming the mode each needs.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions(): array {
		$urls      = array(
			'type'     => 'array',
			'minItems' => 1,
			'maxItems' => OperationService::MAX_URLS,
			'items'    => array(
				'type'      => 'string',
				'minLength' => 8,
				'maxLength' => 2048,
			),
		);
		$requestId = array(
			'type'        => 'string',
			'pattern'     => '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$',
			'description' => __( 'A UUID you generate for this request. Retrying with the same ID returns the first result; a new operation needs a new ID.', 'gt-performance' ),
		);
		$accepted  = array(
			'type'       => 'object',
			'properties' => array(
				'operation_id' => array( 'type' => 'integer' ),
				'state'        => array( 'type' => 'string' ),
				'replayed'     => array( 'type' => 'boolean' ),
			),
		);

		return array(
			'gt-performance/preview-purge'    => array(
				'gtperf_mode'      => 'read',
				'label'            => __( 'Preview a purge', 'gt-performance' ),
				'description'      => __( 'Which cached URLs an update to a post would purge under the current policy, and why: its permalink and related archives plus every page whose recorded dependencies include the post, its terms, or its listings. Set membership for a publication, withdrawal, or term change. Purges nothing.', 'gt-performance' ),
				'input_schema'     => self::object(
					array(
						'post_id'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'membership' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					array( 'post_id' )
				),
				'output_schema'    => self::envelope( array( 'type' => 'object' ) ),
				'execute_callback' => array( self::class, 'previewPurge' ),
			),
			'gt-performance/get-operation'    => array(
				'gtperf_mode'      => 'read',
				'label'            => __( 'Get an operation result', 'gt-performance' ),
				'description'      => __( 'The state and result of an operation or settings proposal: accepted, running, succeeded, partial, failed, cancelled, proposed, applied, rejected, or expired. For purges, each URL reports the origin result, the edge provider\'s response, and what a public request returned afterwards. Poll this; it changes nothing.', 'gt-performance' ),
				'input_schema'     => self::object(
					array(
						'operation_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					array( 'operation_id' )
				),
				'output_schema'    => self::envelope( array( 'type' => 'object' ) ),
				'execute_callback' => array( self::class, 'getOperation' ),
			),
			'gt-performance/purge-urls'       => array(
				'gtperf_mode'      => 'operate',
				'label'            => __( 'Purge URLs', 'gt-performance' ),
				'description'      => __( 'Purge up to 20 URLs from this site\'s page cache and the configured edge cache (Cloudflare or xCloud), then request each publicly to report what visitors receive. Returns an operation ID at once; poll get-operation. Full-site and zone purges are not available here.', 'gt-performance' ),
				'input_schema'     => self::object(
					array(
						'urls'       => $urls,
						'request_id' => $requestId,
					),
					array( 'urls', 'request_id' )
				),
				'output_schema'    => self::envelope( $accepted ),
				'execute_callback' => array( self::class, 'purgeUrls' ),
			),
			'gt-performance/preload-urls'     => array(
				'gtperf_mode'      => 'operate',
				'label'            => __( 'Preload URLs', 'gt-performance' ),
				'description'      => __( 'Queue preloads for up to 20 URLs from this site so they are rebuilt in the page cache. Duplicate preloads already waiting are reused. Returns an operation ID; its result lists the job IDs.', 'gt-performance' ),
				'input_schema'     => self::object(
					array(
						'urls'       => $urls,
						'request_id' => $requestId,
					),
					array( 'urls', 'request_id' )
				),
				'output_schema'    => self::envelope( $accepted ),
				'execute_callback' => array( self::class, 'preloadUrls' ),
			),
			'gt-performance/regenerate-css'   => array(
				'gtperf_mode'      => 'operate',
				'label'            => __( 'Regenerate unused CSS', 'gt-performance' ),
				'description'      => __( 'Queue a fresh unused-CSS build for one cacheable URL. Requires unused CSS optimization to be on. Returns an operation ID.', 'gt-performance' ),
				'input_schema'     => self::object(
					array(
						'url'        => array(
							'type'      => 'string',
							'minLength' => 8,
							'maxLength' => 2048,
						),
						'request_id' => $requestId,
					),
					array( 'url', 'request_id' )
				),
				'output_schema'    => self::envelope( $accepted ),
				'execute_callback' => array( self::class, 'regenerateCss' ),
			),
			'gt-performance/retry-job'        => array(
				'gtperf_mode'      => 'operate',
				'label'            => __( 'Retry a failed job', 'gt-performance' ),
				'description'      => __( 'Retry one failed preload, warming, unused-CSS, or image job (see list-jobs). Other job types, including purges, cannot be retried by an agent. Returns an operation ID.', 'gt-performance' ),
				'input_schema'     => self::object(
					array(
						'job_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'request_id' => $requestId,
					),
					array( 'job_id', 'request_id' )
				),
				'output_schema'    => self::envelope( $accepted ),
				'execute_callback' => array( self::class, 'retryJob' ),
			),
			'gt-performance/propose-settings' => array(
				'gtperf_mode'      => 'operate',
				'label'            => __( 'Propose a settings change', 'gt-performance' ),
				'description'      => __( 'Propose changes to cache warming, JavaScript defer/delay, critical images, unused-CSS rollout, or the CSS safelist, based on the settings_hash from get-settings. This only records a proposal with a diff; an administrator must review and apply it in wp-admin within 15 minutes. It never changes settings by itself.', 'gt-performance' ),
				'input_schema'     => self::object(
					array(
						'changes'       => array(
							'type'                 => 'object',
							'properties'           => ProposalService::fields(),
							'additionalProperties' => false,
							'minProperties'        => 1,
						),
						'expected_hash' => array(
							'type'    => 'string',
							'pattern' => '^[a-f0-9]{64}$',
						),
						'request_id'    => $requestId,
					),
					array( 'changes', 'expected_hash', 'request_id' )
				),
				'output_schema'    => self::envelope( $accepted ),
				'execute_callback' => array( self::class, 'proposeSettings' ),
			),
		);
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function previewPurge( array $input ): array|\WP_Error {
		$preview = ( new PurgePreview() )->forPost( (int) $input['post_id'], (bool) ( $input['membership'] ?? false ) );

		return is_wp_error( $preview ) ? $preview : ReadAbilities::wrap( $preview );
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function getOperation( array $input ): array|\WP_Error {
		$row = ( new OperationRepository() )->find( (int) $input['operation_id'] );
		if ( null === $row ) {
			return new \WP_Error( 'gtperf_operation_missing', __( 'No such operation on this site.', 'gt-performance' ), array( 'status' => 404 ) );
		}

		return ReadAbilities::wrap( OperationService::present( $row ) + ( 'propose_settings' === $row['operation'] ? array( 'expires_at' => (string) $row['expires_at'] ) : array() ) );
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function purgeUrls( array $input ): array|\WP_Error {
		return self::submit( 'purge_urls', array( 'urls' => $input['urls'] ), (string) $input['request_id'] );
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function preloadUrls( array $input ): array|\WP_Error {
		return self::submit( 'preload_urls', array( 'urls' => $input['urls'] ), (string) $input['request_id'] );
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function regenerateCss( array $input ): array|\WP_Error {
		return self::submit( 'regenerate_css', array( 'url' => $input['url'] ), (string) $input['request_id'] );
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function retryJob( array $input ): array|\WP_Error {
		return self::submit( 'retry_job', array( 'job_id' => (int) $input['job_id'] ), (string) $input['request_id'] );
	}

	/**
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function proposeSettings( array $input ): array|\WP_Error {
		$result = ( new ProposalService() )->propose( (array) $input['changes'], (string) $input['expected_hash'], strtolower( (string) $input['request_id'] ) );

		return is_wp_error( $result ) ? $result : ReadAbilities::wrap( $result );
	}

	/**
	 * @param array<string, mixed> $payload Payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function submit( string $operation, array $payload, string $requestId ): array|\WP_Error {
		$result = ( new OperationService() )->submit( $operation, $payload, strtolower( $requestId ) );

		return is_wp_error( $result ) ? $result : ReadAbilities::wrap( $result );
	}

	/**
	 * @param array<string, array<string, mixed>> $properties Properties.
	 * @param list<string>                        $required   Required keys.
	 * @return array<string, mixed>
	 */
	private static function object( array $properties, array $required ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
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
				'data'           => $data,
				'warnings'       => array( 'type' => 'array' ),
			),
		);
	}
}
