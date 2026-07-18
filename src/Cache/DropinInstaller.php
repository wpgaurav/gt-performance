<?php
/**
 * Advanced-cache drop-in ownership and installation.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Cache;

use GTPerformance\Core\Paths;

final class DropinInstaller {
	private const SIGNATURE = 'GT Performance advanced-cache drop-in';

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

	public function install(): bool|\WP_Error {
		$status = $this->status();
		if ( 'conflict' === $status ) {
			return new \WP_Error( 'gtp_dropin_conflict', __( 'Another plugin owns advanced-cache.php. Disable or migrate it first.', 'gt-performance' ) );
		}

		if ( ! wp_mkdir_p( WP_CONTENT_DIR ) ) {
			return new \WP_Error( 'gtp_dropin_directory', __( 'The WordPress content directory is not writable.', 'gt-performance' ) );
		}

		$files = array(
			GTP_DIR . '/src/Cache/Decision.php',
			GTP_DIR . '/src/Cache/RequestContext.php',
			GTP_DIR . '/src/Cache/Eligibility.php',
			GTP_DIR . '/src/Cache/CacheKey.php',
			GTP_DIR . '/src/Cache/DropinRuntime.php',
		);

		$content  = "<?php\n/** " . self::SIGNATURE . " */\n";
		$content .= "defined( 'ABSPATH' ) || exit;\n";
		$content .= '$gtp_files = ' . var_export( $files, true ) . ";\n";
		$content .= "foreach ( \$gtp_files as \$gtp_file ) {\n";
		$content .= "\tif ( ! is_readable( \$gtp_file ) ) {\n\t\treturn;\n\t}\n";
		$content .= "}\n";
		$content .= "foreach ( \$gtp_files as \$gtp_file ) {\n\trequire_once \$gtp_file;\n}\n";
		$content .= '\\GTPerformance\\Cache\\DropinRuntime::serve( ' .
			var_export( Paths::config(), true ) . ', ' .
			var_export( Paths::pages(), true ) . " );\n";

		$temp = $this->target() . '.' . wp_generate_uuid4() . '.tmp';
		if ( false === file_put_contents( $temp, $content, LOCK_EX ) ) {
			return new \WP_Error( 'gtp_dropin_write', __( 'Unable to write the cache drop-in.', 'gt-performance' ) );
		}

		if ( ! rename( $temp, $this->target() ) ) {
			@unlink( $temp );
			return new \WP_Error( 'gtp_dropin_move', __( 'Unable to publish the cache drop-in atomically.', 'gt-performance' ) );
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
			return new \WP_Error( 'gtp_dropin_not_owned', __( 'GT Performance does not own the current cache drop-in.', 'gt-performance' ) );
		}

		if ( ! @unlink( $this->target() ) ) {
			return new \WP_Error( 'gtp_dropin_remove', __( 'GT Performance could not remove its page-cache drop-in.', 'gt-performance' ) );
		}

		return ( new WpCacheConstant() )->restore();
	}
}
