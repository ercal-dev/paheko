<?php

namespace Garradin\Entities\Web;

use Garradin\DB;
use Garradin\Entity;
use Garradin\UserException;
use Garradin\Utils;
use Garradin\Entities\Files\File;
use Garradin\Files\Files;
use Garradin\Web\Render\Render;
use Garradin\Web\Web;

use KD2\DB\EntityManager as EM;

use const Garradin\WWW_URL;

class Page extends Entity
{
	const TABLE = 'web_pages';

	protected $id;
	protected $parent;
	protected $path;
	protected $uri;
	protected $_name = 'index.txt';
	protected $file_path;
	protected $title;
	protected $type;
	protected $status;
	protected $format;
	protected $published;
	protected $modified;
	protected $content;

	protected $_types = [
		'id'        => 'int',
		'parent'    => 'string',
		'path'      => 'string',
		'uri'       => 'string',
		'file_path' => 'string',
		'title'     => 'string',
		'type'      => 'int',
		'status'    => 'string',
		'format'    => 'string',
		'published' => 'DateTime',
		'modified'  => 'DateTime',
		'content'   => 'string',
	];

	const FORMATS_LIST = [
		//Render::FORMAT_BLOCKS => 'Blocs (beta)',
		Render::FORMAT_SKRIV => 'SkrivML',
		Render::FORMAT_MARKDOWN => 'MarkDown',
		Render::FORMAT_ENCRYPTED => 'Chiffré',
	];

	const STATUS_ONLINE = 'online';
	const STATUS_DRAFT = 'draft';

	const STATUS_LIST = [
		self::STATUS_ONLINE => 'En ligne',
		self::STATUS_DRAFT => 'Brouillon',
	];

	const TYPE_CATEGORY = 1;
	const TYPE_PAGE = 2;

	const TEMPLATES = [
		self::TYPE_PAGE => 'article.html',
		self::TYPE_CATEGORY => 'category.html',
	];

	const DUPLICATE_URI_ERROR = 42;

	protected $_file;
	protected $_attachments;
	protected $_tagged_attachments;

	static public function create(int $type, ?string $parent, string $title, string $status = self::STATUS_ONLINE): self
	{
		$page = new self;
		$data = compact('type', 'parent', 'title', 'status');
		$data['content'] = '';

		$page->importForm($data);
		$page->published = new \DateTime;
		$page->modified = new \DateTime;
		$page->type = $type;

		$db = DB::getInstance();
		if ($db->test(self::TABLE, 'uri = ?', $page->uri)) {
			$page->importForm(['uri' => $page->uri . date('-Y-m-d-His')]);
		}

		$page->file_path = $page->filepath(false);

		return $page;
	}

	public function file(bool $force_reload = false)
	{
		if (null === $this->_file || $force_reload) {
			$this->_file = Files::get($this->filepath());
		}

		return $this->_file;
	}

	public function load(array $data): self
	{
		parent::load($data);

		if ($this->file() && $this->file()->modified > $this->modified) {
			$this->loadFromFile($this->file());
			$this->save();
		}

		return $this;
	}

	public function url(): string
	{
		return WWW_URL . $this->uri;
	}

	public function template(): string
	{
		return self::TEMPLATES[$this->type];
	}

	public function asTemplateArray(): array
	{
		$out = $this->asArray();
		$out['url'] = $this->url();
		$out['html'] = $this->render();
		return $out;
	}

	public function render(?string $user_prefix = null): string
	{
		if (!$this->file()) {
			throw new \LogicException('File does not exist: '  . $this->file_path);
		}

		return Render::render($this->format, $this->file(), $this->content, $user_prefix);
	}

	public function preview(string $content): string
	{
		return Render::render($this->format, $this->file(), $content, '#');
	}

	public function filepath(bool $stored = true): string
	{
		return $stored && isset($this->file_path) ? $this->file_path : File::CONTEXT_WEB . '/' . $this->path . '/' . $this->_name;
	}

	public function path(): string
	{
		return $this->path;
	}

	public function syncFile(string $path): void
	{
		$export = $this->export();

		$exists = Files::callStorage('exists', $path);

		// Create file if required
		if (!$exists) {
			$file = $this->_file = File::createAndStore(Utils::dirname($path), Utils::basename($path), null, $export);
		}
		else {
			$target = $this->filepath(false);

			// Move parent directory if needed
			if ($path !== $target) {
				$dir = Files::get(Utils::dirname($path));
				$dir->rename(Utils::dirname($target));
				$this->_file = null;
			}

			$file = $this->file();

			// Or update file
			if ($file->fetch() !== $export) {
				$file->set('modified', $this->modified);
				$file->store(null, $export, false);
			}
		}

		$this->syncSearch();
	}

	public function syncSearch(): void
	{
		if ($this->format == Render::FORMAT_ENCRYPTED) {
			$this->file()->removeFromSearch();
		}
		else {
			$content = $this->render();
			$this->file()->indexForSearch($content, $this->title, 'text/html');
		}
	}

