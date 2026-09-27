<?php
/**
 * Registers GT Performance abilities with the WordPress Abilities API (6.9+).
 *
 * Registration only adds two hooks; the registry fires them lazily the first
 * time something (the core REST API, the MCP Adapter, or code) asks for
 * abilities, so ordinary page views do no extra work.
 *
 * Abilities are always registered so administrators can inspect them, but
 * they are exposed to MCP and the REST API only while agent access is on, and
 * Permissions::read() denies every call while it is off.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Abilities;

use GTPerformance\Contracts\Module;

final class AbilitiesModule implements Module {
	public const CATEGORY = 'gt-performance';

	public static function available(): bool {
		return function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' );
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
	}

	public function registerCategory(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'GT Performance', 'gt-performance' ),
				'description' => __( 'Page cache, queue, warming, and health evidence from GT Performance.', 'gt-performance' ),
			)
		);
	}

	public function registerAbilities(): void {
		foreach ( self::arguments() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Complete registration arguments, category, permission and exposure included.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function arguments(): array {
		$definitions = array_map( static fn ( array $args ): array => $args + array( 'gtperf_mode' => 'read' ), ReadAbilities::definitions() ) + OperateAbilities::definitions();
		$arguments   = array();
		foreach ( $definitions as $name => $args ) {
			$operate = 'operate' === $args['gtperf_mode'];
			unset( $args['gtperf_mode'] );
			$exposed            = $operate ? 'operate' === Permissions::mode() : Permissions::exposed();
			$arguments[ $name ] = $args + array(
				'category'            => self::CATEGORY,
				'permission_callback' => array( Permissions::class, $operate ? 'operate' : 'read' ),
				'meta'                => array(
					// Operations change cache state or record proposals, but deleted
					// cache entries rebuild and request IDs make replays harmless.
					'annotations'  => array(
						'readonly'    => ! $operate,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => $exposed,
					'mcp'          => array(
						'public' => $exposed,
						'type'   => 'tool',
					),
				),
			);
		}

		return $arguments;
	}
}
