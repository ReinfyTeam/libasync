<?php
declare(strict_types=1);

namespace libasync\await;

use Closure;

interface EventLoop {
	/**
	 * Polls tasks, executing them until the timeout is reached.
	 *
	 * @param int $microsecond The timeout in microseconds (default is PHP_INT_MAX).
	 */
	public function poll(int $microsecond = PHP_INT_MAX) : void;

	/**
	 * Adds a new task to the event loop.
	 *
	 * @param Closure(Closure $break,Closure $changeToWakeupMode):void $c The task to be executed.
	 * @param int $timeoutMicrosecond The timeout for the task in seconds (default is PHP_INT_MAX).
	 * @param Closure|null $onTimeout The handler for the timeout event (optional).
	 *
	 * @return EventLoopTask The created EventLoopTask object.
	 */
	public function add(Closure $c, int $timeoutMicrosecond = PHP_INT_MAX, ?Closure $onTimeout = null) : EventLoopTask;
}
