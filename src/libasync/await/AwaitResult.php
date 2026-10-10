<?php

namespace libasync\await;

use Closure;
use GlobalLogger;
use libasync\exception\ExecutionException;
use libasync\global\GlobalAsyncRuntime;
use libasync\utils\ClosureUtils;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Utils;
use prokits\utils\StringFormat;
use Throwable;

/**
 * AwaitResult wraps the result of an asynchronous coroutine execution.
 *
 * Provides mechanisms to:
 * - Handle errors via callbacks
 * - Log errors to PMMP GlobalLogger
 * - Notify command senders about async errors
 * - Optionally "join" or drop coroutine execution
 *
 * Errors are automatically handled in destructor if unhandled in production
 * environment, providing stack trace logging and safety for forgotten awaits.
 *
 * ### Usage example
 *
 * ```
 * $awaitResult = new AwaitResult(
 *     static fn(?Closure $errorHandler, bool $joined) => $coroutine,
 *     static fn($coroutine) => $onCreationCallback
 * );
 *
 * // Handle errors manually
 * $awaitResult->error(static fn(Throwable $thr) => echo "Async failed: $thr");
 *
 * // Log errors automatically
 * $awaitResult->logError();
 *
 * // Notify a command sender on error
 * $awaitResult->logErrorWithSender($player);
 *
 * // Control join behavior
 * $awaitResult->join();      // default, waits for coroutine
 * $awaitResult->mayDrop();   // allow coroutine to run without joining
 * ```
 *
 * ### Notes
 *
 * - `join()` indicates that the caller expects to wait for the coroutine result
 * - `mayDrop()` allows the coroutine to continue without explicit waiting
 * - Destructor will schedule unhandled errors to runtime loop for logging
 *
 * @internal Relies on `GlobalAsyncRuntime` loop for deferred error handling
 */
class AwaitResult {
	protected bool $join = true;
	/** @var array<int|string, string> */
	private array $stackTrace;
	private bool $errorHandled = false;

	/**
	 * @param Closure(?Closure(Throwable):void $errorHandler,bool $joined):Coroutine $createCoroutine
	 * @param Closure(Coroutine $coroutine):void $onCreation
	 */
	public function __construct(
		private readonly Closure $createCoroutine,
		private readonly Closure $onCreation
	) {
		if (!\constant('bootstrap\\PRODUCTION')) {
			ClosureUtils::noCyclic($this->createCoroutine, $this->onCreation);
			ClosureUtils::noCyclic($this->createCoroutine, $this);
			ClosureUtils::noCyclic($this->onCreation, $this);
		}
		//skip __construct
		$this->stackTrace = Utils::printableCurrentTrace(1);
	}

	public function __destruct() {
		if ($this->errorHandled) {
			return;
		}

		$stackTrace = $this->stackTrace;
		$createCoroutine = $this->createCoroutine;
		$onCreation = $this->onCreation;

		if (!\constant('bootstrap\\PRODUCTION')) {
			GlobalLogger::get()->error(
				"\n--- Await Start ---\n" .
				implode("\n", $stackTrace) .
				"\n--- End of exception information ---"
			);
		}

		GlobalAsyncRuntime::getLoop()->add(static function($break) use ($createCoroutine, $onCreation) : void {
			(new self($createCoroutine, $onCreation))->panic();
			$break();
		});
	}

	/**
	 * @param ?Closure(Throwable):void $errorHandler
	 */
	public function error(?Closure $errorHandler) : void {
		$this->errorHandled = true;
		$coroutine = ($this->createCoroutine)($errorHandler, $this->join);
		($this->onCreation)($coroutine);
	}

	public function panic() : void {
		$this->error(null);
	}

	/**
	 * @see self::panic()
	 */
	/** @param Closure(Throwable):void|null $do */
	public function logError(?Closure $do = null) : void {
		$this->error(static function(Throwable $thr) use ($do) : void {
			if (!$thr instanceof ExecutionException) {
				GlobalLogger::get()->logException($thr);
			} else {
				$thr->printWithCallTrace(GlobalLogger::get());
			}
			if ($do !== null) {
				$do($thr);
			}
		});
	}

	/** @param Closure(Throwable):void|null $do */
	public function logErrorWithSender(CommandSender $sender, ?Closure $do = null) : void {
		$this->error(static function(Throwable $thr) use ($do, $sender) {
			if ($thr instanceof ExecutionException) {
				$thr->printWithCallTrace(GlobalLogger::get());
			} else {
				GlobalLogger::get()->logException($thr);
			}
			try {
				if ($sender instanceof Player && $sender->isOnline()) {
					if (class_exists(StringFormat::class)) {
						$message = StringFormat::error('async error encountered');
						if (is_string($message)) {
							$sender->sendMessage($message);
						} else {
							$sender->sendMessage(TextFormat::DARK_RED . 'async error encountered');
						}
					} else {
						$sender->sendMessage(TextFormat::DARK_RED . 'async error encountered');
					}
				}
			} catch (Throwable $thr) {
				GlobalLogger::get()->logException($thr);
			}
			if ($do !== null) {
				$do($thr);
			}
		});
	}

	public function join() : self {
		$this->join = true;
		return $this;
	}

	public function mayDrop() : self {
		$this->join = false;
		return $this;
	}
}
