<?php
/**
 * Check a model response against the evidence it was given.
 *
 * Model text is untrusted data. A finding must cite evidence IDs that exist,
 * and any measurement it states (a number with a unit) must appear in the
 * evidence; otherwise it is kept but marked unsupported so the reader can see
 * it was not grounded. Suggestions must name an allowlisted setting with a
 * valid value that differs from the current one. Nothing validated here can
 * change anything by itself.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\AI;

use GTPerformance\Operations\ProposalService;

final class RecommendationValidator {
	public const MAX_OUTPUT_BYTES = 16 * 1024;

	private const MAX_FINDINGS = 8;

	private const MAX_SUGGESTIONS = 5;

	/**
	 * JSON schema requested from the provider.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		$ids = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		return array(
			'type'       => 'object',
			'required'   => array( 'findings', 'suggestions', 'uncertainty' ),
			'properties' => array(
				'findings'    => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'title', 'explanation', 'evidence_ids', 'confidence' ),
						'properties' => array(
							'title'        => array( 'type' => 'string' ),
							'explanation'  => array( 'type' => 'string' ),
							'evidence_ids' => $ids,
							'confidence'   => array(
								'type' => 'string',
								'enum' => array( 'low', 'medium', 'high' ),
							),
						),
					),
				),
				'suggestions' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'required'   => array( 'path', 'value', 'reason', 'evidence_ids' ),
						'properties' => array(
							'path'         => array(
								'type' => 'string',
								'enum' => array_keys( ProposalService::fields() ),
							),
							'value'        => array(),
							'reason'       => array( 'type' => 'string' ),
							'evidence_ids' => $ids,
						),
					),
				),
				'uncertainty' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * @param array{items:list<array{id:string,kind:string,data:mixed}>} $evidence Report sent.
	 * @param array<string, mixed>                                       $current  Current non-secret settings (PublicSettings::view()).
	 * @return array{findings:list<array<string,mixed>>,suggestions:list<array<string,mixed>>,uncertainty:string,rejected:list<string>}|\WP_Error
	 */
	public static function validate( string $raw, array $evidence, array $current ): array|\WP_Error {
		if ( strlen( $raw ) > self::MAX_OUTPUT_BYTES ) {
			return new \WP_Error( 'gtperf_ai_oversized', __( 'The adviser response was too large and was discarded.', 'gt-performance' ) );
		}
		$data = json_decode( self::stripFence( $raw ), true );
		if ( ! is_array( $data ) || ! isset( $data['findings'] ) || ! is_array( $data['findings'] ) ) {
			return new \WP_Error( 'gtperf_ai_invalid_json', __( 'The adviser did not return the expected structured answer.', 'gt-performance' ) );
		}

		$ids      = array_column( $evidence['items'], 'id' );
		$text     = (string) wp_json_encode( $evidence );
		$rejected = array();
		$findings = array();
		foreach ( array_slice( $data['findings'], 0, self::MAX_FINDINGS ) as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$cited = array_values( array_intersect( array_map( 'strval', (array) ( $finding['evidence_ids'] ?? array() ) ), $ids ) );
			$title = self::clean( (string) ( $finding['title'] ?? '' ), 160 );
			if ( array() === $cited ) {
				$rejected[] = 'Finding "' . $title . '" cited no evidence from the report.';
				continue;
			}
			$explanation = self::clean( (string) ( $finding['explanation'] ?? '' ), 1200 );
			$unsupported = self::unsupportedMeasurements( $title . ' ' . $explanation, $text );
			$findings[]  = array(
				'title'        => $title,
				'explanation'  => $explanation,
				'evidence_ids' => $cited,
				'confidence'   => in_array( $finding['confidence'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $finding['confidence'] : 'low',
				'unsupported'  => $unsupported,
			);
		}

		$fields      = ProposalService::fields();
		$suggestions = array();
		$seen        = array();
		foreach ( array_slice( (array) ( $data['suggestions'] ?? array() ), 0, self::MAX_SUGGESTIONS ) as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$path  = (string) ( $suggestion['path'] ?? '' );
			$value = $suggestion['value'] ?? null;
			$why   = '';
			if ( ! isset( $fields[ $path ] ) ) {
				$why = 'is not a setting the adviser may suggest';
			} elseif ( isset( $seen[ $path ] ) ) {
				$why = 'was suggested twice';
			} elseif ( is_wp_error( rest_validate_value_from_schema( $value, $fields[ $path ], $path ) ) ) {
				$why = 'has an invalid value';
			} elseif ( self::currentValue( $current, $path ) === $value ) {
				$why = 'already has that value';
			} elseif ( array() === array_intersect( array_map( 'strval', (array) ( $suggestion['evidence_ids'] ?? array() ) ), $ids ) ) {
				$why = 'cited no evidence';
			}
			if ( '' !== $why ) {
				$rejected[] = 'Suggestion for ' . self::clean( $path, 60 ) . ' ' . $why . '.';
				continue;
			}
			$seen[ $path ] = true;
			$suggestions[] = array(
				'path'   => $path,
				'value'  => $value,
				'reason' => self::clean( (string) ( $suggestion['reason'] ?? '' ), 600 ),
				'from'   => self::currentValue( $current, $path ),
			);
		}
		if ( self::combinationProblem( $suggestions, $current ) ) {
			$rejected[]  = 'Suggestions that delay scripts without any delay patterns were discarded.';
			$suggestions = array_values( array_filter( $suggestions, static fn ( array $s ): bool => 'javascript.delay' !== $s['path'] ) );
		}

		return array(
			'findings'    => $findings,
			'suggestions' => $suggestions,
			'uncertainty' => self::clean( (string) ( $data['uncertainty'] ?? '' ), 600 ),
			'rejected'    => $rejected,
		);
	}

	/**
	 * Measurements stated in model text that do not occur in the evidence.
	 *
	 * @return list<string>
	 */
	public static function unsupportedMeasurements( string $text, string $evidence ): array {
		if ( ! preg_match_all( '/(\d+(?:\.\d+)?)\s*(ms|milliseconds?|s|sec|seconds?|minutes?|hours?|%|percent|kb|mb|gb|bytes?|requests?|jobs?|pages?|urls?)(?![a-z])/i', $text, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		$unsupported = array();
		foreach ( $matches as $match ) {
			if ( ! preg_match( '/(?<![\d.])' . preg_quote( $match[1], '/' ) . '(?![\d])/', $evidence ) ) {
				$unsupported[] = trim( $match[0] );
			}
		}

		return array_values( array_unique( $unsupported ) );
	}

	/**
	 * @param list<array<string, mixed>> $suggestions Suggestions.
	 * @param array<string, mixed>       $current     Current settings view.
	 */
	private static function combinationProblem( array $suggestions, array $current ): bool {
		$after = array(
			'javascript.delay'          => self::currentValue( $current, 'javascript.delay' ),
			'javascript.delay_patterns' => self::currentValue( $current, 'javascript.delay_patterns' ),
		);
		foreach ( $suggestions as $suggestion ) {
			if ( array_key_exists( $suggestion['path'], $after ) ) {
				$after[ $suggestion['path'] ] = $suggestion['value'];
			}
		}

		return true === $after['javascript.delay'] && array() === (array) $after['javascript.delay_patterns'];
	}

	/**
	 * @param array<string, mixed> $current Settings view.
	 */
	private static function currentValue( array $current, string $path ): mixed {
		list( $section, $key ) = array_pad( explode( '.', $path, 2 ), 2, '' );

		return $current[ $section ][ $key ] ?? null;
	}

	/**
	 * Plain text only: model output is never rendered as HTML or followed.
	 */
	private static function clean( string $text, int $max ): string {
		$text = wp_strip_all_tags( $text );

		return mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $text ) ), 0, $max );
	}

	private static function stripFence( string $raw ): string {
		$raw = trim( $raw );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $m ) ) {
			return $m[1];
		}

		return $raw;
	}
}
