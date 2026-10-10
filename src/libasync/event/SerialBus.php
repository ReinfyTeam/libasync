<?php

namespace libasync\event;

use Closure;
use libasync\utils\ClosureUtils;

/**
 * @template T
 * @implements BusInterface<T>
 */
final class SerialBus implements BusInterface {
	/** @var array<string, array<int, Closure(T):void>> */
	private array $handler = [];

	/**
	 * @param \Closure(T $topic):void $handler
	 * @return Closure():void
	 */
	public function subscribe(Closure $handler) : Closure {
		$combine = [];
		$id = spl_object_id($handler);
		foreach (ClosureUtils::parseSubscriber($handler) as $type) {
			$this->handler[$type][$id] = $handler;
			$combine[] = function() use ($id, $type) {
				unset($this->handler[$type][$id]);
			};
		}
		return static function() use ($combine) {
			foreach ($combine as $x) {
				$x();
			}
		};
	}

	/**
	 * @param T $value
	 */
	public function publish(mixed $value) : void {
		$typ = get_debug_type($value);
		if (!isset($this->handler[$typ])) {
			return;
		}
		foreach ($this->handler[$typ] as $handler) {
			$handler($value);
		}
	}

	public function hasSubscriber(mixed $value) : bool {
		return isset($this->handler[get_debug_type($value)]);
	}

	public function clear() : void {
		$this->handler = [];
	}
}
