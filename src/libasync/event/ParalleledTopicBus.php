<?php

namespace libasync\event;

use Closure;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

class ParalleledTopicBus extends ThreadSafe {
	/** @var ThreadSafeArray<int, string> */
	private ThreadSafeArray $buffer;
	/** @var ThreadSafeArray<string, ThreadSafeArray<int, Closure(mixed...):void>> */
	private ThreadSafeArray $handler;

	public function __construct() {
		$this->buffer = new ThreadSafeArray();
		$this->handler = new ThreadSafeArray();
	}

	/** @return ThreadSafeArray<int, Closure(mixed...):void> */
	private function createHandlerBucket(): ThreadSafeArray {
		return ThreadSafeArray::fromArray([]);
	}

	public function process() : void {
		$pending = [];
		$this->buffer->synchronized(function() use (&$pending) : void {
			$this->handler->synchronized(function() use (&$pending) : void {
				while ($this->buffer->count() > 0) {
					$buf = $this->buffer->pop();
					if (!is_string($buf)) {
						throw new \UnexpectedValueException('Topic buffer did not contain a serialized string');
					}
					$decoded = igbinary_unserialize($buf);
					if (!is_array($decoded) || count($decoded) !== 2 || !is_string($decoded[0]) || !is_array($decoded[1])) {
						throw new \UnexpectedValueException('Serialized topic payload has an invalid shape');
					}
					/** @var string $topic */
					$topic = $decoded[0];
					/** @var array<int, mixed> $val */
					$val = $decoded[1];
					foreach ($this->handler[$topic] ?? [] as $handler) {
						$pending[] = [$handler, $val];
					}
				}
			});
		});
		foreach ($pending as [$handler, $val]) {
			$handler(...$val);
		}
	}

	/** @param Closure(mixed...):void $handler */
	public function subscribe(string $topic, Closure $handler) : void {
		$this->handler->synchronized(function() use ($topic, $handler) : void {
			if (!isset($this->handler[$topic])) {
				$this->handler[$topic] = $this->createHandlerBucket();
			}
			$this->handler[$topic][] = $handler;
		});
	}

	/** @param mixed ...$value */
	public function publish(string $topic, ...$value) : void {
		$this->buffer->synchronized(function() use ($topic, $value) : void {
			$encoded = igbinary_serialize([$topic, $value]);
			if (!is_string($encoded)) {
				throw new \UnexpectedValueException('Unable to serialize topic payload');
			}
			$this->buffer[] = $encoded;
		});
	}
}
