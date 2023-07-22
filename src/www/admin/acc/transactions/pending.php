<?php
namespace Paheko;

use Paheko\Accounting\Transactions;
use Paheko\Entities\Accounting\Transaction;

require_once __DIR__ . '/../_inc.php';

$list = Transactions::listPendingCreditAndDebtForClosedYears();
$list->loadFromQueryString();

$tpl->assign(compact('list'));

$tpl->display('acc/transactions/pending.tpl');
