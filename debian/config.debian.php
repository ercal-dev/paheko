<?php

namespace Garradin;

const ENABLE_UPGRADES = false;

if (!empty($_ENV['GARRADIN_STANDALONE']))
{
	$home = $_ENV['HOME'];

	// Config directory
	if (empty($_ENV['XDG_CONFIG_HOME']))
	{
		$_ENV['XDG_CONFIG_HOME'] = $home . '/.config';
	}

	if (!file_exists($_ENV['XDG_CONFIG_HOME'] . '/garradin'))
	{
		mkdir($_ENV['XDG_CONFIG_HOME'] . '/garradin', 0700, true);
	}

	if (file_exists($_ENV['XDG_CONFIG_HOME'] . '/garradin/config.local.php')) {
		require_once $_ENV['XDG_CONFIG_HOME'] . '/garradin/config.local.php';
	}

	// Data directory: where the data will go
	if (empty($_ENV['XDG_DATA_HOME']))
	{
		$_ENV['XDG_DATA_HOME'] = $home . '/.local/share';
	}

	if (!file_exists($_ENV['XDG_DATA_HOME'] . '/garradin'))
	{
		mkdir($_ENV['XDG_DATA_HOME'] . '/garradin', 0700, true);
	}

	if (!defined('Garradin\DATA_ROOT')) {
		define('Garradin\DATA_ROOT', $_ENV['XDG_DATA_HOME'] . '/garradin');
	}

	// Cache directory: temporary stuff
	if (empty($_ENV['XDG_CACHE_HOME']))
	{
		$_ENV['XDG_CACHE_HOME'] = $home . '/.cache';
	}

	if (!file_exists($_ENV['XDG_CACHE_HOME'] . '/garradin'))
	{
		mkdir($_ENV['XDG_CACHE_HOME'] . '/garradin', 0700, true);
	}

	if (!defined('Garradin\CACHE_ROOT')) {
		define('Garradin\CACHE_ROOT', $_ENV['XDG_CACHE_HOME'] . '/garradin');
	}

	if (!defined('Garradin\DB_FILE')) {
		$last_file = $_ENV['XDG_CONFIG_HOME'] . '/garradin/last';

		if ($_ENV['GARRADIN_STANDALONE'] != 1)
		{
			$last_sqlite = trim($_ENV['GARRADIN_STANDALONE']);
		}
		else if (file_exists($last_file))
		{
			$last_sqlite = trim(file_get_contents($last_file));
		}
		else
		{
			$last_sqlite = $_ENV['XDG_DATA_HOME'] . '/garradin/association.sqlite';
		}

		file_put_contents($last_file, $last_sqlite);

		define('Garradin\DB_FILE', $last_sqlite);
	}

	if (!defined('Garradin\LOCAL_LOGIN')) {
		define('Garradin\LOCAL_LOGIN', -1);
	}
}
elseif (isset($_SERVER['SERVER_NAME'])) {
	if (file_exists('/etc/garradin/config.php')) {
		require_once '/etc/garradin/config.php';
	}

	if (!defined('Garradin\DATA_ROOT')) {
		define('Garradin\DATA_ROOT', '/var/lib/garradin');
	}

	if (!defined('Garradin\CACHE_ROOT')) {
		define('Garradin\CACHE_ROOT', '/var/cache/garradin');
	}
}

if (!defined('Garradin\SECRET_KEY')) {
	if (file_exists(CACHE_ROOT . '/key')) {
		define('Garradin\SECRET_KEY', trim(file_get_contents(CACHE_ROOT . '/key')));
	}
	else {
		define('Garradin\SECRET_KEY', base64_encode(random_bytes(64)));
		file_put_contents(CACHE_ROOT . '/key', SECRET_KEY);
	}
}
