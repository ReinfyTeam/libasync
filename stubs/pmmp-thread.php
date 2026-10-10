<?php

namespace pmmp\thread;

class Thread {
	public static function getCurrentThreadId(): int {}
}

class ThreadSafe {
	/**
	 * @template TResult
	 * @param callable(): TResult $callback
	 * @return TResult
	 */
	public function synchronized(callable $callback): mixed {}

}

/**
 * @template TKey of int|string
 * @template TValue
 * @implements \IteratorAggregate<TKey, TValue>
 * @implements \ArrayAccess<TKey, TValue>
 */
class ThreadSafeArray extends ThreadSafe implements \IteratorAggregate, \ArrayAccess, \Countable {
	/** @param array<TKey, TValue> $values @return static<TKey, TValue> */
	public static function fromArray(array $values): static {}
	/** @return TValue */
	public function pop(): mixed {}

	/** @return TValue */
	public function shift(): mixed {}

	/** @return \Traversable<TKey, TValue> */
	public function getIterator(): \Traversable {}

	/** @param TKey $offset @return TValue|null */
	public function offsetGet(mixed $offset): mixed {}

	/** @param TKey|null $offset @param TValue $value */
	public function offsetSet(mixed $offset, mixed $value): void {}

	/** @param TKey $offset */
	public function offsetExists(mixed $offset): bool {}

	/** @param TKey $offset */
	public function offsetUnset(mixed $offset): void {}

	public function count(): int {}
}
