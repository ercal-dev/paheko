<?php
namespace Garradin;

use Garradin\Accounting\Transactions;
use Garradin\UserTemplate\UserForms;
use Garradin\Entities\UserForm;

require_once __DIR__ . '/../_inc.php';

$transaction = Transactions::get((int) qg('id'));

if (!$transaction) {
	throw new UserException('Cette écriture n\'existe pas');
}

$csrf_key = 'details_' . $transaction->id();

$form->runIf('mark_paid', function () use ($transaction) {
	$transaction->markPaid();
	$transaction->save();
}, $csrf_key, Utils::getSelfURI());

$variables = compact('csrf_key', 'transaction') + [
	'transaction_lines'    => $transaction->getLinesWithAccounts(),
	'transaction_year'     => $transaction->year(),
	'files'                => $transaction->listFiles(),
	'creator_name'         => $transaction->id_creator ? (new Membres)->getNom($transaction->id_creator) : null,
	'files_edit'           => $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE),
	'file_parent'          => $transaction->getAttachementsDirectory(),
	'related_users'        => $transaction->listLinkedUsers(),
	'related_transactions' => $transaction->listRelatedTransactions()
];

$tpl->assign($variables);
$tpl->assign('snippets', UserForms::getSnippets(UserForm::SNIPPET_TRANSACTION, $variables));

$tpl->display('acc/transactions/details.tpl');
