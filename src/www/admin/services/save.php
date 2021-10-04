<?php
namespace Garradin;

use Garradin\Services\Services;
use Garradin\Entities\Services\Service_User;
use Garradin\Entities\Accounting\Account;
use Garradin\Entities\Accounting\Transaction;

require_once __DIR__ . '/_inc.php';

$session->requireAccess($session::SECTION_USERS, $session::ACCESS_WRITE);

$count_all = Services::count();

if (!$count_all) {
	Utils::redirect(ADMIN_URL . 'services/?CREATE');
}

$count_all = Services::count();

if (!$count_all) {
	Utils::redirect(ADMIN_URL . 'services/?CREATE');
}

$user_id = qg('user');

if (!$user_id && ($user_id = f('user'))) {
	$user_id = @key($user_id);
}

if (!$user_id) {
	$user_id = f('id_user');
}

$user_id = (int) $user_id ?: null;
$user_name = $user_id ? (new Membres)->getNom($user_id) : null;

if (!$user_name) {
	$user_id = null;
}

$current_only = !qg('past_services');

$grouped_services = Services::listGroupedWithFees($user_id, $current_only);

if (!count($grouped_services)) {
	Utils::redirect(Utils::getSelfURI(['user' => $user_id, 'past_services' => $current_only]));
}

$has_past_services = count($grouped_services) != $count_all;

$csrf_key = 'service_save';

$form->runIf(f('save') || f('save_and_add_payment'), function () use ($session) {
	$su = Service_User::saveFromForm($session->getUser()->id);

	if (f('save_and_add_payment')) {
		$url = ADMIN_URL . 'services/payment.php?id=' . $su->id;
	}
	else {
		$url = ADMIN_URL . 'services/user.php?id=' . $su->id_user;
	}

	Utils::redirect($url);
}, $csrf_key);

$selected_user = $user_name ? [$user_id => $user_name] : null;

$types_details = Transaction::getTypesDetails();
$account_targets = $types_details[Transaction::TYPE_REVENUE]->accounts[1]->targets_string;

$today = new \DateTime;

$tpl->assign(compact('today', 'grouped_services', 'csrf_key', 'selected_user', 'account_targets', 'user_name', 'user_id', 'current_only', 'has_past_services'));

$tpl->display('services/save.tpl');
