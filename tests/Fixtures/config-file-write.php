<?php
/** Inspect actual temporary-file state at write/publish time in a separate process. */

declare(strict_types=1);

namespace GTPerformance\Cache {
	function chmod( string $path, int $mode ): bool {
		return 'permissions-fail' !== $GLOBALS['mode'] && \chmod( $path, $mode );
	}

	function fwrite( $stream, string $data ): int|false {
		$path = stream_get_meta_data( $stream )['uri'];
		clearstatcache( true, $path );
		$GLOBALS['events']['before_write'] = array(
			'name' => basename( $path ),
			'permissions' => fileperms( $path ) & 0777,
			'bytes' => filesize( $path ),
		);
		return \fwrite( $stream, 'short-write' === $GLOBALS['mode'] ? substr( $data, 0, 12 ) : $data );
	}

	function rename( string $from, string $to ): bool {
		$GLOBALS['events']['before_rename'] = array(
			'name' => basename( $from ),
			'payload' => file_get_contents( $from ),
		);
		return 'rename-fail' !== $GLOBALS['mode'] && \rename( $from, $to );
	}
}

namespace {
	define( 'AUTH_KEY', 'gt-performance-test-auth-key' );
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
	function wp_generate_uuid4(): string { return bin2hex( random_bytes( 16 ) ); }

	require dirname( __DIR__, 2 ) . '/src/Cache/ConfigFile.php';
	$mode = $argv[1];
	$events = array();
	$result = \GTPerformance\Cache\ConfigFile::write( $argv[2], array( 'password' => 'fixture-secret' ) );
	echo json_encode( array( 'result' => $result, 'events' => $events ) );
}
