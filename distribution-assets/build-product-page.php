<?php

declare(strict_types=1);

$options = getopt(
	'',
	array(
		'dashboard-url:',
		'reports-url:',
		'checkout-url:',
		'tokens-path::',
	)
);

$dashboardUrl = isset( $options['dashboard-url'] ) ? (string) $options['dashboard-url'] : '';
$reportsUrl   = isset( $options['reports-url'] ) ? (string) $options['reports-url'] : '';
$checkoutUrl  = isset( $options['checkout-url'] ) ? (string) $options['checkout-url'] : '';
$home         = getenv( 'HOME' );
$tokensPath   = isset( $options['tokens-path'] ) && is_string( $options['tokens-path'] )
	? $options['tokens-path']
	: ( is_string( $home ) ? $home : '' ) . '/Library/Mobile Documents/com~apple~CloudDocs/claude-sync/skills/gt-design/references/tokens.css';

if ( '' === $dashboardUrl || '' === $reportsUrl || '' === $checkoutUrl || ! is_readable( $tokensPath ) ) {
	fwrite( STDERR, "Usage: php distribution-assets/build-product-page.php --dashboard-url=<url> --reports-url=<url> --checkout-url=<url> [--tokens-path=<gtds-tokens.css>]\n" );
	exit( 1 );
}

$baseDirectory = __DIR__;
$htmlPath      = $baseDirectory . '/fluentcart/product-page-content.html';
$cssPath       = $baseDirectory . '/fluentcart/product-page.css';
$outputPath    = $baseDirectory . '/fluentcart/product-page.wordpress.html';

$html = file_get_contents( $htmlPath );
$css  = file_get_contents( $cssPath );
$tokens = file_get_contents( $tokensPath );

if ( false === $html || false === $css || false === $tokens ) {
	fwrite( STDERR, "Unable to read the FluentCart product page sources.\n" );
	exit( 1 );
}

$css = rtrim( $tokens ) . "\n\n" . ltrim( $css );

$html = strtr(
	$html,
	array(
		'{{DASHBOARD_URL}}' => $dashboardUrl,
		'{{REPORTS_URL}}'   => $reportsUrl,
		'{{CHECKOUT_URL}}'  => $checkoutUrl,
	)
);

$attributes = json_encode(
	array(
		'content' => $html,
		'css'     => $css,
	),
	JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ( false === $attributes ) {
	fwrite( STDERR, "Unable to encode the FluentCart product page block.\n" );
	exit( 1 );
}

$block = sprintf( "<!-- wp:marketers-delight/inline-page-block %s /-->\n", $attributes );

if ( false === file_put_contents( $outputPath, $block ) ) {
	fwrite( STDERR, "Unable to write the FluentCart product page block.\n" );
	exit( 1 );
}

fwrite( STDOUT, $outputPath . "\n" );
