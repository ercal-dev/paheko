<?php

namespace Garradin;

use Garradin\Log;
use Garradin\Files\Files;
use Garradin\Entities\Files\File;

use KD2\SMTP;
use KD2\Graphics\Image;

class Config extends Entity
{
	const FILES = [
		'admin_background' => File::CONTEXT_CONFIG . '/admin_bg.png',
		'admin_homepage'   => File::CONTEXT_CONFIG . '/admin_homepage.skriv',
		'admin_css'        => File::CONTEXT_CONFIG . '/admin.css',
		'logo'             => File::CONTEXT_CONFIG . '/logo.png',
		'icon'             => File::CONTEXT_CONFIG . '/icon.png',
		'favicon'          => File::CONTEXT_CONFIG . '/favicon.png',
		'signature'        => File::CONTEXT_CONFIG . '/signature.png',
	];

	const FILES_TYPES = [
		'admin_background' => 'image',
		'admin_css'        => 'code',
		'admin_homepage'   => 'web',
		'logo'             => 'image',
		'icon'             => 'image',
		'favicon'          => 'image',
		'signature'        => 'image',
	];

	const FILES_PUBLIC = [
		'logo', 'icon', 'favicon',
	];

	protected string $org_name;
	protected string $org_email;
	protected ?string $org_address;
	protected ?string $org_phone;
	protected ?string $org_web;

	protected string $currency;
	protected string $country;

	protected int $default_category;

	protected ?int $backup_frequency;
	protected ?int $backup_limit;

	protected ?int $last_chart_change;
	protected ?string $last_version_check;

	protected ?string $color1;
	protected ?string $color2;

	protected array $files = [];

	protected bool $site_disabled;

	protected int $log_retention;
	protected bool $analytical_set_all;

	static protected $_instance = null;

	static public function getInstance()
	{
		return self::$_instance ?: self::$_instance = new self;
	}

	static public function deleteInstance()
	{
		self::$_instance = null;
	}

	public function __clone()
	{
		throw new \LogicException('Cannot clone config');
	}

	protected function __construct()
	{
		parent::__construct();

		$db = DB::getInstance();

		$config = $db->getAssoc('SELECT key, value FROM config ORDER BY key;');

		if (empty($config)) {
			return;
		}

		$default = array_fill_keys(array_keys($this->_types), null);
		$config = array_merge($default, $config);

		foreach ($this->_types as $key => $type) {
			$value = $config[$key];

			if ($type[0] == '?' && $value === null) {
				continue;
			}
		}

		$this->load($config);
	}

	public function setCreateFlag(): void
	{
		foreach ($this->_types as $key => $t) {
			$this->_modified[$key] = null;
		}

		$this->files = array_map(fn($a) => null, self::FILES);
	}

	public function save(bool $selfcheck = true): bool
	{
		if (!count($this->_modified)) {
			return true;
		}

		if ($selfcheck) {
			$this->selfCheck();
		}

		$values = $this->modifiedProperties(true);

		$db = DB::getInstance();
		$db->begin();

		foreach ($values as $key => $value)
		{
			$db->preparedQuery('INSERT OR REPLACE INTO config (key, value) VALUES (?, ?);', $key, $value);
		}

		$db->commit();

		$this->_modified = [];

		if (array_key_exists('log_retention', $values)) {
			Log::clean();
		}

		return true;
	}

	public function delete(): bool
	{
		throw new \LogicException('Cannot delete config');
	}

	public function importForm($source = null): void
	{
		if (null === $source) {
			$source = $_POST;
		}

		// N'enregistrer les couleurs que si ce ne sont pas les couleurs par défaut
		if (isset($source['color1'], $source['color2'])
			&& ($source['color1'] == ADMIN_COLOR1 && $source['color2'] == ADMIN_COLOR2))
		{
			$source['color1'] = null;
			$source['color2'] = null;
		}

		parent::importForm($source);
	}

