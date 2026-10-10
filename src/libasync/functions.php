<?php

namespace libasync {

	use Closure;
	use Generator;
	use libasync\await\Await;
	use libasync\await\AwaitResult;
	use libasync\await\Coroutine;
	use libasync\await\EventLoop;
	use libasync\global\GlobalAsyncRuntime;
	use libasync\runtime\AsyncExecutionEnvironment;
	use libasync\runtime\AsyncRuntime;
	use libasync\utils\ClosureUtils;
	use pocketmine\player\Player;

	/**
	 * Spawns an asynchronous task.
	 *
	 * All async results must be retrieved using `yield from`.
	 * By default, if no loop is provided, {@see GlobalAsyncRuntime::getLoop()}.
	 * Circular references between `$block` and `$loop` are not allowed.
	 *
	 * # Examples
	 *
	 * ```
	 * $result = yield from async(static function() {
	 *     // Do async work and return a value
	 *     return 42;
	 * });
	 * // $result will be 42
	 * ```
	 *
	 * @param Closure():Generator|Generator $block The closure or generator to run asynchronously.
	 * @param EventLoop|null $loop Optional event loop to attach the task to.
	 * @return AwaitResult Async result wrapper.
	 * @throws \InvalidArgumentException if $block holds a cyclic reference to $loop
	 */
	function async(Closure|Generator $block, ?EventLoop $loop = null) : AwaitResult {
		if ($block instanceof Closure && $loop !== null) {
			ClosureUtils::noCyclic($block, $loop);
		}
		return Await::do($block, $loop);
	}

	/**
	 * Executes a closure in a separate thread or process (depends on the {@see $runtime}, for default, {@see GlobalAsyncRuntime::gerThreadedRuntime()}).
	 *
	 * **Only scalar values, arrays, or other serializable data can be returned**,
	 * as objects or resources cannot be passed between threads or processes.
	 * **Passing non-serializable values is undefined behavior and may result in
	 * inconsistent or broken execution.**
	 *
	 * # Type Parameters
	 *
	 * - `T`: The return type of the closure (must be serializable: scalar, array of scalars, etc.).
	 *
	 * # Examples
	 *
	 * ```
	 * $result = yield from thread(fn() => 42);
	 * // $result would be 42
	 *
	 * $arrayResult = yield from thread(fn() => ['a' => 1, 'b' => 2]);
	 * // $arrayResult would be ['a' => 1, 'b' => 2]
	 * ```
	 *
	 * @template T of (scalar|null|\pmmp\thread\ThreadSafe|\pmmp\thread\ThreadSafeArray)
	 * @param Closure():T $do The closure to run in a separate thread.
	 * @param AsyncRuntime|null $runtime Optional async runtime to execute on.
	 * @param AsyncExecutionEnvironment|null $env Optional execution environment.
	 * @return Generator<int,mixed,mixed,T|null> The asynchronous execution result.
	 * @generator-throw ExecutionException If execution fails.
	 */
	function thread(Closure $do, ?AsyncRuntime $runtime = null, ?AsyncExecutionEnvironment $env = null) {
		return Await::threadify($do, $runtime, $env);
	}

	/**
	 * Registers a trap callback for the currently running coroutine.
	 *
	 * Traps are executed every time the coroutine is resumed.
	 * Multiple traps are executed in LIFO order (last added, first executed).
	 * If a trap returns `false`, the coroutine is interrupted immediately.
	 *
	 * This mechanism is useful for workarounds when dealing with non-async code
	 * or external conditions that need to be checked before coroutine continues.
	 *
	 * # Examples
	 *
	 * ```
	 * trap(static fn() => $player->isOnline()); // coroutine will pause if player is offline
	 *
	 * trap(static fn() {
	 *     if (!resourceAvailable()) return false; // interrupts coroutine
	 * });
	 * ```
	 *
	 * @param Closure():bool $c Closure that returns a boolean; returning `false` interrupts the coroutine.
	 * @throws \RuntimeException If no coroutine is running in this context.
	 */
	function trap(Closure $c) : void {
		if (Coroutine::$RUNNING === null) {
			throw new \RuntimeException("no coroutine running in this context");
		}
		Coroutine::$RUNNING->addTrap($c);
	}

