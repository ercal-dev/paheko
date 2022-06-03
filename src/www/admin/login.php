<?php
namespace Garradin;

use KD2\HTTP;

const LOGIN_PROCESS = true;

require_once __DIR__ . '/_inc.php';

// Relance session_start et renvoie une image de 1px transparente
if (qg('keepSessionAlive') !== null)
{
    $session->keepAlive();

    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

    header('Content-Type: image/gif');
    echo base64_decode("R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==");

    exit;
}

// L'utilisateur est déjà connecté
if ($session->isLogged())
{
    Utils::redirect(ADMIN_URL . '');
}

$id_field = DynamicFields::get(DynamicFields::getLoginField());
$id_field_name = $id_field->label;

$form->runIf('login', function () use ($id_field_name, $session) {
    if (!trim((string) f('_id'))) {
        throw new UserException(sprintf('L\'identifiant (%s) n\'a pas été renseigné.', $id_field_name));
    }

    if (!trim((string) f('password'))) {
        throw new UserException('Le mot de passe n\'a pas été renseigné.');
    }

    if (!$session->login(f('_id'), f('password'), (bool) f('permanent'))) {
        throw new UserException(sprintf("Connexion impossible.\nVérifiez votre identifiant (%s) et votre mot de passe.", $id_field_name));
    }
}, 'login', ADMIN_URL);

$tpl->assign('ssl_enabled', HTTP::getScheme() == 'https' ? false : true);

$tpl->assign(compact('id_field_name'));
$tpl->assign('changed', qg('changed') !== null);

$tpl->display('login.tpl');