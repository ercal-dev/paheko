<?php

namespace Garradin\Files;

use Garradin\Static_Cache;
use Garradin\DB;
use Garradin\Utils;
use Garradin\ValidationException;
use Garradin\Membres\Session;
use Garradin\Entities\Files\File;
use Garradin\Entities\Web\Page;

use KD2\DB\EntityManager as EM;

use const Garradin\{FILE_STORAGE_BACKEND, FILE_STORAGE_QUOTA, FILE_STORAGE_CONFIG};

class Files
{
	static public function redirectOldWikiPage(string $uri): void {
		$uri = Utils::transformTitleToURI($uri);

		$db = DB::getInstance();

		if ($db->test(Page::TABLE, 'uri = ?')) {
			Utils::redirect('!web/page.php?uri=' . $uri);
		}
	}

	static public function search(string $search, string $path = null): array
	{
		if (strlen($search) > 100) {
			throw new UserException('Recherche trop longue : maximum 100 caractères');
		}

		$where = '';
		$params = [trim($search)];

		if (null !== $path) {
			$where = ' AND path LIKE ?';
			$params[] = $path;
		}

		$query = sprintf('SELECT
			*,
			dirname(path) AS parent,
			snippet(files_search, \'<b>\', \'</b>\', \'…\', 2) AS snippet,
			rank(matchinfo(files_search), 0, 1.0, 1.0) AS points
			FROM files_search
			WHERE files_search MATCH ? %s
			ORDER BY points DESC
			LIMIT 0,50;', $where);

		return DB::getInstance()->get($query, ...$params);
	}

	static public function list(string $path = ''): array
	{
		File::validatePath($path);

		// Update this path
		self::callStorage('sync', $path);

		return EM::getInstance(File::class)->all('SELECT * FROM @TABLE WHERE path = ? ORDER BY type DESC, name COLLATE NOCASE ASC;', $path);
	}

	static public function listAllDirectoriesAssoc(string $context): array
	{
		return DB::getInstance()->getAssoc('SELECT
			TRIM(path || \'/\' || name, \'/\'),
			TRIM(REPLACE(path, ?, \'\') || \'/\' || name, \'/\')
			FROM files WHERE (path = ? OR path LIKE ?) AND type = ? ORDER BY path COLLATE NOCASE, name COLLATE NOCASE;', $context, $context, $context . '/%', File::TYPE_DIRECTORY);
	}

	static public function delete(string $path): void
	{
		$file = self::get($path);

		if (!$file) {
			return;
		}

		$file->delete();
	}

	/**
	 * Creates a new temporary table files_tmp containg all files from the path argument
	 */
	static public function listToSQL(string $path): int
	{
		$db = DB::getInstance();
		$db->begin();

		$columns = File::getColumns();
		$db->exec(sprintf('CREATE TEMP TABLE IF NOT EXISTS files_tmp (%s);', implode(',', $columns)));

		$i = 0;

		foreach (self::list($path) as $file) {
			$file = $file->asArray();
			unset($file['id']);
			$db->insert('files_tmp', $file);
			$i++;
		}

		$db->commit();
		return $i;
	}

	static public function callStorage(string $function, ...$args)
	{
		$storage = FILE_STORAGE_BACKEND ?? 'SQLite';
		$class_name = __NAMESPACE__ . '\\Storage\\' . $storage;

		call_user_func([$class_name, 'configure'], FILE_STORAGE_CONFIG);

		// Check that we can store this data
		if ($function == 'store') {
			$quota = FILE_STORAGE_QUOTA ?: self::callStorage('getQuota');
			$used = self::callStorage('getTotalSize');

			$size = $args[0] ? filesize($args[1]) : strlen($args[2]);

			if (($used + $size) >= $quota) {
				throw new \OutOfBoundsException('File quota has been exhausted');
			}
		}

		return call_user_func_array([$class_name, $function], $args);
	}

	/**
	 * Copy all files from a storage backend to another one
	 * This can be used to move from SQLite to FileSystem for example
	 * Note that this only copies files, and is not removing them from the source storage backend.
	 */
	static public function migrateStorage(string $from, string $to, $from_config = null, $to_config = null, ?callable $callback = null): void
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

		try {
			call_user_func([$from, 'checkLock']);
			call_user_func([$to, 'checkLock']);

			//call_user_func([$from, 'lock']);
			//call_user_func([$to, 'lock']);

			$db = DB::getInstance();
			$db->begin();

			foreach ($db->iterate('SELECT * FROM files ORDER BY type DESC, path ASC, name ASC;') as $file) {
				$f = new File;
				$f->load((array) $file);

				if ($f->type == File::TYPE_DIRECTORY) {
					call_user_func([$to, 'mkdir'], $f);
				}
				else {
					$from_path = call_user_func([$from, 'getFullPath'], $f);
					call_user_func([$to, 'storePath'], $f, $from_path);
				}

				if (null !== $callback) {
					$callback($f);
				}
			}

			$db->commit();
		}
		finally {
			call_user_func([$from, 'unlock']);
			call_user_func([$to, 'unlock']);
		}
	}

	/**
	 * Delete all files from a storage backend
	 */
	static public function truncateStorage(string $backend, $config = null): void
	{
		$backend = __NAMESPACE__ . '\\Storage\\' . $backend;

		call_user_func([$backend, 'configure'], $config);

		if (!class_exists($backend)) {
			throw new \InvalidArgumentException('Invalid storage: ' . $backend);
		}

		call_user_func([$backend, 'truncate']);
	}

	static public function get(?string $path, ?string $name = null, int $type = null): ?File
	{
		if (null === $path) {
			return null;
		}

		$fullpath = $path;

		if ($name) {
			$fullpath .= '/' . $name;
		}

		try {
			File::validatePath($fullpath);
		}
		catch (ValidationException $e) {
			return null;
		}

		$path = dirname($fullpath);
		$name = basename($fullpath);
		$where = '';

		if (null !== $type) {
			$where = ' AND type = ' . $type;
		}

		$sql = sprintf('SELECT * FROM @TABLE WHERE path = ? AND name = ? %s LIMIT 1;', $where);

		$file = EM::findOne(File::class, $sql, $path, $name);

		if (null !== $file) {
			$file = self::callStorage('update', $file);
		}

		return $file;
	}

	static public function getFromURI(string $uri): ?File
	{
		$uri = trim($uri, '/');
		$uri = rawurldecode($uri);

		$context = substr($uri, 0, strpos($uri, '/'));

		// Use alias for web files
		if (!array_key_exists($context, File::CONTEXTS_NAMES)) {
			$uri = File::CONTEXT_WEB . '/' . $uri;
		}

		return self::get($uri, null, File::TYPE_FILE);
	}

	static public function getContext(string $path): ?string
	{
		$context = strtok($path, '/');

		if (!array_key_exists($context, File::CONTEXTS_NAMES)) {
			return null;
		}

		return $context;
	}

	static public function getContextRef(string $path): ?string
	{
		$context = strtok($path, '/');
		return strtok('/') ?: null;
	}

	static public function getBreadcrumbs(string $path): array
	{
		$parts = explode('/', $path);
		$breadcrumbs = [];

		foreach ($parts as $part) {
			$path = trim(key($breadcrumbs) . '/' . $part, '/');
			$breadcrumbs[$path] = $part;
		}

		return $breadcrumbs;
	}
}
