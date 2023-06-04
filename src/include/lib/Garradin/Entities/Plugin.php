<?php

namespace Garradin\Entities;

use Garradin\Entity;
use Garradin\DB;
use Garradin\Plugins;
use Garradin\Template;
use Garradin\UserException;
use Garradin\Utils;
use Garradin\Files\Files;
use Garradin\UserTemplate\UserTemplate;
use Garradin\Users\Session;
use \KD2\HTML\Markdown;

use Garradin\Entities\Files\File;

use const Garradin\{PLUGINS_ROOT, WWW_URL, ROOT, ADMIN_URL};

class Plugin extends Entity
{
	const META_FILE = 'plugin.ini';
	const CONFIG_FILE = 'admin/config.php';
	const INDEX_FILE = 'admin/index.php';
	const ICON_FILE = 'admin/icon.svg';
	const INSTALL_FILE = 'install.php';
	const UPGRADE_FILE = 'upgrade.php';
	const UNINSTALL_FILE = 'uninstall.php';

	const PROTECTED_FILES = [
		self::META_FILE,
		self::INSTALL_FILE,
		self::UPGRADE_FILE,
		self::UNINSTALL_FILE,
	];

	const MIME_TYPES = [
		'css'  => 'text/css',
		'gif'  => 'image/gif',
		'htm'  => 'text/html',
		'html' => 'text/html',
		'ico'  => 'image/x-ico',
		'jpe'  => 'image/jpeg',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'js'   => 'application/javascript',
		'pdf'  => 'application/pdf',
		'png'  => 'image/png',
		'xml'  => 'text/xml',
		'svg'  => 'image/svg+xml',
		'webp' => 'image/webp',
		'md'   => 'text/x-markdown',
	];

	const TABLE = 'plugins';

	protected ?int $id;

	/**
	 * Directory name
	 */
	protected string $name;

	protected string $label;
	protected string $version;

	protected ?string $description;
	protected ?string $author;
	protected ?string $author_url;

	protected bool $home_button;
	protected bool $menu;
	protected ?string $restrict_section;
	protected ?int $restrict_level;

	protected ?\stdClass $config = null;
	protected bool $enabled;

	protected ?string $_broken_message = null;

	public function hasCode(): bool
	{
		return Plugins::exists($this->name);
	}

	public function selfCheck(): void
	{
		$this->assert(preg_match('/^' . Plugins::NAME_REGEXP . '$/', $this->name), 'Nom unique d\'extension invalide: ' . $this->name);
		$this->assert(isset($this->label) && trim($this->label) !== '', sprintf('%s : le nom de l\'extension ("name") ne peut rester vide', $this->name));
		$this->assert(isset($this->label) && trim($this->version) !== '', sprintf('%s : la version ne peut rester vide', $this->name));

		if ($this->hasCode() || $this->enabled) {
			$this->assert(!$this->menu || $this->hasFile(self::INDEX_FILE), 'Le fichier admin/index.php n\'existe pas alors que la directive "menu" est activée.');
			$this->assert(!$this->home_button || $this->hasFile(self::INDEX_FILE), 'Le fichier admin/index.php n\'existe pas alors que la directive "home_button" est activée.');
			$this->assert(!$this->home_button || $this->hasFile(self::ICON_FILE), 'Le fichier admin/icon.svg n\'existe pas alors que la directive "home_button" est activée.');
		}

		$this->assert(!isset($this->restrict_section) || in_array($this->restrict_section, Session::SECTIONS, true), 'Restriction de section invalide');
		$this->assert(!isset($this->restrict_level) || in_array($this->restrict_level, Session::ACCESS_LEVELS, true), 'Restriction de niveau invalide');
	}

	public function setBrokenMessage(string $str)
	{
		$this->_broken_message = $str;
	}

	public function getBrokenMessage(): ?string
	{
		return $this->_broken_message;
	}

