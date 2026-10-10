<?php

namespace libasync\event;

use Closure;
use libasync\utils\ClosureUtils;
use libasync\utils\Utils;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

/**
 * @template T
 * @implements BusInterface<T>
 */
class ParallelBus extends ThreadSafe implements BusInterface {
	/** @var ThreadSafeArray<int, string> */
	private ThreadSafeArray $buffer;
	/** @var ThreadSafeArray<string, ThreadSafeArray<int, Closure(T):void>> */
	private ThreadSafeArray $handler;

	public function __construct() {
		$this->buffer = new ThreadSafeArray();
		$this->handler = new ThreadSafeArray();
	}

	public function process() : void {
		$pending = [];
		$this->buffer->synchronized(function() use (&$pending) : void {
			$this->handler->synchronized(function() use (&$pending) : void {
				while ($this->buffer->count() > 0) {
					$buf = $this->buffer->pop();
					$val = Utils::smartDeserialize($buf);
					foreach ($this->handler[get_debug_type($val)] ?? [] as $handler) {
						$pending[] = [$handler, $val];
					}
				}
			});
		});
		foreach ($pending as [$handler, $val]) {
			$handler($val);
		}
	}

	/**
	 * @param \Closure(T $topic):void $handler
	 * @return Closure():void
	 */
	public function subscribe(Closure $handler) : Closure {
		ClosureUtils::validateThreadSafety($handler);
		return $this->handler->synchronized(function() use ($handler) : Closure {
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
		});
	}

	public function publish(mixed $value) : void {
		$this->buffer->synchronized(fn() => $this->buffer[] = Utils::smartSerialize($value));
	}

	/** @param T $value */
	public function hasSubscriber(mixed $value) : bool {
		return $this->synchronized(fn() => isset($this->handler[get_debug_type($value)]));
	}

	public function clear() : void {
		$this->handler = new ThreadSafeArray();
	}
}
