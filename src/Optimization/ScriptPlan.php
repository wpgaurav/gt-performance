<?php
/**
 * Decide, per printed script, whether it can be deferred or delayed safely.
 *
 * WordPress knows what HTML cannot: each script's dependencies and the inline
 * code printed before or after it. A deferred or delayed script runs later
 * than its position in the document, so:
 *
 * - inline code printed after a script expects it to have run already;
 * - a script that others depend on must not run after them.
 *
 * Deferral follows core's own rule: a handle is deferred only if it has no
 * inline "after" code and every handle depending on it can be deferred too.
 * Delay applies to a selected handle together with everything depending on
 * it, or to none of that chain when any member is excluded or has inline
 * "after" code. Scripts WordPress did not register have unknown ordering, so
 * they are delayed only when selected explicitly and never deferred.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Optimization;

final class ScriptPlan {
	public const DEFER = 'defer';
	public const DELAY = 'delay';
	public const KEEP  = 'keep';

	/**
	 * @param array<string, array{src:string,deps:list<string>,after:bool}> $scripts Printed registered scripts by handle.
	 * @param callable(string):bool                                         $excluded   Whether a source must be left alone.
	 * @param callable(string):bool                                         $selected   Whether a source matches a delay pattern.
	 * @return array<string, array{action:string,reason:string}>
	 */
	public static function build( array $scripts, bool $defer, bool $delay, callable $excluded, callable $selected ): array {
		$dependents = array();
		foreach ( $scripts as $handle => $script ) {
			foreach ( $script['deps'] as $dep ) {
				if ( isset( $scripts[ $dep ] ) ) {
					$dependents[ $dep ][] = $handle;
				}
			}
		}

		$plan = array();
		foreach ( $scripts as $handle => $script ) {
			$plan[ $handle ] = array(
				'action' => self::KEEP,
				'reason' => $excluded( $script['src'] ) ? 'excluded' : 'unchanged',
			);
		}

		if ( $delay ) {
			foreach ( $scripts as $handle => $script ) {
				if ( self::KEEP !== $plan[ $handle ]['action'] || ! $selected( $script['src'] ) || $excluded( $script['src'] ) ) {
					continue;
				}
				$chain   = self::closure( (string) $handle, $dependents );
				$blocker = '';
				foreach ( $chain as $member ) {
					if ( $excluded( $scripts[ $member ]['src'] ) ) {
						$blocker = $member . ' is excluded';
					} elseif ( $scripts[ $member ]['after'] ) {
						$blocker = $member . ' has inline code that runs right after it';
					}
					if ( '' !== $blocker ) {
						break;
					}
				}
				foreach ( $chain as $member ) {
					if ( '' === $blocker ) {
						$plan[ $member ] = array(
							'action' => self::DELAY,
							'reason' => $member === $handle ? 'selected for delay' : 'depends on delayed ' . $handle,
						);
					} elseif ( self::DELAY !== $plan[ $member ]['action'] ) {
						$plan[ $member ] = array(
							'action' => self::KEEP,
							'reason' => 'delay of ' . $handle . ' skipped: ' . $blocker,
						);
					}
				}
			}
		}

		if ( $defer ) {
			$memo = array();
			foreach ( array_keys( $scripts ) as $handle ) {
				if ( self::KEEP !== $plan[ $handle ]['action'] || 'unchanged' !== $plan[ $handle ]['reason'] ) {
					continue;
				}
				$blocker = self::deferBlocker( (string) $handle, $scripts, $dependents, $plan, $excluded, $memo );
				$plan[ $handle ] = '' === $blocker
					? array(
						'action' => self::DEFER,
						'reason' => 'no inline code or dependent needs it earlier',
					)
					: array(
						'action' => self::KEEP,
						'reason' => 'not deferred: ' . $blocker,
					);
			}
		}

		return $plan;
	}

	/**
	 * @param array<string, list<string>> $dependents Reverse edges.
	 * @return list<string> The handle and every handle depending on it, transitively.
	 */
	private static function closure( string $handle, array $dependents ): array {
		$seen  = array( $handle => true );
		$stack = array( $handle );
		while ( array() !== $stack ) {
			foreach ( $dependents[ array_pop( $stack ) ] ?? array() as $child ) {
				if ( ! isset( $seen[ $child ] ) ) {
					$seen[ $child ] = true;
					$stack[]        = $child;
				}
			}
		}

		return array_keys( $seen );
	}

	/**
	 * Why a handle cannot be deferred, or '' when it can.
	 *
	 * @param array<string, array{src:string,deps:list<string>,after:bool}> $scripts    Scripts.
	 * @param array<string, list<string>>                                   $dependents Reverse edges.
	 * @param array<string, array{action:string,reason:string}>             $plan       Plan so far.
	 * @param callable(string):bool                                         $excluded   Exclusion test.
	 * @param array<string, string>                                         $memo       Results by handle.
	 */
	private static function deferBlocker( string $handle, array $scripts, array $dependents, array $plan, callable $excluded, array &$memo ): string {
		if ( isset( $memo[ $handle ] ) ) {
			return $memo[ $handle ];
		}
		$memo[ $handle ] = 'dependency cycle';
		$blocker         = '';
		if ( $excluded( $scripts[ $handle ]['src'] ) ) {
			$blocker = $handle . ' is excluded';
		} elseif ( $scripts[ $handle ]['after'] ) {
			$blocker = $handle . ' has inline code that runs right after it';
		} else {
			foreach ( $dependents[ $handle ] ?? array() as $child ) {
				if ( self::DELAY === $plan[ $child ]['action'] ) {
					continue;
				}
				$childBlocker = self::deferBlocker( $child, $scripts, $dependents, $plan, $excluded, $memo );
				if ( '' !== $childBlocker ) {
					$blocker = 'dependent ' . $child . ' cannot be deferred (' . $childBlocker . ')';
					break;
				}
			}
		}
		$memo[ $handle ] = $blocker;

		return $blocker;
	}
}
