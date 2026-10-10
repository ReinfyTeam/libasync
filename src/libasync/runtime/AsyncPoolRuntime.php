<?php

namespace libasync\runtime;

use Closure;
use libasync\utils\ClosureUtils;
use pocketmine\scheduler\AsyncPool;
use pocketmine\utils\Utils;

readonly class AsyncPoolRuntime implements AsyncRuntime {
	public function __construct(
		private AsyncPool $pool
	) {
	}

	/**
	 * @template T of (scalar|null|\pmmp\thread\ThreadSafe|\pmmp\thread\ThreadSafeArray)
	 * @param Closure(mixed ...):T $closure
	 * @return AsyncExecutionReceipt<T>
	 */
	public function runAsync(Closure $closure, ?AsyncExecutionEnvironment $env = null) : AsyncExecutionReceipt {
		ClosureUtils::validateThreadSafety($closure);
		/** @var AsyncExecutionReceipt<T> $rec */
		$rec = new AsyncExecutionReceipt();
		$rec->setCallTrace(Utils::printableCurrentTrace());
		$task = new AsyncExecutionTask($rec, $closure, $env);
		$this->pool->submitTask($task);
		return $rec;
	}

	public function getPool() : AsyncPool { return $this->pool; }
}
