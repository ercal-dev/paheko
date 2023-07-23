<?php

namespace Paheko\Files;

use Paheko\Static_Cache;
use Paheko\Config;
use Paheko\DB;
use Paheko\DynamicList;
use Paheko\Plugins;
use Paheko\Utils;
use Paheko\UserException;
use Paheko\ValidationException;
use Paheko\Users\DynamicFields;
use Paheko\Users\Session;
use Paheko\Entities\Files\File;
use Paheko\Entities\Web\Page;

use KD2\DB\EntityManager as EM;
use KD2\ZipWriter;

use const Paheko\{FILE_STORAGE_BACKEND, FILE_STORAGE_QUOTA, FILE_STORAGE_CONFIG};

class Files
{
	/**
	 * To enable or disable quota check
	 */
	static protected $quota = true;

	static public function enableQuota(): void
	{
		self::$quota = true;
	}

	static public function disableQuota(): void
	{
		self::$quota = false;
	}

	static public function assertStorageIsUnlocked(): void
	{
		if (self::callStorage('isLocked')) {
			throw new \RuntimeException('File storage is locked');
		}
	}

	static public function listContextsPermissions(Session $s): array
	{
		$perm = self::buildUserPermissions($s);
		$contexts = [
			'Fichiers de votre fiche de membre personnelle' => File::CONTEXT_USER . '/' . $s::getUserId() . '/',
			'Documents de l\'association' => File::CONTEXT_DOCUMENTS,
			'Fichiers des membres' => File::CONTEXT_USER . '//',
			'Fichiers des écritures comptables' => File::CONTEXT_TRANSACTION . '//',
			'Fichiers du site web (contenu des pages, images, etc.)' => File::CONTEXT_WEB . '//',
			'Fichiers de la configuration (logo, etc.)' => File::CONTEXT_CONFIG,
			'Code des modules' => File::CONTEXT_MODULES,
		];

		$out = [];

		foreach ($contexts as $name => $path) {
			$out[$name] = $perm[$path] ?? null;
		}

		return $out;
	}