	/**
	 * Fills information from plugin.ini file
	 */
	public function updateFromINI(): bool
	{
		if (!$this->hasFile(self::META_FILE)) {
			return false;
		}

		$ini = parse_ini_file($this->path(self::META_FILE), false, \INI_SCANNER_TYPED);

		if (empty($ini)) {
			return false;
		}

		$ini = (object) $ini;

		if (!isset($ini->name)) {
			return false;
		}

		$this->assert(empty($ini->min_version) || version_compare(\Garradin\paheko_version(), $ini->min_version, '>='), sprintf('L\'extension "%s" nécessite Paheko version %s ou supérieure.', $this->name, $ini->min_version));

		$restrict_section = null;
		$restrict_level = null;

		if (isset($ini->restrict_section, $ini->restrict_level)
			&& array_key_exists($ini->restrict_level, Session::ACCESS_LEVELS)
			&& in_array($ini->restrict_section, Session::SECTIONS)) {
			$restrict_section = $ini->restrict_section;
			$restrict_level = Session::ACCESS_LEVELS[$ini->restrict_level];
		}

		$this->set('label', $ini->name);
		$this->set('version', $ini->version);
		$this->set('description', $ini->description ?? null);
		$this->set('author', $ini->author ?? null);
		$this->set('author_url', $ini->author_url ?? null);
		$this->set('home_button', !empty($ini->home_button));
		$this->set('menu', !empty($ini->menu));
		$this->set('restrict_section', $restrict_section);
		$this->set('restrict_level', $restrict_level);

		return true;
	}

	public function icon_url(): ?string
	{
		if (!$this->hasFile(self::ICON_FILE)) {
			return null;
		}

		return $this->url(self::ICON_FILE);
	}

	public function path(string $file = null): ?string
	{
		$path = Plugins::getPath($this->name);

		if (!$path) {
			return null;
		}

		return $path . ($file ? '/' . $file : '');
	}

	public function hasFile(string $file): bool
	{
		$path = $this->path($file);

		if (!$path) {
			return false;
		}

		return file_exists($path);
	}

	public function hasConfig(): bool
	{
		return $this->hasFile(self::CONFIG_FILE);
	}

	public function url(string $file = '', array $params = null)
	{
		if (null !== $params) {
			$params = '?' . http_build_query($params);
		}

		if (substr($file, 0, 6) == 'admin/') {
			$url = ADMIN_URL;
			$file = substr($file, 6);
		}
		else {
			$url = WWW_URL;
		}

		return sprintf('%sp/%s/%s%s', $url, $this->name, $file, $params);
	}

	public function getConfig(string $key = null)
	{
		if (is_null($key)) {
			return $this->config;
		}

		if ($this->config && property_exists($this->config, $key)) {
			return $this->config->$key;
		}

		return null;
	}

	public function setConfigProperty(string $key, $value = null)
	{
		if (null === $this->config) {
			$this->config = new \stdClass;
		}

		if (is_null($value)) {
			unset($this->config->$key);
		}
		else {
			$this->config->$key = $value;
		}

		$this->_modified['config'] = true;
	}

	public function setConfig(\stdClass $config)
	{
		$this->config = $config;
		$this->_modified['config'] = true;
	}

	/**
	 * Associer un signal à un callback du plugin
	 * @param  string $signal   Nom du signal (par exemple boucle.agenda pour la boucle de type AGENDA)
	 * @param  mixed  $callback Callback, sous forme d'un nom de fonction ou de méthode statique
	 * @return boolean TRUE
	 */
	public function registerSignal(string $signal, callable $callback): void
	{
		$callable_name = '';

		if (!is_callable($callback, true, $callable_name) || !is_string($callable_name))
		{
			throw new \LogicException('Le callback donné n\'est pas valide.');
		}

		// pour empêcher d'appeler des méthodes de Garradin après un import de base de données "hackée"
		if (strpos($callable_name, 'Garradin\\Plugin\\') !== 0)
		{
			throw new \LogicException('Le callback donné n\'utilise pas le namespace Garradin\\Plugin : ' . $callable_name);
		}

		$db = DB::getInstance();

		$callable_name = str_replace('Garradin\\Plugin\\', '', $callable_name);

		$db->preparedQuery('INSERT OR REPLACE INTO plugins_signals VALUES (?, ?, ?);', [$signal, $this->name, $callable_name]);
	}

	public function unregisterSignal(string $signal): void
	{
		DB::getInstance()->preparedQuery('DELETE FROM plugins_signals WHERE plugin = ? AND signal = ?;', [$this->name, $signal]);
	}

