<?php

namespace Garradin;

use Garradin\Entities\Accounting\Account;
use Garradin\Accounting\Charts;
use Garradin\Accounting\Years;

require_once __DIR__ . '/../../_inc.php';

$targets = qg('targets');
$chart = qg('chart');

// Cache the page until the charts have changed
$hash = sha1($targets . $chart);
$last_change = Config::getInstance()->get('last_chart_change') ?: time();

// Exit if there's no need to reload
Utils::HTTPCache($hash, $last_change);

if ($chart) {
	$chart = Charts::get((int)qg('chart'));
}
elseif (qg('year')) {
	$year = Years::get((int)qg('year'));

	if ($year) {
		$chart = $year->chart();
	}
}
elseif ($current_year) {
	$chart = $current_year->chart();
}

if (!$chart) {
	throw new UserException('Aucun exercice ouvert disponible');
}

$accounts = $chart->accounts();

$tpl->assign(compact('chart', 'targets'));

$all = qg('all');

if (null !== $all) {
	$session->set('account_selector_all', (bool) $all);
}

$all = (bool) $session->get('account_selector_all');

if (!$targets) {
	$tpl->assign('accounts', !$all ? $accounts->listCommonTypes() : $accounts->listAll());
}
else {
	$tpl->assign('grouped_accounts', $accounts->listCommonGrouped(explode(':', $targets)));
}

$tpl->assign('all', $all);

$tpl->display('acc/charts/accounts/selector.tpl');