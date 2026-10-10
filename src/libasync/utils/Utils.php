<?php

namespace libasync\utils;

use pmmp\thread\ThreadSafe;

final class Utils {
	public static function smartSerialize(mixed $m) : ThreadSafe|null|string {
		if ($m instanceof ThreadSafe) {
			return $m;
		}
		return igbinary_serialize($m);
	}

	public static function smartDeserialize(mixed $m) : mixed {
		if ($m instanceof ThreadSafe) {
			return $m;
		}
		if (!is_string($m)) {
			throw new \InvalidArgumentException('Serialized value must be a string or ThreadSafe object');
		}
		return igbinary_unserialize($m);
	}
}
