<?php
declare(strict_types=1);

namespace libasync\await;

use Closure;
use Generator;
use GlobalLogger;
use libasync\AsyncTimings;
use libasync\exception\ExecutionException;
use libasync\exception\ExecutionExceptionWrapper;
use libasync\exception\TimeoutException;
use pocketmine\Server;
use pocketmine\thread\ThreadCrashInfoFrame;
use pocketmine\timings\TimingsHandler;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils as PMMPUtils;
use Throwable;
use WeakReference;

class Coroutine {
	private const SKIP_FRAMES = 3;
	public static ?self $RUNNING = null;
	/** @var array<int,self> */
	public static array $joinedCoroutine = [];
	/** @var array<int|string, string> */
	private readonly array $callTrace;
	private readonly string $name;
	/** @var (Closure():bool)[] */
	private array $trapHandlers = [];
    /** @var (Closure():void)[] */
    private array $deferHandlers = [];
	/** @var WeakReference<EventLoopTask>|null */
	private ?WeakReference $taskRef = null;

	/** @param Closure(Throwable):void|null $errorHandler */
	public function __construct(
		private Generator $generator,
		private ?Closure  $errorHandler,
		private bool      $joined
	) {
		$this->callTrace = PMMPUtils::printableCurrentTrace(self::SKIP_FRAMES);
		$this->name = $this->determineCallerName();

		if ($this->joined) {
			self::$joinedCoroutine[spl_object_id($this)] = $this;
		}
	}

	private function determineCallerName() : string {
		if (!TimingsHandler::isEnabled()) {
			return 'Unknown';
		}

		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::SKIP_FRAMES + 1);
		$caller = $trace[self::SKIP_FRAMES] ?? null;

		if ($caller === null) {
			return 'Unknown';
		}

		if (!isset($caller['file'], $caller['line'])) {
			return 'Unknown';
		}