	public function save(): bool
	{
		$change_parent = null;

		if (isset($this->_modified['uri']) || isset($this->_modified['path'])) {
			$this->set('file_path', $this->filepath(false));
			$change_parent = $this->_modified['path'];
		}

		// Update modified date if required
		if (count($this->_modified) && !isset($this->_modified['modified'])) {
			$this->set('modified', new \DateTime);
		}

		$current_path = $this->_modified['file_path'] ?? $this->file_path;
		parent::save();
		$this->syncFile($current_path);

		// Rename/move children
		if ($change_parent) {
			$db = DB::getInstance();
			$sql = sprintf('UPDATE web_pages
				SET
					path = %1$s || substr(path, %2$d),
					parent = %1$s || substr(parent, %2$d),
					file_path = \'web/\' || %1$s || substr(file_path, %2$d + 4)
				WHERE path LIKE %3$s;',
				$db->quote($this->path), strlen($change_parent) + 1, $db->quote($change_parent . '/%'));
			$db->exec($sql);
		}

		return true;
	}

	public function delete(): bool
	{
		Files::get(Utils::dirname($this->file_path))->delete();
		return parent::delete();
	}

	public function selfCheck(): void
	{
		$db = DB::getInstance();
		$this->assert($this->type === self::TYPE_CATEGORY || $this->type === self::TYPE_PAGE, 'Unknown page type');
		$this->assert(array_key_exists($this->status, self::STATUS_LIST), 'Unknown page status');
		$this->assert(array_key_exists($this->format, self::FORMATS_LIST), 'Unknown page format');
		$this->assert(trim($this->title) !== '', 'Le titre ne peut rester vide');
		$this->assert(trim($this->file_path) !== '', 'Le chemin de fichier ne peut rester vide');
		$this->assert(trim($this->path) !== '', 'Le chemin ne peut rester vide');
		$this->assert(trim($this->uri) !== '', 'L\'URI ne peut rester vide');
		$this->assert($this->path !== $this->parent, 'Invalid parent page');
		$this->assert($this->parent === '' || $db->test(self::TABLE, 'path = ?', $this->parent), 'Page parent inexistante');

		$this->assert(!$this->exists() || !$db->test(self::TABLE, 'uri = ? AND id != ?', $this->uri, $this->id()), 'Cette adresse URI est déjà utilisée par une autre page, merci d\'en choisir une autre : ' . $this->uri, self::DUPLICATE_URI_ERROR);
		$this->assert($this->exists() || !$db->test(self::TABLE, 'uri = ?', $this->uri), 'Cette adresse URI est déjà utilisée par une autre page, merci d\'en choisir une autre : ' . $this->uri, self::DUPLICATE_URI_ERROR);
	}

	public function importForm(array $source = null)
	{
		if (null === $source) {
			$source = $_POST;
		}

		if (isset($source['date']) && isset($source['date_time'])) {
			$source['published'] = $source['date'] . ' ' . $source['date_time'];
		}

		$parent = $this->parent;

		if (isset($source['title']) && is_null($this->path)) {
			$source['uri'] = $source['title'];
		}

		if (isset($source['uri'])) {
			$source['uri'] = Utils::transformTitleToURI($source['uri']);

			if (!$this->exists()) {
				$source['uri'] = strtolower($source['uri']);
			}

			$source['path'] = trim($parent . '/' . $source['uri'], '/');
		}

		$uri = $source['uri'] ?? $this->uri;

		if (array_key_exists('parent', $source)) {
			if (is_array($source['parent'])) {
				$source['parent'] = key($source['parent']);
			}

			if (empty($source['parent'])) {
				$source['parent'] = '';
			}

			$parent = $source['parent'];
			$source['path'] = trim($parent . '/' . $uri, '/');
		}

		if (!empty($source['encryption']) ) {
			$this->set('format', Render::FORMAT_ENCRYPTED);
		}
		else {
			$this->set('format', Render::FORMAT_SKRIV);
		}

		return parent::importForm($source);
	}

	public function getBreadcrumbs(): array
	{
		$sql = '
			WITH RECURSIVE parents(title, parent, path, level) AS (
				SELECT title, parent, path, 1 FROM web_pages WHERE id = ?
				UNION ALL
				SELECT p.title, p.parent, p.path, level + 1
				FROM web_pages p
					JOIN parents ON parents.parent = p.path
			)
			SELECT path, title FROM parents ORDER BY level DESC;';
		return DB::getInstance()->getAssoc($sql, $this->id());
	}

	public function listAttachments(): array
	{
		if (null === $this->_attachments) {
			$list = Files::list(Utils::dirname($this->filepath()));

			// Remove the page itself
			$list = array_filter($list, function ($a) {
				return $a->name != $this->_name && $a->type != $a::TYPE_DIRECTORY;
			});

			$this->_attachments = $list;
		}

		return $this->_attachments;
	}

	/**
	 * List attachments that are cited in the text content
	 */
	public function listTaggedAttachments(): array
	{
		if (null === $this->_tagged_attachments) {
			$this->render();
			$this->_tagged_attachments = Render::listAttachments($this->file());
		}

		return $this->_tagged_attachments;
	}

