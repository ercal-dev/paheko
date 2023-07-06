<?php

namespace Garradin\Entities\Web;

use Garradin\DB;
use Garradin\Entity;
use Garradin\Form;
use Garradin\Utils;
use Garradin\Entities\Files\File;
use Garradin\Files\Files;
use Garradin\Web\Render\Render;
use Garradin\Web\Web;
use Garradin\Web\Cache;
use Garradin\UserTemplate\Modifiers;

use KD2\DB\EntityManager as EM;

use const Garradin\{WWW_URL, ADMIN_URL};

class Page extends Entity
{
	const NAME = 'Page du site web';

	const TABLE = 'web_pages';

	protected ?int $id;
	protected ?string $parent = null;
	protected string $path;
	protected string $dir_path;
	protected string $uri;
	protected string $title;
	protected int $type;
	protected string $status;
	protected string $format;
	protected \DateTime $published;
	protected \DateTime $modified;
	protected string $content;

	const FORMATS_LIST = [
		Render::FORMAT_MARKDOWN => 'MarkDown',
		Render::FORMAT_ENCRYPTED => 'Chiffré',
		Render::FORMAT_SKRIV => 'SkrivML',
	];

	const STATUS_ONLINE = 'online';
	const STATUS_DRAFT = 'draft';

	const STATUS_LIST = [
		self::STATUS_ONLINE => 'En ligne',
		self::STATUS_DRAFT => 'Brouillon',
	];

	const TYPE_CATEGORY = 1;
	const TYPE_PAGE = 2;

	const TYPES = [
		self::TYPE_CATEGORY => 'Category',
		self::TYPE_PAGE => 'Page',
	];

	const TEMPLATES = [
		self::TYPE_PAGE => 'article.html',
		self::TYPE_CATEGORY => 'category.html',
	];

	const DUPLICATE_URI_ERROR = 42;

	protected ?File $_dir = null;
	protected ?array $_attachments = null;
	protected ?array $_tagged_attachments = null;
	protected ?string $_html = null;

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

