<?php
declare(strict_types=1);

use libasync\exception\ExecutionExceptionWrapper;
use libasync\runtime\AsyncExecutionEnvironment;
use pocketmine\thread\ThreadSafeClassLoader;
use pocketmine\utils\Utils;

ob_start();

if ($argc < 4) {
	die("No closure data provided.");
}

require_once __DIR__ . "/../../../vendor/autoload.php";

try {
	$autoloader = json_decode(base64_decode($argv[1]), true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($autoloader) || count($autoloader) !== 3) {
		throw new RuntimeException("Unable to decode autoloader.");
	}
	[$lookup, $psr4, $composer] = $autoloader;
	if (!is_array($lookup) || !is_array($psr4) || !is_array($composer)) {
		throw new RuntimeException("Autoloader data has an invalid shape.");
	}

	foreach ($composer as $autoload) {
		if (!is_string($autoload) || !file_exists($autoload)) {
			throw new RuntimeException("Autoload file not found.");
		}
		require_once $autoload;
	}

	$loader = new ThreadSafeClassLoader();
	foreach ($lookup as $item) {
		if (!is_string($item) || !is_dir($item)) {
			throw new RuntimeException("Lookup path not found.");
		}
		$loader->addPath('', $item);
	}

	foreach ($psr4 as $k => $v) {
		if (!is_string($k) || !is_array($v)) {
			throw new RuntimeException("Invalid PSR-4 autoloader data.");
		}
		foreach ($v as $vv) {
			if (!is_string($vv) || !is_dir($vv)) {
				throw new RuntimeException("PSR-4 path not found.");
			}
			$loader->addPath($k, $vv);
		}
	}

	$loader->register();

	$closure = \Opis\Closure\unserialize(Utils::assumeNotFalse(base64_decode($argv[2]), "Invalid closure data provided."), null);
	if (!$closure instanceof \Closure) {
		throw new RuntimeException("Invalid closure deserialized.");
	}

	$env = null;
	if ($argv[3] !== '' && $argv[3] !== 'null') {
		$serializedEnv = Utils::assumeNotFalse(base64_decode($argv[3]), "Invalid closure data provided.");

		$env = $serializedEnv !== '' ? igbinary_unserialize($serializedEnv) : null;
		if (!is_array($env) || count($env) !== 2 || !array_key_exists(0, $env) || !array_key_exists(1, $env) || !is_string($env[0]) || !is_string($env[1])) {
			throw new RuntimeException("Invalid environment data.");
		}
		$env = [
			\Opis\Closure\unserialize($env[0], null),
			\Opis\Closure\unserialize($env[1], null),
		];
		if (!$env[0] instanceof \Closure || !$env[1] instanceof \Closure) {
			throw new RuntimeException("Invalid environment closures.");
		}
	}


	if (!defined('bootstrap\PRODUCTION')) {
		define("bootstrap\PRODUCTION", false);
	}

	$callable = $closure;
	$result = $env === null
		? $callable()
		: (new AsyncExecutionEnvironment($env[0], $env[1]))->run($callable);

	ob_end_clean();
	echo igbinary_serialize([true, $result]);

} catch (Throwable $err) {
	ob_end_clean();
	$error = (ExecutionExceptionWrapper::wrap($err));
	echo igbinary_serialize([false, $error]);
	exit(1);
}
