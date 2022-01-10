<?php
namespace Garradin;

use Garradin\Users\Users;

require_once __DIR__ . '/_inc.php';

$session->requireAccess($session::SECTION_USERS, $session::ACCESS_ADMIN);

$user = Users::get((int) qg('id'));

if (!$user) {
	throw new UserException("Ce membre n'existe pas.");
}

if ($user->id == $session->getUser()->id) {
	throw new UserException("Il n'est pas possible de supprimer votre propre compte, merci de demander à un administrateur de le faire.");
}

$csrf_key = 'delete_user_' . $user->id;

$form->runIf('delete', function () use ($user) {
	$user->delete();
}, $csrf_key, '!users/');

$tpl->assign(compact('user', 'csrf_key'));

$tpl->display('users/delete.tpl');
