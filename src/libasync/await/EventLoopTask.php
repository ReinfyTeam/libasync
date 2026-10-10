<?php
declare(strict_types=1);

namespace libasync\await;

use Closure;
use GlobalLogger;
use Throwable;

class EventLoopTask {
	private float $timeoutTime;

	public function __construct(
		/** @var Closure(Closure():void, Closure():void, Closure(float):void, Closure(?Closure(float):void):void):void */
		private Closure  $task,
		private Closure  $cancelTask,
		private Closure  $changeToWaiting,
		float            $timeout = PHP_FLOAT_MAX,
		/** @var null|Closure(float $exceed):void $onTimeout */
		private ?Closure $onTimeout = null
	) {
		$this->timeoutTime = hrtime(true) + ($timeout * 1000000);
	}

	public function execute() : void {
		$currentTime = hrtime(true);
		$logger = GlobalLogger::get();
		if ($currentTime >= $this->timeoutTime && $this->onTimeout !== null) {
			try {
				($this->onTimeout)(($currentTime - $this->timeoutTime) / 1000000);
			} catch (Throwable $e) {
				$logger->error("Task timeout handler failed: " . $e->getMessage());
				$logger->logException($e);
			}
			$this->cancel();
			return;
		}

		try {
			($this->task)($this->cancel(...), $this->changeToWaiting, $this->setTimeout(...), $this->setOnTimeout(...));
		} catch (Throwable $e) {
			$logger->error("Task execution failed: " . $e->getMessage());
			$logger->logException($e);
		}
	}

	public function setTimeout(float $microsecond) : void {
		$this->timeoutTime = hrtime(true) + ($microsecond * 1000000);
	}

	/**
	 * @param null|Closure(float $exceed):void $onTimeout
	 */
	public function setOnTimeout(?Closure $onTimeout) : void { $this->onTimeout = $onTimeout; }

	public function cancel() : void {
		($this->cancelTask)();
		unset($this->task, $this->cancelTask, $this->changeToWaiting, $this->onTimeout);
	}
}
