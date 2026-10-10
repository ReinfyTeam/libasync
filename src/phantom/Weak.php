<?php

namespace phantom;

use WeakReference;

/**
 * Weak facade around an object, forwarding operations only if the target is alive.
 *
 * Internally backed by {@see WeakReference}. All property access, method calls,
 * invocation, string casting, and debug output are forwarded to the underlying
 * object.
 *
 * If the object has already been garbage-collected, all interactions will throw
 * a {@see LifetimeError}.
 *
 * This class **does not** extend object lifetime and **does not** implement manual
 * GC. It merely serves as a checked-lifetime wrapper and research tool.
 *
 * # Property/method forwarding
 *
 * ```
 *   class Foo { public int $value = 42; public function hi() { return "hi"; } }
 *   $foo = new Foo();
 *   $weak = weak($foo);
 *   echo $weak->value;    // 42
 *   echo $weak->hi();     // "hi"
 * ```
 *
 * # Access raw referenced object (when explicit typing is preferred)
 *
 * ```
 *   /** @var Foo $bar *\/
 *   $bar = $weak->_ref();
 *   echo $bar->value;
 * ```
 *
 * # Invocation forwarding
 *
 * ```
 *   class Handler { public function __invoke($x) { return $x * 2; } }
 *   $h = weak(new Handler());
 *   echo $h(5); // 10
 * ```
 *
 * # Private property access (supported via closure rebinding)
 *
 * ```
 *   class Secret { private int $x = 99; private function get() { return $this->x; } }
 *   $s = weak(new Secret());
 *   echo $s->get(); // 99
 * ```
 *
 * # LifetimeError after GC
 *
 * ```
 *   $obj = new Foo();
 *   $w = weak($obj);
 *   unset($obj);
 *   gc_collect_cycles();
 *   $w->value; // throws LifetimeError
 * ```
 *
 * # Detecting liveness manually
 *
 * ```
 *   try {
 *       $alive = $w->_ref();
 *   } catch (LifetimeError) {
 *       // object is dead
 *   }
 * ```
 *
 * Serialization, static reconstruction, and wakeup are intentionally forbidden.
 *
 * The following methods always throw BadMethodCallException:
 * - __serialize
 * - __unserialize
 * - __sleep
 * - __wakeup
 * - __set_state
 * - __callStatic
 *
 * @template T of object
 */
final readonly class Weak {
	/** @var \WeakReference<T> */
	private \WeakReference $____________________ref;

	/** @param T $obj */
	public function __construct(object $obj) {
		$this->____________________ref = WeakReference::create($obj);
	}

	/** @param array<int, mixed> $arguments */
	public static function __callStatic(string $name, array $arguments) : mixed {
		throw new \BadMethodCallException("you should not call this at all time.",);
	}

	/** @param array<string, mixed> $an_array */
	public static function __set_state(array $an_array) : object {
		throw new \BadMethodCallException("you should not call this at all time.",);
	}

	/**
	 * @return T
	 */
	public function _ref() {
		return $this->____________________ref->get() ?? throw new LifetimeError("source object doesn't live long enough");
	}

	/** @param array<int, mixed> $arguments */
	public function __call(string $name, array $arguments) : mixed {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
		return (fn() => $this->$name(...$arguments))->call($obj);
	}

	public function __clone() : void {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
	}

	/** @return array<int, mixed> */
	public function __debugInfo() : array {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
		return [$obj];
	}

	public function &__get(string $name) : mixed {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
		$call = (function &() use ($name) {
			return $this->$name;
		})->call($obj);
		return $call;
	}

	public function __set(string $name, mixed $value) : void {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
		(fn() => $this->$name = $value)->call($obj);
	}

	/** @param array<int, mixed> $args */
	public function __invoke(...$args) : mixed {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
		if (!is_callable($obj)) {
			throw new \BadMethodCallException("source object is not invokable");
		}
		return (fn() => $this(...$args))->call($obj);
	}

	public function __isset(string $name) : bool {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
		return (fn() => isset($this->$name))->call($obj);
	}

	/** @return array<string, mixed> */
	public function __serialize() : array {
		throw new \BadMethodCallException("you should not call this at all time.",);
	}

	/** @return array<int, string> */
	public function __sleep() : array {
		throw new \BadMethodCallException("you should not call this at all time.",);
	}

	public function __toString() : string {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
		if (!$obj instanceof \Stringable) {
			throw new \BadMethodCallException("source object is not stringable");
		}
		return (string) $obj;
	}

	/** @param array<string, mixed> $data */
	public function __unserialize(array $data) : void {
		throw new \BadMethodCallException("you should not call this at all time.",);
	}

	public function __unset(string $name) : void {
		$obj = $this->____________________ref->get();
		if ($obj === null) {
			throw new LifetimeError("source object doesn't live long enough");
		}
		(function() use ($name) : void { unset($this->$name); })->call($obj);
	}

	public function __wakeup() : void {
		throw new \BadMethodCallException("you should not call this at all time.",);
	}
}
