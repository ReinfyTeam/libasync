# libasync

`libasync` is a lightweight asynchronous library designed primarily
for [PocketMine-MP](https://github.com/pmmp/PocketMine-MP), but fully general-purpose. It supports **seamless
multitasking**, cooperative coroutines, and even multi-thread/multi-process execution in **NTS environments** **without
code
changes**.

## Requirements and installation

- PHP 8.2 or later.
- PocketMine-MP API 5 when using the bundled `AsyncLoader` plugin entry point.
- The Composer dependency `opis/closure` for closure serialization.

Install the library in a PHP project with Composer:

```text
composer require reinfyteam/libasync
```

Composer autoload registers the library's public function entry points, so
Composer consumers do not need to require internal function files manually.

For PocketMine-MP, load the plugin through the project's supported plugin or
virion packaging workflow. The build metadata is still being validated against
[DevTools](https://github.com/NhanAZ/DevTools); the current repository does not
claim that a standalone PHAR can automatically bundle every Composer package.

> NTS Ready LITERALLY, you can use libasync without `ext-pthread` and `ext-pmmpthread` with a true `process`, you don't
> need any extra work.

## 🚀 Pain Points Solved

* **Blocking Operations**: Avoid server stalls caused by synchronous I/O or heavy computation.
* **Complex Coroutine Management**: Simplifies handling of async tasks, delays, timeouts, and resource pools.
* **Thread/Process Safety**: Enables concurrent execution without rewriting logic for multi-threaded or multi-process
  environments.
* **Fine-grained Async Control**: Provides awaitable results, cancellation, and timing primitives like ticks and event-loop
  aware delays.

## 🤔 Why Choose This System?

* [PocketMine-MP](https://github.com/pmmp/PocketMine-MP) Optimized: Tailored to Minecraft server internals for minimal
  overhead.
* **Framework Agnostic**: Works in any PHP project, not limited to PocketMine-MP.
* **NTS Ready**: Transparent support for non-thread-safe PHP builds with multi-process or multi-thread fallback.
* **Flexible Event Loop**: Easily integrate with existing loops or use built-in classic event loop.
* **Lightweight & Minimal**: No heavy dependencies; fully PSR-compatible design.

## Features

- **Coroutines:** Easily write asynchronous code using `async()` and `thread()`.
- **Deferred Execution:** Register deferred closures to be executed when a coroutine finishes (`defer()`).
- **Traps:** Add trap closures that trigger under specific conditions (`trap()`, `trap_online()`).
- **Timeouts:** Set timeouts for coroutine tasks (`timeout()`).
- **Coroutine Lifecycle Control:**
    - `joined()` ensures a coroutine runs to completion, even during shutdown.
    - `may_drop()` allows a coroutine to be dropped immediately if not critical.
- **Safe Closures:** Detect and prevent cyclic references in closures using `ClosureUtils::noCyclic()`.

## Quick Start

```php
use function libasync\async;
use function libasync\defer;
use function libasync\delay;
use function libasync\joined;
use function libasync\may_drop;
use function libasync\thread;
use function libasync\timeout;
use function libasync\trap;

// Run an async task
async(static function() {
    // Do async work and return a value
})->panic();

async(static function() {
    // Run a task in a separate thread (or process, depending on runtime)
    $threadResult = yield from thread(static fn() => ['a' => 1, 'b' => 2]);
    // $threadResult === ['a' => 1, 'b' => 2]
    
    // Trap example: coroutine pauses if condition fails
    trap(static fn() => someConditionCheck());

    // Deferred cleanup: executed when coroutine finishes
    defer(static fn() => echo "Cleaning up...\n");

    // Mark a coroutine as "joined" to ensure it completes
    joined();

    // Or mark it as "may_drop" for non-critical tasks
    may_drop();

    // Set a timeout for the currently running coroutine (seconds)
    timeout(5.0); // throws if task not completed in 5s

    // Delay execution of a closure by ticks (1 tick = 50ms)
    yield from delay(static fn() => echo "Delayed hello\n", 10);

    // Player-specific trap (PocketMine-MP)
    if (class_exists(\pocketmine\player\Player::class)) {
        $player = /* get Player instance */;
        trap_online($player); // coroutine pauses if player is offline
    }
})->panic();
```
