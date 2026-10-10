<?php

namespace libasync\await\lock\rw;

use Generator;
use libasync\await\AwaitSignal;
use libasync\await\lock\Lock;
use LogicException;

/**
 * @internal
 */
readonly class ReadLock implements Lock {
	public function __construct(
		/** @var RefCell<bool> */
		private RefCell $writing,
		/** @var RefCell<bool> */
		private RefCell $reading,
	) {
	}

	/** @return Generator<int,mixed,mixed,void> */
	public function lock() : Generator {
		while ($this->writing->value) {
			yield AwaitSignal::SIG_WAIT;
		}
		$this->reading->value = true;
	}

	public function unlock() : void {
		if (!$this->reading->value) {
			throw new LogicException("ReadLock hasn't been locked yet.");
		}
		$this->reading->value = false;
	}
}
