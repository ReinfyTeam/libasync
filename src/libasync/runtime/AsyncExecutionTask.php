<?php

namespace libasync\runtime;

use Closure;
use libasync\exception\ExecutionExceptionWrapper;
use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use pocketmine\scheduler\AsyncTask;
use Throwable;

/**
 * @template T of (scalar|null|ThreadSafe|ThreadSafeArray)
 */
class AsyncExecutionTask extends AsyncTask {
	private const THREAD_LOCAL_RECEIPT = 'receipt';
	private ?string $error = null;

	/**
	 * @param AsyncExecutionReceipt<T> $receipt
	 * @param Closure(mixed ...):T $func
	 */
	public function __construct(
		AsyncExecutionReceipt $receipt,
		private readonly Closure $func,
		private readonly ?AsyncExecutionEnvironment $env,
	) {
		$this->storeLocal(self::THREAD_LOCAL_RECEIPT, $receipt);
	}

	public function onRun() : void {
		try {
			if ($this->env !== null) {
				$result = $this->env->run($this->func);
			} else {
				$result = ($this->func)();
			}
			$this->setResult($result);
		} catch (Throwable $err) {
			$error = igbinary_serialize(ExecutionExceptionWrapper::wrap($err));
			if (!is_string($error)) {
				throw new \UnexpectedValueException('Unable to serialize asynchronous execution error');
			}
			$this->error = $error;
		}
	}

	public function onCompletion() : void {
		$receipt = $this->fetchLocal(self::THREAD_LOCAL_RECEIPT);
		assert($receipt instanceof AsyncExecutionReceipt);
		if ($this->error !== null) {
			$error = igbinary_unserialize($this->error);
			if (!$error instanceof ExecutionExceptionWrapper) {
				throw new \UnexpectedValueException('Serialized asynchronous execution error has an invalid type');
			}
			$receipt->setError($error);
		} else {
			$result = $this->getResult();
			if (!is_scalar($result) && $result !== null && !$result instanceof ThreadSafe) {
				throw new \UnexpectedValueException('Asynchronous task returned an unsupported result type');
			}
			$receipt->setResult($result);
		}
	}
}
