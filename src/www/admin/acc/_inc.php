<?php

namespace Garradin;

use Garradin\Accounting\Years;

require_once __DIR__ . '/../_inc.php';

if (!defined('Garradin\ALLOW_ACCOUNTS_ACCESS') || !ALLOW_ACCOUNTS_ACCESS) {
	$session->requireAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ);
}

$current_year_id = $session->get('acc_year');
$current_year = null;

if ($current_year_id) {
	// Check that the year is still valid
	$current_year = Years::get($current_year_id);

	if (!$current_year || $current_year->closed) {
		$current_year_id = null;
		$session->set('acc_year', null);
	}
}

if (!$current_year_id) {
	$current_year = Years::getCurrentOpenYear();

	if ($current_year) {
		$current_year_id = $current_year->id();
		$session->set('acc_year', $current_year_id);
	}
}

define('Garradin\CURRENT_YEAR_ID', $current_year_id);

$tpl->assign('current_year', $current_year);
