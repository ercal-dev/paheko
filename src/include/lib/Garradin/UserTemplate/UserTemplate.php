<?php

namespace Garradin\UserTemplate;

use KD2\Brindille;
use KD2\Brindille_Exception;
use KD2\Translate;

use Garradin\Config;
use Garradin\Plugin;
use Garradin\Utils;

use Garradin\Web\Skeleton;
use Garradin\Entities\Files\File;

use Garradin\UserTemplate\Modifiers;
use Garradin\UserTemplate\Functions;
use Garradin\UserTemplate\Sections;

use const Garradin\{WWW_URL, ADMIN_URL, SHARED_USER_TEMPLATES_CACHE_ROOT, USER_TEMPLATES_CACHE_ROOT, DATA_ROOT};

class UserTemplate extends Brindille
{
	protected $path;
	protected $modified;
	protected $file;

	static protected $root_variables;

	static public function getRootVariables()
	{
		if (null !== self::$root_variables) {
			return self::$root_variables;
		}

		static $keys = ['color1', 'color2', 'org_name', 'org_address', 'org_email', 'org_phone', 'org_web', 'currency', 'country', 'files'];

		$config = Config::getInstance();

		$files = $config::FILES;

		// Put URL in files array
		array_walk($files, function (&$v, $k) use ($config) {
			$v = $config->fileURL($k);
		});

		$config = array_intersect_key($config->asArray(), array_flip($keys));
		$config['files'] = $files;

		// @deprecated
		// FIXME: remove in a future version
		$config['nom_asso'] = $config['org_name'];
		$config['adresse_asso'] = $config['org_address'];
		$config['email_asso'] = $config['org_email'];
		$config['telephone_asso'] = $config['org_phone'];
		$config['site_asso'] = $config['org_web'];

		self::$root_variables = [
			'root_url'     => WWW_URL,
			'request_url'  => Utils::getRequestURI(),
			'admin_url'    => ADMIN_URL,
			'_GET'         => &$_GET,
			'_POST'        => &$_POST,
			'visitor_lang' => Translate::getHttpLang(),
			'config'       => $config,
		];

		return self::$root_variables;
	}

	public function __construct(?File $file = null)
	{
		if ($file) {
			$this->file = $file;
			$this->modified = $file->modified->getTimestamp();
		}

		$this->assignArray(self::getRootVariables());

		$this->registerAll();

		Plugin::fireSignal('usertemplate.init', ['template' => $this]);
	}

	public function registerAll()
	{
		// Register default Brindille modifiers
		$this->registerDefaults();

		// Common modifiers
		foreach (CommonModifiers::MODIFIERS_LIST as $key => $name) {
			$this->registerModifier(is_int($key) ? $name : $key, is_int($key) ? [CommonModifiers::class, $name] : $name);
		}

		foreach (CommonModifiers::FUNCTIONS_LIST as $key => $name) {
			$this->registerFunction(is_int($key) ? $name : $key, is_int($key) ? [CommonModifiers::class, $name] : $name);
		}

		// PHP modifiers
		foreach (Modifiers::PHP_MODIFIERS_LIST as $name) {
			$this->registerModifier($name, $name);
		}

		// Local modifiers
		foreach (Modifiers::MODIFIERS_LIST as $name) {
			$this->registerModifier($name, [Modifiers::class, $name]);
		}

		// Local functions
		foreach (Functions::FUNCTIONS_LIST as $name) {
			$this->registerFunction($name, [Functions::class, $name]);
		}

		// Local sections
		foreach (Sections::SECTIONS_LIST as $name) {
			$this->registerSection($name, [Sections::class, $name]);
		}
	}

	public function setSource(string $path)
	{
		$this->path = $path;
		$this->modified = filemtime($path);
	}

	public function display(): void
	{
		// Use custom cache for user templates
		if ($this->file) {
			$compiled_path = sprintf('%s/%s.php', USER_TEMPLATES_CACHE_ROOT, sha1($this->file->path));
		}
		// Use shared cache for default templates
		else {
			$compiled_path = sprintf('%s/%s.php', SHARED_USER_TEMPLATES_CACHE_ROOT, sha1($this->path));
		}

		if (!is_dir(dirname($compiled_path))) {
			// Force cache directory mkdir
			Utils::safe_mkdir(dirname($compiled_path), 0777, true);
		}

		if (file_exists($compiled_path) && filemtime($compiled_path) >= $this->modified) {
			require $compiled_path;
			return;
		}

		$tmp_path = $compiled_path . '.tmp';

		$source = $this->file ? $this->file->fetch() : file_get_contents($this->path);

		try {
			$code = $this->compile($source);
			file_put_contents($tmp_path, $code);

			require $tmp_path;
		}
		catch (Brindille_Exception $e) {
			throw new Brindille_Exception(sprintf("Erreur de syntaxe dans '%s' : %s",
				$this->file ? $this->file->name : Utils::basename($this->path),
				$e->getMessage()), 0, $e);
		}
		catch (\Throwable $e) {
			// Don't delete temporary file as it can be used to debug
			throw $e;
		}

		if (!file_exists(Utils::dirname($compiled_path))) {
			Utils::safe_mkdir(Utils::dirname($compiled_path), 0777, true);
		}

		rename($tmp_path, $compiled_path);
	}

	public function fetch(): string
	{
		ob_start();
		$this->display();
		return ob_get_clean();
	}

	public function displayPDF(?string $filename = null): void
	{
		header('Content-type: application/pdf');

		if ($filename) {
			header(sprintf('Content-Disposition: attachment; filename="%s"', Utils::safeFileName($filename)));
		}

		Utils::streamPDF($this->fetch());
	}
}
