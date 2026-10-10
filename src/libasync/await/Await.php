<?php
declare(strict_types=1);

namespace libasync\await;

use Closure;
use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ReturnType;
use Generator;
use libasync\global\GlobalAsyncRuntime;
use libasync\runtime\AsyncExecutionEnvironment;
use libasync\runtime\AsyncRuntime;
use pocketmine\utils\Utils as PMMPUtils;
use function libasync\may_drop;
use const bootstrap\PRODUCTION;

final class Await {
	private function __construct() { }

	/**
	 * @param resource $stream
	 * @return Generator<int,mixed,void,string>
	 */
	public static function stream($stream) : Generator {
		$buffer = '';
		while (!feof($stream)) {
			$read = fread($stream, 1 << 16);
			if ($read !== '') {
				$buffer .= $read;
				yield self::suspend;
			} else {
				yield from self::udelay(200);
			}
		}
		if (is_resource($stream)) {
			fclose($stream);
		}
		return $buffer;
	}
	/**
	 * Suspends the current execution and yields control back to the event loop.
	 */
	public const suspend = AwaitSignal::SIG_WAIT;
	/**
	 * Interrupts the current coroutine execution.
	 */
	public const interrupt = AwaitSignal::SIG_INTERRUPT;

	/**
	 * Converts a closure into a generator for asynchronous execution.
	 *
	 * @param Closure():mixed $f The closure to convert.
	 * @return Generator<int,mixed,mixed,mixed> The generator that yields from the closure result.
	 */
	public static function f2c(Closure $f) : Generator {
		$v = $f();
		if ($v instanceof Generator) {
			/** @var Generator<int,mixed,mixed,mixed> $v */
			return yield from $v;
		}
		return $v;
	}

	/**
	 * Traps execution and allows manual control through the given trap callback.
	 *
	 * @param Closure():bool $trap The trap callback that is executed when trapped.
	 * @return Generator<int,mixed,void,void> Yields a trap signal and executes the trap.
	 */
	public static function trap(Closure $trap) : Generator {
		yield AwaitSignal::SIG_TRAP;
		yield $trap;
	}

	/**
	 * Delays execution by the given number of seconds.
	 *
	 * @param int $sec The number of seconds to delay.
	 * @return Generator<int,mixed,void,void> The generator that yields a delay signal.
	 */
	public static function delay(int $sec) : Generator {
		yield from self::udelay($sec * 1000);
	}

	/**
	 * Delays execution by the given number of microseconds.
	 *
	 * @param int $microseconds The number of microseconds to delay.
	 * @return Generator<int,mixed,void,void> The generator that yields a delay signal.
	 */
	public static function udelay(int $microseconds) : Generator {
		yield from self::ndelay($microseconds * 1000 * 1000);
	}

	/**
	 * Delays execution by the given number of nanoseconds.
	 * Uses a busy-wait loop to achieve the delay.
	 *
	 * @param int $nanoseconds The number of nanoseconds to delay.
	 * @return Generator<int,mixed,void,void> The generator that yields a wait signal until the target time is reached.
	 */
	public static function ndelay(int $nanoseconds) : Generator {
		$targetTime = hrtime(true) + $nanoseconds;

		while (((float) hrtime(true)) < $targetTime) {
			yield AwaitSignal::SIG_WAIT;
		}
	}

	/**
	 * Executes a tick operation for a specified number of times at fixed intervals.
	 * Allows cancellation of the tick operation.
	 *
	 * @param Closure(Closure():void):void $do The callback to execute on each tick.
	 * @param int $tick The interval (in milliseconds) between each tick.
	 * @param int $times The number of ticks to execute.
	 * @return Generator<int,mixed,void,void> The generator that executes the tick operations.
	 */
	public static function tick(Closure $do, int $tick, int $times) : Generator {
		$c = true;
		$cancel = static function() use (&$c) { $c = false; };
		may_drop();

		while ($times-- > 0 && $c) {
			yield from self::udelay($tick * (1000 / 20));
			$do($cancel);
		}
	}

	/**
	 * Executes a block of code asynchronously in a separate thread.
	 * Handles any exceptions that might occur during execution.
	 *
	 * @template T of (scalar|null|\pmmp\thread\ThreadSafe|\pmmp\thread\ThreadSafeArray)
	 * @param Closure(mixed ...):T $do The closure to execute asynchronously.
	 * @param AsyncRuntime|null $runtime The runtime environment to use.
	 * @param AsyncExecutionEnvironment|null $env The execution environment to use.
	 * @return Generator<int,mixed,mixed,T|null> The result of the asynchronous execution.
	 * @generator-throw ExecutionException If execution fails.
	 */
	public static function threadify(Closure $do, ?AsyncRuntime $runtime = null, ?AsyncExecutionEnvironment $env = null) {
		$runtime ??= GlobalAsyncRuntime::gerThreadedRuntime();

		$rec = $runtime->runAsync($do, $env);

		yield AwaitSignal::SIG_NOTIFIED;
		yield static function(Closure $notifier) use ($rec) : void {
		$rec->setNotifier($notifier);
	};

		yield AwaitSignal::SIG_EXCEPTION;
		yield [$rec->getCallTrace(), $rec->getError()];

		return $rec->getResult();
	}

	/**
	 * Executes a block of code either synchronously or asynchronously based on the type of input.
	 *
	 * @param (Closure():Generator)|Generator<void,mixed,void,mixed> $block The block of code to execute.
	 * @param EventLoop|null $loop The event loop to use for async execution.
	 * @return AwaitResult The result of the execution.
	 */
	public static function do(Closure|Generator $block, ?EventLoop $loop = null) : AwaitResult {
		if (!PRODUCTION && $block instanceof Closure) {
			PMMPUtils::validateCallableSignature(new CallbackType(new ReturnType()), $block);
		}

		return new AwaitResult(
			static fn($errorHandler, $joined) => new Coroutine($block instanceof Closure ? self::f2c($block) : $block, $errorHandler, $joined),
			static fn(Coroutine $coroutine) => $coroutine->register($loop ?? GlobalAsyncRuntime::getLoop())
		);
	}
}
