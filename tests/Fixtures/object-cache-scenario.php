<?php
/**
 * Isolated GT Performance object-cache scenarios.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

if ( $argc < 3 ) {
	exit( 2 );
}

$scenario = (string) $argv[1];
$dropin   = (string) $argv[2];
$store    = (string) ( $argv[3] ?? '' );

define( 'ABSPATH', sys_get_temp_dir() . '/gtp-object-cache-wordpress/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/gtp-object-cache-content-' . getmypid() );
define( 'GTP_REDIS_ENABLED', true );
define( 'GTP_REDIS_PERSISTENT', false );

final class Redis {
	public const OPT_READ_TIMEOUT = 3;

	/** @var array<string, string> */
	private static array $memory = array();

	/** @var list<array<int|string, int|string>|null> */
	private static array $setOptions = array();

	private static string $storeFile = '';

	public static function useStoreFile( string $file ): void {
		self::$storeFile = $file;
	}

	public function connect(): bool {
		return true;
	}

	public function pconnect(): bool {
		return true;
	}

	public function setOption(): bool {
		return true;
	}

	public function auth(): bool {
		return true;
	}

	public function select(): bool {
		return true;
	}

	public function close(): bool {
		return true;
	}

	public function get( string $key ): string|false {
		if ( '' === self::$storeFile ) {
			return self::$memory[ $key ] ?? false;
		}

		$data = self::readFileStore( true );

		return isset( $data['values'][ $key ] ) ? (string) $data['values'][ $key ] : false;
	}

	/**
	 * @param array<int|string, int|string>|null $options Conditional SET options.
	 */
	public function set( string $key, string $value, ?array $options = null ): bool {
		self::$setOptions[] = $options;
		$nx = is_array( $options ) && in_array( 'nx', $options, true );
		$xx = is_array( $options ) && in_array( 'xx', $options, true );

		if ( '' === self::$storeFile ) {
			$exists = array_key_exists( $key, self::$memory );
			if ( ( $nx && $exists ) || ( $xx && ! $exists ) ) {
				return false;
			}

			self::$memory[ $key ] = $value;
			return true;
		}

		$handle = fopen( self::$storeFile, 'c+' );
		if ( false === $handle ) {
			return false;
		}

		flock( $handle, LOCK_EX );
		$data   = self::decodeStore( stream_get_contents( $handle ) );
		$exists = array_key_exists( $key, $data['values'] );
		if ( ( $nx && $exists ) || ( $xx && ! $exists ) ) {
			flock( $handle, LOCK_UN );
			fclose( $handle );
			return false;
		}

		$data['values'][ $key ] = $value;
		ftruncate( $handle, 0 );
		rewind( $handle );
		fwrite( $handle, (string) json_encode( $data ) );
		fflush( $handle );
		flock( $handle, LOCK_UN );
		fclose( $handle );

		return true;
	}

	public function setex( string $key, int $expire, string $value ): bool {
		unset( $expire );

		return $this->set( $key, $value );
	}

	public function del( string|array $keys ): int {
		$deleted = 0;
		foreach ( (array) $keys as $key ) {
			if ( array_key_exists( $key, self::$memory ) ) {
				unset( self::$memory[ $key ] );
				++$deleted;
			}
		}

		return $deleted;
	}

	public static function replaceFirstValue( mixed $value ): void {
		$key = (string) array_key_first( self::$memory );
		self::$memory[ $key ] = serialize( array( 'value' => $value ) );
	}

	/**
	 * @return list<array<int|string, int|string>|null>
	 */
	public static function setOptions(): array {
		return self::$setOptions;
	}

	/**
	 * The barrier makes the old GET-then-SET add implementation deterministic:
	 * both workers observe a miss before either unconditional SET can run.
	 *
	 * @return array{reads: int, values: array<string, string>}
	 */
	private static function readFileStore( bool $barrier ): array {
		$handle = fopen( self::$storeFile, 'c+' );
		if ( false === $handle ) {
			return array( 'reads' => 0, 'values' => array() );
		}

		flock( $handle, LOCK_EX );
		$data = self::decodeStore( stream_get_contents( $handle ) );
		if ( $barrier ) {
			++$data['reads'];
			ftruncate( $handle, 0 );
			rewind( $handle );
			fwrite( $handle, (string) json_encode( $data ) );
			fflush( $handle );
		}
		flock( $handle, LOCK_UN );
		fclose( $handle );

		if ( $barrier ) {
			$deadline = microtime( true ) + 5;
			do {
				usleep( 10000 );
				$current = self::readFileStore( false );
			} while ( $current['reads'] < 2 && microtime( true ) < $deadline );
		}

		return $data;
	}

	/**
	 * @return array{reads: int, values: array<string, string>}
	 */
	private static function decodeStore( string|false $contents ): array {
		$data = is_string( $contents ) ? json_decode( $contents, true ) : null;

		return array(
			'reads'  => is_array( $data ) ? (int) ( $data['reads'] ?? 0 ) : 0,
			'values' => is_array( $data ) && is_array( $data['values'] ?? null ) ? $data['values'] : array(),
		);
	}
}

