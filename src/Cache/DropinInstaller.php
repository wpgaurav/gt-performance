<?php
/**
 * Advanced-cache drop-in ownership and installation.
 *
 * The drop-in ships as a static file in the plugin's dropins directory and is
 * copied verbatim; only the version in its signature is stamped in. No PHP
 * source is generated. Publication uses an atomic same-filesystem rename so a
 * request can never include a half-written file, which WP_Filesystem cannot
 * guarantee.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Settings;

final class DropinInstaller {
	private const SIGNATURE      = 'GT Performance advanced-cache drop-in';
	private const VERSION_OPTION = 'gt_performance_dropin_version';

	public function target(): string {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	public function status(): string {
		$target = $this->target();
		if ( ! is_file( $target ) ) {
			return 'missing';
		}

		$contents = file_get_contents( $target );
		if ( is_string( $contents ) && str_contains( $contents, self::SIGNATURE ) ) {
			return 'owned';
		}

		return 'conflict';
	}

	/**
	 * Read the plugin version recorded in an owned drop-in, or '' when absent.
	 */
	public function installedVersion(): string {
		$target = $this->target();
		if ( ! is_file( $target ) ) {
			return '';
		}

		$contents = (string) file_get_contents( $target );
		// Each separator must be followed by more version characters, so the
		// sentence-ending period after the signature is not captured.
		if ( preg_match( '/' . preg_quote( self::SIGNATURE, '/' ) . ' v([0-9A-Za-z]+(?:[.\\-][0-9A-Za-z]+)*)/', $contents, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Regenerate an owned drop-in after a plugin update so a renamed embedded file
	 * or a changed DropinRuntime::serve() signature can never leave a stale
	 * advanced-cache.php that silently disables caching or fatals on every front-end
	 * request. Option-gated so the file is touched at most once per version.
	 */
	public static function syncVersion(): void {
		if ( GTPERF_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			return;
		}

		$installer = new self();
		if ( 'owned' === $installer->status() && GTPERF_VERSION !== $installer->installedVersion() ) {
			$installer->install();
		}

		update_option( self::VERSION_OPTION, GTPERF_VERSION, false );
	}

	public function install(): bool|\WP_Error {
		$status = $this->status();
		if ( 'conflict' === $status ) {
			return new \WP_Error( 'gtperf_dropin_conflict', __( 'Another plugin owns advanced-cache.php. Disable or migrate it first.', 'gt-performance' ) );
		}

		if ( ! wp_mkdir_p( WP_CONTENT_DIR ) ) {
			return new \WP_Error( 'gtperf_dropin_directory', __( 'The WordPress content directory is not writable.', 'gt-performance' ) );
		}

		// The compiled configuration carries the plugin directory the drop-in
		// loads its runtime from, so it must exist before the drop-in is live.
		Settings::compile();

		$content = file_get_contents( GTPERF_DIR . '/dropins/advanced-cache.php' );
		if ( ! is_string( $content ) ) {
			return new \WP_Error( 'gtperf_dropin_source', __( 'Unable to read the bundled cache drop-in.', 'gt-performance' ) );
		}

		$content = (string) preg_replace(
			'/' . preg_quote( self::SIGNATURE, '/' ) . '/',
			self::SIGNATURE . ' v' . GTPERF_VERSION,
			$content,
			1
		);

		$temp = $this->target() . '.' . wp_generate_uuid4() . '.tmp';
		if ( false === file_put_contents( $temp, $content, LOCK_EX ) ) {
			return new \WP_Error( 'gtperf_dropin_write', __( 'Unable to write the cache drop-in.', 'gt-performance' ) );
		}

		if ( ! rename( $temp, $this->target() ) ) {
			@unlink( $temp );
			return new \WP_Error( 'gtperf_dropin_move', __( 'Unable to publish the cache drop-in atomically.', 'gt-performance' ) );
		}

		$constant = ( new WpCacheConstant() )->enable();
		if ( is_wp_error( $constant ) ) {
			@unlink( $this->target() );
			return $constant;
		}

		return true;
	}

	public function remove(): bool|\WP_Error {
		if ( 'owned' !== $this->status() ) {
			return new \WP_Error( 'gtperf_dropin_not_owned', __( 'GT Performance does not own the current cache drop-in.', 'gt-performance' ) );
		}

		if ( ! @unlink( $this->target() ) ) {
			return new \WP_Error( 'gtperf_dropin_remove', __( 'GT Performance could not remove its page-cache drop-in.', 'gt-performance' ) );
		}

		return ( new WpCacheConstant() )->restore();
	}
}
