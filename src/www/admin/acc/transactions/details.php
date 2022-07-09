<?php
namespace Garradin;

use Garradin\Accounting\Transactions;
use Garradin\UserTemplate\Document;

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

$tpl->assign(compact('transaction', 'csrf_key'));

$tpl->assign('files', $transaction->listFiles());
$tpl->assign('tr_year', $transaction->year());
$tpl->assign('creator_name', $transaction->id_creator ? (new Membres)->getNom($transaction->id_creator) : null);

$tpl->assign('files_edit', $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE));
$tpl->assign('file_parent', $transaction->getAttachementsDirectory());
$tpl->assign('related_users', $transaction->listLinkedUsers());
$tpl->assign('related_transactions', $transaction->listRelatedTransactions());
$tpl->assign('documents', Document::list(Document::CONTEXT_TRANSACTION));
$tpl->assign('doc_params', ['id_transaction' => $transaction->id()]);

$tpl->display('acc/transactions/details.tpl');
