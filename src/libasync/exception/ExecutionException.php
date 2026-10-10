<?php

namespace libasync\exception;

use Logger;
use pmmp\thread\ThreadSafeArray;

class ExecutionException extends \Exception {
	/**
	 * @param array<int|string, string>|ThreadSafeArray<int|string, string> $callTrace
	 */
	public function __construct(
		private readonly ExecutionExceptionWrapper $exception,
		private readonly ThreadSafeArray|array     $callTrace,
	) {
		parent::__construct($this->exception->getMessage(), $this->exception->getCode());
	}

	public function getWrapper() : ExecutionExceptionWrapper { return $this->exception; }

	/** @return array<int|string, string>|ThreadSafeArray<int|string, string> */
	public function getCallTrace() : array|ThreadSafeArray { return $this->callTrace; }

	public function printWithCallTrace(?Logger $logger = null) : void {
		$this->exception->printWithCallTrace($this->callTrace, $logger);
	}
}
