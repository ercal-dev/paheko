<?php

namespace Paheko\Entities\Files;

use KD2\Graphics\Image;
use KD2\Graphics\Blob;
use KD2\DB\EntityManager as EM;
use KD2\Security;
use KD2\WebDAV\WOPI;
use KD2\Office\ToText;

use Paheko\Config;
use Paheko\DB;
use Paheko\Entity;
use Paheko\Plugins;
use Paheko\Template;
use Paheko\UserException;
use Paheko\ValidationException;
use Paheko\Users\Session;
use Paheko\Static_Cache;
use Paheko\Utils;
use Paheko\Entities\Web\Page;
use Paheko\Web\Render\Render;
use Paheko\Web\Router;
use Paheko\Web\Cache as Web_Cache;
use Paheko\Files\WebDAV\Storage;
use Paheko\Users\DynamicFields;

use Paheko\Files\Files;

use const Paheko\{WWW_URL, BASE_URL, ENABLE_XSENDFILE, SECRET_KEY, WOPI_DISCOVERY_URL, SHARED_CACHE_ROOT, PDFTOTEXT_COMMAND};

/**
 * This is a virtual entity, it cannot be saved to a SQL table
 */
class File extends Entity
{
	const TABLE = 'files';
	const EXTENSIONS_TEXT_CONVERT = ['ods', 'odt', 'odp', 'pptx', 'xlsx', 'docx', 'pdf'];

	protected ?int $id;

	/**
	 * Parent directory of file
	 */
	protected ?string $parent = null;

	/**
	 * File name
	 */
	protected string $name;

	/**
	 * Complete file path (parent + '/' + name)
	 */
	protected string $path;

	/**
	 * Type of file: file or directory
	 */
	protected int $type = self::TYPE_FILE;
	protected ?string $mime = null;
	protected ?int $size = null;
	protected \DateTime $modified;
	protected bool $image;
	protected ?string $md5;
	protected ?\DateTime $trash = null;

	const TYPE_FILE = 1;
	const TYPE_DIRECTORY = 2;
	const TYPE_LINK = 3;

	/**
	 * Tailles de miniatures autorisées, pour ne pas avoir 500 fichiers générés avec 500 tailles différentes
	 * @var array
	 */
	const ALLOWED_THUMB_SIZES = [
		'150px' => [['resize', 150]],
		'200px' => [['resize', 200]],
		'500px' => [['resize', 500]],
		'crop-256px' => [['cropResize', 256, 256]],
	];

	const THUMB_CACHE_ID = 'file.thumb.%s.%s';

	const THUMB_SIZE_TINY = '200px';
	const THUMB_SIZE_SMALL = '500px';

	const CONTEXT_DOCUMENTS = 'documents';
	const CONTEXT_USER = 'user';
	const CONTEXT_TRANSACTION = 'transaction';
	const CONTEXT_CONFIG = 'config';
	const CONTEXT_WEB = 'web';
	const CONTEXT_MODULES = 'modules';
	const CONTEXT_ATTACHMENTS = 'attachments';

	/**
	 * @deprecated
	 */
	const CONTEXT_SKELETON = 'skel';

	const CONTEXTS_NAMES = [
		self::CONTEXT_DOCUMENTS => 'Documents',
		self::CONTEXT_USER => 'Membre',
		self::CONTEXT_TRANSACTION => 'Écriture comptable',
		self::CONTEXT_CONFIG => 'Configuration',
		self::CONTEXT_WEB => 'Site web',
		self::CONTEXT_MODULES => 'Modules',
		self::CONTEXT_SKELETON => 'Squelettes',
		self::CONTEXT_ATTACHMENTS => 'Fichiers joints aux messages',
	];

	const IMAGE_TYPES = [
		'image/png',
		'image/gif',
		'image/jpeg',
		'image/webp',
	];

	const PREVIEW_TYPES = [
		// We expect modern browsers to be able to preview a PDF file
		// even if the user has disabled PDF opening in browser
		// (something we cannot detect)
		'application/pdf',
		'audio/mpeg',
		'audio/ogg',
		'audio/wave',
		'audio/wav',
		'audio/x-wav',
		'audio/x-pn-wav',
		'audio/webm',
		'video/webm',
		'video/ogg',
		'application/ogg',
		'video/mp4',
		'image/png',
		'image/gif',
		'image/jpeg',
		'image/webp',
		'image/svg+xml',
		'text/plain',
		'text/html',
	];

	// https://book.hacktricks.xyz/pentesting-web/file-upload
	const FORBIDDEN_EXTENSIONS = '!^(?:cgi|exe|sh|bash|com|pif|jspx?|jar|js[wxv]|action|do|php(?:s|\d+)?|pht|phtml?|shtml|phar|htaccess|inc|cfml?|cfc|dbm|swf|pl|perl|py|pyc|asp|so)$!i';

	public function selfCheck(): void
	{
		$this->assert($this->type === self::TYPE_DIRECTORY || $this->type === self::TYPE_FILE, 'Unknown file type');
		$this->assert($this->type === self::TYPE_DIRECTORY || $this->size !== null, 'File size must be set');
		$this->assert(trim($this->name) !== '', 'Le nom de fichier ne peut rester vide');
		$this->assert(strlen($this->path), 'Le chemin ne peut rester vide');
		$this->assert(null === $this->parent || strlen($this->parent), 'Le chemin ne peut rester vide');
	}

