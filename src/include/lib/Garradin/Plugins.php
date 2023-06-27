<?php

namespace Garradin;

use Garradin\Entities\Plugin;
use Garradin\Entities\Module;

use Garradin\Users\Session;
use Garradin\DB;
use Garradin\UserTemplate\CommonFunctions;
use Garradin\UserTemplate\Modules;

use \KD2\DB\EntityManager as EM;

use const Garradin\{SYSTEM_SIGNALS, ADMIN_URL, WWW_URL, PLUGINS_ROOT};

class Plugins
{
	const NAME_REGEXP = '[a-z][a-z0-9]*(?:_[a-z0-9]+)*';

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

	/**
	 * Set to false to disable signal firing
	 * @var boolean
	 */
	static protected $signals = true;

	static public function toggleSignals(bool $enabled)
	{
		self::$signals = $enabled;
	}

	static public function getPrivateURL(string $id, string $path = '')
	{
		return ADMIN_URL . 'p/' . $id . '/' . ltrim($path, '/');
	}

	static public function getPublicURL(string $id, string $path = '')
	{
		return WWW_URL . 'p/' . $id . '/' . ltrim($path, '/');
	}

	static public function getPath(string $name): ?string
	{
		if (file_exists(PLUGINS_ROOT . '/' . $name)) {
			return PLUGINS_ROOT . '/' . $name;
		}
		elseif (file_exists(PLUGINS_ROOT . '/' . $name . '.tar.gz')) {
			return 'phar://' . PLUGINS_ROOT . '/' . $name . '.tar.gz';
		}

		return null;
	}

	static public function routeStatic(string $name, string $uri): bool
	{
		$path = self::getPath($name);

		if (!$path) {
			throw new \RuntimeException('Invalid plugin: ' . $name);
		}

		if (!preg_match('!^(?:public/|admin/)!', $uri) || false !== strpos($uri, '..')) {
			return false;
		}

		$path .= '/' . $uri;

		if (!file_exists($path)) {
			return false;
		}

		if (substr($uri, -3) === '.md') {
			Router::markdown(file_get_contents($path));
			return true;
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
			return true;
		}
	}

	static public function exists(string $name): bool
	{
		return self::getPath($name) !== null;
	}