	/**
	 * Returns an array of all file permissions for a given user
	 */
	static public function buildUserPermissions(Session $s): array
	{
		$is_admin = $s->canAccess($s::SECTION_CONFIG, $s::ACCESS_ADMIN);

		$p = [];

		if (!$s->canAccess($s::SECTION_USERS, $s::ACCESS_WRITE)
			&& $s->isLogged()) {
			$id = $s::getUserId();
			$list = DynamicFields::getInstance()->fieldsByType('file');

			// Add permissions for each field
			foreach ($list as $name => $field) {
				if (!$field->write_access) {
					continue;
				}

				$p[File::CONTEXT_USER . '/' . $id . '/' . $name . '/'] = [
					'mkdir' => false,
					'move' => false,
					'create' => true,
					'read' => true,
					'write' => true,
					'delete' => false,
					'share' => false,
				];
			}

			// The user can always access his own profile files
			$p[File::CONTEXT_USER . '/' . $id . '/'] = [
				'mkdir' => false,
				'move' => false,
				'create' => false,
				'read' => true,
				'write' => false,
				'delete' => false,
				'share' => false,
			];
		}


		// Subdirectories can be managed by member managemnt
		$p[File::CONTEXT_USER . '//'] = [
			'mkdir' => false,
			'move' => false,
			'create' => $s->canAccess($s::SECTION_USERS, $s::ACCESS_WRITE),
			'read' => $s->canAccess($s::SECTION_USERS, $s::ACCESS_READ),
			'write' => $s->canAccess($s::SECTION_USERS, $s::ACCESS_WRITE),
			'delete' => $s->canAccess($s::SECTION_USERS, $s::ACCESS_WRITE),
			'share' => false,
		];

		// Users can't do anything on the root though
		$p[File::CONTEXT_USER] = [
			'mkdir' => false,
			'move' => false,
			'create' => false,
			'write' => false,
			'delete' => false,
			'read' => $s->canAccess($s::SECTION_USERS, $s::ACCESS_READ),
			'share' => false,
		];

		$p[File::CONTEXT_CONFIG] = [
			'mkdir' => false,
			'move' => false,
			'create' => false,
			'read' => $s->isLogged(), // All config files can be accessed by all logged-in users
			'write' => $is_admin,
			'delete' => false,
			'share' => false,
		];

		// Modules source code
		$p[File::CONTEXT_MODULES . '/'] = [
			'mkdir' => $is_admin,
			'move' => $is_admin,
			'create' => $is_admin,
			'read' => $s->isLogged(),
			'write' => $is_admin,
			'delete' => $is_admin,
			'share' => false,
		];

		// Modules source code
		$p[File::CONTEXT_MODULES] = [
			'mkdir' => false,
			'move' => false,
			'create' => false,
			'read' => $s->isLogged(),
			'write' => false,
			'delete' => false,
			'share' => false,
		];

		$p[File::CONTEXT_WEB . '//'] = [
			'mkdir' => false,
			'move' => false,
			'create' => $s->canAccess($s::SECTION_WEB, $s::ACCESS_WRITE),
			'read' => $s->canAccess($s::SECTION_WEB, $s::ACCESS_READ),
			'write' => $s->canAccess($s::SECTION_WEB, $s::ACCESS_WRITE),
			'delete' => $s->canAccess($s::SECTION_WEB, $s::ACCESS_WRITE),
			'share' => false,
		];

		// At root level of web you can only create new articles
		$p[File::CONTEXT_WEB] = [
			'mkdir' => $s->canAccess($s::SECTION_WEB, $s::ACCESS_WRITE),
			'move' => false,
			'create' => false,
			'read' => $s->canAccess($s::SECTION_WEB, $s::ACCESS_READ),
			'write' => false,
			'delete' => false,
			'share' => false,
		];

		// Documents: you can do everything as long as you have access
		$p[File::CONTEXT_DOCUMENTS . '/'] = [
			'mkdir' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
			'move' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
			'create' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
			'read' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_READ),
			'write' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
			'delete' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_ADMIN),
			'share' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
		];

		// The root directory cannot be deleted or renamed/moved
		$p[File::CONTEXT_DOCUMENTS] = [
			'mkdir' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
			'move' => false,
			'create' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
			'read' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_READ),
			'write' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
			'delete' => false,
			'share' => $s->canAccess($s::SECTION_DOCUMENTS, $s::ACCESS_WRITE),
		];

		// You can write in transaction subdirectories
		$p[File::CONTEXT_TRANSACTION . '//'] = [
			'mkdir' => false,
			'move' => false,
			'create' => $s->canAccess($s::SECTION_ACCOUNTING, $s::ACCESS_WRITE),
			'read' => $s->canAccess($s::SECTION_ACCOUNTING, $s::ACCESS_READ),
			'write' => $s->canAccess($s::SECTION_ACCOUNTING, $s::ACCESS_WRITE),
			'delete' => $s->canAccess($s::SECTION_ACCOUNTING, $s::ACCESS_ADMIN),
			'share' => $s->canAccess($s::SECTION_ACCOUNTING, $s::ACCESS_WRITE),
		];

		// But not in root
		$p[File::CONTEXT_TRANSACTION] = [
			'mkdir' => false,
			'move' => false,
			'write' => false,
			'create' => false,
			'delete' => false,
			'read' => $s->canAccess($s::SECTION_ACCOUNTING, $s::ACCESS_READ),
			'share' => false,
		];

		$p[''] = [
			'mkdir' => false,
			'move' => false,
			'write' => false,
			'create' => false,
			'delete' => false,
			'read' => true,
			'share' => false,
		];

		return $p;
	}

	static public function search(string $search, string $path = null): array
	{
		if (strlen($search) > 100) {
			throw new ValidationException('Recherche trop longue : maximum 100 caractères');
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
			snippet(files_search, \'<mark>\', \'</mark>\', \'…\', 2, -30) AS snippet,
			rank(matchinfo(files_search), 0, 1.0, 1.0) AS points
			FROM files_search
			WHERE files_search MATCH ? %s
			ORDER BY points DESC
			LIMIT 0,50;', $where);

		$out = [];

		$db = DB::getInstance();
		$db->begin();

		foreach ($db->iterate($query, ...$params) as $row) {
			$out[] = $row;
		}

		$db->commit();

		return $out;
	}

	static protected function _getParentClause(?string $parent = null): string
	{
		$db = DB::getInstance();

		if (!$parent) {
			return $db->where('parent', 'IN', array_keys(File::CONTEXTS_NAMES));
		}
		else {
			File::validatePath($parent);
			return 'parent = ' . $db->quote($parent);
		}
	}

	/**
	 * Returns a list of files and directories inside a parent path
	 * This is not recursive and will only return files and directories
	 * directly in the specified $parent path.
	 */
	static public function list(?string $parent = null): array
	{
		$where = self::_getParentClause($parent);
		$sql = sprintf('SELECT * FROM @TABLE WHERE trash IS NULL AND %s ORDER BY type DESC, name COLLATE U_NOCASE ASC;', $where);

		return EM::getInstance(File::class)->all($sql);
	}

	static public function getDynamicList(?string $parent = null): DynamicList
	{
		$columns = [
			'icon' => ['label' => '', 'select' => NULL],
			'name' => [
				'label' => 'Nom',
				'order' => 'type DESC, name COLLATE U_NOCASE %s',
			],
			'size' => [
				'label' => 'Taille',
				'order' => 'type DESC, size %s, name COLLATE U_NOCASE ASC',
			],
			'modified' => [
				'label' => 'Modifié',
				'order' => 'type DESC, modified %s, name COLLATE U_NOCASE ASC',
			],
		];

		$tables = File::TABLE;
		$conditions = self::_getParentClause($parent);
		$conditions .= ' AND trash IS NULL';

		$list = new DynamicList($columns, $tables, $conditions);

		$list->orderBy('name', false);
		$list->setEntity(File::class);

		// Don't take conditions for saving preferences hash
		$list->togglePreferenceHashElement('conditions', false);

		return $list;
	}

	static public function listForUser(int $id, string $field_name = null): array
	{
		$files = [];
		$path = (string) $id;

		if ($field_name) {
			$path .= '/' . $field_name;
			return self::listForContext(File::CONTEXT_USER, $path);
		}

		$list = self::listForContext(File::CONTEXT_USER, $path);

		if (!$list) {
			return [];
		}

		foreach ($list as $dir) {
			foreach (Files::list($dir->path) as $file) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Returns a list of files or directories matching a glob pattern
	 * only * and ? characters are supported in pattern
	 * Sub-directories are not returned
	 */
	static public function glob(string $pattern): array
	{
		return EM::getInstance(File::class)->all(
			'SELECT * FROM @TABLE WHERE path GLOB ? AND path NOT GLOB ? ORDER BY type DESC, name COLLATE U_NOCASE ASC;',
			$pattern,
			$pattern . '/*'
		);
	}

	static public function zipAll(?string $target = null): void
	{
		Files::zip($target, array_keys(File::CONTEXTS_NAMES), null);
	}

	/**
	 * Creates a ZIP file archive from multiple paths
	 * @param null|string $target Target file name, if left NULL, then will be sent to browser
	 * @param  array $paths List of paths to append to ZIP file
	 * @param  Session $session Logged-in user session, if set access rights to the path will be checked,
	 * if left NULL, then no check will be made (!).
	 */
	static public function zip(?string $target, array $paths, ?Session $session, ?string $download_name = null): void
	{
		if (!$target) {
			$download_name ??= Config::getInstance()->org_name . ' - Documents';
			header('Content-type: application/zip');
			header(sprintf('Content-Disposition: attachment; filename="%s"', $download_name. '.zip'));
			$target = 'php://output';
		}

		$zip = new ZipWriter($target);
		$zip->setCompression(0);

		foreach ($paths as $path) {
			foreach (Files::listRecursive($path, $session, false) as $file) {
				$pointer = $file->getReadOnlyPointer();
				$path = !$pointer ? $file->getLocalFilePath() : null;

				if (!$path && !$pointer) {
					continue;
				}

				$zip->add($file->path, null, $path, $pointer);

				if ($pointer) {
					fclose($pointer);
				}
			}
		}

		$zip->close();
	}

	static public function listRecursive(?string $path = null, ?Session $session = null, bool $include_directories = true): \Generator
	{
		$params = [];
		$db = DB::getInstance();

		if (!$path) {
			$sql = 'SELECT * FROM @TABLE WHERE parent IS NULL AND trash IS NULL;';
		}
		else {
			$sql = 'SELECT * FROM @TABLE WHERE path = ? OR (parent = ? OR parent LIKE ? ESCAPE \'!\')';

			if (!$include_directories) {
				$sql .= ' AND type != ' . File::TYPE_DIRECTORY;
			}

			$sql .= ';';
			$params = [$path, $path, $db->escapeLike($path, '!') . '/%'];
		}

		$list = EM::getInstance(File::class)->iterate($sql, ...$params);

		foreach ($list as $file) {
			if ($session && !$file->canRead($session)) {
				continue;
			}

			yield $file->path => $file;
		}
	}

	static public function all(bool $include_trash = false)
	{
		$sql = 'SELECT * FROM @TABLE';

		if (!$include_trash) {
			$sql .= ' WHERE trash IS NULL';
		}

		return EM::getInstance(File::class)->all($sql);
	}

	/**
	 * List files and directories inside a context (first-level directory)
	 */
	static public function listForContext(string $context, ?string $ref = null): array
	{
		$path = $context;

		if ($ref) {
			$path .= '/' . $ref;
		}

		return self::list($path) ?? [];
	}

	/**
	 * Delete a specified file/directory path
	 */
	static public function delete(string $path): void
	{
		$file = self::get($path);

		if (!$file) {
			return;
		}

		$file->delete();
	}

	static public function callStorage(string $function, ...$args)
	{
		$class_name = __NAMESPACE__ . '\\Storage\\' . FILE_STORAGE_BACKEND;

		call_user_func([$class_name, 'configure'], FILE_STORAGE_CONFIG);

		return call_user_func_array([$class_name, $function], $args);
	}

	static public function ensureContextsExists(): void
	{
		foreach (File::CONTEXTS_NAMES as $key => $label) {
			self::ensureDirectoryExists($key);
		}
	}

	/**
	 * Returns a file, if it doesn't exist, NULL is returned
	 * If the file exists in DB but not in storage, it is deleted from DB
	 */
	static public function get(?string $path): ?File
	{
		if (null === $path) {
			// Root always exists, but is virtual
			$file = new File;
			$file->path = '';
			$file->name = '';
			$file->parent = null;
			$file->type = $file::TYPE_DIRECTORY;
			return $file;
		}

		try {
			File::validatePath($path);
		}
		catch (ValidationException $e) {
			return null;
		}

		$file = EM::findOne(File::class, 'SELECT * FROM @TABLE WHERE path = ? LIMIT 1;', $path);

		if (!$file && array_key_exists($path, File::CONTEXTS_NAMES)) {
			self::ensureContextsExists();
			$file = EM::findOne(File::class, 'SELECT * FROM @TABLE WHERE path = ? LIMIT 1;', $path);
		}

		if (!$file) {
			return null;
		}

		return $file;
	}

	static public function exists(string $path): bool
	{
		return DB::getInstance()->test('files', 'path = ?', $path);
	}

	static public function getFromURI(string $uri): ?File
	{
		$uri = trim($uri, '/');
		$uri = rawurldecode($uri);

		return self::get($uri, File::TYPE_FILE);
	}

	static public function getContext(string $path): ?string
	{
		$pos = strpos($path, '/');

		if (false === $pos) {
			return $path;
		}

		$context = substr($path, 0, $pos);

		if (!$context) {
			return null;
		}

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
		$path = '';

		foreach ($parts as $part) {
			$path = trim($path . '/' . $part, '/');
			$breadcrumbs[$path] = $part;
		}

		return $breadcrumbs;
	}

	static public function getQuota(): float
	{
		return FILE_STORAGE_QUOTA ?? self::callStorage('getQuota');
	}

	static public function getUsedQuota(): float
	{
		return (float) DB::getInstance()->firstColumn('SELECT SUM(size) FROM files;');
	}

	static public function getRemainingQuota(): float
	{
		$quota = self::getQuota();

		return max(0, $quota - self::getUsedQuota());
	}

	static public function checkQuota(int $size = 0): void
	{
		if (!self::$quota) {
			return;
		}

		$remaining = self::getRemainingQuota();

		if (($remaining - (float) $size) <= 0) {
			throw new ValidationException('L\'espace disque est insuffisant pour réaliser cette opération');
		}
	}

	static protected function create(string $parent, string $name, array $source = []): File
	{
		Files::checkQuota();

		File::validateFileName($name);
		File::validatePath($parent);

		File::validateCanHTML($name, $parent);

		$name = File::filterName($name);

		$target = $parent . '/' . $name;

		self::ensureDirectoryExists($parent);
		$finfo = \finfo_open(\FILEINFO_MIME_TYPE);

		$file = self::get($target);

		if (!$file) {
			$file = new File;
			$file->set('path', $target);
			$file->set('parent', $parent);
			$file->set('name', $name);
		}

		if (isset($source['pointer'])) {
			if (0 !== fseek($source['pointer'], 0, SEEK_END)) {
				throw new \RuntimeException('Stream is not seekable');
			}

			$file->set('size', ftell($source['pointer']));
			fseek($source['pointer'], 0, SEEK_SET);
			$file->set('mime', mime_content_type($source['pointer']));
		}
		elseif (isset($source['path'])) {
			$file->set('mime', finfo_file($finfo, $source['path']));
			$file->set('size', filesize($source['path']));
			$file->set('modified', new \DateTime('@' . filemtime($source['path'])));
		}
		elseif (isset($source['content'])) {
			$file->set('size', strlen($source['content']));
			$file->set('mime', finfo_buffer($finfo, $source['content']));
		}
		else {
			$file->set('size', 0);
			$file->set('mime', 'text/plain');
		}

		$file->set('image', in_array($file->mime, $file::IMAGE_TYPES));

		// Force empty files as text/plain
		if ($file->mime == 'application/x-empty' && !$file->size) {
			$file->set('mime', 'text/plain');
		}

		return $file;
	}

	static public function createDocument(string $parent, string $name, string $extension): File
	{
		// From https://github.com/nextcloud/richdocuments/tree/2338e2ff7078040d54fc0c70a96c8a1b860f43a0/emptyTemplates
		// We need to copy an empty template, or Collabora will create flat-XML file
		if ($extension == 'ods') {
			$tpl = 'UEsDBBQAAAAAAOw6wVCFbDmKLgAAAC4AAAAIAAAAbWltZXR5cGVhcHBsaWNhdGlvbi92bmQub2FzaXMub3BlbmRvY3VtZW50LnNwcmVhZHNoZWV0UEsDBBQAAAAIABxZFFFL43PrmgAAAEABAAAVAAAATUVUQS1JTkYvbWFuaWZlc3QueG1slVDRDoMgDHz3KwjvwvZK1H9poEYSKETqon8vLpluWfawPrXXy921XQTyIxY2r0asMVA5x14uM5kExRdDELEYtiZlJJfsEpHYfPLNXd2kGBpRqzvB0QdsK3nexIUtIbQZeOqllhcc0XloecvYS8g5eAvsE+kHOfWMod7dVckzgisTIkv9p61NxIdGveBHAMaV9bGu0p3++tXQ7FBLAwQUAAAACAAAWRRRA4GGVIkAAAD/AAAACwAAAGNvbnRlbnQueG1sXY/RCsIwDEWf9SvG3uv0Ncz9S01TLLTNWFJwf29xbljzEu49N1wysvcBCRxjSZTVIGetu3ulmAU2eu/LkoGtBIFsEwkoAs+U9yv4TcPtcu2nc1dn/DqCS5hVuqG1fe0y3iIZRxg/+LQzW5ST1YBGdI3Uwge7tcpDy7yQdfIk0i03NMFD/n85vQFQSwECFAMUAAAAAADsOsFQhWw5ii4AAAAuAAAACAAAAAAAAAAAAAAAtIEAAAAAbWltZXR5cGVQSwECFAMUAAAACAAcWRRRS+Nz65oAAABAAQAAFQAAAAAAAAAAAAAAtIFUAAAATUVUQS1JTkYvbWFuaWZlc3QueG1sUEsBAhQDFAAAAAgAAFkUUQOBhlSJAAAA/wAAAAsAAAAAAAAAAAAAALSBIQEAAGNvbnRlbnQueG1sUEsFBgAAAAADAAMAsgAAANMBAAAAAA==';
		}
		elseif ($extension == 'odp') {
			$tpl = 'UEsDBBQAAAAAAC6dVEszJqyoLwAAAC8AAAAIAAAAbWltZXR5cGVhcHBsaWNhdGlvbi92bmQub2FzaXMub3BlbmRvY3VtZW50LnByZXNlbnRhdGlvblBLAwQUAAAACAAsYRRRP7fJFJoAAABBAQAAFQAAAE1FVEEtSU5GL21hbmlmZXN0LnhtbJVQwQqDMAy97ytK77bbNaj/EmpkhTYtNg79+1VhujF2WC5JXh7vJWkjsh+pCLwKtcTA5Wg7PU8MCYsvwBipgDhImXhIbo7EAp98uJmrVv1F1WgPcPSBmkqeVnVicwhNRrl32uoTjjR4bGTN1GnMOXiH4hPbBw9mX8O8u5s8Ual552j7p69LLJtIPeHHBkKL2G1cpVv79az+8gRQSwMEFAAAAAgAMl4UUXz4vRWJAAAA/gAAAAsAAABjb250ZW50LnhtbF2P0QqDMAxFn+dXiO+d22tw/ksXUyjYpJgI8+8tOGVdXsK994Qkg4QQkWASXBOxORS20ttPmlnhSF/dujCI16jAPpGCIUgmPqfgl4bn/dGNTVtq+DqKS8ymbT82t9MLZZELHslNhHOd+dUkeYvo1LaZ6vAt01bkpfNCWm4ouPAB9hV5yf8fx2YHUEsBAhQDFAAAAAAALp1USzMmrKgvAAAALwAAAAgAAAAAAAAAAAAAALSBAAAAAG1pbWV0eXBlUEsBAhQDFAAAAAgALGEUUT+3yRSaAAAAQQEAABUAAAAAAAAAAAAAALSBVQAAAE1FVEEtSU5GL21hbmlmZXN0LnhtbFBLAQIUAxQAAAAIADJeFFF8+L0ViQAAAP4AAAALAAAAAAAAAAAAAAC0gSIBAABjb250ZW50LnhtbFBLBQYAAAAAAwADALIAAADUAQAAAAA=';
		}
		else {
			$extension = 'odt';
			$tpl = 'UEsDBBQAAAAAAPMbH0texjIMJwAAACcAAAAIAAAAbWltZXR5cGVhcHBsaWNhdGlvbi92bmQub2FzaXMub3BlbmRvY3VtZW50LnRleHRQSwMEFAAAAAgA3U0SUeqX5meSAAAAMQEAABUAAABNRVRBLUlORi9tYW5pZmVzdC54bWyVUEEOgzAMu+8VqHfa7Rq1/CUqQavUphUNE/wemDTYNO2wW2I7thWbkMNAVeA1NHOKXI/VqWlkyFhDBcZEFcRDLsR99lMiFvjUw01fVXdp7AEMIVK7CcelObEpxrag3J0y6oQT9QFbWQo5haXE4FFCZvPgXj8r6PdkLTSLMv+E+cyyX26df8TunmanN19rvr7TrVBLAwQUAAAACACQThJRWmJBaH8AAADjAAAACwAAAGNvbnRlbnQueG1sXY/RCsMgDEXf+xWj767ba+j8FxcjCGpKE6H9+wlbRfYUbs69uWTlECISeMaaqahBLtrm7cipCHzpa657AXYSBYrLJKAIvFG5UjC64Xl/zHZaf0pwj5vKYq9FaA0mOCTjCdMAXFXOTiMa0TNRI/3Im/3ZfUqHttQysqnL/0/sB1BLAQIUAxQAAAAAAPMbH0texjIMJwAAACcAAAAIAAAAAAAAAAAAAACkgQAAAABtaW1ldHlwZVBLAQIUAxQAAAAIAN1NElHql+ZnkgAAADEBAAAVAAAAAAAAAAAAAACkgU0AAABNRVRBLUlORi9tYW5pZmVzdC54bWxQSwECFAMUAAAACACQThJRWmJBaH8AAADjAAAACwAAAAAAAAAAAAAApIESAQAAY29udGVudC54bWxQSwUGAAAAAAMAAwCyAAAAugEAAAAA';
		}

		$target = $parent . '/' . $name . '.' . $extension;

		if (self::exists($target)) {
			throw new ValidationException('Un document existe déjà avec ce nom : ' . $name . '.' .$extension);
		}

		return Files::createFromString($target, base64_decode($tpl));
	}

	static public function createObject(string $target)
	{
		$parent = Utils::dirname($target);
		$name = Utils::basename($target);
		return self::create($parent, $name);
	}

	static protected function createFrom(string $target, array $source): File
	{
		$parent = Utils::dirname($target);
		$name = Utils::basename($target);
		$file = self::create($parent, $name, $source);
		$file->store($source);
		return $file;
	}

	/**
	 * Create and store a file from a local path
	 * @param  string $target         Target parent path + name
	 * @param  string $path    Source file path
	 * @return File
	 */
	static public function createFromPath(string $target, string $path): File
	{
		return self::createFrom($target, compact('path'));
	}

	/**
	 * Create and store a file from a string
	 * @param  string $target         Target parent path + name
	 * @param  string $content    Source file contents
	 * @return File
	 */
	static public function createFromString(string $target, string $content): File
	{
		return self::createFrom($target, compact('content'));
	}

	static public function createFromPointer(string $target, $pointer): File
	{
		return self::createFrom($target, compact('pointer'));
	}

	/**
	 * Upload multiple files
	 * @param  string $parent Target parent directory (eg. 'documents/Logos')
	 * @param  string $key  The name of the file input in the HTML form (this MUST have a '[]' at the end of the name)
	 * @return array list of File objects created
	 */
	static public function uploadMultiple(string $parent, string $key): array
	{
		if (!isset($_FILES[$key]['name'][0])) {
			throw new UserException('Aucun fichier reçu');
		}

		// Transpose array
		// see https://www.php.net/manual/en/features.file-upload.multiple.php#53240
		$files = Utils::array_transpose($_FILES[$key]);
		$out = [];

		// First check all files
		foreach ($files as $file) {
			if (!empty($file['error'])) {
				throw new UserException(self::getUploadErrorMessage($file['error']));
			}

			if (empty($file['size']) || empty($file['name'])) {
				throw new UserException('Fichier reçu invalide : vide ou sans nom de fichier.');
			}

			if (!is_uploaded_file($file['tmp_name'])) {
				throw new \RuntimeException('Le fichier n\'a pas été envoyé de manière conventionnelle.');
			}
		}

		// Then create files
		foreach ($files as $file) {
			$name = File::filterName($file['name']);
			$out[] = self::createFromPath($parent . '/' . $name, $file['tmp_name']);
		}

		return $out;
	}

	/**
	 * Upload a file using POST from a HTML form
	 * @param  string $parent Target parent directory (eg. 'documents/Logos')
	 * @param  string $key  The name of the file input in the HTML form
	 * @return self Created file object
	 */
	static public function upload(string $parent, string $key, ?string $name = null): File
	{
		if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
			throw new UserException('Aucun fichier reçu');
		}

		$file = $_FILES[$key];

		if (!empty($file['error'])) {
			throw new UserException(self::getUploadErrorMessage($file['error']));
		}

		if (empty($file['size']) || empty($file['name'])) {
			throw new UserException('Fichier reçu invalide : vide ou sans nom de fichier.');
		}

		if (!is_uploaded_file($file['tmp_name'])) {
			throw new \RuntimeException('Le fichier n\'a pas été envoyé de manière conventionnelle.');
		}

		$name = File::filterName($name ?? $file['name']);

		return self::createFromPath($parent . '/' . $name, $file['tmp_name']);
	}


	/**
	 * Récupération du message d'erreur
	 * @param  integer $error Code erreur du $_FILE
	 * @return string Message d'erreur
	 */
	static public function getUploadErrorMessage($error)
	{
		switch ($error)
		{
			case UPLOAD_ERR_INI_SIZE:
				return 'Le fichier excède la taille permise par la configuration.';
			case UPLOAD_ERR_FORM_SIZE:
				return 'Le fichier excède la taille permise par le formulaire.';
			case UPLOAD_ERR_PARTIAL:
				return 'L\'envoi du fichier a été interrompu.';
			case UPLOAD_ERR_NO_FILE:
				return 'Aucun fichier n\'a été reçu.';
			case UPLOAD_ERR_NO_TMP_DIR:
				return 'Pas de répertoire temporaire pour stocker le fichier.';
			case UPLOAD_ERR_CANT_WRITE:
				return 'Impossible d\'écrire le fichier sur le disque du serveur.';
			case UPLOAD_ERR_EXTENSION:
				return 'Une extension du serveur a interrompu l\'envoi du fichier.';
			default:
				return 'Erreur inconnue: ' . $error;
		}
	}

	/**
	 * Create a new directory
	 * @param  string $parent        Target parent path
	 * @param  string $name          Target name
	 * @param  bool   $create_parent Create parent directories if they don't exist
	 * @return self
	 */
	static public function mkdir(string $path, bool $create_parent = true): File
	{
		$path = trim($path, '/');
		$parent = Utils::dirname($path);
		$name = Utils::basename($path);

		$name = File::filterName($name);
		$path = trim($parent . '/' . $name, '/');

		File::validatePath($path);

		Files::checkQuota();

		if (self::exists($path)) {
			throw new ValidationException('Le nom de répertoire choisi existe déjà: ' . $path);
		}

		if ($parent !== null && $create_parent) {
			self::ensureDirectoryExists($parent);
		}

		$file = new File;
		$type = $file::TYPE_DIRECTORY;
		$file->import(compact('path', 'name', 'parent') + [
			'type'     => file::TYPE_DIRECTORY,
			'image'    => false,
		]);

		$file->modified = new \DateTime;
		$file->save();

		Plugins::fireSignal('files.mkdir', compact('file'));

		return $file;
	}

	static public function ensureDirectoryExists(string $path): void
	{
		$parts = explode('/', trim($path, '/'));
		$parts = array_filter($parts);
		$tree = '';

		foreach ($parts as $part) {
			$tree = trim($tree . '/' . $part, '/');

			if (!self::exists($tree)) {
				self::mkdir($tree, false);
			}
		}
	}

	/**
	 * Return list of context that can be read by currently logged user
	 */
	static public function listReadAccessContexts(?Session $session): array
	{
		if (!$session->isLogged()) {
			return [];
		}

		$list = [];

		if ($session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN)) {
			$access[] = File::CONTEXT_CONFIG;
			$access[] = File::CONTEXT_MODULES;
		}

		if ($session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ)) {
			$access[] = File::CONTEXT_TRANSACTION;
		}

		if ($session->canAccess($session::SECTION_USERS, $session::ACCESS_READ)) {
			$access[] = File::CONTEXT_USER;
		}

		if ($session->canAccess($session::SECTION_DOCUMENTS, $session::ACCESS_READ)) {
			$access[] = File::CONTEXT_DOCUMENTS;
		}

		if ($session->canAccess($session::SECTION_WEB, $session::ACCESS_READ)) {
			$access[] = File::CONTEXT_WEB;
		}

		return array_intersect_key(File::CONTEXTS_NAMES, array_flip($access));
	}

	/**
	 * Remove empty directories in transactions and users
	 */
	static public function pruneEmptyDirectories(): void
	{
		$sql = sprintf('SELECT * FROM @TABLE a
			WHERE type = %d
				AND (path LIKE \'%s/\' OR path LIKE \'%s/\')
				AND (SELECT COUNT(*) FROM @TABLE b WHERE b.parent = a.path AND type = %d) = 0;',
			File::TYPE_DIRECTORY,
			File::CONTEXT_TRANSACTION,
			File::CONTEXT_USER,
			File::TYPE_FILE
		);

		foreach (EM::getInstance(File::class)->all($sql) as $dir) {
			$dir->delete();
		}
	}

	static public function getIconShape(string $name)
	{
		$ext = substr($name, strrpos($name, '.') + 1);
		$ext = strtolower($ext);

		switch ($ext) {
			case 'ods':
			case 'xls':
			case 'xlsx':
			case 'csv':
				return 'table';
			case 'odt':
			case 'doc':
			case 'docx':
			case 'rtf':
				return 'document';
			case 'pdf':
				return 'pdf';
			case 'odp':
			case 'ppt':
			case 'pptx':
				return 'gallery';
			case 'txt':
			case 'skriv':
				return 'text';
			case 'md':
				return 'markdown';
			case 'html':
			case 'css':
			case 'js':
			case 'tpl':
				return 'code';
			case 'mkv':
			case 'mp4':
			case 'avi':
			case 'ogm':
			case 'ogv':
				return 'video';
			case 'png':
			case 'jpg':
			case 'jpeg':
			case 'webp':
			case 'gif':
			case 'svg':
				return 'image';
		}

		return 'document';
	}
}