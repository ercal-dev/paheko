<?php
namespace Paheko;

use Paheko\Install;

require_once __DIR__ . '/../_inc.php';

$form->runIf('reset_ok', function () use ($session) {
	Install::reset($session, f('checkme'));
}, 'reset');

$tpl->display('config/advanced/reset.tpl');
