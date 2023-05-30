<?php
namespace Garradin;

use Garradin\Accounting\Transactions;
use Garradin\Services\Services_User;
use Garradin\Users\Categories;

require_once __DIR__ . '/_inc.php';

$id = (int) qg('id');

$membre = $membres->get($id);

if (!$membre)
{
    throw new UserException("Ce membre n'existe pas.");
}

$champs = $config->get('champs_membres');
$tpl->assign('champs', $champs->getList());

$category = Categories::get($membre->id_category);
$tpl->assign('category', $category);

$tpl->assign('services', Services_User::listDistinctForUser($membre->id));

if ($session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ)) {
	$tpl->assign('transactions_linked', Transactions::countForUser($membre->id));
	$tpl->assign('transactions_created', Transactions::countForCreator($membre->id));
}

$tpl->assign('membre', $membre);

$tpl->display('admin/membres/fiche.tpl');
