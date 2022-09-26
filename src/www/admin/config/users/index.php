<?php
namespace Garradin;

use Garradin\Users\DynamicFields;
use Garradin\Users\Categories;

require_once __DIR__ . '/../_inc.php';

$csrf_key = 'users_config';
$df = DynamicFields::getInstance();
$config = Config::getInstance();

$form->runIf('save', function() use ($df, $config) {
	$config->importForm();
	$config->save();

	if (!empty($_POST['login_field'])) {
		$df->changeLoginField($_POST['login_field']);
	}

	if (!empty($_POST['name_fields'])) {
		$df->changeNameFields(array_keys($_POST['name_fields']));
	}
}, $csrf_key, Utils::getSelfURI());

$names = $df->listAssocNames();
$name_fields = array_intersect_key($names, array_flip(DynamicFields::getNameFields()));

$tpl->assign([
	'users_categories' => Categories::listSimple(),
	'fields_list'      => $names,
	'login_field'      => DynamicFields::getLoginField(),
	'login_fields_list' => $df->listEligibleLoginFields(),
	'name_fields'      => $name_fields,
	'log_retention_options' => [
		0 => 'Ne pas enregistrer de journaux',
		7 => 'Une semaine',
		30 => 'Un mois',
		90 => '3 mois',
		180 => '6 mois',
		365 => 'Un an',
		720 => 'Deux ans',
	],
]);

$tpl->assign(compact('csrf_key', 'config'));

$tpl->display('config/users/index.tpl');
