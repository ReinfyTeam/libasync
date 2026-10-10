<?php

namespace libasync\await\lock;

interface Lock {
	/** @return \Generator<int,mixed,mixed,void> */
	public function lock() : \Generator;

	public function unlock() : void;
}
