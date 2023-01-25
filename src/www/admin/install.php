<?php
namespace Garradin;

use Garradin\Entities\Accounting\Chart;
use Garradin\Membres\Session;

const INSTALL_PROCESS = true;

require_once __DIR__ . '/../../include/test_required.php';
require_once __DIR__ . '/../../include/init.php';

if (file_exists(DB_FILE))
{
    throw new UserException('Garradin est déjà installé');
}

if (DISABLE_INSTALL_FORM) {
	throw new \RuntimeException('Install form has been disabled');
}

Install::checkAndCreateDirectories();
Install::checkReset();

function f($key)
{
    return \KD2\Form::get($key);
}

$tpl = Template::getInstance();
$tpl->assign('admin_url', ADMIN_URL);

$form = new Form;
$tpl->assign_by_ref('form', $form);

$form->runIf('save', function () {
    Install::installFromForm();
    Session::getInstance()->forceLogin(1);
}, 'install', ADMIN_URL);

$tpl->assign('countries', Chart::COUNTRY_LIST);

$tpl->display('admin/install.tpl');
