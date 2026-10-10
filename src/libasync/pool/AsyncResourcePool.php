<?php

namespace libasync\pool;

use Closure;
use libasync\utils\ResourceRef;
use pocketmine\utils\Utils;

/**
 * @template T
 * @implements ResourcePoolInterface<T>
 */
class AsyncResourcePool implements ResourcePoolInterface {
	public function __construct(
		/** @var Closure():T */
		private readonly Closure $prepareFunc,
		/** @var Closure(T $res,bool $force):void */
		private readonly Closure $freeFunc,
		/** @var Closure(T $res,Closure(T $res):void $push):bool */
		private readonly Closure $recycleFunc,
	) {

	}

	protected int $capacity;
	/** @var T[] */
	private array $resources = [];
	public function setCapacity(int $capacity) : void { $this->capacity = $capacity; }

	public function prepare(int $count) : void {
		$c = count($this->resources);
		$max = min($c + $count, $this->capacity);
		for ($i = $c; $i < $max; $i++) {
			$this->resources[] = ($this->prepareFunc)();
		}
	}

	public function select() : ?ResourceRef {
		$resourceCount = count($this->resources);
		if ($resourceCount === 0) {
			$this->prepare($this->capacity);
		}
		$res = array_pop($this->resources);
		Utils::assumeNotFalse($res !== null);
		/** @var T $res */
		return $this->packRes($res);
	}

	public function close() : void {
		foreach ($this->resources as $res) {
			($this->freeFunc)($res, true);
		}
		$this->resources = [];
	}

	/**
	 * @param T $res
	 * @return ResourceRef<T>
	 */
	private function packRes($res) : ResourceRef {
		return new ResourceRef(
			res: $res,
			recyclable: true,
			freeFunc: function($res, bool $force = false) : void {
				($this->freeFunc)($res, $force);
				$this->prepare(1);
			},
			recycleFunc: function($res) : bool {
				$recycled = false;
				$realPush = function($res) use (&$recycled) {
					$recycled = true;
					$this->resources[] = $res;
				};
				($this->recycleFunc)($res, $realPush);
				return $recycled;
			});
	}
}