	/**
	 * Déclenche le signal donné auprès des plugins enregistrés
	 * @param  string $signal Nom du signal
	 * @param  array  $params Paramètres du callback (array ou null)
	 * @return NULL 		  NULL si aucun plugin n'a été appelé,
	 * TRUE si un plugin a été appelé et a arrêté l'exécution,
	 * FALSE si des plugins ont été appelés mais aucun n'a stoppé l'exécution
	 */
	static public function fireSignal($signal, $params = null, &$callback_return = null)
	{
		if (!self::$signals) {
			return null;
		}

		// Process SYSTEM_SIGNALS first
		foreach (SYSTEM_SIGNALS as $system_signal) {
			if (key($system_signal) != $signal) {
				continue;
			}

			if (!is_callable(current($system_signal))) {
				throw new \LogicException(sprintf('System signal: cannot call "%s" for signal "%s"', current($system_signal), key($system_signal)));
			}

			if (true === call_user_func_array(current($system_signal), [&$params, &$callback_return])) {
				return true;
			}
		}

		$list = DB::getInstance()->get('SELECT s.* FROM plugins_signals AS s INNER JOIN plugins p ON p.name = s.plugin
			WHERE s.signal = ? AND p.enabled = 1;', $signal);

		if (!count($list)) {
			return null;
		}

		if (null === $params) {
			$params = [];
		}

		foreach ($list as $row)
		{
			$path = self::getPath($row->plugin);

			// Ne pas appeler les plugins dont le code n'existe pas/plus,
			if (!$path) {
				continue;
			}

			$callback = 'Garradin\\Plugin\\' . $row->callback;

			if (!is_callable($callback)) {
				continue;
			}

			$params['plugin_root'] = $path;

			$return = call_user_func_array($callback, [&$params, &$callback_return]);

			if (true === $return) {
				return true;
			}
		}

		return false;
	}

	static public function listModulesAndPlugins(bool $installable = false): array
	{
		$list = [];

		if ($installable) {
			foreach (EM::getInstance(Module::class)->iterate('SELECT * FROM @TABLE WHERE enabled = 0;') as $m) {
				$list[$m->name] = ['module' => $m];
			}

			foreach (self::listInstallable() as $name => $p) {
				$list[$name] = ['plugin'   => $p];
			}

			foreach (self::listInstalled() as $p) {
				if ($p->enabled) {
					continue;
				}

				$list[$p->name] = ['plugin'   => $p];
			}
		}
		else {
			foreach (EM::getInstance(Module::class)->iterate('SELECT * FROM @TABLE WHERE enabled = 1;') as $m) {
				$list[$m->name] = ['module' => $m];
			}

			foreach (self::listInstalled() as $p) {
				if (!$p->enabled) {
					continue;
				}

				if (!$p->hasCode()) {
					$p->set('enabled', false);
					$p->save();
					continue;
				}

				$list[$p->name] = ['plugin'   => $p];
			}
		}

		foreach ($list as &$item) {
			$type = isset($item['plugin']) ? 'plugin' : 'module';
			$c = $item[$type];
			$item = $c->asArray();
			$item[$type] = $c;
			$item['icon_url'] = $c->icon_url();
			$item['config_url'] = $c->hasConfig() ? $c->url($c::CONFIG_FILE) : null;
			$item['readme_url'] = $c->enabled && $c->hasFile($c::README_FILE) ? $c->url($c::README_FILE) : null;
			$item['installed'] = $type == 'plugin' ? $c->exists() : true;
			$item['broken'] = $type == 'plugin' ? !$c->hasCode() : false;
			$item['broken_message'] = $type == 'plugin' ? $c->getBrokenMessage() : false;

			$item['url'] = null;

			if ($c->hasFile($c::INDEX_FILE)) {
				$item['url'] = $c->url($c::INDEX_FILE);
			}
		}

		unset($item);

		usort($list, fn ($a, $b) => strnatcasecmp($a['label'] ?? $a['name'], $b['label'] ?? $b['name']));

		return $list;
	}

	static public function listModulesAndPluginsMenu(Session $session): array
	{
		$list = [];

		$sql = 'SELECT \'module\' AS type, name, label, restrict_section, restrict_level FROM modules WHERE menu = 1 AND enabled = 1
			UNION ALL
			SELECT \'plugin\' AS type, name, label, restrict_section, restrict_level FROM plugins WHERE menu = 1 AND enabled = 1;';

		foreach (DB::getInstance()->get($sql) as $item) {
			if ($item->restrict_section && !$session->canAccess($item->restrict_section, $item->restrict_level)) {
				continue;
			}

			$list[$item->type . '_' . $item->name] = $item;
		}

		// Sort items by label
		uasort($list, fn ($a, $b) => strnatcasecmp($a->label, $b->label));

		foreach ($list as &$item) {
			$item = sprintf('<a href="%s/%s/">%s</a>',
				$item->type == 'plugin' ? ADMIN_URL . 'p' : WWW_URL  . 'm',
				$item->name,
				$item->label
			);
		}

		unset($item);

		// Append plugins from signals
		self::fireSignal('menu.item', compact('session'), $list);

		return $list;
	}

	static public function listModulesAndPluginsHomeButtons(Session $session): array
	{
		$list = [];

		$sql = 'SELECT \'module\' AS type, name, label, restrict_section, restrict_level FROM modules WHERE home_button = 1 AND enabled = 1
			UNION ALL
			SELECT \'plugin\' AS type, name, label, restrict_section, restrict_level FROM plugins WHERE home_button = 1 AND enabled = 1;';

		foreach (DB::getInstance()->get($sql) as $item) {
			if ($item->restrict_section && !$session->canAccess($item->restrict_section, $item->restrict_level)) {
				continue;
			}

			$list[$item->type . '_' . $item->name] = $item;
		}

		// Sort items by label
		uasort($list, fn ($a, $b) => strnatcasecmp($a->label, $b->label));

		foreach ($list as &$item) {
			$url = sprintf('%s/%s/', $item->type == 'plugin' ? ADMIN_URL . 'p' : WWW_URL  . 'm', $item->name);
			$item = CommonFunctions::linkButton([
				'label' => $item->label,
				'icon' => $url . 'icon.svg',
				'href' => $url,
			]);
		}

		unset($item);

		foreach (Modules::snippets(Modules::SNIPPET_HOME_BUTTON) as $name => $v) {
			$list['module_' . $name] = $v;
		}

		Plugins::fireSignal('home.button', ['user' => $session->getUser(), 'session' => $session], $list);

		return $list;
	}

	static public function get(string $name): ?Plugin
	{
		return EM::findOne(Plugin::class, 'SELECT * FROM @TABLE WHERE name = ?;', $name);
	}

	static public function listInstalled(): array
	{
		return EM::getInstance(Plugin::class)->all('SELECT * FROM @TABLE ORDER BY label COLLATE NOCASE ASC;');
	}

	static public function refresh(): void
	{
		$db = DB::getInstance();
		$existing = $db->getAssoc(sprintf('SELECT id, name FROM %s;', Plugin::TABLE));

		foreach ($existing as $name) {
			$f = self::get($name);
			try {
				$f->updateFromINI();
				$f->save();
			}
			catch (ValidationException $e) {
				throw new ValidationException($name . ': ' . $e->getMessage(), 0, $e);
			}
		}
	}


	/**
	 * Liste les plugins téléchargés mais non installés
	 */
	static public function listInstallable(bool $check_exists = true): array
	{
		$list = [];

		if ($check_exists) {
			$exists = DB::getInstance()->getAssoc('SELECT name, name FROM plugins;');
		}
		else {
			$exists = [];
		}

		foreach (glob(PLUGINS_ROOT . '/*') as $file)
		{
			if (substr($file, 0, 1) == '.') {
				continue;
			}

			if (is_dir($file) && file_exists($file . '/' . Plugin::META_FILE)) {
				$file = basename($file);
				$name = $file;
			}
			elseif (substr($file, -7) == '.tar.gz') {
				$file = basename($file);
				$name = substr($file, 0, -7);
			}
			else {
				continue;
			}

			// Ignore existing plugins
			if (in_array($name, $exists)) {
				continue;
			}

			$list[$file] = null;

			$p = new Plugin;
			$p->name = $name;
			$p->updateFromINI();
			$list[$name] = $p;

			try {
				$p->selfCheck();
			}
			catch (ValidationException $e) {
				$p->setBrokenMessage($e->getMessage());
			}
		}

		ksort($list);

		return $list;
	}

	static public function install(string $name): void
	{
		$plugin = self::get($name);

		if ($plugin) {
			$plugin->set('enabled', true);
			$plugin->save();
			return;
		}

		$p = new Plugin;
		$p->name = $name;

		if (!$p->hasFile($p::META_FILE)) {
			throw new UserException(sprintf('Le plugin "%s" n\'est pas une extension Paheko : fichier plugin.ini manquant.', $name));
		}

		$p->updateFromINI();

		$db = DB::getInstance();
		$db->begin();
		$p->set('enabled', true);
		$p->save();

		if ($p->hasFile($p::INSTALL_FILE)) {
			$p->call($p::INSTALL_FILE, true);
		}

		$db->commit();
	}

	/**
	 * Upgrade all plugins if required
	 * This is run after an upgrade, a database restoration, or in the Plugins page
	 */
	static public function upgradeAllIfRequired(): bool
	{
		$i = 0;

		foreach (self::listInstalled() as $plugin) {
			// Ignore plugins if code is no longer available
			if (!$plugin->isAvailable()) {
				continue;
			}

			if ($plugin->needUpgrade()) {
				$plugin->upgrade();
				$i++;
			}

			unset($plugin);
		}

		return $i > 0;
	}
}
