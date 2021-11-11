<?php

namespace Garradin;

use Garradin\Users\Session;

require_once __DIR__ . '/../../include/init.php';

function f($key)
{
    return \KD2\Form::get($key);
}

// Query-Validate: valider les éléments passés en GET
function qv(Array $rules)
{
    if (\KD2\Form::validate($rules, $errors, $_GET))
    {
        return true;
    }

    foreach ($errors as &$error)
    {
        $error = sprintf('%s: %s', $error['name'], $error['rule']);
    }

    throw new UserException(sprintf('Paramètres invalides (%s).', implode(', ',  $errors)));
}

function qg($key)
{
    return isset($_GET[$key]) ? $_GET[$key] : null;
}

$tpl = Template::getInstance();
$tpl->assign('admin_url', ADMIN_URL);

$form = new Form;
$tpl->assign_by_ref('form', $form);

$session = Session::getInstance();
$config = Config::getInstance();

$tpl->assign('session', $session);
$tpl->assign('config', $config);

if (!defined('Garradin\LOGIN_PROCESS'))
{
    if (!$session->isLogged())
    {
        if ($session->isOTPRequired())
        {
            Utils::redirect(ADMIN_URL . 'login_otp.php');
        }
        else
        {
            Utils::redirect(ADMIN_URL . 'login.php');
        }
    }

    $tpl->assign('is_logged', true);

    $user = $session->getUser();
    $tpl->assign('user', $user);

    $tpl->assign('current', '');

    if ($session->get('plugins_menu') === null)
    {
        // Construction de la liste de plugins pour le menu
        // et stockage en session pour ne pas la recalculer à chaque page
        $session->set('plugins_menu', Plugin::listMenu($session));
    }

    $tpl->assign('plugins_menu', $session->get('plugins_menu'));
}

// Make sure we allow frames to work
if (array_key_exists('_dialog', $_GET)) {
    header('X-Frame-Options: SAMEORIGIN', true);
}
