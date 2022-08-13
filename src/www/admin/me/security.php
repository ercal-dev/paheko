<?php

namespace Garradin;

use Garradin\Users\DynamicFields;
use Garradin\Users\Session;

require_once __DIR__ . '/../_inc.php';

$user = $session->getUser();
$csrf_key = 'edit_security_' . md5($user->password);
$edit = qg('edit');

$form->runIf('confirm', function () use ($user) {
	$user->importSecurityForm(true);
	$user->save();
}, $csrf_key, '!me/security.php?ok');

$otp = null;

if ($edit == 'otp') {
	$otp = $session->getNewOTPSecret();
}

$tpl->assign('can_use_pgp', \KD2\Security::canUseEncryption());
$tpl->assign('pgp_fingerprint', $user->pgp_key ? $session->getPGPFingerprint($user->pgp_key, true) : null);

$tpl->assign('ok', qg('ok') !== null);
$sessions_count = $session->countActiveSessions();

$id_field = current(DynamicFields::getInstance()->fieldsBySystemUse('login'));
$id = $user->{$id_field->name};
$can_change_password = $user->canChangePassword();

$tpl->assign(compact('id', 'edit', 'id_field', 'user', 'csrf_key', 'sessions_count', 'can_change_password', 'otp'));

$tpl->display('me/security.tpl');
