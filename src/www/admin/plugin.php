<?php

namespace Garradin;

require_once __DIR__ . '/_inc.php';

$page = qg('_u') ?: 'index.php';

$plugin = new Plugin(qg('_p'));

define('Garradin\PLUGIN_ROOT', $plugin->path());
define('Garradin\PLUGIN_URL', ADMIN_URL . 'plugin/' . $plugin->id() . '/');
define('Garradin\PLUGIN_QSP', '?');

$tpl->assign('plugin', $plugin->getInfos());
$tpl->assign('plugin_url', PLUGIN_URL);
$tpl->assign('plugin_root', PLUGIN_ROOT);

$plugin->call('admin/' . $page);
