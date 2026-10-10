<?php

namespace libasync\await\lock;

use Generator;

class ReentrantLock implements Lock {
	private Semaphore $sema;

	public function __construct() {
		$this->sema = new Semaphore();
	}

	public function lock() : Generator {
		yield from $this->sema->acquire();
	}

	public function unlock() : void {
		$this->sema->release();
	}
}
