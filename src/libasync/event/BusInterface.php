<?php

namespace libasync\event;

/**
 * @template T
 */
interface BusInterface {
	/**
	 * @param \Closure(T $value):void $handler
	 * @return \Closure():void
	 */
	public function subscribe(\Closure $handler) : \Closure;

	/**
	 * @param T $value
	 */
	public function publish(mixed $value) : void;

	public function hasSubscriber(mixed $value) : bool;

	public function clear() : void;
}
