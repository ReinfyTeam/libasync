<?php

namespace libasync\runtime;

use Generator;
use libasync\await\AwaitSignal;
use libasync\exception\ExecutionExceptionWrapper;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;

/**
 * AsyncExecutionReceipt represents the result of an asynchronous execution.
 *
 * It can safely hold thread-safe or igbinary-serializable values across threads
 * and provides:
 * - Storage for result or exception
 * - Finished-state tracking
 * - Optional notifier callback
 * - Coroutine-friendly waiting via `yieldWait()`
 * - Call trace storage for debugging
 *
 * @template T of (scalar|null|ThreadSafe|ThreadSafeArray)
 * T must be thread-safe or igbinary-serializable
 *
 * # Basic usage
 *
 * ```
 * $receipt = new AsyncExecutionReceipt();
 *
 * // Set a result from async execution
 * $receipt->setResult(42);
 *
 * // Wait in a coroutine
 * foreach ($receipt->yieldWait() as $_) {
 *     // coroutine can yield until finished
 * }
 *
 * $result = $receipt->getResult(); // 42
 * ```
 *
 * # Notifier callback
 * ```
 * $receipt->setNotifier(fn() => echo "Async finished!");
 * ```
 */
class AsyncExecutionReceipt {
	/** @var T|null The result of the async execution */
	private mixed $result;

	/** @var string|null Serialized ExecutionExceptionWrapper if error occurred */
	private ?string $error = null;

	/** @var bool Whether the async execution is finished */
	private bool $finished = false;

	/** @var string[] Stack trace of the async call */
	protected array $callTrace;

	/** @var \Closure|null Optional notifier to run when finished */
	protected ?\Closure $notifier = null;

	/**
	 * Get the result of the async execution.
	 *
	 * @return T|null
	 */
	public function getResult() {
		return $this->result;
	}

	/**
	 * Set an exception for this async execution.
	 *
	 * @param ExecutionExceptionWrapper|null $error
	 */
	public function setError(?ExecutionExceptionWrapper $error) : void {
		$this->error = igbinary_serialize($error);
		$this->setFinished();
	}

	/**
	 * Get the error set by the async execution, if any.
	 *
	 * @return ExecutionExceptionWrapper|null
	 */
	public function getError() : ?ExecutionExceptionWrapper {
		if ($this->error === null) {
			return null;
		}
		$error = igbinary_unserialize($this->error);
		if (!$error instanceof ExecutionExceptionWrapper) {
			throw new \UnexpectedValueException('Serialized execution error has an invalid type');
		}
		return $error;
	}

	/**
	 * Check if the async execution has finished.
	 *
	 * @return bool
	 */
	public function isFinished() : bool {
		return $this->finished;
	}

	/**
	 * Mark the async execution as finished and notify listener if set.
	 */
	private function setFinished() : void {
		$this->finished = true;
		$this->tryNotify();
	}

	/**
	 * Set a notifier callback to be executed when async execution finishes.
	 *
	 * @param \Closure|null $notifier
	 */
	public function setNotifier(?\Closure $notifier) : void {
		$this->notifier = $notifier;
		$this->tryNotify();
	}

	/**
	 * Set the result of the async execution.
	 *
	 * @param T $result
	 */
	public function setResult(mixed $result) : void {
		$this->result = $result;
		$this->setFinished();
	}

	/**
	 * Set the call trace for debugging purposes.
	 *
	 * @param string[] $callTrace
	 */
	public function setCallTrace(array $callTrace) : void {
		$this->callTrace = $callTrace;
	}

	/**
	 * Get the stored call trace.
	 *
	 * @return string[]
	 */
	public function getCallTrace() : array {
		return $this->callTrace;
	}

	/**
	 * Coroutine-friendly wait until execution finishes.
	 *
	 * Usage with `yield` allows other coroutines to continue.
	 *
	 * @return Generator
	 */
	public function yieldWait() : Generator {
		while (!$this->isFinished()) {
			yield AwaitSignal::SIG_WAIT;
		}
	}

	/**
	 * Attempt to call the notifier if execution has finished.
	 */
	private function tryNotify() : void {
		if ($this->finished && $this->notifier !== null) {
			($this->notifier)();
			$this->notifier = null;
		}
	}
}