	public function save(bool $selfcheck = true): bool
	{
		if ($this->parent) {
			Files::ensureDirectoryExists($this->parent);
		}

		$ok = parent::save();

		$context = $this->context();

		// Link file to transaction/user
		if ($ok && $this->type === self::TYPE_FILE && in_array($context, [self::CONTEXT_USER, self::CONTEXT_TRANSACTION])) {
			// Only insert if ID exists in table
			$db = DB::getInstance();

			if ($context == self::CONTEXT_USER) {
				$id = (int)Utils::basename(Utils::dirname($this->parent));
				$field = Utils::basename($this->parent);

				if (!$id || !$field) {
					return $ok;
				}

				// The field does not exist anymore, don't link
				if (!DynamicFields::get($field)) {
					return $ok;
				}

				$sql = sprintf('INSERT OR IGNORE INTO %s_files (id_file, id_user, field) SELECT %d, %d, %s FROM %1$s WHERE id = %3$d;',
					'users',
					$this->id(),
					$id,
					$db->quote($field)
				);
			}
			else {
				$id = (int)Utils::basename($this->parent);

				if (!$id) {
					return $ok;
				}

				$sql = sprintf('INSERT OR IGNORE INTO %s_files (id_file, id_transaction) SELECT %d, %d FROM %1$s WHERE id = %3$d;',
					'acc_transactions',
					$this->id(),
					$id
				);
			}

			$db->exec($sql);
		}

		return $ok;
	}

	public function context(): string
	{
		return strtok($this->path, '/');
	}

	public function parent(): File
	{
		return Files::get($this->parent);
	}

	public function getLocalFilePath(): ?string
	{
		$path = Files::callStorage('getLocalFilePath', $this);

		if (null === $path || !file_exists($path)) {
			return null;
		}

		return $path;
	}

	public function etag(): string
	{
		if ($this->md5) {
			return $this->md5;
		}
		elseif (!$this->isDir()) {
			return md5($this->path . $this->size . $this->modified->getTimestamp());
		}
		else {
			return md5($this->path . $this->getRecursiveSize() . $this->getRecursiveLastModified());
		}
	}

	public function rehash($pointer = null): void
	{
		$path = !$pointer ? $this->getLocalFilePath() : null;

		if ($path) {
			$hash = md5_file($path);
		}
		else {
			$p = $pointer ?? $this->getReadOnlyPointer();

			if (!$p) {
				return;
			}

			$hash = hash_init('md5');

			while (!feof($p)) {
				hash_update($hash, fread($p, 8192));
			}

			$hash = hash_final($hash);

			if (null === $pointer) {
				fclose($p);
			}
			else {
				fseek($pointer, 0, SEEK_SET);
			}
		}

		$this->set('md5', $hash);
	}

	/**
	 * Return TRUE if the file can be previewed natively in a browser
	 * @return bool
	 */
	public function canPreview(): bool
	{
		if (in_array($this->mime, self::PREVIEW_TYPES)) {
			return true;
		}

		if (!WOPI_DISCOVERY_URL) {
			return false;
		}

		if ($this->getWopiURL()) {
			return true;
		}

		return false;
	}

	public function moveToTrash(): void
	{
		if ($this->trash) {
			return;
		}

		$this->set('trash', new \DateTime);
		$this->save();
	}

	public function restoreFromTrash(): void
	{
		if (!$this->trash) {
			return;
		}

		$db = DB::getInstance();
		$exists = $db->test(File::TABLE, 'path = ? AND trash IS NULL', $this->path);
		$parent_exists = $db->test(File::TABLE, 'path = ? AND trash IS NULL AND type = ?', $this->parent, self::TYPE_DIRECTORY);

		$this->set('trash', null);

		// Parent directory no longer exists, or another file with the same name has been created:
		// move file to documents root, but under a new name to make sure it doesn't overwrite an existing file
		if ($exists || !$parent_exists) {
			$new_name = sprintf('Restauré de la corbeille - %s - %s', date('d-m-Y His'), $this->name);
			$parent = self::CONTEXT_DOCUMENTS;
			$this->rename($parent . '/' . $new_name);
		}

		$this->save();
	}

	public function deleteCache(): void
	{
		// This also deletes thumbnails
		Web_Cache::delete($this->uri());

		// clean up thumbnails
		foreach (self::ALLOWED_THUMB_SIZES as $key => $operations)
		{
			Static_Cache::remove(sprintf(self::THUMB_CACHE_ID, $this->pathHash(), $key));
		}
	}

	public function delete(): bool
	{
		Files::assertStorageIsUnlocked();

		$db = DB::getInstance();
		$db->begin();

		// Also delete sub-directories and files
		if ($this->type == self::TYPE_DIRECTORY) {
			foreach (Files::list($this->path) as $file) {
				$file->delete();
			}
		}

		// Delete actual file content
		Files::callStorage('delete', $this);

		Plugins::fireSignal('files.delete', ['file' => $this]);

		$this->deleteCache();
		$r = parent::delete();

		$db->commit();

		return $r;
	}

