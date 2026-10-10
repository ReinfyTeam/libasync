<?php

namespace libasync;

use libasync\await\ClassicEventLoop;
use libasync\await\Coroutine;
use libasync\global\GlobalAsyncRuntime;
use libasync\runtime\AsyncPoolRuntime;
use libasync\runtime\AsyncRuntime;
use libasync\utils\ThreadSafePrefixedLogger;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\AsyncPool;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\GarbageCollectionTask;
use pocketmine\Server;

class AsyncLoader extends PluginBase {
	private static self $instance;
	protected AsyncPool $pool;
	protected AsyncRuntime $poolRuntime;

	public static function getInstance() : self { return self::$instance; }

	protected function onLoad() : void {
		self::$instance = $this;

		if (!defined('bootstrap\PRODUCTION')) {
			define('bootstrap\PRODUCTION', false);
		}

		$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
		if (!file_exists($autoload)) {
			$this->getLogger()->critical("Composer autoloader not found at " . $autoload);
			$this->getServer()->getPluginManager()->disablePlugin($this);
			return;
		}
		require_once $autoload;
		require_once __DIR__ . '/functions.php';
		require_once __DIR__ . '/../phantom/functions.php';

		$lp = new ClassicEventLoop();
		GlobalAsyncRuntime::setLoop($lp);
		$scheduler = $this->getScheduler();
		$scheduler->scheduleRepeatingTask(new ClosureTask(static fn() => $lp->poll(10)), 1);

		$this->pool = new AsyncPool(1, 1024, Server::getInstance()->getLoader(), new ThreadSafePrefixedLogger(Server::getInstance()->getLogger(), "libasync"), Server::getInstance()->getTickSleeper());
		$this->poolRuntime = new AsyncPoolRuntime($this->pool);
		//$this->poolRuntime = new ProcessAsyncRuntime();
		GlobalAsyncRuntime::setRuntime($this->poolRuntime);

		$scheduler->scheduleRepeatingTask(new ClosureTask(function() {
			$pool = $this->pool;
			if (($w = $pool->shutdownUnusedWorkers()) > 0) {
				$this->getLogger()->debug("Shut down $w idle async pool workers");
			}
			foreach ($pool->getRunningWorkers() as $i) {
				$pool->submitTaskToWorker(new GarbageCollectionTask(), $i);
			}
		}), 20 * 60 * 3);
	}

	protected function onDisable() : void {
		$tck = Server::getInstance()->getTick();
		$timeout = microtime(true) + 30;
		while (Coroutine::$joinedCoroutine !== []) {
			$this->getScheduler()->mainThreadHeartbeat($tck++);
			$this->pool->collectTasks();
			if (microtime(true) > $timeout) {
				break;
			}
		}
		$this->pool->shutdown();
	}

	public function getPoolRuntime() : AsyncRuntime { return $this->poolRuntime; }
}
