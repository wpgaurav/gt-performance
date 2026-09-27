<?php
/**
 * WordPress Site Health test backed by the shared health report.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Diagnostics;

final class SiteHealth {
	public const TEST = 'gt_performance_health';

	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
	}

	/**
	 * @param array<string, mixed> $tests Registered tests.
	 * @return array<string, mixed>
	 */
	public function tests( array $tests ): array {
		$tests['direct'][ self::TEST ] = array(
			'label' => __( 'GT Performance health', 'gt-performance' ),
			'test'  => array( $this, 'run' ),
		);

		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run(): array {
		return self::result( ( new HealthReport() )->build()['checks'] );
	}

	/**
	 * @param list<array<string, mixed>> $checks Report checks.
	 * @return array<string, mixed>
	 */
	public static function result( array $checks ): array {
		$overall   = HealthReport::overall( $checks );
		$attention = array_filter( $checks, static fn ( array $check ): bool => in_array( $check['status'], array( 'warning', 'fail' ), true ) );

		$description = '<p>' . esc_html__( 'Queue, cron, storage, drop-ins, runtime configuration, purge verification, CSS reports, and cache warming.', 'gt-performance' ) . '</p>';
		if ( array() !== $attention ) {
			$description .= '<ul>';
			foreach ( $attention as $check ) {
				$description .= '<li><strong>' . esc_html( (string) $check['label'] ) . ':</strong> ' . esc_html( (string) $check['value'] ) . '</li>';
			}
			$description .= '</ul>';
		}

		$labels = array(
			'pass'    => __( 'GT Performance is healthy', 'gt-performance' ),
			'warning' => __( 'GT Performance has items to review', 'gt-performance' ),
			'fail'    => __( 'GT Performance cannot cache or publish correctly', 'gt-performance' ),
		);

		return array(
			'label'       => $labels[ $overall ] ?? $labels['pass'],
			'status'      => array(
				'pass'    => 'good',
				'warning' => 'recommended',
				'fail'    => 'critical',
			)[ $overall ] ?? 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'gt-performance' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=gt-performance&tab=tools' ) ),
				esc_html__( 'Open GT Performance tools', 'gt-performance' )
			),
			'test'        => self::TEST,
		);
	}
}
