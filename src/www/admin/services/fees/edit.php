<?php
namespace Garradin;

use Garradin\Services\Fees;
use Garradin\Accounting\Accounts;
use Garradin\Accounting\Years;

require_once __DIR__ . '/../_inc.php';

$session->requireAccess($session::SECTION_USERS, $session::ACCESS_ADMIN);

$fee = Fees::get((int) qg('id'));

if (!$fee) {
	throw new UserException("Ce tarif n'existe pas");
}

$service = $fee->service();
$csrf_key = 'fee_edit_' . $fee->id();

$form->runIf('save', function () use ($fee) {
	$fee->importForm();
	$fee->save();
}, $csrf_key, ADMIN_URL . 'services/fees/?id=' . $service->id());

if ($fee->amount) {
	$amount_type = 1;
}
elseif ($fee->formula) {
	$amount_type = 2;
}
else {
	$amount_type = 0;
}

$accounting_enabled = (bool) $fee->id_account;

$years = Years::listOpen();

$account = $fee->id_account ? [$fee->id_account => Accounts::getSelectorLabel($fee->id_account)] : null;
$analytical_account = $fee->id_analytical ? [$fee->id_analytical => Accounts::getSelectorLabel($fee->id_analytical)] : null;

$tpl->assign(compact('service', 'amount_type', 'fee', 'csrf_key', 'account', 'accounting_enabled', 'years', 'analytical_account'));

$tpl->display('services/fees/edit.tpl');
