<?php
/**
 * GT Performance Redis object-cache drop-in.
 *
 * This file intentionally has no plugin runtime dependency.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Object_Cache' ) ) {
	class WP_Object_Cache {
		/**
		 * @var \Redis|null
		 */
		private $redis;

		/**
		 * @var array<string, mixed>
		 */
		private array $config = array();

		/**
		 * @var array<string, mixed>
		 */
		private array $local = array();

		/**
		 * @var array<string, true>
		 */
		private array $globalGroups = array();

		/**
		 * @var array<string, true>
		 */
		private array $nonPersistentGroups = array();

		private int $blogId;

		public int $cache_hits = 0;
		public int $cache_misses = 0;

		public function __construct() {
			$this->blogId = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
			$this->connect();
		}

		public function add( $key, $value, $group = 'default', $expire = 0 ): bool {
			$this->get( $key, $group, false, $found );
			if ( $found ) {
				return false;
			}

			return $this->set( $key, $value, $group, $expire );
		}

		public function replace( $key, $value, $group = 'default', $expire = 0 ): bool {
			$this->get( $key, $group, false, $found );
			if ( ! $found ) {
				return false;
			}

			return $this->set( $key, $value, $group, $expire );
		}

		public function set( $key, $value, $group = 'default', $expire = 0 ): bool {
			$cacheKey = $this->key( $key, $group );
			if ( $this->nonPersistent( $group ) || null === $this->redis ) {
				$this->local[ $cacheKey ] = $value;
				return true;
			}

			$payload = serialize( array( 'value' => $value ) );
			try {
				return $expire > 0
					? (bool) $this->redis->setex( $cacheKey, (int) $expire, $payload )
					: (bool) $this->redis->set( $cacheKey, $payload );
			} catch ( \Throwable ) {
				$this->local[ $cacheKey ] = $value;
				return true;
			}
		}

		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			unset( $force );
			$cacheKey = $this->key( $key, $group );
			if ( array_key_exists( $cacheKey, $this->local ) ) {
				$found = true;
				++$this->cache_hits;
				return $this->local[ $cacheKey ];
			}

			if ( $this->nonPersistent( $group ) || null === $this->redis ) {
				$found = false;
				++$this->cache_misses;
				return false;
			}

			try {
				$payload = $this->redis->get( $cacheKey );
			} catch ( \Throwable ) {
				$payload = false;
			}

			if ( ! is_string( $payload ) ) {
				$found = false;
				++$this->cache_misses;
				return false;
			}

			$data = @unserialize( $payload );
			if ( ! is_array( $data ) || ! array_key_exists( 'value', $data ) ) {
				$found = false;
				return false;
			}

			$found = true;
			++$this->cache_hits;
			$this->local[ $cacheKey ] = $data['value'];

			return $data['value'];
		}

		public function delete( $key, $group = 'default', $deprecated = false ): bool {
			unset( $deprecated );
			$cacheKey = $this->key( $key, $group );
			unset( $this->local[ $cacheKey ] );
			if ( $this->nonPersistent( $group ) || null === $this->redis ) {
				return true;
			}

			try {
				return (bool) $this->redis->del( $cacheKey );
			} catch ( \Throwable ) {
				return false;
			}
		}

		public function incr( $key, $offset = 1, $group = 'default' ) {
			$value = $this->get( $key, $group, false, $found );
			if ( ! $found || ! is_numeric( $value ) ) {
				return false;
			}
			$value = (int) $value + (int) $offset;
			$this->set( $key, $value, $group );

			return $value;
		}

		public function decr( $key, $offset = 1, $group = 'default' ) {
			$value = $this->incr( $key, -1 * (int) $offset, $group );
			if ( false !== $value && $value < 0 ) {
				$value = 0;
				$this->set( $key, $value, $group );
			}

			return $value;
		}

		public function flush(): bool {
			$this->local = array();
			if ( null === $this->redis ) {
				return true;
			}
			$prefix = $this->basePrefix();
			$keys   = $this->scan( $prefix . '*' );
			if ( $keys ) {
				$this->redis->del( $keys );
			}

			return true;
		}

		public function flush_group( $group ): bool {
			$group = $this->sanitizeGroup( $group );
			foreach ( array_keys( $this->local ) as $key ) {
				if ( str_contains( $key, ':' . $group . ':' ) ) {
					unset( $this->local[ $key ] );
				}
			}
			if ( null !== $this->redis ) {
				$keys = $this->scan( $this->basePrefix() . '*:' . $group . ':*' );
				if ( $keys ) {
					$this->redis->del( $keys );
				}
			}

			return true;
		}

		public function get_multiple( $keys, $group = 'default', $force = false ): array {
			$values = array();
			foreach ( $keys as $key ) {
				$values[ $key ] = $this->get( $key, $group, $force );
			}

			return $values;
		}

		public function set_multiple( array $data, $group = 'default', $expire = 0 ): array {
			$results = array();
			foreach ( $data as $key => $value ) {
				$results[ $key ] = $this->set( $key, $value, $group, $expire );
			}

			return $results;
		}

		public function delete_multiple( array $keys, $group = 'default' ): array {
			$results = array();
			foreach ( $keys as $key ) {
				$results[ $key ] = $this->delete( $key, $group );
			}

			return $results;
		}

		public function add_global_groups( $groups ): void {
			foreach ( (array) $groups as $group ) {
				$this->globalGroups[ $this->sanitizeGroup( $group ) ] = true;
			}
		}

		public function add_non_persistent_groups( $groups ): void {
			foreach ( (array) $groups as $group ) {
				$this->nonPersistentGroups[ $this->sanitizeGroup( $group ) ] = true;
			}
		}

		public function switch_to_blog( $blogId ): void {
			$this->blogId = (int) $blogId;
		}

		public function close(): bool {
			if ( null !== $this->redis ) {
				try {
					$this->redis->close();
				} catch ( \Throwable ) {
					return false;
				}
			}

			return true;
		}

		public function stats(): void {
			echo '<p>GT Performance Redis: ' . esc_html( (string) $this->cache_hits ) . ' hits, ' . esc_html( (string) $this->cache_misses ) . ' misses.</p>';
		}

		private function connect(): void {
			if ( ! class_exists( '\\Redis' ) ) {
				return;
			}

			try {
				$this->config = $this->configuration();
				if ( ! (bool) $this->config['enabled'] ) {
					return;
				}

				$redis      = new \Redis();
				$host       = (string) $this->config['host'];
				$host       = (bool) $this->config['tls'] && ! str_contains( $host, '://' ) ? 'tls://' . $host : $host;
				$port       = (int) $this->config['port'];
				$timeout    = (float) $this->config['connection_timeout'];
				$persistent = (bool) $this->config['persistent'];
				$connected  = $persistent
					? $redis->pconnect( $host, $port, $timeout, 'gt-performance-' . md5( $this->basePrefix() ) )
					: $redis->connect( $host, $port, $timeout );
				if ( ! $connected ) {
					return;
				}
				$redis->setOption( \Redis::OPT_READ_TIMEOUT, (float) $this->config['read_timeout'] );

				$username = (string) $this->config['username'];
				$password = (string) $this->config['password'];
				if ( '' !== $password || '' !== $username ) {
					$authenticated = '' !== $username
						? $redis->auth( array( $username, $password ) )
						: $redis->auth( $password );
					if ( ! $authenticated ) {
						return;
					}
				}
				if ( ! $redis->select( (int) $this->config['database'] ) ) {
					return;
				}
				$this->redis = $redis;
			} catch ( \Throwable ) {
				$this->redis = null;
			}
		}

		private function key( $key, $group ): string {
			$group  = $this->sanitizeGroup( $group );
			$global = isset( $this->globalGroups[ $group ] );
			$blog   = $global ? 'global' : 'blog-' . $this->blogId;

			return $this->basePrefix() . $blog . ':' . $group . ':' . md5( (string) $key );
		}

		private function basePrefix(): string {
			$prefix = (string) ( $this->config['prefix'] ?? '' );
			if ( '' === $prefix ) {
				$prefix = 'gtp:' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : 'wordpress' ) . ABSPATH );
			}

			return rtrim( $prefix, ':' ) . ':';
		}

		/**
		 * @return array<string, mixed>
		 */
		private function configuration(): array {
			$file   = WP_CONTENT_DIR . '/cache/gt-performance/redis-config.php';
			$config = is_readable( $file ) ? require $file : array();
			$config = is_array( $config ) ? $config : array();

			return array(
				'enabled'            => defined( 'GTP_REDIS_ENABLED' ) ? (bool) GTP_REDIS_ENABLED : (bool) ( $config['enabled'] ?? defined( 'GTP_REDIS_HOST' ) ),
				'host'               => defined( 'GTP_REDIS_HOST' ) ? (string) GTP_REDIS_HOST : (string) ( $config['host'] ?? '127.0.0.1' ),
				'port'               => defined( 'GTP_REDIS_PORT' ) ? (int) GTP_REDIS_PORT : (int) ( $config['port'] ?? 6379 ),
				'database'           => defined( 'GTP_REDIS_DATABASE' ) ? (int) GTP_REDIS_DATABASE : (int) ( $config['database'] ?? 0 ),
				'username'           => defined( 'GTP_REDIS_USERNAME' ) ? (string) GTP_REDIS_USERNAME : (string) ( $config['username'] ?? '' ),
				'password'           => defined( 'GTP_REDIS_PASSWORD' ) ? (string) GTP_REDIS_PASSWORD : (string) ( $config['password'] ?? '' ),
				'tls'                => defined( 'GTP_REDIS_TLS' ) ? (bool) GTP_REDIS_TLS : (bool) ( $config['tls'] ?? false ),
				'persistent'         => defined( 'GTP_REDIS_PERSISTENT' ) ? (bool) GTP_REDIS_PERSISTENT : (bool) ( $config['persistent'] ?? true ),
				'prefix'             => defined( 'GTP_REDIS_PREFIX' ) ? (string) GTP_REDIS_PREFIX : (string) ( $config['prefix'] ?? '' ),
				'connection_timeout' => defined( 'GTP_REDIS_TIMEOUT' ) ? (float) GTP_REDIS_TIMEOUT : (float) ( $config['connection_timeout'] ?? 0.5 ),
				'read_timeout'       => defined( 'GTP_REDIS_READ_TIMEOUT' ) ? (float) GTP_REDIS_READ_TIMEOUT : (float) ( $config['read_timeout'] ?? 0.5 ),
			);
		}

		private function nonPersistent( $group ): bool {
			return isset( $this->nonPersistentGroups[ $this->sanitizeGroup( $group ) ] );
		}

		private function sanitizeGroup( $group ): string {
			$sanitized = strtolower( (string) preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $group ) );

			return '' === $sanitized ? 'default' : $sanitized;
		}

		/**
		 * @return list<string>
		 */
		private function scan( string $pattern ): array {
			if ( null === $this->redis ) {
				return array();
			}
			$iterator = null;
			$keys     = array();
			do {
				$batch = $this->redis->scan( $iterator, $pattern, 500 );
				if ( is_array( $batch ) ) {
					$keys = array_merge( $keys, $batch );
				}
			} while ( 0 !== $iterator );

			return $keys;
		}
	}
}

