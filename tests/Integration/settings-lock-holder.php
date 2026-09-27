<?php
/** Holds the settings lock in a separate connection, then saves, then releases. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
\GTPerformance\Configuration\SettingsLock::acquire();
file_put_contents( (string) getenv( 'GTPERF_BARRIER' ) . '.held', '1' );
usleep( 1500000 );
$settings                          = \GTPerformance\Core\Settings::all();
$settings['cache']['entry_budget'] = 4321;
\GTPerformance\Core\Settings::save( $settings );
\GTPerformance\Configuration\SettingsLock::release();
