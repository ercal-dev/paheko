<?php

namespace Garradin\Web;

use Garradin\Files\Files;
use Garradin\Entities\Files\File;
use Garradin\UserException;
use Garradin\UserTemplate\UserTemplate;

use KD2\Brindille_Exception;
use KD2\DB\EntityManager as EM;

use const Garradin\ROOT;

class Skeleton
{
	const TEMPLATE_TYPES = '!^(?:text/(?:html|plain)|\w+/(?:\w+\+)?xml)$!';

	protected $name;
	protected $file;

	public function __construct(string $tpl)
	{
		if (!preg_match('!^[\w\d_-]+(?:\.[\w\d_-]+)*$!i', $tpl)) {
			throw new \InvalidArgumentException('Invalid skeleton name');
		}

		$this->file = Files::get(File::CONTEXT_SKELETON . '/' . $tpl);

		$this->name = $tpl;
	}

	public function defaultPath(): ?string
	{
		$path = ROOT . '/www/skel-dist/' . $this->name;

		if (file_exists($path)) {
			return $path;
		}

		return null;
	}

	public function error_404(): void
	{
		// Detect loop if 404.html does not exist
		if ($this->name == '404.html') {
			throw new UserException('Cette page n\'existe pas.');
		}

		header('Content-Type: text/html;charset=utf-8', true);
		header('HTTP/1.1 404 Not Found', true);
		$tpl = new self('404.html');
		$tpl->serve();
	}

	public function serve(array $params = []): void
	{
		if (!$this->exists()) {
			$this->error_404();
			return;
		}

		$type = $this->type();

		// Unknown type
		if (null === $type) {
			$this->error_404();
			return;
		}

		// We can't serve directories
		if ($this->file && $this->file->type != $this->file::TYPE_FILE) {
			$this->error_404();
			return;
		}

		// Serve a template
		if (preg_match(self::TEMPLATE_TYPES, $type)) {
			$ut = new UserTemplate($this->file);
			$ut->setContentType($type);

			if (!$this->file) {
				$ut->setSource($this->defaultPath());
			}

			try {
				$ut->assignArray($params);
				$ut->displayWeb();
			}
			catch (Brindille_Exception $e) {
				if (!headers_sent()) {
					header('Content-Type: text/html; charset=utf-8', true);
				}

				printf('<div style="border: 5px solid orange; padding: 10px; background: yellow;"><h2>Erreur dans le squelette</h2><p>%s</p></div>', nl2br(htmlspecialchars($e->getMessage())));
			}
		}
		// Serve a static file
		elseif ($this->file) {
			$this->file->serve();
		}
		// Serve a static skeleton file (from skel-dist)
		else {
			header(sprintf('Content-Type: %s;charset=utf-8', $type), true);
			readfile($this->defaultPath());
		}
	}

	public function fetch(array $params = []): string
	{
		if (!$this->exists()) {
			return '';
		}

		if (preg_match(self::TEMPLATE_TYPES, $this->type())) {
			$ut = new UserTemplate($this->file);

			if (!$this->file) {
				$ut->setSource($this->defaultPath());
			}

			$ut->assignArray($params);

			return $ut->fetch();
		}
		elseif ($this->file) {
			return $this->file->fetch();
		}
		else {
			return file_get_contents($this->defaultPath());
		}
	}

	public function display(array $params = []): void
	{
		if (!$this->exists()) {
			return;
		}

		if (preg_match(self::TEMPLATE_TYPES, $this->type())) {
			$ut = new UserTemplate($this->file);

			if (!$this->file) {
				$ut->setSource($this->defaultPath());
			}

			$ut->assignArray($params);

			$ut->display();
		}
		elseif ($this->file) {
			echo $this->file->fetch();
		}
		else {
			readfile($this->defaultPath());
		}
	}

	public function exists()
	{
		return $this->file ? true : ($this->defaultPath() ? true : false);
	}

	public function raw(): string
	{
		if (!$this->exists()) {
			throw new UserException('Ce fichier n\'existe pas');
		}

		return $this->file ? $this->file->fetch() : file_get_contents($this->defaultPath());
	}

	public function edit(string $content)
	{
		$file = Files::get(File::CONTEXT_SKELETON . '/' . $this->name);

		if ($file) {
			$file->setContent($content);
		}
		else {
			File::createAndStore(File::CONTEXT_SKELETON, $this->name, null, $content);
		}
	}

	public function type(): ?string
	{
		$name = $this->file->name ?? $this->defaultPath();
		$dot = strrpos($name, '.');

		// Templates with no extension are returned as HTML by default
		// unless {{:http type=...}} is used
		if ($dot === false) {
			return 'text/html';
		}

		$ext = substr($name, $dot+1);

		switch ($ext) {
			case 'txt':
				return 'text/plain';
			case 'css':
				return 'text/css';
			case 'html':
			case 'htm':
				return 'text/html';
			case 'xml':
				return 'text/xml';
			case 'js':
				return 'text/javascript';
			case 'png':
			case 'gif':
			case 'webp':
				return 'image/' . $ext;
			case 'jpeg':
			case 'jpg':
				return 'image/jpeg';
		}

		if (preg_match('/php\d*/i', $ext)) {
			return null;
		}

		if ($this->file) {
			return $this->file->mime;
  		}

		$finfo = \finfo_open(\FILEINFO_MIME_TYPE);
		return finfo_file($finfo, $this->defaultPath());

	}

	public function reset()
	{
		if ($this->file) {
			$this->file->delete();
		}
	}

	static public function resetSelected(array $selected)
	{
		foreach ($selected as $file) {
			$f = new self($file);
			$f->reset();
		}
	}

	static public function list(): array
	{
		$sources = [];

		$path = ROOT . '/www/skel-dist/';
		$i = new \DirectoryIterator($path);

		foreach ($i as $file) {
			if ($file->isDot() || $file->isDir()) {
				continue;
			}

			$mime = mime_content_type($file->getRealPath());

			$sources[$file->getFilename()] = ['is_text' => substr($mime, 0, 5) == 'text/', 'changed' => null];
		}

		unset($i);

		$list = Files::list(File::CONTEXT_SKELETON);

		foreach ($list as $file) {
			if ($file->type != $file::TYPE_FILE) {
				continue;
			}

			$sources[$file->name] = ['is_text' => substr($file->mime, 0, 5) == 'text/', 'changed' => $file->modified];
		}

		ksort($sources);

		return $sources;
	}
}