	/**
	 * Change ONLY the file name, not the parent path
	 * @param  string $new_name New file name
	 * @return bool
	 */
	public function changeFileName(string $new_name): bool
	{
		$new_name = self::filterName($new_name);
		return $this->rename(ltrim($this->parent . '/' . $new_name, '/'));
	}

	/**
	 * Change ONLY the directory where the file is
	 * @param  string $target New directory path
	 * @return bool
	 */
	public function move(string $target, bool $check_session = true): bool
	{
		return $this->rename($target . '/' . $this->name, $check_session);
	}

	/**
	 * Rename a file, this can include moving it (the UNIX way)
	 * @param  string $new_path Target path
	 * @return bool
	 */
	public function rename(string $new_path, bool $check_session = true): bool
	{
		$name = Utils::basename($new_path);

		self::validatePath($new_path);
		self::validateFileName($name);

		if ($check_session) {
			self::validateCanHTML($name, $new_path);
		}

		if ($new_path == $this->path) {
			throw new UserException(sprintf('Impossible de renommer "%s" lui-même', $this->path));
		}

		if (0 === strpos($new_path . '/', $this->path . '/')) {
			if ($this->type != self::TYPE_DIRECTORY) {
				throw new UserException(sprintf('Impossible de renommer "%s" vers "%s"', $this->path, $new_path));
			}
		}

		$parent = Utils::dirname($new_path);

		Plugins::fireSignal('files.move', ['file' => $this, 'new_path' => $new_path]);

		$this->set('parent', $parent);
		$this->set('path', $new_path);
		$this->set('name', $name);
		return $this->save();
	}

	/**
	 * Copy the current file to a new location
	 * @param  string $target Target path
	 * @return self
	 */
	public function copy(string $target): self
	{
		return Files::createFromPointer($target, Files::callStorage('getReadOnlyPointer', $this));
	}

	public function setContent(string $content): self
	{
		$this->set('modified', new \DateTime);
		$this->store(['content' => rtrim($content)]);
		return $this;
	}

	/**
	 * Store contents in file, either from a local path, from a binary string or from a pointer
	 *
	 * @param  array $source [path, content or pointer]
	 * @param  string $source_content
	 * @param  bool   $index_search Set to FALSE if you don't want the document to be indexed in the file search
	 * @return self
	 */
	public function store(array $source): self
	{
		if (!$this->path || !$this->name) {
			throw new \LogicException('Cannot store a file that does not have a target path and name');
		}

		if ($this->type == self::TYPE_DIRECTORY) {
			throw new \LogicException('Cannot store a directory');
		}

		if (!isset($source['path']) && !isset($source['content']) && !isset($source['pointer'])) {
			throw new \InvalidArgumentException('Unknown source type');
		}
		elseif (count($source) != 1) {
			throw new \InvalidArgumentException('Invalid source type');
		}

		Files::assertStorageIsUnlocked();

		$delete_after = false;
		$path = $content = $pointer = null;
		extract($source);

		if ($path) {
			$this->set('size', filesize($path));
			Files::checkQuota($this->size);
			$this->set('md5', md5_file($path));
		}
		elseif (null !== $content) {
			$this->set('size', strlen($content));
			Files::checkQuota($this->size);
			$this->set('md5', md5($content));
		}
		elseif ($pointer) {
			// See https://github.com/php/php-src/issues/9441
			if (stream_get_meta_data($pointer)['uri'] == 'php://input') {
				while (!feof($pointer)) {
					fread($pointer, 8192);
				}
			}
			elseif (0 !== fseek($pointer, 0, SEEK_END)) {
				throw new \RuntimeException('Stream is not seekable');
			}

			$this->set('size', ftell($pointer));
			fseek($pointer, 0, SEEK_SET);
			Files::checkQuota($this->size);

			$this->rehash($pointer);
		}

		// Check that it's a real image
		if ($this->image) {
			if ($path) {
				$blob = file_get_contents($path, false, null, 0, 1000);
			}
			elseif ($pointer) {
				$blob = fread($pointer, 1000);
				fseek($pointer, 0, SEEK_SET);
			}
			else {
				$blob = substr($content, 0, 1000);
			}

			if ($size = Blob::getSize($blob)) {
				// This is to avoid pixel flood attacks
				if ($size[0] > 8000 || $size[1] > 8000) {
					throw new ValidationException('Cette image est trop grande (taille max 8000 x 8000 pixels)');
				}

				// Recompress PNG files from base64, assuming they are coming
				// from JS canvas which doesn't know how to gzip (d'oh!)
				if ($size[2] == 'image/png' && null !== $content) {
					$i = Image::createFromBlob($content);
					$content = $i->output('png', true);
					$this->set('size', strlen($content));
					unset($i);
				}
			}
			elseif ($type = Blob::getType($blob)) {
				// WebP is fine, but we cannot get its size
			}
			else {
				// Not an image
				$this->set('image', false);
			}
		}

		if (!isset($this->modified)) {
			$this->set('modified', new \DateTime);
		}

		$new = !$this->exists();

		$db = DB::getInstance();
		$db->begin();

		// Save metadata now, and rollback if required
		$this->save();

		try {
			if (null !== $path) {
				$return = Files::callStorage('storePath', $this, $path);
			}
			elseif (null !== $content) {
				$return = Files::callStorage('storeContent', $this, $content);
			}
			else {
				$return = Files::callStorage('storePointer', $this, $pointer);
			}

			if (!$return) {
				throw new UserException('Le fichier n\'a pas pu être enregistré.');
			}

			Plugins::fireSignal('files.store', ['file' => $this]);

			// clean up thumbnails
			foreach (self::ALLOWED_THUMB_SIZES as $key => $operations)
			{
				Static_Cache::remove(sprintf(self::THUMB_CACHE_ID, $this->pathHash(), $key));
			}

			Web_Cache::delete($this->uri());

			// Index regular files, not directories
			if ($this->type == self::TYPE_FILE) {
				$this->indexForSearch(compact('content', 'path', 'pointer'));
			}

			$db->commit();

			return $this;
		}
		catch (\Exception $e) {
			$db->rollback();
			throw $e;
		}
		finally {
			if (null !== $pointer) {
				fclose($pointer);
			}
		}
	}

