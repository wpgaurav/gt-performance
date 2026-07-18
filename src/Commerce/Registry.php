<?php
/**
 * Active commerce adapter registry.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

use GTPerformance\Core\Settings;

final class Registry {
	/**
	 * @var list<CommerceAdapter>
	 */
	private array $adapters;

	public function __construct() {
		$this->adapters = array(
			new FluentCartAdapter(),
			new EddAdapter(),
			new WooCommerceAdapter(),
		);
	}

	/**
	 * @return list<CommerceAdapter>
	 */
	public function active(): array {
		return array_values(
			array_filter(
				$this->adapters,
				static fn ( CommerceAdapter $adapter ): bool =>
					(bool) Settings::get( 'commerce.' . $adapter->id(), true ) && $adapter->active()
			)
		);
	}

	/**
	 * @return array{paths:list<string>,cookies:list<string>,query:list<string>}
	 */
	public function policy(): array {
		$policy = array(
			'paths'   => array(),
			'cookies' => array(),
			'query'   => array(),
		);

		foreach ( $this->active() as $adapter ) {
			$policy['paths']   = array_merge( $policy['paths'], $adapter->bypassPaths() );
			$policy['cookies'] = array_merge( $policy['cookies'], $adapter->bypassCookies() );
			$policy['query']   = array_merge( $policy['query'], $adapter->bypassQueryParameters() );
		}

		foreach ( $policy as $key => $values ) {
			$policy[ $key ] = array_values( array_unique( array_filter( $values ) ) );
		}

		return $policy;
	}
}