	/**
	 * List attachments that are *NOT* cited in the text content
	 */
	public function listOrphanAttachments(): array
	{
		$used = $this->listTaggedAttachements();
		$orphans = [];

		foreach ($this->listAttachments() as $file) {
			if (!in_array($file->uri(), $used)) {
				$orphans[] = $file->uri();
			}
		}

		return $orphans;
	}

	/**
	 * Return list of images
	 * If $all is FALSE then this will only return images that are not present in the content
	 */
	public function getImageGallery(bool $all = true): array
	{
		return $this->getAttachmentsGallery($all, true);
	}

	/**
	 * Return list of files
	 * If $all is FALSE then this will only return files that are not present in the content
	 */
	public function getAttachmentsGallery(bool $all = true, bool $images = false): array
	{
		$out = [];
		$tagged = [];

		if (!$all) {
			$tagged = $this->listTaggedAttachments($this->content);
		}

		foreach ($this->listAttachments() as $a) {
			if ($images && !$a->image) {
				continue;
			}
			elseif (!$images && $a->image) {
				continue;
			}

			// Skip
			if (!$all && in_array($a->name, $tagged)) {
				continue;
			}

			$out[] = $a;
		}

		return $out;
	}

	/**
	 * Return list of internal links in page that link to non-existing pages
	 */
	public function checkInternalLinks(?array &$pages = null): array
	{
		if ($this->format == Render::FORMAT_ENCRYPTED) {
			return [];
		}

		$html = $this->render();
		preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/', $html, $match, PREG_PATTERN_ORDER);
		$errors = [];

		foreach ($match[1] as $link) {
			if (strpos($link, WWW_URL) === 0) {
				$link = substr($link, strlen(WWW_URL));
			}

			$link = trim($link, '/');

			// Link is not internal
			if (preg_match('!https?:|\w+:|/|#!', $link)) {
				continue;
			}

			if (null !== $pages && !array_key_exists($link, $pages)) {
				$errors[] = $link;
			}
			elseif (null === $pages && !Web::getByURI($link)) {
				$errors[] = $link;
			}
		}

		return array_unique($errors);
	}

	public function export(): string
	{
		$meta = [
			'Title' => str_replace("\n", '', trim($this->title)),
			'Status' => $this->status,
			'Published' => $this->published->format('Y-m-d H:i:s'),
			'Format' => $this->format,
		];

		$out = '';

		foreach ($meta as $key => $value) {
			$out .= sprintf("%s: %s\n", $key, $value);
		}

		$content = preg_replace("/\r\n?/", "\n", $this->content);
		$out .= "\n----\n\n" . $content;

		return $out;
	}

	public function importFromRaw(string $str): bool
	{
		$str = preg_replace("/\r\n?/", "\n", $str);
		$str = explode("\n\n----\n\n", $str, 2);

		if (count($str) !== 2) {
			return false;
		}

		list($meta, $content) = $str;

		$meta = explode("\n", trim($meta));

		foreach ($meta as $line) {
			$key = strtolower(trim(strtok($line, ':')));
			$value = trim(strtok(''));

			if ($key == 'title') {
				$this->set('title', $value);
			}
			elseif ($key == 'published') {
				$this->set('published', new \DateTime($value));
			}
			elseif ($key == 'format') {
				$value = strtolower($value);

				if (!array_key_exists($value, self::FORMATS_LIST)) {
					throw new \LogicException('Unknown format: ' . $value);
				}

				$this->set('format', $value);
			}
			elseif ($key == 'status') {
				$value = strtolower($value);

				if (!array_key_exists($value, self::STATUS_LIST)) {
					throw new \LogicException('Unknown status: ' . $value);
				}

				$this->set('status', $value);
			}
			else {
				// Ignore other metadata
			}
		}

		$this->set('content', trim($content, "\n\r"));

		return true;
	}

	public function loadFromFile(File $file): void
	{
		if (!$this->importFromRaw($file->fetch())) {
			throw new \LogicException('Invalid page content: ' . $file->parent);
		}

		$this->set('modified', $file->modified);

		$this->set('type', $this->checkRealType());
	}

	public function checkRealType(): int
	{
		foreach (Files::list(Utils::dirname($this->filepath())) as $subfile) {
			if ($subfile->type == File::TYPE_DIRECTORY) {
				return self::TYPE_CATEGORY;
			}
		}

		return self::TYPE_PAGE;
	}

	public function toggleType(): void
	{
		$real_type = $this->checkRealType();

		if ($real_type == self::TYPE_CATEGORY) {
			$this->set('type', $real_type);
		}
		elseif ($this->type == self::TYPE_CATEGORY) {
			$this->set('type', self::TYPE_PAGE);
		}
		else {
			$this->set('type', self::TYPE_CATEGORY);
		}
	}

	static public function fromFile(File $file): self
	{
		$page = new self;

		// Path is relative to web root
		$page->set('file_path', $file->path);
		$page->set('path', substr(Utils::dirname($file->path), strlen(File::CONTEXT_WEB . '/')));
		$page->set('uri', Utils::basename($page->path));
		$page->set('parent', Utils::dirname($page->path) == '.' ? '' : Utils::dirname($page->path));

		$page->loadFromFile($file);
		return $page;
	}
}
