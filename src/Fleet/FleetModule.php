<?php
/**
 * Signed fleet policy REST receiver.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Fleet;

use GTPerformance\Contracts\Module;

final class FleetModule implements Module {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			'gt-performance/v1',
			'/fleet/policy',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function apply( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$result = ( new PolicyService() )->apply( (array) $request->get_json_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = new \WP_REST_Response(
			array(
				'applied' => true,
				'result'  => $result,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store, private, max-age=0' );

		return $response;
	}
}