	public function indexForSearch(?array $source = null, ?string $title = null, ?string $forced_mime = null): void
	{
		$mime = $forced_mime ?? $this->mime;
		$ext = $this->extension();
		$content = null;

		if ($this->isDir() && (!$mime || !isset($source['content']))) {
			return;
		}

		// Store content in search table
		if (substr($mime, 0, 5) == 'text/') {
			$content = $source['content'] ?? $this->fetch();

			if ($mime === 'text/html' || $mime == 'text/xml') {
				$content = html_entity_decode(strip_tags($content),  ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
			}
		}
		elseif ($ext == 'pdf' && PDFTOTEXT_COMMAND === 'pdftotext') {
			$cmd = escapeshellcmd(PDFTOTEXT_COMMAND) . ' -nopgbrk - -';

			if (empty($source)) {
				$source['pointer'] = $this->getReadOnlyPointer();
				$source['path'] = $source['pointer'] ? null : $this->getLocalFilePath();
			}

			try {
				if (isset($source['content'])) {
					Utils::exec($cmd, 2, fn() => $source['content'], fn($out) => $content = $out);
				}
				elseif (isset($source['pointer'])) {
					fseek($source['pointer'], 0, SEEK_END);
					$size = ftell($source['pointer']);
					rewind($source['pointer']);

					if ($size >= 8*1024*1024) {
						throw new \OverflowException('PDF file is too large');
					}

					Utils::exec($cmd, 2, fn() => fread($source['pointer'], $size), fn($out) => $content = $out);
				}
				else {
					$cmd = sprintf('%s -nopgbrk %s -', escapeshellcmd(PDFTOTEXT_COMMAND), escapeshellarg($source['path']));
					$content = '';
					Utils::exec($cmd, 2, null, function($out) use (&$content) { $content .= $out; });
					$content = $content ?: null;
				}
			}
			catch (\OverflowException $e) {
				// PDF extraction was longer than 2 seconds: PDF file is likely too large
				$content = null;
			}
		}
		elseif (in_array($ext, self::EXTENSIONS_TEXT_CONVERT) && is_array($source)) {
			$content = ToText::from($source);
		}
		else {
			$content = null;
		}

		// Only index valid UTF-8
		if (isset($content) && preg_match('//u', $content)) {
			// Truncate text at 150KB
			$content = substr(trim($content), 0, 150*1024);
		}
		else {
			$content = null;
		}

		if (null === $content && null === $title) {
			// This is already the same as what has been inserted by SQLite
			return;
		}

		$db = DB::getInstance();
		$db->preparedQuery('REPLACE INTO files_search (docid, path, title, content) VALUES (?, ?, ?, ?);',
			$this->id(), $this->path, $title ?? $this->name, $content);
	}

	/**
	 * Returns true if this is a vector or bitmap image
	 * as 'image' property is only for bitmaps
	 * @return boolean
	 */
	public function isImage(): bool
	{
		if ($this->image || $this->mime == 'image/svg+xml') {
			return true;
		}

		return false;
	}

	public function isDir(): bool
	{
		return $this->type == self::TYPE_DIRECTORY;
	}

	public function iconShape(): ?string
	{
		if ($this->isImage()) {
			return 'image';
		}
		elseif ($this->isDir()) {
			return 'directory';
		}

		return Files::getIconShape($this->name);
	}

	/**
	 * Full URL with https://...
	 */
	public function url(bool $download = false): string
	{
		$base = in_array($this->context(), [self::CONTEXT_WEB, self::CONTEXT_MODULES, self::CONTEXT_CONFIG]) ? WWW_URL : BASE_URL;
		$url = $base . $this->uri();

		if ($download) {
			$url .= '?download';
		}

		return $url;
	}

	/**
	 * Returns local URI, eg. user/1245/file.jpg
	 */
	public function uri(): string
	{
		if ($this->context() == self::CONTEXT_WEB) {
			if ($this->isDir()) {
				$parts = [$this->name];
			}
			else {
				$parts = [Utils::basename($this->parent), $this->name];
			}
		}
		else {
			$parts = explode('/', $this->path);
		}

		$parts = array_map('rawurlencode', $parts);

		return implode('/', $parts);
	}

	public function thumb_url($size = null): string
	{
		if (!$this->image) {
			return $this->url();
		}

		if (is_int($size)) {
			$size .= 'px';
		}

		$size = isset(self::ALLOWED_THUMB_SIZES[$size]) ? $size : key(self::ALLOWED_THUMB_SIZES);
		return sprintf('%s?%dpx', $this->url(), $size);
	}

	/**
	 * Return a HTML link to the file
	 */
	public function link(Session $session, ?string $thumb = null, bool $allow_edit = false, ?string $url = null)
	{
		if ($thumb == 'auto') {
			if ($this->isImage()) {
				$thumb = '150px';
			}
			else {
				$thumb = 'icon';
			}
		}

		if ($thumb == 'icon') {
			$label = sprintf('<span data-icon="%s"></span>', Utils::iconUnicode($this->iconShape()));
		}
		elseif ($thumb) {
			$label = sprintf('<img src="%s" alt="%s" />', htmlspecialchars($this->thumb_url($thumb)), htmlspecialchars($this->name));
		}
		else {
			$label = preg_replace('/[_.-]/', '&shy;$0', htmlspecialchars($this->name));
		}

		$editor = $this->editorType();

		if ($url) {
			$attrs = sprintf('href="%s"', Utils::getLocalURL($url));
		}
		elseif ($editor && ($allow_edit || $editor == 'wopi') && $this->canWrite($session)) {
			$attrs = sprintf('href="%s" target="_dialog" data-dialog-class="fullscreen"',
				Utils::getLocalURL('!common/files/edit.php?p=') . rawurlencode($this->path));
		}
		elseif ($this->canPreview($session)) {
			$attrs = sprintf('href="%s" target="_dialog" data-mime="%s"',
				$this->isImage() ? $this->url() : Utils::getLocalURL('!common/files/preview.php?p=') . rawurlencode($this->path),
				$this->mime);
		}
		else {
			$attrs = sprintf('href="%s" target="_blank"', $this->url(true));
		}

		return sprintf('<a %s>%s</a>', $attrs, $label);
	}

	/**
	 * Envoie le fichier au client HTTP
	 */
	public function serve(?Session $session = null, bool $download = false, ?string $share_hash = null, ?string $share_password = null): void
	{
		$can_access = $this->canRead();

		if (!$can_access && $share_hash) {
			$can_access = $this->checkShareLink($share_hash, $share_password);

			if (!$can_access && $this->checkShareLinkRequiresPassword($share_hash)) {
				$tpl = Template::getInstance();
				$has_password = (bool) $share_password;

				$tpl->assign(compact('can_access', 'has_password'));
				$tpl->display('ask_share_password.tpl');
				return;
			}
		}

		if (!$can_access) {
			header('HTTP/1.1 403 Forbidden', true, 403);
			throw new UserException('Vous n\'avez pas accès à ce fichier.', 403);
			return;
		}

		// Only simple files can be served, not directories
		if ($this->type != self::TYPE_FILE) {
			header('HTTP/1.1 404 Not Found', true, 404);
			throw new UserException('Page non trouvée', 404);
		}

		$this->_serve(null, $download);

		if (($path = $this->getLocalFilePath()) && in_array($this->context(), [self::CONTEXT_WEB, self::CONTEXT_CONFIG])) {
			Web_Cache::link($this->uri(), $path);
		}
	}

	public function serveAuto(?Session $session = null, array $params = []): void
	{
		$found_sizes = array_intersect_key($params, self::ALLOWED_THUMB_SIZES);
		$size = key($found_sizes);

		if ($size && $this->image) {
			$this->serveThumbnail($session, $size);
		}
		else {
			$this->serve($session, isset($params['download']));
		}
	}

	public function asImageObject(): Image
	{
		$path = $this->getLocalFilePath();
		$pointer = null;

		if ($path) {
			$i = new Image($path);
		}
		else {
			$pointer = $this->getReadOnlyPointer();
			$i = Image::createFromPointer($pointer, null, true);
		}

		return $i;
	}

	/**
	 * Envoie une miniature à la taille indiquée au client HTTP
	 */
	public function serveThumbnail(?Session $session = null, string $size = null): void
	{
		if (!$this->canRead($session)) {
			header('HTTP/1.1 403 Forbidden', true, 403);
			throw new UserException('Accès interdit', 403);
			return;
		}

		if (!$this->image) {
			throw new UserException('Il n\'est pas possible de fournir une miniature pour un fichier qui n\'est pas une image.');
		}

		if (!array_key_exists($size, self::ALLOWED_THUMB_SIZES)) {
			throw new UserException('Cette taille de miniature n\'est pas autorisée.');
		}

		$cache_id = sprintf(self::THUMB_CACHE_ID, $this->pathHash(), $size);
		$destination = Static_Cache::getPath($cache_id);

		if (!Static_Cache::exists($cache_id)) {
			try {
				$i = $this->asImageObject();

				// Always autorotate first
				$i->autoRotate();

				$operations = self::ALLOWED_THUMB_SIZES[$size];
				$allowed_operations = ['resize', 'cropResize', 'flip', 'rotate', 'crop'];

				foreach ($operations as $operation) {
					$arguments = array_slice($operation, 1);
					$operation = $operation[0];

					if (!in_array($operation, $allowed_operations)) {
						throw new \InvalidArgumentException('Opération invalide: ' . $operation);
					}

					$i->$operation(...$arguments);
				}

				$format = null;

				if ($i->format() !== 'gif') {
					$format = ['webp', null];
				}

				$i->save($destination, $format);
			}
			catch (\RuntimeException $e) {
				throw new UserException('Impossible de créer la miniature', 0, $e);
			}
		}

		$this->_serve($destination, false);

		if (in_array($this->context(), [self::CONTEXT_WEB, self::CONTEXT_CONFIG])) {
			Web_Cache::link($this->uri(), $destination, $size);
		}
	}

	/**
	 * Servir un fichier local en HTTP
	 * @param  string $path Chemin vers le fichier local
	 * @param  string $type Type MIME du fichier
	 * @param  string $name Nom du fichier avec extension
	 * @param  integer $size Taille du fichier en octets (facultatif)
	 */
	protected function _serve(?string $path = null, bool $download = false): void
	{
		if ($this->isPublic()) {
			Utils::HTTPCache($this->etag(), $this->modified->getTimestamp());
		}
		else {
			// Disable browser cache
			header('Pragma: private');
			header('Expires: -1');
			header('Cache-Control: private, must-revalidate, post-check=0, pre-check=0');
		}

		$type = $this->mime;

		// Force CSS mimetype
		if (substr($this->name, -4) == '.css') {
			$type = 'text/css';
		}
		elseif (substr($this->name, -3) == '.js') {
			$type = 'text/javascript';
		}

		if (substr($type, 0, 5) == 'text/') {
			$type .= ';charset=utf-8';
		}

		header(sprintf('Content-Type: %s', $type));
		header(sprintf('Content-Disposition: %s; filename="%s"', $download ? 'attachment' : 'inline', $this->name));

		// Use X-SendFile, if available, and storage has a local copy
		if (Router::isXSendFileEnabled()) {
			$local_path = $path ?? Files::callStorage('getLocalFilePath', $this);

			if ($path) {
				Router::xSendFile($local_path);
				return;
			}
		}

		// Disable gzip, against buffering issues
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', 1);
		}

		@ini_set('zlib.output_compression', 'Off');

		header(sprintf('Content-Length: %d', $path ? filesize($path) : $this->size));

		if (@ob_get_length()) {
			@ob_clean();
		}

		flush();

		if (null !== $path) {
			readfile($path);
		}
		else {
			$p = Files::callStorage('getReadOnlyPointer', $this);

			while (!feof($p)) {
				echo fread($p, 8192);
				flush();
			}

			fclose($p);
		}
	}

