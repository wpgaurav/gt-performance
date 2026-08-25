<?php
/**
 * Minimal client for FluentCart licensing endpoints.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Licensing;

final class FluentCartClient {
	public function __construct(
		private readonly Configuration $configuration = new Configuration(),
	) {
	}

	/**
	 * @param array<string, scalar> $payload Request fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function request( string $action, array $payload = array() ): array|\WP_Error {
		if ( $this->configuration->itemId() < 1 ) {
			return new \WP_Error( 'gtp_license_product', __( 'The GT Performance product is not configured.', 'gt-performance' ) );
		}

		$url  = add_query_arg( 'fluent-cart', sanitize_key( $action ), $this->configuration->serverUrl() );
		$body = array_merge(
			array(
				'item_id'          => (string) $this->configuration->itemId(),
				'current_version'  => GTP_VERSION,
				'site_url'         => $this->configuration->siteUrl(),
				'platform_version' => get_bloginfo( 'version' ),
				'server_version'   => PHP_VERSION,
			),
			$payload
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 2,
				'sslverify'   => true,
				'body'        => $body,
				'headers'     => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'gtp_license_connection', __( 'GT Performance could not reach the license server.', 'gt-performance' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'gtp_license_response', __( 'The license server returned an unreadable response.', 'gt-performance' ) );
		}

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data = array_merge( $data['data'], array( 'success' => $data['success'] ?? true ) );
		}

		if ( $status < 200 || $status >= 300 || ( isset( $data['success'] ) && false === $data['success'] ) ) {
			return new \WP_Error(
				'gtp_license_rejected',
				$this->safeMessage( $data )
			);
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data Response data.
	 */
	private function safeMessage( array $data ): string {
		$message = (string) ( $data['message'] ?? '' );
		if ( '' === $message && isset( $data['data']['message'] ) ) {
			$message = (string) $data['data']['message'];
		}
		$message = sanitize_text_field( $message );

		return '' !== $message
			? $message
			: __( 'The license server rejected the request.', 'gt-performance' );
	}
}
