<?php

namespace libasync\utils;

use Closure;

/**
 * Generic resource wrapper with optional recycling and cleanup callback.
 *
 * This class allows you to wrap a resource (any type) and manage its lifecycle,
 * including:
 * - Optional recycling via a user-provided callback
 * - Forced freeing via a user-provided callback
 * - Optional `onClose` callback triggered when the resource is closed
 *
 * @template T Type of the wrapped resource
 *
 * @example Basic usage:
 * ```
 * use libasync\utils\ResourceRef;
 *
 * // Resource could be a database connection, socket, or any object
 * $conn = new ResourceRef(
 *     $dbConnection,
 *     true, // recyclable
 *     fn($res, bool $force) => $res->close(), // freeFunc
 *     // returns whether the resource is recycled
 *     fn($res) => $res->recycle() // recycleFunc, returns bool
 * );
 *
 * // Access the resource
 * $db = $conn->get();
 *
 * // Check if it is recyclable
 * if ($conn->isRecyclable()) { ... }
 *
 * // Register on-close callback
 * $conn->onClose(fn($res) => echo "Resource closed\n");
 *
 * // Close or recycle resource
 * $conn->close();
 * ```
 *
 * @example Force close even if recyclable
 * ```php
 * $conn->close(true); // will bypass recycling and call freeFunc
 * ```
 */
class ResourceRef {
	/** @var Closure(T):void|null Callback to run after resource is closed */
	private ?Closure $onClose = null;

	/**
	 * @param T $res The resource to wrap
	 * @param bool $recyclable Whether the resource can be recycled
	 * @param Closure(T,bool):void $freeFunc Function to free the resource
	 * @param Closure(T):bool $recycleFunc Function to attempt recycling the resource; should return true if recycled
	 */
	public function __construct(
		private mixed   $res,
		private bool    $recyclable,
		private Closure $freeFunc,
		private Closure $recycleFunc
	) {
	}

	/**
	 * Get the wrapped resource.
	 *
	 * @return T
	 */
	public function get() : mixed { return $this->res; }

	/**
	 * Check whether the resource is recyclable.
	 *
	 * @return bool
	 */
	public function isRecyclable() : bool { return $this->recyclable; }

	/**
	 * Close the resource.
	 *
	 * If the resource is recyclable, attempts recycling via `$recycleFunc`.
	 * If recycling fails or resource is not recyclable, calls `$freeFunc`.
	 * Finally, triggers `$onClose` callback if defined.
	 *
	 * @param bool $force If true, bypass recycling and force free the resource
	 */
	public function close(bool $force = false) : void {
		if ($this->recyclable) {
			$recycled = ($this->recycleFunc)($this->res);
			if (!$recycled) {
				goto free;
			}
		} else {
			free:
			($this->freeFunc)($this->res, $force);
		}
		if ($this->onClose !== null) {
			($this->onClose)($this->res);
		}
	}

	/**
	 * Set a callback to be executed after the resource is closed.
	 *
	 * @param null|Closure(T):void $onClose Callback that receives the resource, or null to unset
	 */
	public function onClose(?Closure $onClose) : void { $this->onClose = $onClose; }
}