function wp_cache_init(): void {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}
function wp_cache_add( $key, $data, $group = '', $expire = 0 ): bool {
	return $GLOBALS['wp_object_cache']->add( $key, $data, $group, $expire );
}
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ): bool {
	return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, $expire );
}
function wp_cache_set( $key, $data, $group = '', $expire = 0 ): bool {
	return $GLOBALS['wp_object_cache']->set( $key, $data, $group, $expire );
}
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
}
function wp_cache_delete( $key, $group = '', $deprecated = false ): bool {
	return $GLOBALS['wp_object_cache']->delete( $key, $group, $deprecated );
}
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group );
}
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group );
}
function wp_cache_flush(): bool {
	return $GLOBALS['wp_object_cache']->flush();
}
function wp_cache_flush_group( $group ): bool {
	return $GLOBALS['wp_object_cache']->flush_group( $group );
}
function wp_cache_get_multiple( $keys, $group = '', $force = false ): array {
	return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
}
function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ): array {
	return $GLOBALS['wp_object_cache']->set_multiple( $data, $group, $expire );
}
function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ): array {
	$results = array();
	foreach ( $data as $key => $value ) {
		$results[ $key ] = wp_cache_add( $key, $value, $group, $expire );
	}
	return $results;
}
function wp_cache_delete_multiple( array $keys, $group = '' ): array {
	return $GLOBALS['wp_object_cache']->delete_multiple( $keys, $group );
}
function wp_cache_add_global_groups( $groups ): void {
	$GLOBALS['wp_object_cache']->add_global_groups( $groups );
}
function wp_cache_add_non_persistent_groups( $groups ): void {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
}
function wp_cache_switch_to_blog( $blogId ): void {
	$GLOBALS['wp_object_cache']->switch_to_blog( $blogId );
}
function wp_cache_close(): bool {
	return $GLOBALS['wp_object_cache']->close();
}
function wp_cache_supports( $feature ): bool {
	return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_group' ), true );
}
