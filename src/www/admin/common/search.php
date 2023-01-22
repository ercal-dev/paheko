<?php
namespace Garradin;

use Garradin\Accounting\Years;

require_once __DIR__ . '/../_inc.php';

if (empty($target) || !in_array($target, Recherche::TARGETS)) {
	throw new UserException('Cible inconnue');
}

$access_section = $target == 'compta' ? $session::SECTION_ACCOUNTING : $session::SECTION_USERS;

$recherche = new Recherche;

$query = (object) [
	'query' => f('q') ? json_decode(f('q'), true) : null,
	'order' => f('order') ?: $recherche->getDefaultOrder($target),
	'limit' => f('limit') ?: 100,
	'desc'  => $recherche->getDefaultDesc($target),
];

$query->desc = (bool) f('desc');

$text_query = trim((string) qg('qt'));
$result = null;
$sql_query = null;
$search = null;
$id = f('id') ?: qg('id');

$is_unprotected = false;

// Recherche simple
if ($text_query !== '' && $target === 'membres' && empty($query->query))
{
	$query = $recherche->buildSimpleMemberQuery($text_query);
}
elseif ($text_query !== '' && $target == 'compta' && empty($query->query)) {
	$query = $recherche->buildSimpleAccountingQuery($text_query, (int) qg('year'));

	if (is_string($query)) {
		Utils::redirect($query);
	}
}
// Recherche existante
elseif ($id && empty($query->query))
{
	$search = $recherche->get($id);

	if (!$search) {
		throw new UserException('Recherche inconnue ou invalide');
	}

	if ($search->type != Recherche::TYPE_JSON) {
		if ($search->type == Recherche::TYPE_SQL_UNPROTECTED) {
			$is_unprotected = true;
		}

		$sql_query = $search->contenu;
	}
	else {
		$query = $search->query;
		$query->limit = (int) f('limit') ?: $query->limit;
	}
}

// Recherche SQL
if (f('sql_query')) {
	$sql_query = f('sql_query');

	if ($session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN)) {
		$is_unprotected = (bool) f('unprotected');
	}
	else {
		$is_unprotected = false;
	}
}

// Execute search
if ($query->query || $sql_query) {
	try {
		if ($sql_query) {
			$sql = $sql_query;
		}
		else {
			$sql = $recherche->buildQuery($target, $query->query, $query->order, $query->desc, $query->limit);
		}

	   $result = $recherche->searchSQL($target, $sql, null, false, $is_unprotected);

		if (f('to_sql')) {
			$sql_query = $sql;
		}
	}
	catch (UserException $e) {
		$form->addError($e->getMessage());
	}
}

if (null !== $result)
{
	if (count($result) == 1 && $text_query !== '' && $target === 'membres') {
		Utils::redirect(ADMIN_URL . 'membres/fiche.php?id=' . (int)$result[0]->_user_id);
	}

	if ((f('save_new') || f('save')) && !$form->hasErrors())
	{
		if (!$sql_query) {
			$type = Recherche::TYPE_JSON;
		}
		elseif ($is_unprotected) {
			$type = Recherche::TYPE_SQL_UNPROTECTED;
		}
		else {
			$type = Recherche::TYPE_SQL;
		}

		if ($id && !f('save_new')) {
			$recherche->edit($id, [
				'type'    => $type,
				'contenu' => $sql_query ?: $query,
			]);
		}
		else
		{
			$label = $sql_query ? 'Recherche SQL du ' : 'Recherche avancée du ';
			$label .= date('d/m/Y à H:i:s');
			$id = $recherche->add($label, $user->id, $type, $target, $sql_query ?: $query);
		}

		$url = $target == 'compta' ? '!acc/saved_searches.php?edit=' : '!membres/recherches.php?edit=';
		Utils::redirect($url . $id);
	}

	$tpl->assign('result_header', $recherche->getResultHeader($target, $result));
}
elseif ($target === 'membres')
{
	$query->query = [[
		'operator' => 'AND',
		'conditions' => [
			[
				'column'   => $config->get('champ_identite'),
				'operator' => '= ?',
				'values'   => [''],
			],
		],
	]];
	$result = null;
}
elseif ($target === 'compta')
{
	// Default
	$query->query = [[
		'operator' => 'AND',
		'conditions' => [
			[
				'column'   => 't.id_year',
				'operator' => '= ?',
				'values'   => [(int)qg('year') ?: Years::getCurrentOpenYearId()],
			],
			[
				'column'   => 't.label',
				'operator' => 'LIKE %?%',
				'values'   => '',
			],
			[
				'column'   => 't.reference',
				'operator' => 'LIKE %?%',
				'values'   => '',
			],
		],
	]];

	if (null !== qg('type')) {
		$query->query[0]['conditions'][] = [
			'column' => 't.type',
			'operator' => '= ?',
			'values' => [(int)qg('type')],
		];
	}

	if (null !== qg('account')) {
		$query->query[0]['conditions'][] = [
			'column' => 'a.code',
			'operator' => '= ?',
			'values' => [qg('account')],
		];
	}

	$query->desc = true;
	$result = null;
}

$columns = $recherche->getColumns($target);
$is_admin = $session->canAccess($access_section, $session::ACCESS_ADMIN);
$schema = $recherche->schema($target);

$tpl->assign(compact('query', 'sql_query', 'result', 'columns', 'is_admin', 'schema', 'search', 'target', 'is_unprotected'));

if ($target == 'compta') {
	$tpl->display('acc/search.tpl');
}
else {
	$tpl->display('admin/membres/recherche.tpl');
}