	public function fetch()
	{
		if ($this->type == self::TYPE_DIRECTORY) {
			throw new \LogicException('Cannot fetch a directory');
		}

		$p = Files::callStorage('getReadOnlyPointer', $this);

		if (null === $p) {
			$path = Files::callStorage('getLocalFilePath', $this);

			if (!$path || !file_exists($path)) {
				return '';
			}

			return file_get_contents($path);
		}

		$out = '';

		while (!feof($p)) {
			$out .= fread($p, 8192);
		}

		fclose($p);
		return $out;
	}

	public function render(?string $user_prefix = null)
	{
		$editor_type = $this->renderFormat();

		if ($editor_type == 'skriv' || $editor_type == 'markdown') {
			return Render::render($editor_type, $this, $this->fetch(), $user_prefix);
		}
		elseif ($editor_type == 'text') {
			return sprintf('<pre>%s</pre>', htmlspecialchars($this->fetch()));
		}
		else {
			throw new \LogicException('Cannot render file of this type');
		}
	}

	public function canRead(Session $session = null): bool
	{
		// Web pages and config files are always public
		if ($this->isPublic()) {
			return true;
		}

		$session ??= Session::getInstance();

		return $session->checkFilePermission($this->path, 'read');
	}

	public function canShare(Session $session = null): bool
	{
		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		return $session->checkFilePermission($this->path, 'share');
	}