		return $page;
	}

	public function dir(bool $force_reload = false): File
	{
		if (null === $this->_dir || $force_reload) {
			$this->_dir = Files::get($this->dir_path);

			if (null === $this->_dir) {
				$this->_dir = Files::mkdir($this->dir_path);
			}
		}

		return $this->_dir;
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

	public function render(bool $admin = false): string
	{
		$user_prefix = ADMIN_URL . 'web/?uri=';

		$this->_html ??= Render::render($this->format, $this->dir(), $this->content, $user_prefix);

		return $this->_html;
	}

	public function excerpt(int $length = 500): string
	{
		return $this->preview(Modifiers::truncate($this->content, $length));
	}

	public function requiresExcerpt(int $length = 500): bool
	{
		return mb_strlen($this->content) > $length;
	}

	public function preview(string $content): string
	{
		$user_prefix = ADMIN_URL . 'web/?uri=';
		return Render::render($this->format, $this->dir(), $content, $user_prefix);
	}

	public function path(): string
	{
		return $this->path;
	}

	public function syncSearch(): void
	{
		if ($this->format == Render::FORMAT_ENCRYPTED) {
			$content = null;
		}
		else {
			$content = $this->render();
		}

		$this->dir()->indexForSearch(compact('content'), $this->title, 'text/html');
	}

	public function save(bool $selfcheck = true): bool
	{
		$change_parent = null;

		if (isset($this->_modified['uri']) || isset($this->_modified['path'])) {
			$change_parent = $this->_modified['path'];
		}

		// Update modified date if required
		if (count($this->_modified) && !isset($this->_modified['modified'])) {
			$this->set('modified', new \DateTime);
		}

		$content_modified = $this->isModified('content') || $this->isModified('format');

		Files::ensureDirectoryExists($this->dir_path);
		parent::save($selfcheck);

		if ($content_modified) {
			$this->syncSearch();
		}

		// Rename/move children
		if ($change_parent) {
			$db = DB::getInstance();
			$sql = sprintf('UPDATE web_pages
				SET
					path = %1$s || substr(path, %2$d),
					parent = %1$s || substr(parent, %2$d)
					dir_path = \'web/\' || %1$s || substr(parent, %2$d)
				WHERE path LIKE %3$s;',
				$db->quote($this->path), strlen($change_parent) + 1, $db->quote($change_parent . '/%'));
			$db->exec($sql);
		}

		Cache::clear();

		return true;
	}

	public function delete(): bool
	{
		$dir = $this->dir();

		if ($dir) {
			$dir->delete();
		}

		Cache::clear();
		return parent::delete();
	}

	public function selfCheck(): void
	{
		$db = DB::getInstance();
		$this->assert($this->type === self::TYPE_CATEGORY || $this->type === self::TYPE_PAGE, 'Unknown page type');
		$this->assert(array_key_exists($this->status, self::STATUS_LIST), 'Unknown page status');
		$this->assert(array_key_exists($this->format, self::FORMATS_LIST), 'Unknown page format');
		$this->assert(trim($this->title) !== '', 'Le titre ne peut rester vide');
		$this->assert(mb_strlen($this->title) <= 200, 'Le titre ne peut faire plus de 200 caractères');
		$this->assert(trim($this->path) !== '', 'Le chemin ne peut rester vide');
		$this->assert(trim($this->uri) !== '', 'L\'URI ne peut rester vide');
		$this->assert(strlen($this->uri) <= 150, 'L\'URI ne peut faire plus de 150 caractères');
		$this->assert($this->path !== $this->parent, 'Invalid parent page');
		$this->assert($this->parent === null || $db->test(self::TABLE, 'path = ?', $this->parent), 'Page parent inexistante');

		$this->assert(!$this->exists() || !$db->test(self::TABLE, 'uri = ? AND id != ?', $this->uri, $this->id()), 'Cette adresse URI est déjà utilisée par une autre page, merci d\'en choisir une autre : ' . $this->uri, self::DUPLICATE_URI_ERROR);
		$this->assert($this->exists() || !$db->test(self::TABLE, 'uri = ?', $this->uri), 'Cette adresse URI est déjà utilisée par une autre page, merci d\'en choisir une autre : ' . $this->uri, self::DUPLICATE_URI_ERROR);

		$root = File::CONTEXT_WEB . '/';
		$this->assert(0 === strpos($this->dir_path, $root), 'Invalid directory context');

		$dir = Files::get($this->dir_path);
		$this->assert(!$dir || $dir->isDir(), 'Chemin de répertoire invalide');
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

		if (isset($source['title']) && !$this->exists()) {
			$source['uri'] = $source['title'];
		}

		if (isset($source['uri'])) {
			$source['uri'] = Utils::transformTitleToURI($source['uri']);

			if (!$this->exists()) {
				$source['uri'] = strtolower($source['uri']);
			}

			$source['path'] = trim($parent . '/' . $source['uri'], '/');
		}

		$uri = $source['uri'] ?? ($this->uri ?? null);

		if (array_key_exists('parent', $source)) {
			$source['parent'] = Form::getSelectorValue($source['parent']) ?: null;
			$source['path'] = trim($source['parent'] . '/' . $uri, '/');
		}

		if (isset($source['path'])) {
			$source['dir_path'] = File::CONTEXT_WEB . '/' . $source['path'];
		}

		if (!empty($source['encryption']) ) {
			$this->set('format', Render::FORMAT_ENCRYPTED);
		}
		elseif (empty($source['format'])) {
			$this->set('format', Render::FORMAT_MARKDOWN);
		}

		$this->set('status', empty($source['status']) ? self::STATUS_ONLINE : $source['status']);

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
			$list = Files::list($this->dir_path);

			// Remove sub-directories
			$list = array_filter($list, fn ($a) => $a->type != $a::TYPE_DIRECTORY);

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
			$this->_tagged_attachments = Render::listAttachments($this->dir());
		}

		return $this->_tagged_attachments;
	}

	/**
	 * List attachments that are *NOT* cited in the text content
	 */
	public function listOrphanAttachments(): array
	{
		$used = $this->listTaggedAttachments();
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
			$tagged = $this->listTaggedAttachments();
		}

		foreach ($this->listAttachments() as $a) {
			if ($images && !$a->isImage()) {
				continue;
			}
			elseif (!$images && $a->isImage()) {
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

		$html = Render::render($this->format, $this->dir(), $this->content);
		preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/', $html, $match, PREG_PATTERN_ORDER);
		$errors = [];

		foreach ($match[1] as $link) {
			if (strpos($link, WWW_URL) === 0) {
				$link = substr($link, strlen(WWW_URL));
			}

			$link = trim($link, '/');

			// Link is not internal
			if (!trim($link) || preg_match('!https?:|\w:|/|#|\.!', $link)) {
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

	public function checkRealType(): int
	{
		// Make sure this is actually not a category
		foreach (Files::list($this->dir_path) as $subfile) {
			if ($subfile->type == File::TYPE_DIRECTORY) {
				return self::TYPE_CATEGORY;
			}
		}

		// No subdirectory found: this is a single page
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

	public function isCategory(): bool
	{
		return $this->type == self::TYPE_CATEGORY;
	}

	public function isOnline(): bool
	{
		return $this->status == self::STATUS_ONLINE;
	}
}