	public function delete(): bool
	{
		if ($this->hasFile(self::UNINSTALL_FILE)) {
			$this->call(self::UNINSTALL_FILE, true);
		}

		$db = DB::getInstance();
		$db->delete('plugins_signals', 'plugin = ?', $this->name);
		return parent::delete();
	}

	/**
	 * Renvoie TRUE si le plugin a besoin d'être mis à jour
	 * (si la version notée dans la DB est différente de la version notée dans paheko_plugin.ini)
	 * @return boolean TRUE si le plugin doit être mis à jour, FALSE sinon
	 */
	public function needUpgrade(): bool
	{
		$infos = (object) parse_ini_file($this->path(self::META_FILE), false);

		if (version_compare($this->version, $infos->version, '!=')) {
			return true;
		}

		return false;
	}

	/**
	 * Mettre à jour le plugin
	 * Appelle le fichier upgrade.php dans l'archive si celui-ci existe.
	 */
	public function upgrade(): void
	{
		$this->updateFromINI();

		if ($this->hasFile(self::UPGRADE_FILE)) {
			$this->call(self::UPGRADE_FILE, true);
		}

		$this->save();
	}

	public function oldVersion(): ?string
	{
		return $this->getModifiedProperty('version');
	}

	public function call(string $file, bool $allow_protected = false): void
	{
		$file = ltrim($file, './');

		if (preg_match('!(?:\.\.|[/\\\\]\.|\.[/\\\\])!', $file)) {
			throw new \UnexpectedValueException('Chemin de fichier incorrect.');
		}

		if (!$allow_protected && in_array($file, self::PROTECTED_FILES)) {
			throw new UserException('Le fichier ' . $file . ' ne peut être appelé par cette méthode.');
		}

		$path = $this->path($file);

		if (!file_exists($path)) {
			throw new UserException(sprintf('Le fichier "%s" n\'existe pas dans le plugin "%s"', $file, $this->name));
		}

		if (is_dir($path)) {
			throw new UserException(sprintf('Sécurité : impossible de lister le répertoire "%s" du plugin "%s".', $file, $this->name));
		}

		$is_private = (0 === strpos($file, 'admin/'));

		// Créer l'environnement d'exécution du plugin
		if (substr($file, -4) === '.php') {
			if (substr($file, 0, 6) == 'admin/' || substr($file, 0, 7) == 'public/') {
				define('Garradin\PLUGIN_ROOT', $this->path());
				define('Garradin\PLUGIN_URL', WWW_URL . 'p/' . $this->name . '/');
				define('Garradin\PLUGIN_ADMIN_URL', WWW_URL .'admin/p/' . $this->name . '/');
				define('Garradin\PLUGIN_QSP', '?');

				$tpl = Template::getInstance();

				if ($is_private) {
					require ROOT . '/www/admin/_inc.php';
					$tpl->assign('current', 'plugin_' . $this->name);
				}

				$tpl->assign('plugin', $this);
				$tpl->assign('plugin_url', \Garradin\PLUGIN_URL);
				$tpl->assign('plugin_admin_url', \Garradin\PLUGIN_ADMIN_URL);
				$tpl->assign('plugin_root', \Garradin\PLUGIN_ROOT);
			}

			$plugin = $this;

			include $path;
		}
		else {
			// Récupération du type MIME à partir de l'extension
			$pos = strrpos($path, '.');
			$ext = substr($path, $pos+1);

			$mime = self::MIME_TYPES[$ext] ?? 'text/plain';

			header('Content-Type: ' .$mime);
			header('Content-Length: ' . filesize($path));
			header('Cache-Control: public, max-age=3600');
			header('Last-Modified: ' . date(DATE_RFC7231, filemtime($path)));

			readfile($path);
		}
	}

	public function route(string $uri): void
	{
		$uri = ltrim($uri, '/');

		if (0 === strpos($uri, 'admin/')) {
			if (!Session::getInstance()->isLogged()) {
				Utils::redirect('!login.php');
			}
		}
		else {
			$uri = 'public/' . $uri;
		}

		if (!$uri || substr($uri, -1) == '/') {
			$uri .= 'index.php';
		}

		try {
			$this->call($uri);
		}
		catch (\UnexpectedValueException $e) {
			http_response_code(404);
			throw new UserException($e->getMessage());
		}
	}

	public function isAvailable(): bool
	{
		return $this->hasFile(self::META_FILE);
	}
}