if ( '' !== $store ) {
	Redis::useStoreFile( $store );
}

require $dropin;

$cache = new WP_Object_Cache();

if ( 'write-through' === $scenario ) {
	$cache->set( 'key', 'first', 'group' );
	$cache->get( 'key', 'group' );
	$cache->set( 'key', 'second', 'group' );
	$found = null;
	$value = $cache->get( 'key', 'group', false, $found );

	echo (string) json_encode( array( 'ok' => true === $found && 'second' === $value ) );
	exit;
}

if ( 'force' === $scenario ) {
	$cache->set( 'key', 'local', 'group' );
	Redis::replaceFirstValue( 'backend' );
	$found = null;
	$value = $cache->get( 'key', 'group', true, $found );

	echo (string) json_encode( array( 'ok' => true === $found && 'backend' === $value ) );
	exit;
}

if ( 'conditional-options' === $scenario ) {
	$added = $cache->add( 'added', 'value', 'group', 30 );
	$cache->set( 'replaced', 'old', 'group' );
	$replaced = $cache->replace( 'replaced', 'new', 'group', 45 );
	$options  = Redis::setOptions();

	echo (string) json_encode(
		array(
			'ok' => $added
				&& $replaced
				&& array( 'nx', 'ex' => 30 ) === $options[0]
				&& array( 'xx', 'ex' => 45 ) === $options[2],
		)
	);
	exit;
}

if ( 'cron-advancement' === $scenario ) {
	$previous = array(
		'version' => 2,
		1000      => array( 'site_maintenance_refresh' => array() ),
	);
	$advanced = array(
		'version' => 2,
		1300      => array( 'site_maintenance_refresh' => array() ),
	);

	$cache->set( 'cron', $previous, 'options' );
	$cache->get( 'cron', 'options' );
	$cache->set( 'cron', $advanced, 'options' );
	$found = null;
	$value = $cache->get( 'cron', 'options', false, $found );

	echo (string) json_encode( array( 'ok' => true === $found && $advanced === $value ) );
	exit;
}

if ( 'notoptions' === $scenario ) {
	$database = array();
	$get      = static function ( string $option ) use ( &$database, $cache ) {
		$notoptions = $cache->get( 'notoptions', 'options', false, $found );
		if ( true === $found && is_array( $notoptions ) && isset( $notoptions[ $option ] ) ) {
			return false;
		}

		$value = $cache->get( $option, 'options', false, $found );
		if ( true === $found ) {
			return $value;
		}
		if ( array_key_exists( $option, $database ) ) {
			$cache->set( $option, $database[ $option ], 'options' );
			return $database[ $option ];
		}

		$notoptions            = is_array( $notoptions ) ? $notoptions : array();
		$notoptions[ $option ] = true;
		$cache->set( 'notoptions', $notoptions, 'options' );
		return false;
	};

	$get( 'recreated' );
	$database['recreated'] = 'database-value';
	$cache->set( 'recreated', $database['recreated'], 'options' );
	$notoptions = $cache->get( 'notoptions', 'options' );
	if ( is_array( $notoptions ) ) {
		unset( $notoptions['recreated'] );
		$cache->set( 'notoptions', $notoptions, 'options' );
	}

	echo (string) json_encode( array( 'ok' => 'database-value' === $get( 'recreated' ) ) );
	exit;
}

if ( 'delete-reports-absence' === $scenario ) {
	$cache->add_non_persistent_groups( array( 'transient-ish' ) );
	$cache->set( 'present', 'value', 'transient-ish' );

	echo (string) json_encode(
		array(
			'ok' => true === $cache->delete( 'present', 'transient-ish' )
				&& false === $cache->delete( 'present', 'transient-ish' )
				&& false === $cache->delete( 'never-stored', 'transient-ish' ),
		)
	);
	exit;
}

if ( 'add-worker' === $scenario ) {
	echo $cache->add( 'race', getmypid(), 'group' ) ? '1' : '0';
	exit;
}

exit( 3 );