		return Filesystem::cleanPath($caller['file']) . '#L' . $caller['line'];
	}

	public function getName() : string { return $this->name; }

	/**
	 * @param Closure():bool $trap
	 */
	public function addTrap(Closure $trap) : void { $this->trapHandlers[] = $trap; }

	/**
	 * @param Closure():void $defer
	 */
	public function addDefer(Closure $defer) : void { $this->deferHandlers[] = $defer; }

	public function register(EventLoop $loop) : void {
		$timings = AsyncTimings::getByName($this->name);
		$resumeTimings = AsyncTimings::getResumeByName($this->name);

        $selfRef = WeakReference::create($this);

        $task = $loop->add(function(Closure $break, Closure $wakeupMode) use ($timings, $resumeTimings, $selfRef) : void {
            $self = $selfRef->get();
            if ($self === null) {
                $break();
                return;
            }
            $self->runLoopIteration($break, $wakeupMode, $timings, $resumeTimings);
        });

        $task->setOnTimeout(static function(float $exceed) use ($selfRef) : void {
            $self = $selfRef->get();
            if ($self !== null) {
                $self->handleTimeout($exceed);
            }
        });

        $this->taskRef = WeakReference::create($task);
    }

	/**
	 * @param Closure():void $break
	 * @param Closure():Closure $wakeupMode
	 */
	private function runLoopIteration(Closure $break, Closure $wakeupMode, TimingsHandler $timings, TimingsHandler $resumeTimings) : void {
        try {
            self::$RUNNING = $this;
            $timings->startTiming();

            if ($this->executeInternal($timings, $resumeTimings, $wakeupMode)) {
                $this->terminate($break);
            }
        } catch (Throwable $thr) {
            $this->handleException($thr);
            $this->terminate($break);
        } finally {
            self::$RUNNING = null;
            $timings->stopTiming();
        }
    }

	/** @param Closure():Closure $wakeupMode */
	private function executeInternal(TimingsHandler $timings, TimingsHandler $resumeTimings, Closure $wakeupMode) : bool {
		$gen = $this->generator;

		if (!$gen->valid() || !$this->processTraps()) {
			$timings->stopTiming();
			return true;
		}

		$signal = $gen->current();

		match ($signal) {
			AwaitSignal::SIG_NOTIFIED => $this->handleNotifiedSignal($gen, $wakeupMode),
			AwaitSignal::SIG_WAIT => null,
			AwaitSignal::SIG_EXCEPTION => $this->handleExceptionSignal($gen),
			AwaitSignal::SIG_TRAP => $this->handleTrapSignal($gen),
			AwaitSignal::SIG_FINISH, AwaitSignal::SIG_INTERRUPT => true,
			default => GlobalLogger::get()->debug("Unsupported signal: " . var_export($signal, true))
		};

		$resumeTimings->time(fn() => $gen->next());
		return false;
	}

	private function processTraps() : bool {
		foreach (array_reverse($this->trapHandlers) as $trap) {
			if (!$trap()) {
				return false;
			}
		}

		return true;
	}

	/** @param Closure():Closure $wakeupMode */
	private function handleNotifiedSignal(Generator $gen, Closure $wakeupMode) : void {
		$gen->next();
		$notifier = $gen->current();
		if (!$notifier instanceof Closure) {
			throw new \UnexpectedValueException('Coroutine notification signal did not provide a closure');
		}
		$notifier($wakeupMode());
	}

	private function handleExceptionSignal(Generator $gen) : void {
		$gen->next();
		$value = $gen->current();
		if (!is_array($value) || !array_key_exists(0, $value) || !array_key_exists(1, $value)) {
			throw new \UnexpectedValueException('Coroutine exception signal has an invalid payload');
		}
		$callTrace = $value[0];
		$exception = $value[1];
		if (!is_array($callTrace)) {
			throw new \UnexpectedValueException('Coroutine exception signal has an invalid call trace');
		}
		/** @var array<int|string, string> $validatedCallTrace */
		$validatedCallTrace = [];
		foreach ($callTrace as $key => $frame) {
			if (!is_string($frame)) {
				throw new \UnexpectedValueException('Coroutine exception signal has a non-string call trace frame');
			}
			$validatedCallTrace[$key] = $frame;
		}
		if ($exception !== null && !$exception instanceof ExecutionExceptionWrapper) {
			throw new \UnexpectedValueException('Coroutine exception signal has an invalid exception wrapper');
		}

		if ($exception !== null) {
			$gen->throw(new ExecutionException($exception, $validatedCallTrace));
		}
	}

	private function handleTrapSignal(Generator $gen) : void {
		$gen->next();
		$trap = $gen->current();
		if (!$trap instanceof Closure) {
			throw new \UnexpectedValueException('Coroutine trap signal did not provide a closure');
		}
		$this->trapHandlers[] = $trap;
	}

	/** @param Closure():void $break */
	private function terminate(Closure $break) : void {
		if (!isset($this->generator)) {
			return;
		}
		unset(self::$joinedCoroutine[spl_object_id($this)]);

		foreach ($this->deferHandlers as $defer) {
			$defer();
		}

        $this->taskRef = null;
        unset($this->generator, $this->deferHandlers, $this->errorHandler, $this->trapHandlers);

        $break();
    }

	private function handleException(Throwable $thr) : void {
		if ($this->errorHandler !== null) {
			($this->errorHandler)($thr);
		} else {
			self::logCrash($thr, $this->callTrace);
		}
	}

	/** @param array<int|string, string> $callTrace */
	private static function logCrash(Throwable $thr, array $callTrace) : void {
		$x = array_map(static fn(string $trace) => new ThreadCrashInfoFrame($trace, 'unknown', 0), $callTrace);
		if ($thr instanceof ExecutionException) {
			$thr->printWithCallTrace(GlobalLogger::get());
			$wrapper = $thr->getWrapper();
		} else {
			GlobalLogger::get()->logException($thr);
			$wrapper = ExecutionExceptionWrapper::wrap($thr);
		}

		global $lastExceptionError;
		$lastExceptionError = [
			'type' => $wrapper->getClass(),
			'message' => $wrapper->getMessage(),
			'fullFile' => $wrapper->getFile(),
			'file' => Filesystem::cleanPath($wrapper->getFile()),
			'line' => $wrapper->getLine(),
			'trace' => $x,
			'thread' => 'Coroutine',
		];
		Server::getInstance()->crashDump();
	}

    public function getTask() : ?EventLoopTask {
        $task = $this->taskRef?->get();
        return $task instanceof EventLoopTask ? $task : null;
    }

	private function handleTimeout(float $exceed) : void {
		unset(self::$joinedCoroutine[spl_object_id($this)]);

		$timeoutException = new TimeoutException("Coroutine timed out, exceed=$exceed");
		$executionException = new ExecutionException(ExecutionExceptionWrapper::wrap($timeoutException), $this->callTrace);

		$this->generator->throw($executionException);
	}
}
