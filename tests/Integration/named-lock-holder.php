<?php
/** Holds a named lock from a separate database connection until told to stop. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$name    = (string) getenv( 'GTPERF_LOCK_NAME' );
$barrier = (string) getenv( 'GTPERF_BARRIER' );
if ( ! \GTPerformance\Core\NamedLock::acquire( $name, 60 ) ) {
	exit( 2 );
}
file_put_contents( $barrier . '.held', '1' );
$deadline = microtime( true ) + 20;
while ( ! is_file( $barrier . '.release' ) && microtime( true ) < $deadline ) {
	usleep( 20000 );
}
\GTPerformance\Core\NamedLock::release( $name );
