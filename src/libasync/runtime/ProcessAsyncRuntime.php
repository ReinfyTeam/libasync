<?php

namespace libasync\runtime;

use Closure;
use Composer\Autoload\ClassLoader;
use Exception;
use libasync\await\Await;
use libasync\exception\ExecutionExceptionWrapper;
use libasync\utils\ClosureUtils;
use pmmp\thread\ThreadSafe;
use pocketmine\Server;
use pocketmine\utils\Utils;
use RuntimeException;
use function libasync\async;

class ProcessAsyncRuntime implements AsyncRuntime {
	private string $autoloaderData;

	public function runAsync(Closure $closure, ?AsyncExecutionEnvironment $env = null) : AsyncExecutionReceipt {
		try {
			ClosureUtils::validateThreadSafety($closure);
		} catch (Exception $e) {
			throw new RuntimeException("Invalid closure: " . $e->getMessage());
		}

		$rec = new AsyncExecutionReceipt();
		$rec->setCallTrace(Utils::printableCurrentTrace());

		try {
			$serializedClosure = \Opis\Closure\serialize($closure);
			$envData = $env !== null ? igbinary_serialize([\Opis\Closure\serialize($env->argsCtor), \Opis\Closure\serialize($env->argsDtor)]) : null;
		} catch (Exception $e) {
			throw new RuntimeException("Failed to serialize closure or environment data: " . $e->getMessage());
		}

		$process = Utils::assumeNotFalse(proc_open(
			[
				PHP_BINARY,
				__DIR__ . '/process.php',
				$this->getAutoloaderData(),
				base64_encode($serializedClosure), base64_encode($envData ?? ''),
			],
			[
				1 => ['pipe', 'w'],
				2 => Utils::assumeNotFalse(fopen("php://stderr", "wb"), "Unable to open stderr"),
			],
			$pipes
		), "Something has gone horribly wrong");

		if (!is_resource($process)) {
			throw new RuntimeException("Failed to start subprocess.");
		}

		$stdin = $pipes[1] ?? null;
		if (!is_resource($stdin)) {
			throw new RuntimeException("Process did not provide an output pipe.");
		}
		stream_set_blocking($stdin, false);
		async(static function() use ($process, $rec, $stdin) {
			$output = yield from Await::stream($stdin);
			try {
				$decoded = igbinary_unserialize($output);
				if (!is_array($decoded) || count($decoded) !== 2 || !array_key_exists(0, $decoded) || !array_key_exists(1, $decoded) || !is_bool($decoded[0])) {
					throw new RuntimeException("Invalid subprocess output.");
				}
				[$success, $result] = $decoded;
				if ($success) {
					if (!is_scalar($result) && $result !== null && !$result instanceof ThreadSafe) {
						throw new \UnexpectedValueException('Subprocess returned an unsupported result type');
					}
					$rec->setResult($result);
				} else {
					if ($result !== null && !$result instanceof ExecutionExceptionWrapper) {
						throw new \UnexpectedValueException('Subprocess returned an invalid execution error');
					}
					$rec->setError($result);
				}
			} catch (Exception $e) {
				throw new RuntimeException("Error while processing subprocess output: " . $e->getMessage());
			} finally {
				if (is_resource($stdin)) {
					fclose($stdin);
				}
				if (is_resource($process)) {
					proc_close($process);
				}
			}
		})->panic();

		return $rec;
	}

	private function getAutoloaderData() : string {
		if (!isset($this->autoloaderData)) {
			$loader = Server::getInstance()->getLoader();
			$lookupData = (fn() => $this->fallbackLookup)->call($loader);
			$psrData = (fn() => $this->psr4Lookup)->call($loader);
			$composer = [];
			foreach (ClassLoader::getRegisteredLoaders() as $loader) {
				$vendor = (fn() => $this->vendorDir)->call($loader);
				if (!is_string($vendor)) {
					throw new RuntimeException('Composer loader has an invalid vendor directory');
				}
				$composer[] = ($vendor . '/autoload.php');
			}
			$this->autoloaderData = base64_encode(json_encode([$lookupData, $psrData, $composer], JSON_THROW_ON_ERROR));
		}
		return $this->autoloaderData;
	}
}