	protected function _filterType(string $key, $value)
	{
		switch ($this->_types[$key]) {
			case 'int':
				return (int) $value;
			case 'bool':
				return (bool) $value;
			case 'string':
				return (string) $value;
			default:
				throw new \InvalidArgumentException(sprintf('"%s" has unknown type "%s"', $key, $this->_types[$key]));
		}
	}

	public function selfCheck(): void
	{
		$this->assert(trim($this->org_name) != '', 'Le nom de l\'association ne peut rester vide.');
		$this->assert(trim($this->currency) != '', 'La monnaie ne peut rester vide.');
		$this->assert(trim($this->country) != '' && Utils::getCountryName($this->country), 'Le pays ne peut rester vide.');
		$this->assert(!isset($this->org_web) || filter_var($this->org_web, FILTER_VALIDATE_URL), 'L\'adresse URL du site web est invalide.');
		$this->assert(trim($this->org_email) != '' && SMTP::checkEmailIsValid($this->org_email, false), 'L\'adresse e-mail de l\'association est  invalide.');

		$this->assert($this->log_retention >= 0, 'La durée de rétention doit être égale ou supérieur à zéro.');

		// Files
		$this->assert(count($this->files) == count(self::FILES));

		foreach ($this->files as $key => $value) {
			$this->assert(array_key_exists($key, self::FILES));
			$this->assert(is_int($value) || is_null($value));
		}

		$db = DB::getInstance();
		$this->assert($db->test('users_categories', 'id = ?', $this->default_category), 'Catégorie de membres inconnue');
	}

	public function file(string $key): ?File
	{
		if (!isset(self::FILES[$key])) {
			throw new \InvalidArgumentException('Invalid file key: ' . $key);
		}

		if (empty($this->files[$key])) {
			return null;
		}

		return Files::get(self::FILES[$key]);
	}

	public function fileURL(string $key, string $params = ''): ?string
	{
		if (empty($this->files[$key])) {

			if ($key == 'favicon') {
				return ADMIN_URL . 'static/favicon.png';
			}
			elseif ($key == 'icon') {
				return ADMIN_URL . 'static/icon.png';
			}

			return null;
		}

		$params = $params ? $params . '&' : '';

		return BASE_URL . self::FILES[$key] . '?' . $params . substr(md5($this->files[$key]), 0, 10);
	}


	public function hasFile(string $key): bool
	{
		return $this->files[$key] ? true : false;
	}

	public function updateFiles(): void
	{
		$files = $this->files;

		foreach (self::FILES as $key => $path) {
			if ($f = Files::get($path)) {
				$files[$key] = $f->modified->getTimestamp();
			}
			else {
				$files[$key] = null;
			}
		}

		$this->set('files', $files);
	}

	public function setFile(string $key, ?string $value, bool $upload = false): ?File
	{
		$f = Files::get(self::FILES[$key]);
		$files = $this->files;
		$type = self::FILES_TYPES[$key];
		$path = self::FILES[$key];

		// NULL = delete file
		if (null === $value) {
			if ($f) {
				$f->delete();
			}

			$f = null;
		}
		elseif ($upload) {
			$f = Files::upload(Utils::dirname($path), $value, Utils::basename($path));

			if ($type == 'image' && !$f->image) {
				$this->setFile($key, null);
				throw new UserException('Le fichier n\'est pas une image.');
			}

			// Force favicon format
			if ($key == 'favicon') {
				$format = 'png';
				$i = new Image($f->fullpath());
				$i->cropResize(32, 32);
				$f->setContent($i->output($format, true));
			}
			// Force icon format
			else if ($key == 'icon') {
				$format = 'png';
				$i = new Image($f->fullpath());
				$i->cropResize(512, 512);
				$f->setContent($i->output($format, true));
			}
			// Force signature size
			else if ($key == 'signature') {
				$format = 'png';
				$i = new Image($f->fullpath());
				$i->resize(200, 200);
				$f->setContent($i->output($format, true));
			}
		}
		elseif ($f) {
			$f->setContent($value);
		}
		else {
			$f = Files::createFromString($path, $value);
		}

		$files[$key] = $f ? $f->modified->getTimestamp() : null;
		$this->set('files', $files);

		return $f;
	}
}
