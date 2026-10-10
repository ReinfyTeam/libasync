<?php

namespace libasync\await\lock;

use Generator;
use libasync\await\AwaitSignal;

class Semaphore {
	private int $sema = 0;

	public function __construct(
		private readonly int $maxOwningThread = 1
	) {
	}

	public function acquire() : Generator {
		while ($this->sema < -$this->maxOwningThread) {
			yield AwaitSignal::SIG_WAIT;
		}
		$this->sema--;
	}

	public function release() : void {
		$this->sema++;
	}
}
