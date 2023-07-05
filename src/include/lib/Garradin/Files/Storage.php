<?php

namespace Garradin\Files;

use Garradin\DB;
use Garradin\Entities\Files\File;

use const Garradin\{FILE_STORAGE_BACKEND, FILE_STORAGE_QUOTA, FILE_STORAGE_CONFIG};

class Storage
{
	/**
	 * Copy all files from a storage backend to another one
	 * This can be used to move from SQLite to FileSystem for example
	 * Note that this only copies files, and is not removing them from the source storage backend.
	 */
	static public function migrate(string $from, string $to, $from_config = null, $to_config = null, ?callable $callback = null): void
	{
		$from = __NAMESPACE__ . '\\Storage\\' . $from;
		$to = __NAMESPACE__ . '\\Storage\\' . $to;

		if (!class_exists($from)) {
			throw new \InvalidArgumentException('Invalid storage: ' . $from);
		}

		if (!class_exists($to)) {
			throw new \InvalidArgumentException('Invalid storage: ' . $to);
		}

		call_user_func([$from, 'configure'], $from_config);
		call_user_func([$to, 'configure'], $to_config);

		$db = DB::getInstance();

		try {
			if (call_user_func([$from, 'isLocked'])) {
				throw new \RuntimeException('Storage is locked: ' . $from);
			}

			if (call_user_func([$to, 'isLocked'])) {
				throw new \RuntimeException('Storage is locked: ' . $to);
			}

			call_user_func([$from, 'lock']);
			call_user_func([$to, 'lock']);

			$db->begin();
			$i = 0;

			self::migrateDirectory($from, $to, '', $i, $callback);
		}
		catch (RuntimeException $e) {
			throw new \RuntimeException('Migration failed', 0, $e);
		}
		finally {
			if ($db->inTransaction()) {
				$db->rollback();
			}

			call_user_func([$from, 'unlock']);
			call_user_func([$to, 'unlock']);
		}
	}

	static protected function migrateDirectory(string $from, string $to, string $path, int &$i, ?callable $callback)
	{
		$db = DB::getInstance();

		foreach (call_user_func([$from, 'list'], $path) as $file) {
			if (++$i >= 100) {
				$db->commit();
				$db->begin();
				$i = 0;
			}

			if ($file->type == File::TYPE_DIRECTORY) {
				call_user_func([$to, 'mkdir'], $file);
				self::migrateDirectory($from, $to, $file->path, $i, $callback);
			}
			else {
				$pointer = call_user_func([$from, 'getReadOnlyPointer'], $file);

				if (null !== $pointer) {
					call_user_func([$to, 'storePointer'], $file, $pointer);
				}
				else {
					$path = call_user_func([$from, 'getLocalFilePath'], $file);
					call_user_func([$to, 'storePath'], $file, $path);
				}
			}

			if (null !== $callback) {
				$callback($file);
			}

			unset($file);
		}
	}

	/**
	 * Delete all files from a storage backend
	 */
	static public function truncate(string $backend, $config = null): void
	{
		$backend = __NAMESPACE__ . '\\Storage\\' . $backend;

		call_user_func([$backend, 'configure'], $config);

		if (!class_exists($backend)) {
			throw new \InvalidArgumentException('Invalid storage: ' . $backend);
		}

		call_user_func([$backend, 'truncate']);
	}
}