	public function canWrite(Session $session = null): bool
	{
		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		return $session->checkFilePermission($this->path, 'write');
	}

	public function canDelete(Session $session = null): bool
	{
		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		// Deny delete of directories in web context
		if ($this->isDir() && $this->context() == self::CONTEXT_WEB) {
			return false;
		}

		return $session->checkFilePermission($this->path, 'delete');
	}

	public function canMoveTo(string $destination, Session $session = null): bool
	{
		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		return $session->checkFilePermission($this->path, 'move') && $this->canDelete() && self::canCreate($destination);
	}

	public function canCopyTo(string $destination, Session $session = null): bool
	{
		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		return $this->canRead() && self::canCreate($destination);
	}

	public function canCreateDirHere(Session $session = null)
	{
		if (!$this->isDir()) {
			return false;
		}

		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		return $session->checkFilePermission($this->path, 'mkdir');
	}

	static public function canCreateDir(string $path, Session $session = null)
	{
		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		return $session->checkFilePermission($path, 'mkdir');
	}

	public function canCreateHere(Session $session = null): bool
	{
		if (!$this->isDir()) {
			return false;
		}

		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		return $session->checkFilePermission($this->path, 'create');
	}

	public function canRename(Session $session = null): bool
	{
		return $this->canCreate($this->parent ?? '', $session);
	}

