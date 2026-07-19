<?php
/**
 * Deterministic commerce policy coverage audit.
 *
 * @package GTPerformance
 */

declare(strict_types=1);

namespace GTPerformance\Commerce;

use GTPerformance\Cache\Eligibility;
use GTPerformance\Cache\RequestContext;

final class PolicyAudit {
	/**
	 * @param array{paths:list<string>,cookies:list<string>,query:list<string>} $requirements Adapter requirements.
	 * @param array<string, mixed>                                             $cache        Compiled cache policy.
	 * @return list<array<string, bool|string>>
	 */
	public function audit( string $adapterId, array $requirements, array $cache ): array {
		$checks = array();
		foreach ( $requirements['paths'] as $path ) {
			$request  = new RequestContext( 'GET', 'https', 'example.test', $path, array(), array(), array(), '' );
			$decision = ( new Eligibility() )->decide( $request, $cache );
			$checks[] = $this->result( $adapterId, 'path', $path, $decision->cacheable, $decision->reason );
		}

		foreach ( $requirements['cookies'] as $cookie ) {
			$request  = new RequestContext( 'GET', 'https', 'example.test', '/', array(), array( $cookie . 'audit' => '1' ), array(), '' );
			$decision = ( new Eligibility() )->decide( $request, $cache );
			$checks[] = $this->result( $adapterId, 'cookie', $cookie, $decision->cacheable, $decision->reason );
		}

		foreach ( $requirements['query'] as $parameter ) {
			$request  = new RequestContext( 'GET', 'https', 'example.test', '/', array( $parameter => 'audit' ), array(), array(), '' );
			$decision = ( new Eligibility() )->decide( $request, $cache );
			$checks[] = $this->result( $adapterId, 'query', $parameter, $decision->cacheable, $decision->reason );
		}

		return $checks;
	}

	/**
	 * @return array<string, bool|string>
	 */
	private function result( string $adapterId, string $kind, string $value, bool $cacheable, string $reason ): array {
		return array(
			'adapter' => $adapterId,
			'kind'    => $kind,
			'value'   => $value,
			'status'  => $cacheable ? 'fail' : 'pass',
			'reason'  => $reason,
			'safe'    => ! $cacheable,
		);
	}
}
