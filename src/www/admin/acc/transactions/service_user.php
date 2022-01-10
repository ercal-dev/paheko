<?php
namespace Garradin;

use Garradin\Accounting\Reports;
use Garradin\Accounting\Years;

require_once __DIR__ . '/../../_inc.php';

$session->requireAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ);

$criterias = ['subscription' => (int)qg('id')];

$tpl->assign('balance', Reports::getClosingSumsWithAccounts($criterias));
$tpl->assign('journal', Reports::getJournal($criterias));
$tpl->assign('user_id', qg('user'));
$tpl->assign('service_user_id', qg('id'));

$tpl->display('acc/transactions/service_user.tpl');