	static public function canCreate(string $path, Session $session = null): bool
	{
		$session ??= Session::getInstance();

		if (!$session->isLogged()) {
			return false;
		}

		return $session->checkFilePermission($path, 'create');
	}

	public function pathHash(): string
	{
		return sha1($this->path);
	}

	public function isPublic(): bool
	{
		$context = $this->context();

		if ($context == self::CONTEXT_MODULES || $context == self::CONTEXT_WEB) {
			return true;
		}

		if ($context == self::CONTEXT_CONFIG) {
			$file = array_search($this->path, Config::FILES);

			if ($file && in_array($file, Config::FILES_PUBLIC)) {
				return true;
			}
		}

		return false;
	}

	public function path_uri(): string
	{
		return rawurlencode($this->path);
	}

	public function parent_uri(): string
	{
		return $this->parent ? rawurlencode($this->parent) : '';
	}

	public function extension(): ?string
	{
		$pos = strrpos($this->name, '.');

		if (false === $pos) {
			return null;
		}

		return strtolower(substr($this->name, $pos+1));
	}

	static public function filterName(string $name): string
	{
		return strtr(trim($name), [
			"\0" => '',
			'\\' => '',
			'/' => '',
			':' => '',
			'*' => '',
			'?' => '',
			'"' => '',
			'<' => '',
			'>' => '',
			'|' => '',
		]);
	}

	static public function validateFileName(string $name): void
	{
		if (0 === strpos($name, '.ht') || $name == '.user.ini') {
			throw new ValidationException('Nom de fichier interdit');
		}

		if (strpos($name, "\0") !== false) {
			throw new ValidationException('Nom de fichier invalide');
		}

		if (strlen($name) > 250) {
			throw new ValidationException('Nom de fichier trop long');
		}

		$extension = strtolower(substr($name, strrpos($name, '.')+1));

		if (preg_match(self::FORBIDDEN_EXTENSIONS, $extension)) {
			throw new ValidationException('Extension de fichier non autorisée, merci de renommer le fichier avant envoi.');
		}
	}

	static public function validatePath(string $path): array
	{
		if (false != strpos($path, '..')) {
			throw new ValidationException('Chemin invalide: ' . $path);
		}

		$parts = explode('/', trim($path, '/'));

		if (count($parts) < 1) {
			throw new ValidationException('Chemin invalide: ' . $path);
		}

		$context = array_shift($parts);

		if (!array_key_exists($context, self::CONTEXTS_NAMES)) {
			throw new ValidationException('Chemin invalide: ' . $path);
		}

		$name = array_pop($parts);
		$ref = implode('/', $parts);
		return [$context, $ref ?: null, $name];
	}

	/**
	 * Only admins can create or rename files to .html / .js
	 * This is to avoid XSS attacks from a non-admin user
	 */
	static public function validateCanHTML(string $name, string $path, ?Session $session = null): void
	{
		if (!preg_match('/\.(?:htm|js|xhtm)/', $name)) {
			return;
		}

		$session ??= Session::getInstance();

		if (0 === strpos($path, self::CONTEXT_MODULES . '/web') && $session->canAccess($session::SECTION_WEB, $session::ACCESS_ADMIN)) {
			return;
		}

		if ($session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN)) {
			return;
		}

