<?php
/**
 * What this site offers to external assistants, detected at runtime.
 *
 * GT Performance never installs or activates the MCP Adapter, never bundles an
 * Abilities backport, and implements no protocol of its own.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Abilities;

final class Integration {
	/**
	 * The adapter release this integration was qualified against.
	 */
	public const QUALIFIED_ADAPTER = '0.6.1';

	/**
	 * @return array{abilities_api:bool,mcp_adapter:string,mcp_endpoint:string,ai_client:bool}
	 */
	public static function readiness(): array {
		return array(
			'abilities_api' => AbilitiesModule::available(),
			'mcp_adapter'   => self::adapterVersion(),
			'mcp_endpoint'  => '' === self::adapterVersion() ? '' : self::endpoint(),
			'ai_client'     => function_exists( 'wp_ai_client_prompt' ),
		);
	}

	/**
	 * Installed adapter version, or '' when it is not active.
	 */
	public static function adapterVersion(): string {
		if ( defined( 'WP_MCP_VERSION' ) ) {
			return (string) constant( 'WP_MCP_VERSION' );
		}

		return class_exists( '\WP\MCP\Core\McpAdapter' ) ? (string) constant( '\WP\MCP\Core\McpAdapter::VERSION' ) : '';
	}

	/**
	 * The plugin that loaded the MCP Adapter: its own plugin, or one that bundles
	 * it (Rank Math ships it in its vendor directory). '' when none is loaded.
	 */
	public static function adapterSource(): string {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return '';
		}
		$file    = (string) ( new \ReflectionClass( \WP\MCP\Core\McpAdapter::class ) )->getFileName();
		$plugins = wp_normalize_path( (string) WP_PLUGIN_DIR ) . '/';
		$file    = wp_normalize_path( $file );
		if ( ! str_starts_with( $file, $plugins ) ) {
			return '';
		}
		$slug = strtok( substr( $file, strlen( $plugins ) ), '/' );
		if ( ! is_string( $slug ) || 'mcp-adapter' === $slug ) {
			return '';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $basename => $data ) {
			if ( str_starts_with( $basename, $slug . '/' ) ) {
				return (string) ( $data['Name'] ?? $slug );
			}
		}

		return $slug;
	}

	/**
	 * The route of the MCP server the adapter actually registered.
	 *
	 * Asked of the adapter rather than predicted, so a site that changes the
	 * default server's route or ID gets its real endpoint. The adapter creates
	 * its servers on rest_api_init, which has not run on an admin screen, so the
	 * REST server is started first when needed. That happens only where the
	 * endpoint is shown.
	 */
	public static function endpoint(): string {
		if ( ! class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
			return '';
		}
		if ( ! did_action( 'rest_api_init' ) ) {
			rest_get_server();
		}

		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$servers = method_exists( $adapter, 'get_servers' ) ? (array) $adapter->get_servers() : array();
		$server  = $servers['mcp-adapter-default-server'] ?? reset( $servers );
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_server_route_namespace' ) || ! method_exists( $server, 'get_server_route' ) ) {
			return '';
		}

		return rest_url( trim( (string) $server->get_server_route_namespace(), '/' ) . '/' . trim( (string) $server->get_server_route(), '/' ) );
	}

	/**
	 * @return list<string>
	 */
	public static function abilities(): array {
		return array_keys( ReadAbilities::definitions() + OperateAbilities::definitions() );
	}
}