	/**
	 * Registers a deferred callback for the currently running coroutine.
	 *
	 * Deferred closures are executed **in FIFO order** when the coroutine exits.
	 * That is, closures registered first are executed first.
	 *
	 * This is useful for cleanup tasks, resource release, or any code that
	 * must run when the coroutine finishes.
	 *
	 * # Examples
	 *
	 * ```
	 * defer(static fn() => echo "first cleanup\n");
	 * defer(static fn() => echo "second cleanup\n");
	 * // When the coroutine exits, prints:
	 * // first cleanup
	 * // second cleanup
	 * ```
	 *
	 * @param Closure():void $c The closure to defer.
	 * @return void
	 * @throws \RuntimeException If no coroutine is running in this context.
	 */
	function defer(Closure $c) : void {
		if (Coroutine::$RUNNING === null) {
			throw new \RuntimeException("no coroutine running in this context");
		}
		Coroutine::$RUNNING->addDefer($c);
	}

	/**
	 * Marks the currently running coroutine as *joined*.
	 *
	 * A joined coroutine is guaranteed to continue running until its task completes,
	 * even during shutdown. The runtime will wait for it to finish before exiting.
	 *
	 * # Examples
	 *
	 * ```
	 * async(function() {
	 *     joined(); // ensure this coroutine runs to completion
	 *     // do important cleanup or final task
	 * });
	 * ```
	 *
	 * @return void
	 * @throws \RuntimeException If no coroutine is running in this context.
	 */
	function joined() : void {
		if (Coroutine::$RUNNING === null) {
			throw new \RuntimeException("no coroutine running in this context");
		}
		Coroutine::$joinedCoroutine[spl_object_id(Coroutine::$RUNNING)] = Coroutine::$RUNNING;
	}

	/**
	 * Marks the currently running coroutine as *may_drop*.
	 *
	 * Coroutines marked with `may_drop` can be immediately dropped during shutdown,
	 * without guaranteeing completion of their tasks. Useful for tasks that are
	 * non-critical or disposable.
	 *
	 * # Examples
	 *
	 * ```
	 * async(function() {
	 *     may_drop(); // coroutine may be terminated if shutdown occurs
	 *     // do non-critical work
	 * });
	 * ```
	 *
	 * @return void
	 * @throws \RuntimeException If no coroutine is running in this context.
	 */
	function may_drop() : void {
		if (Coroutine::$RUNNING === null) {
			throw new \RuntimeException("no coroutine running in this context");
		}
		unset(Coroutine::$joinedCoroutine[spl_object_id(Coroutine::$RUNNING)]);
	}

	if (class_exists(\pocketmine\player\Player::class)) {
		/**
		 * Registers a trap that triggers while the player is online.
		 *
		 * Useful for automatically handling player-related async tasks.
		 *
		 * @param Player $player The player object to monitor.
		 */
		function trap_online(Player $player) : void {
			trap(static fn() => $player->isOnline() && !$player->isClosed());
		}
	}

	/**
	 * Sets a timeout for the currently running coroutine's task.
	 *
	 * # Panics
	 *
	 * Throws a RuntimeException if no coroutine is running or if the task is unavailable.
	 *
	 * @param float $second Timeout in seconds.
	 * @return void
	 */
	function timeout(float $second) : void {
		if (Coroutine::$RUNNING === null) {
			throw new \RuntimeException("no coroutine running in this context");
		}
		$task = Coroutine::$RUNNING->getTask();
		if ($task === null) {
			throw new \RuntimeException("coroutine task no longer available");
		}
		$task->setTimeout($second * 1000);
	}

	/**
	 * Delays execution of a closure by a number of ticks.
	 *
	 * # Notes
	 *
	 * The function guarantees that the closure will not be executed before the specified
	 * number of ticks has passed. Actual execution may be later, depending on system load,
	 * event loop polling frequency, and other factors.
	 *
	 * # Example
	 *
	 * ```
	 * delay(static fn() => echo "hello", 10); // will run at least after 10 ticks
	 * ```
	 *
	 * @param Closure():mixed $func The closure to delay.
	 * @param int $tick Number of ticks to delay (1 tick = 50ms).
	 * @return AwaitResult The async result wrapper.
	 */
	function delay(Closure $func, int $tick = 0) : AwaitResult {
		return async(function() use ($func, $tick) {
			yield from Await::udelay($tick * 50);
			yield from Await::f2c($func);
		});
	}
}
