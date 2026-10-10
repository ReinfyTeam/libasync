<?php

namespace libasync\utils;

use pmmp\thread\ThreadSafeArray;
use pocketmine\thread\log\ThreadSafeLogger;

class ThreadSafePluginLogger extends ThreadSafePrefixedLogger implements \AttachableLogger {

	/**
	 * @var \Closure[]
	 * @phpstan-var ThreadSafeArray<int, \Closure(mixed, string): void>
	 */
	private ThreadSafeArray $attachments;

	public function __construct(ThreadSafeLogger $delegate, string $prefix) {
		parent::__construct($delegate, $prefix);
		$this->attachments = new ThreadSafeArray();
	}

	/**
	 * @phpstan-param \Closure(mixed, string): void $attachment
	 */
	public function addAttachment(\Closure $attachment) {
		$this->attachments[spl_object_id($attachment)] = $attachment;
	}

	/**
	 * @phpstan-param \Closure(mixed, string): void $attachment
	 */
	public function removeAttachment(\Closure $attachment) {
		unset($this->attachments[spl_object_id($attachment)]);
	}

	public function removeAttachments() {
		$this->attachments = new ThreadSafeArray();
	}

	/** @return array<int, \Closure(mixed, string): void> */
	public function getAttachments() : array {
		$attachments = (array) $this->attachments;
		/** @var array<int, \Closure(mixed, string): void> $attachments */
		return $attachments;
	}

	public function log($level, $message) {
		parent::log($level, $message);
		foreach ($this->attachments as $attachment) {
			/** @var \Closure(mixed, string): void $attachment */
			$attachment($level, $message);
		}
	}
}
