<?php
namespace Garradin;

use Garradin\Accounting\Reports;
use Garradin\Users\Users;

require_once __DIR__ . '/../../_inc.php';

$session->requireAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ);

$u = Users::get((int)qg('id'));

if (!$u) {
	throw new UserException('Ce membre n\'existe pas');
}

$criterias = ['creator' => $u->id];

$tpl->assign('journal', Reports::getJournal($criterias, true));
$tpl->assign('transaction_creator', $u);

$tpl->display('acc/transactions/creator.tpl');
