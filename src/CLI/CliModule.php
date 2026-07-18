<?php
/**
 * WP-CLI registration.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\CLI;

use GTPerformance\Contracts\Module;

final class CliModule implements Module {
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'gt-performance', Command::class );
		}
	}
}
