<?php
/**
 * Settings merge semantics.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Tests\Unit;

use GTPerformance\Core\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SettingsMergeTest extends TestCase {
	/**
	 * @param array<string, mixed> $defaults Base values.
	 * @param array<string, mixed> $values   Overriding values.
	 * @return array<string, mixed>
	 */
	private function merge( array $defaults, array $values ): array {
		$method = new ReflectionMethod( Settings::class, 'merge' );

		/** @var array<string, mixed> $result */
		$result = $method->invoke( null, $defaults, $values );

		return $result;
	}

	public function test_shorter_task_list_does_not_resurrect_deselected_destructive_tasks(): void {
		$saved = array(
			'database' => array(
				'tasks' => array( 'revisions', 'auto_drafts', 'spam_comments', 'trashed_posts', 'trashed_comments' ),
			),
		);
		$submitted = array(
			'database' => array(
				'tasks' => array( 'revisions' ),
			),
		);

		$merged = $this->merge( $saved, $submitted );

		self::assertSame( array( 'revisions' ), $merged['database']['tasks'] );
	}

	public function test_associative_sections_still_deep_merge(): void {
		$defaults = array(
			'cache' => array(
				'enabled'   => true,
				'fresh_ttl' => 3600,
				'stale_ttl' => 86400,
			),
		);
		$values = array(
			'cache' => array( 'fresh_ttl' => 60 ),
		);

		$merged = $this->merge( $defaults, $values );

		self::assertTrue( $merged['cache']['enabled'] );
		self::assertSame( 60, $merged['cache']['fresh_ttl'] );
		self::assertSame( 86400, $merged['cache']['stale_ttl'] );
	}

	public function test_empty_list_clears_a_saved_list(): void {
		$saved     = array( 'cache' => array( 'bypass_paths' => array( '/a/', '/b/' ) ) );
		$submitted = array( 'cache' => array( 'bypass_paths' => array() ) );

		$merged = $this->merge( $saved, $submitted );

		self::assertSame( array(), $merged['cache']['bypass_paths'] );
	}

	public function test_unknown_and_removed_keys_are_discarded(): void {
		$defaults = array(
			'media' => array(
				'lazy_load' => true,
			),
		);
		$values = array(
			'media' => array(
				'lazy_load'          => false,
				'self_host_gravatar' => true,
			),
			'rum' => array( 'enabled' => true ),
		);

		$merged = $this->merge( $defaults, $values );

		self::assertSame( array( 'media' => array( 'lazy_load' => false ) ), $merged );
	}
}
