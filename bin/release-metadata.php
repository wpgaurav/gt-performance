<?php
/**
 * Validate release version surfaces and extract changelog notes.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

$root    = dirname( __DIR__ );
$options = getopt( '', array( 'tag:', 'notes-file:', 'github-output:' ) );
$tag     = is_array( $options ) && isset( $options['tag'] ) ? trim( (string) $options['tag'] ) : '';

$read = static function ( string $path ) use ( $root ): string {
	$content = file_get_contents( $root . '/' . $path );
	if ( ! is_string( $content ) ) {
		throw new RuntimeException( 'Unable to read ' . $path );
	}

	return $content;
};

$match = static function ( string $pattern, string $content, string $label ): string {
	if ( 1 !== preg_match( $pattern, $content, $matches ) || ! isset( $matches[1] ) ) {
		throw new RuntimeException( 'Unable to read the version from ' . $label );
	}

	return trim( (string) $matches[1] );
};

try {
	$composer = json_decode( $read( 'composer.json' ), true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $composer ) || ! isset( $composer['version'] ) ) {
		throw new RuntimeException( 'composer.json does not declare a version.' );
	}

	$plugin  = $read( 'gt-performance.php' );
	$readme  = $read( 'readme.txt' );
	$build   = $read( 'bin/build-package.sh' );
	$stan    = $read( 'tests/phpstan-bootstrap.php' );
	$project = $read( 'README.md' );
	$version = trim( (string) $composer['version'] );
	$surfaces = array(
		'composer.json'          => $version,
		'plugin header'          => $match( '/^[ \t*]*Version:\s*([^\s]+)/m', $plugin, 'the plugin header' ),
		'GTP_VERSION'            => $match( "/define\\(\\s*'GTP_VERSION',\\s*'([^']+)'\\s*\\)/", $plugin, 'GTP_VERSION' ),
		'readme stable tag'      => $match( '/^Stable tag:\s*(\S+)/mi', $readme, 'readme.txt' ),
		'package builder'        => $match( '/GTP_PACKAGE_VERSION:-([^}]+)}/', $build, 'bin/build-package.sh' ),
		'PHPStan bootstrap'      => $match( "/define\\(\\s*'GTP_VERSION',\\s*'([^']+)'\\s*\\)/", $stan, 'tests/phpstan-bootstrap.php' ),
		'README current release' => $match( '/current release is `([^`]+)`/i', $project, 'README.md' ),
	);

	if ( 1 !== preg_match( '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?(?:\+[0-9A-Za-z]+(?:[.-][0-9A-Za-z]+)*)?$/', $version ) ) {
		throw new RuntimeException( 'The release version is not valid semantic versioning: ' . $version );
	}

	$mismatches = array_filter(
		$surfaces,
		static fn( string $surfaceVersion ): bool => $surfaceVersion !== $version
	);
	if ( $mismatches ) {
		$details = array_map(
			static fn( string $name, string $surfaceVersion ): string => $name . '=' . $surfaceVersion,
			array_keys( $mismatches ),
			array_values( $mismatches )
		);
		throw new RuntimeException( 'Release version mismatch: ' . implode( ', ', $details ) );
	}

	$expectedTag = 'v' . $version;
	if ( '' !== $tag && $tag !== $expectedTag ) {
		throw new RuntimeException( "Tag {$tag} does not match {$expectedTag}." );
	}

	$changelog = $read( 'CHANGELOG.md' );
	$pattern   = '/^##\s+' . preg_quote( $version, '/' ) . '\s+-\s+\d{4}-\d{2}-\d{2}\R\R(?<notes>.*?)(?=^##\s+|\z)/ms';
	if ( 1 !== preg_match( $pattern, $changelog, $matches ) || ! isset( $matches['notes'] ) ) {
		throw new RuntimeException( 'CHANGELOG.md has no dated section for ' . $version );
	}
	$notes      = trim( (string) $matches['notes'] ) . "\n";
	$prerelease = str_contains( $version, '-' );
	$metadata   = array(
		'version'    => $version,
		'tag'        => $expectedTag,
		'prerelease' => $prerelease,
		'zip_name'   => 'gt-performance-' . $version . '.zip',
		'surfaces'   => $surfaces,
	);

	if ( is_array( $options ) && isset( $options['notes-file'] ) ) {
		$written = file_put_contents( (string) $options['notes-file'], $notes );
		if ( false === $written ) {
			throw new RuntimeException( 'Unable to write the release notes file.' );
		}
	}

	if ( is_array( $options ) && isset( $options['github-output'] ) ) {
		$output = implode(
			"\n",
			array(
				'version=' . $version,
				'tag=' . $expectedTag,
				'prerelease=' . ( $prerelease ? 'true' : 'false' ),
				'zip_name=' . $metadata['zip_name'],
			)
		) . "\n";
		if ( false === file_put_contents( (string) $options['github-output'], $output, FILE_APPEND ) ) {
			throw new RuntimeException( 'Unable to write GitHub Actions outputs.' );
		}
	}

	echo json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'Release validation failed: ' . $throwable->getMessage() . "\n" );
	exit( 1 );
}
