<?php
/**
 * Privacy-preserving Core Web Vitals collection.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\RUM;

use GTPerformance\Contracts\Module;
use GTPerformance\Core\Settings;

final class RumModule implements Module {
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wp_footer', array( $this, 'script' ), 1000 );
		add_action( 'gt_performance_database_cleanup', array( $this, 'cleanup' ) );
	}

	public function routes(): void {
		register_rest_route(
			'gt-performance/v1',
			'/vitals',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'collect' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'metric' => array(
						'required' => true,
						'type'     => 'string',
					),
					'value'  => array(
						'required' => true,
						'type'     => 'number',
					),
					'token'  => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public function script(): void {
		if ( ! (bool) Settings::get( 'rum.enabled', false ) || is_user_logged_in() ) {
			return;
		}

		$sample = (float) Settings::get( 'rum.sample_rate', 0.05 );
		$token  = $this->token( gmdate( 'Y-m-d' ) );
		$url    = rest_url( 'gt-performance/v1/vitals' );
		$config = wp_json_encode(
			array(
				'url'    => $url,
				'token'  => $token,
				'sample' => $sample,
			)
		);
		?>
		<script data-gt-performance="rum">
		(()=>{const c=<?php echo $config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;if(Math.random()>c.sample)return;
		const send=(metric,value)=>navigator.sendBeacon(c.url,new Blob([JSON.stringify({metric,value,token:c.token,device:innerWidth<768?'mobile':'desktop',cache_status:'<?php echo esc_js( $this->cacheStatus() ); ?>'})],{type:'application/json'}));
		try{new PerformanceObserver(l=>{const e=l.getEntries().at(-1);if(e)send('LCP',e.startTime)}).observe({type:'largest-contentful-paint',buffered:true});}catch(e){}
		try{let cls=0;new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)cls+=e.value;send('CLS',cls)}).observe({type:'layout-shift',buffered:true});}catch(e){}
		try{new PerformanceObserver(l=>{const e=l.getEntries().sort((a,b)=>b.duration-a.duration)[0];if(e)send('INP',e.duration)}).observe({type:'event',durationThreshold:40,buffered:true});}catch(e){}
		addEventListener('load',()=>{const n=performance.getEntriesByType('navigation')[0];if(n)send('TTFB',n.responseStart)});})();
		</script>
		<?php
	}

	public function collect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! (bool) Settings::get( 'rum.enabled', false ) ) {
			return new \WP_Error( 'gtp_rum_disabled', __( 'RUM collection is disabled.', 'gt-performance' ), array( 'status' => 404 ) );
		}

		$token = (string) $request->get_param( 'token' );
		if ( ! hash_equals( $this->token( gmdate( 'Y-m-d' ) ), $token )
			&& ! hash_equals( $this->token( gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ), $token ) ) {
			return new \WP_Error( 'gtp_rum_token', __( 'Invalid RUM token.', 'gt-performance' ), array( 'status' => 403 ) );
		}

		$metric = strtoupper( sanitize_key( (string) $request->get_param( 'metric' ) ) );
		$value  = (float) $request->get_param( 'value' );
		if ( ! in_array( $metric, array( 'TTFB', 'LCP', 'INP', 'CLS' ), true ) || $value < 0 || $value > 120000 ) {
			return new \WP_Error( 'gtp_rum_metric', __( 'Invalid RUM metric.', 'gt-performance' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'gtp_vitals',
			array(
				'metric'       => $metric,
				'value'        => $value,
				'rating'       => $this->rating( $metric, $value ),
				'template'     => '',
				'cache_status' => sanitize_key( (string) $request->get_param( 'cache_status' ) ),
				'device'       => sanitize_key( (string) $request->get_param( 'device' ) ),
				'recorded_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%f', '%s', '%s', '%s', '%s', '%s' )
		);

		return new \WP_REST_Response( array( 'stored' => true ), 201 );
	}

	public function cleanup(): void {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) Settings::get( 'rum.retention', 30 ) * DAY_IN_SECONDS );
		$table  = $wpdb->prefix . 'gtp_vitals';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the trusted WordPress prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at < %s", $cutoff ) );
	}

	private function token( string $date ): string {
		return hash_hmac( 'sha256', $date . '|' . home_url( '/' ), wp_salt( 'nonce' ) );
	}

	private function cacheStatus(): string {
		foreach ( headers_list() as $header ) {
			if ( str_starts_with( strtolower( $header ), 'x-gt-cache:' ) ) {
				return trim( substr( $header, strlen( 'x-gt-cache:' ) ) );
			}
		}

		return 'unknown';
	}

	private function rating( string $metric, float $value ): string {
		$thresholds = array(
			'TTFB' => array( 800, 1800 ),
			'LCP'  => array( 2500, 4000 ),
			'INP'  => array( 200, 500 ),
			'CLS'  => array( 0.1, 0.25 ),
		);
		$range      = $thresholds[ $metric ];

		return $value <= $range[0] ? 'good' : ( $value <= $range[1] ? 'needs-improvement' : 'poor' );
	}
}
