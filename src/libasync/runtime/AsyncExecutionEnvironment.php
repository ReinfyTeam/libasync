<?php

namespace libasync\runtime;

use Closure;
use libasync\utils\ClosureUtils;
use pmmp\thread\ThreadSafe;
use pocketmine\utils\Utils;
use Throwable;

/**
 * AsyncExecutionEnvironment provides a thread-safe execution context for asynchronous
 * operations, managing argument creation and destruction automatically.
 *
 * Each environment has:
 * - `$argsCtor`: a closure to create resources/arguments before execution
 * - `$argsDtor`: a closure to destroy resources/arguments after execution
 *
 * The `run()` method ensures resources are cleaned up even if the executed closure
 * throws an exception.
 *
 * Example usage:
 * ```php
 * use libasync\runtime\AsyncExecutionEnvironment;
 *
 * $env = new AsyncExecutionEnvironment(
 *     argsCtor: fn() => [new Resource()],
 *     argsDtor: fn($res) => $res->close()
 * );
 *
 * $result = $env->run(function($resource, $injected) {
 *     // do something with $resource
 *     return $resource->compute($injected);
 * }, [$someInjectedValue]);
 * ```
 *
 * Factory helper:
 * ```php
 * $simpleEnv = AsyncExecutionEnvironment::simple(
 *     fn() => new Resource(),
 *     fn($res) => $res->close()
 * );
 * ```
 *
 * Thread-safety:
 * This class extends `pmmp\thread\ThreadSafe`, so it can be safely shared
 * across PMMP threads.
 */
class AsyncExecutionEnvironment extends ThreadSafe {
	/**
	 * @param Closure():array<int, mixed> $argsCtor Closure that returns an array of arguments/resources
	 * @param Closure(mixed...):void $argsDtor Closure to destroy the arguments/resources
	 */
	public function __construct(
		public readonly Closure $argsCtor,
		public readonly Closure $argsDtor,
	) {
		if (!\constant('bootstrap\\PRODUCTION')) {
			Utils::validateCallableSignature(static fn() : array => [], $this->argsCtor);
			ClosureUtils::validateStatic($this->argsDtor);
		}
	}

	/**
	 * Create arguments/resources for execution.
	 *
	 * @return array<int, mixed> The array of arguments
	 */
	public function createArgs() : array { return ($this->argsCtor)(); }

	/**
	 * Destroy previously created arguments/resources.
	 *
	 * @param array<int, mixed> $args Arguments/resources returned from `createArgs()`
	 */
	public function destroyArgs(array $args) : void { ($this->argsDtor)(...$args); }

	/**
	 * Run a closure inside this execution environment.
	 *
	 * Automatically creates and destroys resources.
	 *
	 * @param Closure(mixed ...):mixed $f Closure to execute. Arguments created by argsCtor are prepended.
	 * @param array<int, mixed> $injected Additional arguments to pass to $f
	 * @return mixed The return value of the closure
	 * @throws Throwable Re-throws any exception thrown by the closure
	 */
	public function run(Closure $f, array $injected = []) {
		$args = $this->createArgs();
		try {
			$result = $f(...$args, ...$injected);
			$this->destroyArgs($args);
			return $result;
		} catch (Throwable $thr) {
			$this->destroyArgs($args);
			throw $thr;
		}
	}

	/**
	 * Helper to create a simple environment with a single resource.
	 *
	 * @param Closure():mixed $new Closure to create a resource
	 * @param Closure(mixed):void $free Closure to free the resource
	 * @return self
	 */
	public static function simple(Closure $new, Closure $free) : self {
		return new AsyncExecutionEnvironment(
			static fn() : array => [$new()],
			static function(mixed ...$args) use ($free) : void {
				foreach ($args as $arg) {
					$free($arg);
				}
			},
		);
	}
}
