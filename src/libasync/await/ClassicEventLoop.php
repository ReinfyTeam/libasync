<?php
declare(strict_types=1);

namespace libasync\await;

use Closure;
use libasync\utils\ClosureUtils;
use SplQueue;
use function phantom\weak;

class ClassicEventLoop implements EventLoop {
	/** @var array<int, EventLoopTask> */
	private array $polling = [];
	/** @var array<int, EventLoopTask> */
	private array $waiting = [];
	/** @var SplQueue<int> */
	private SplQueue $wakeupQueue;

	public function __construct() {
		$this->wakeupQueue = new SplQueue();
	}

	public function poll(int $microsecond = PHP_INT_MAX) : void {
		$deadline = hrtime(true) + ($microsecond * 1000000);

		foreach ($this->polling as $task) {
			if (hrtime(true) >= $deadline) {
				break;
			}
			$task->execute();
		}

		while (!$this->wakeupQueue->isEmpty()) {
			$id = $this->wakeupQueue->dequeue();
			$this->polling[$id] = $this->waiting[$id];
			unset($this->waiting[$id]);
		}
	}

	/**
	 * @param Closure(Closure():void, Closure():Closure):void $c
	 * @param null|Closure(float):void $onTimeout
	 */
	public function add(Closure $c, int $timeoutMicrosecond = PHP_INT_MAX, ?Closure $onTimeout = null) : EventLoopTask {
		$id = spl_object_id($c);
		$weak = weak($this);
		if (!\bootstrap\PRODUCTION) {
			ClosureUtils::noCyclic($c, $this);
		}
		$task = new EventLoopTask(
			$c,
			static function() use ($weak, $id) {
				unset($weak->_ref()->polling[$id], $weak->_ref()->waiting[$id]);
			},
			static function() use ($weak, $id) : Closure {
				$weak->_ref()->waiting[$id] = $weak->_ref()->polling[$id];
				unset($weak->_ref()->polling[$id]);
				return static fn() => $weak->_ref()->wakeupQueue->enqueue($id);
			},
			$timeoutMicrosecond, $onTimeout
		);
		$this->polling[$id] = $task;
		return $task;
	}
}
