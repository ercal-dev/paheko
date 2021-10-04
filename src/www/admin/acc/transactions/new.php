<?php
namespace Garradin;

use Garradin\Entities\Accounting\Account;
use Garradin\Entities\Accounting\Transaction;
use Garradin\Entities\Files\File;
use Garradin\Accounting\Transactions;
use Garradin\Accounting\Years;

require_once __DIR__ . '/../_inc.php';

$session->requireAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE);

if (!CURRENT_YEAR_ID) {
	Utils::redirect(ADMIN_URL . 'acc/years/?msg=OPEN');
}

$chart = $current_year->chart();
$accounts = $chart->accounts();

$transaction = new Transaction;
$lines = [[], []];
$amount = 0;
$payoff_for = qg('payoff_for') ?: f('payoff_for');
$types_accounts = null;

// Duplicate transaction
if (qg('copy')) {
	$old = Transactions::get((int)qg('copy'));
	$transaction = $old->duplicate($current_year);
	$lines = $transaction->getLinesWithAccounts();
	$payoff_for = null;
	$amount = $transaction->getLinesCreditSum();
	$types_accounts = $transaction->getTypesAccounts();
	$transaction->resetLines();

	foreach ($lines as $k => &$line) {
		$line->account = [$line->id_account => sprintf('%s — %s', $line->account_code, $line->account_name)];
	}

	unset($line);
}

$date = new \DateTime;

if ($session->get('acc_last_date')) {
	$date = \DateTime::createFromFormat('!d/m/Y', $session->get('acc_last_date'));
}

if (!$date || ($date < $current_year->start_date || $date > $current_year->end_date)) {
	$date = $current_year->start_date;
}

$transaction->date = $date;

// Quick pay-off for debts and credits, directly from a debt/credit details page
if ($id = $payoff_for) {
	$payoff_for = $transaction->payOffFrom($id);

	if (!$payoff_for) {
		throw new UserException('Écriture inconnue');
	}

	$amount = $payoff_for->sum;
}

// Quick transaction from an account journal page
if ($id = qg('account')) {
	$account = $accounts::get($id);

	if (!$account || $account->id_chart != $current_year->id_chart) {
		throw new UserException('Ce compte ne correspond pas à l\'exercice comptable ou n\'existe pas');
	}

	$transaction->type = Transaction::getTypeFromAccountType($account->type);
	$key = sprintf('account_%d_%d', $transaction->type, 0);

	if (!isset($_POST[$key])) {
		$lines[0]['account'] = $_POST[$key] = [$account->id => sprintf('%s — %s', $account->code, $account->label)];
	}
}
elseif (!empty($_POST['lines']) && is_array($_POST['lines'])) {
	$lines = Utils::array_transpose($_POST['lines']);

	foreach ($lines as &$line) {
		$line['credit'] = Utils::moneyToInteger($line['credit']);
		$line['debit'] = Utils::moneyToInteger($line['debit']);
	}
}

if (f('save') && $form->check('acc_transaction_new')) {
	try {
		$transaction->id_year = $current_year->id();
		$transaction->importFromNewForm();
		$transaction->id_creator = $session->getUser()->id;
		$transaction->save();

		 // Link members
		if (null !== f('users') && is_array(f('users'))) {
			$transaction->updateLinkedUsers(array_keys(f('users')));
		}

		$session->set('acc_last_date', f('date'));

		Utils::redirect(Utils::getSelfURI(false) . '?ok=' . $transaction->id());
	}
	catch (UserException $e) {
		$form->addError($e->getMessage());
	}
}

$tpl->assign(compact('transaction', 'payoff_for', 'amount', 'lines', 'types_accounts'));
$tpl->assign('payoff_targets', implode(':', [Account::TYPE_BANK, Account::TYPE_CASH, Account::TYPE_OUTSTANDING]));
$tpl->assign('ok', (int) qg('ok'));

$tpl->assign('types_details', Transaction::getTypesDetails());
$tpl->assign('chart_id', $chart->id());

$tpl->assign('analytical_accounts', ['' => '-- Aucun'] + $accounts->listAnalytical());
$tpl->display('acc/transactions/new.tpl');
