<?php
namespace Garradin;

use Garradin\Upgrade;
use KD2\Security;

require_once __DIR__ . '/_inc.php';

if (!ENABLE_UPGRADES) {
	Utils::redirect('/admin/');
	exit;
}

$i = Upgrade::getInstaller();

$csrf_key = 'upgrade_' . sha1(SECRET_KEY);
$releases = $i->listReleases();
$v = garradin_version();

// Remove releases that are in the past
foreach ($releases as $rv => $release) {
	if (!version_compare($rv, $v, '>')) {
		unset($releases[$rv]);
	}
}

$latest = $i->latest();
$tpl->assign('downloaded', false);
$tpl->assign('can_verify', Security::canUseEncryption());

$form->runIf('download', function () use ($i, $tpl) {
	$i->download(f('download'));
	$tpl->assign('downloaded', true);
	$tpl->assign('verified', $i->verify(f('download')));
	$tpl->assign('diff', $i->diff(f('download')));
	$tpl->assign('version', f('download'));
}, $csrf_key);

$form->runIf('upgrade', function () use ($i) {
	$url = ADMIN_URL . 'upgrade.php';
	$i->upgrade(f('upgrade'));
	header('Location: ' . $url);
	exit;
}, $csrf_key);

$tpl->assign('website', WEBSITE);
$tpl->assign(compact('releases', 'latest', 'csrf_key'));
$tpl->display('config/upgrade.tpl');
