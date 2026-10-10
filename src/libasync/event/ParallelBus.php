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
	/** @var ThreadSafeArray<int, ThreadSafe|string> */
	private ThreadSafeArray $buffer;
	/** @var ThreadSafeArray<string, ThreadSafeArray<int, Closure(T):void>> */
	private ThreadSafeArray $handler;

	public function __construct() {
		$this->buffer = new ThreadSafeArray();
		$this->handler = new ThreadSafeArray();
	}

	/** @return ThreadSafeArray<int, Closure(T):void> */
	private function createHandlerBucket(): ThreadSafeArray {
		return ThreadSafeArray::fromArray([]);
	}

	public function process() : void {
		/** @var array<int, array{0: Closure(T):void, 1:T}> $pending */
		$pending = [];
		$this->buffer->synchronized(function() use (&$pending) : void {
			$this->handler->synchronized(function() use (&$pending) : void {
				while ($this->buffer->count() > 0) {
					$buf = $this->buffer->pop();
					$val = Utils::smartDeserialize($buf);
					foreach ($this->handler[get_debug_type($val)] ?? [] as $handler) {
						if (!$handler instanceof Closure) {
							throw new \UnexpectedValueException('Parallel bus handler has an invalid type');
						}
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
			/** @var list<Closure():void> $combine */
			$combine = [];
			$id = spl_object_id($handler);
			$handlerMap = $this->handler;
			foreach (ClosureUtils::parseSubscriber($handler) as $type) {
				if (!isset($this->handler[$type])) {
					$this->handler[$type] = $this->createHandlerBucket();
				}
				$this->handler[$type][$id] = $handler;
				$combine[] = static function() use ($handlerMap, $id, $type): void {
					unset($handlerMap[$type][$id]);
				};
			}
			return static function() use ($combine): void {
				foreach ($combine as $x) {
					$x();
				}
			};
		});
	}

	public function publish(mixed $value) : void {
		$this->buffer->synchronized(function() use ($value) : void {
			$encoded = Utils::smartSerialize($value);
			if (!$encoded instanceof ThreadSafe && !is_string($encoded)) {
				throw new \UnexpectedValueException('Unable to serialize parallel bus payload');
			}
			$this->buffer[] = $encoded;
		});
	}

	/** @param T $value */
	public function hasSubscriber(mixed $value) : bool {
		return $this->synchronized(fn() => isset($this->handler[get_debug_type($value)]));
	}

	public function clear() : void {
		$this->handler = new ThreadSafeArray();
	}
}