		throw new ValidationException('Seuls les administrateurs peuvent créer des fichiers de ce type.');
	}

	public function renderFormat(): ?string
	{
		if (substr($this->name, -6) == '.skriv') {
			$format = Render::FORMAT_SKRIV;
		}
		elseif (substr($this->name, -3) == '.md') {
			$format = Render::FORMAT_MARKDOWN;
		}
		elseif (substr($this->mime, 0, 5) == 'text/' && $this->mime != 'text/html') {
			$format = 'text';
		}
		else {
			$format = null;
		}

		return $format;
	}

	public function editorType(): ?string
	{
		static $text_extensions = ['css', 'txt', 'xml', 'html', 'htm', 'tpl', 'ini'];

		$ext = $this->extension();

		$format = $this->renderFormat();

		if ($format == Render::FORMAT_SKRIV || $format == Render::FORMAT_MARKDOWN) {
			return 'web';
		}
		elseif ($format == 'text' || in_array($ext, $text_extensions)) {
			return 'code';
		}
		elseif (!WOPI_DISCOVERY_URL) {
			return null;
		}

		if ($this->getWopiURL()) {
			return 'wopi';
		}

		return null;
	}

	public function getWopiURL(): ?string
	{
		if (!WOPI_DISCOVERY_URL) {
			return null;
		}

		$cache_file = SHARED_CACHE_ROOT . '/wopi.json';
		static $data = null;

		if (null === $data) {
			// We are caching discovery for 15 days, there is no need to request the server all the time
			if (file_exists($cache_file) && filemtime($cache_file) >= 3600*24*15) {
				$data = json_decode(file_get_contents($cache_file), true);
			}

			if (!$data) {
				try {
					$data = WOPI::discover(WOPI_DISCOVERY_URL);
					file_put_contents($cache_file, json_encode($data));
				}
				catch (\RuntimeException $e) {
					return null;
				}
			}
		}

		$ext = $this->extension();
		$url = null;

		if (isset($data['extensions'][$ext]['edit'])) {
			$url = $data['extensions'][$ext]['edit'];
		}
		elseif (isset($data['mimetypes'][$this->mime]['edit'])) {
			$url = $data['mimetypes'][$this->mime]['edit'];
		}

		return $url;
	}

	public function editorHTML(bool $readonly = false): ?string
	{
		$url = $this->getWopiURL();

		if (!$url) {
			return null;
		}

		$wopi = new WOPI;
		$url = $wopi->setEditorOptions($url, [
			// Undocumented editor parameters
			// see https://github.com/nextcloud/richdocuments/blob/2338e2ff7078040d54fc0c70a96c8a1b860f43a0/src/helpers/url.js#L49
			'lang' => 'fr',
			//'closebutton' => 1,
			//'revisionhistory' => 1,
			//'title' => 'Test',
			'permission' => $readonly || !$this->canWrite() ? 'readonly' : '',
		]);
		$wopi->setStorage(new Storage(Session::getInstance()));
		return $wopi->getEditorHTML($url, $this->path, $this->name);
	}

	public function export(): array
	{
		return $this->asArray(true) + ['url' => $this->url()];
	}

	/**
	 * Returns a sharing link for a file, valid
	 * @param  int $expiry Expiry, in hours
	 * @param  string|null $password
	 * @return string
	 */
	public function createShareLink(int $expiry = 24, ?string $password = null): string
	{
		$expiry = intval(time() / 3600) + $expiry;

		$hash = $this->_createShareHash($expiry, $password);

		$expiry -= intval(gmmktime(0, 0, 0, 8, 1, 2022) / 3600);
		$expiry = base_convert($expiry, 10, 36);

		return sprintf('%s?s=%s%s:%s', $this->url(), $password ? ':' : '', $hash, $expiry);
	}

	protected function _createShareHash(int $expiry, ?string $password): string
	{
		$password = trim((string)$password) ?: null;

		$str = sprintf('%s:%s:%s:%s', SECRET_KEY, $this->path, $expiry, $password);

		$hash = hash('sha256', $str, true);
		$hash = substr($hash, 0, 10);
		$hash = Security::base64_encode_url_safe($hash);
		return $hash;
	}

	public function checkShareLinkRequiresPassword(string $str): bool
	{
		return substr($str, 0, 1) == ':';
	}

	public function checkShareLink(string $str, ?string $password): bool
	{
		$str = ltrim($str, ':');

		$hash = strtok($str, ':');
		$expiry = strtok(false);

		if (!ctype_alnum($expiry)) {
			return false;
		}

		$expiry = (int)base_convert($expiry, 36, 10);
		$expiry += intval(gmmktime(0, 0, 0, 8, 1, 2022) / 3600);

		if ($expiry < time()/3600) {
			return false;
		}

		$hash_check = $this->_createShareHash($expiry, $password);

		return hash_equals($hash, $hash_check);
	}

	public function touch($date = null)
	{
		if (null === $date) {
			$date = new \DateTime;
		}
		elseif (!($date instanceof \DateTimeInterface) && ctype_digit($date)) {
			$date = new \DateTime('@' . $date);
		}
		elseif (!($date instanceof \DateTimeInterface)) {
			throw new \InvalidArgumentException('Invalid date string: ' . $date);
		}

		Files::assertStorageIsUnlocked();
		Files::callStorage('touch', $this->path, $date);
		$this->set('modified', $date);
		$this->save();
	}

	public function getReadOnlyPointer()
	{
		return Files::callStorage('getReadOnlyPointer', $this);
	}

	public function getRecursiveSize(): int
	{
		if ($this->type == self::TYPE_FILE) {
			return $this->size;
		}

		$db = DB::getInstance();
		return $db->firstColumn('SELECT SUM(size) FROM files
			WHERE type = ? AND path LIKE ? ESCAPE \'!\';',
			File::TYPE_FILE,
			$db->escapeLike($this->path, '!') . '/%'
		) ?: 0;
	}

	public function getRecursiveLastModified(): int
	{
		if ($this->type == self::TYPE_FILE) {
			return $this->modified;
		}

		$db = DB::getInstance();
		return $db->firstColumn('SELECT strftime(\'%s\', MAX(modified)) FROM files
			WHERE type = ? AND path LIKE ? ESCAPE \'!\';',
			File::TYPE_FILE,
			$db->escapeLike($this->path, '!') . '/%'
		) ?: 0;
	}

	public function webdav_root_url(): string
	{
		return BASE_URL . 'dav/' . $this->context() . '/';
	}
}
