<?php

namespace libasync\exception;

use InvalidArgumentException;
use Logger;
use pmmp\thread\ThreadSafeArray;
use pocketmine\errorhandler\ErrorTypeToStringMap;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils as PMMPUtils;
use Throwable;

final class ExecutionExceptionWrapper {
	private function __construct(
		protected string $class,
		protected string $message,
		protected string $traceSerialized,
		protected int    $code,
		protected string $file,
		protected int    $line
	) {
	}

	/** @param array<int|string, string> $trace */
	public static function from(
		string $class,
		string $message,
		array  $trace,
		int    $code,
		string $file,
		int    $line
	) : self {
		$traceSerialized = igbinary_serialize($trace);
		if (!is_string($traceSerialized)) {
			throw new \UnexpectedValueException('Unable to serialize execution trace');
		}
		return new self($class, $message, $traceSerialized, $code, $file, $line);
	}

	public static function wrap(Throwable $thr) : self {
		$traceSerialized = igbinary_serialize(PMMPUtils::printableTrace($thr->getTrace()));
		if (!is_string($traceSerialized)) {
			throw new \UnexpectedValueException('Unable to serialize execution trace');
		}
		return new self($thr::class, $thr->getMessage(), $traceSerialized, $thr->getCode(), $thr->getFile(), $thr->getLine());
	}

	public function is(string $class) : bool {
		return is_subclass_of($this->class, $class) || $this->class === $class;
	}

	public function __toString() {
		return 'Nop';
	}

	public static function printPromiseExceptionMessage(ExecutionExceptionWrapper $e) : string {
		$errstr = preg_replace('/\s+/', ' ', trim($e->getMessage()));

		$errno = $e->getCode();
		try {
			$errno = ErrorTypeToStringMap::get($errno);
		} catch (InvalidArgumentException) {
			// Keep non-error exception codes numeric.
		}

		$errfile = Filesystem::cleanPath($e->getFile());
		$errline = $e->getLine();

		return $e->getClass() . ": \"$errstr\" ($errno) in \"$errfile\" at line $errline";
	}

	/** @param array<int|string, string>|ThreadSafeArray<int|string, string> $callTrace */
	public function printWithCallTrace(ThreadSafeArray|array $callTrace, ?Logger $logger = null) : void {
		$logger ??= \GlobalLogger::get();
		/** @var array<int|string, string> $callTrace */
		$callTrace = (array) $callTrace;
		$logger->critical(self::printPromiseExceptionMessage($this));
		$logger->critical(
			"\n--- Caller Stack trace ---\n" .
			implode("\n", $callTrace) .
			"\n--- End of exception information ---"
		);
	}

	public function getMessage() : string {
		return $this->message;
	}

	public function getCode() : int {
		return $this->code;
	}

	public function getFile() : string {
		return $this->file;
	}

	public function getLine() : int {
		return $this->line;
	}

	public function getClass() : string {
		return $this->class;
	}

	/** @return array<int|string, string> */
	public function getTrace() : array {
		$trace = igbinary_unserialize($this->traceSerialized);
		if (!is_array($trace)) {
			throw new \UnexpectedValueException('Serialized execution trace is not an array');
		}
		foreach ($trace as $frame) {
			if (!is_string($frame)) {
				throw new \UnexpectedValueException('Serialized execution trace contains a non-string frame');
			}
		}
		/** @var array<int|string, string> $trace */
		return $trace;
	}
}
