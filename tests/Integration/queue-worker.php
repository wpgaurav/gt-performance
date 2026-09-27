<?php
/** A separate database connection with a deterministic pre-mutation barrier. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

final class GtperfBarrierDatabase extends wpdb {
	public function get_var( $query = null, $x = 0, $y = 0 ) {
		$value = parent::get_var( $query, $x, $y );
		$mode = getenv( 'GTPERF_WORKER_MODE' );
		$selected = 'claim' === $mode ? str_contains( (string) $query, 'ORDER BY CASE' ) : str_contains( (string) $query, 'WHERE active_key =' );
		if ( $selected ) {
			$path = (string) getenv( 'GTPERF_BARRIER' );
			file_put_contents( $path . '.' . getmypid(), 'selected' );
			$deadline = microtime( true ) + 10;
			while ( ! is_file( $path . '.go' ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
		}
		return $value;
	}
}
$wpdb = new GtperfBarrierDatabase( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$wpdb->set_prefix( $table_prefix );
$wpdb->suppress_errors( true );
$jobs = new \GTPerformance\Queue\JobRepository();
$result = 'claim' === getenv( 'GTPERF_WORKER_MODE' ) ? $jobs->claim() : $jobs->enqueue( 'preload_url', array( 'url' => home_url( '/concurrent/' ) ) );
file_put_contents( (string) getenv( 'GTPERF_BARRIER' ) . '.result.' . getmypid(), json_encode( $result ) );
