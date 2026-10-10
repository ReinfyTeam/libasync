<?php

namespace libasync\await\lock\rw;


/**
 * @template T
 */
readonly class ReadWriteLock {
	protected ReadLock $reading;
	protected WriteLock $writing;

	public function __construct() {
		$read = new RefCell(false);
		$write = new RefCell(false);
		$this->reading = new ReadLock($write, $read);
		$this->writing = new WriteLock($write, $read);
	}

	public function read() : ReadLock {
		return $this->reading;
	}

	public function write() : WriteLock {
		return $this->writing;
	}
}
