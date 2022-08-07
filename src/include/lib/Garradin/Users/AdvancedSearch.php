<?php

namespace Garradin\Users;

use Garradin\DynamicList;
use Garradin\Users\DynamicFields;
use Garradin\AdvancedSearch as A_S;
use Garradin\DB;
use Garradin\Utils;

class AdvancedSearch extends A_S
{
	/**
	 * Returns list of columns for search
	 * @return array
	 */
	public function columns(): array
	{
		$db = DB::getInstance();
		$fields = DynamicFields::getInstance();

		$columns = [];

		$columns['id'] = [];

		$columns['identity'] = [
			'label'    => $fields::getNameLabel(),
			'type'     => 'text',
			'null'     => true,
			'select'   => $fields::getNameFieldsSQL('u'),
			'where'    => $fields::getNameFieldsSQL('us') . ' %s',
			'order'    => sprintf('us.%s %%s', current($fields::getNameFields())),
		];

		$columns['is_parent'] = [
			'label' => 'Est responsable',
			'type' => 'boolean',
			'null' => false,
			'select' => 'CASE WHEN u.is_parent = 1 THEN \'Oui\' ELSE \'Non\' END',
			'where' => 'u.is_parent %s',
		];

		$columns['is_child'] = [
			'label' => 'Est rattaché',
			'type' => 'boolean',
			'null' => false,
			'select' => 'CASE WHEN u.id_parent IS NOT NULL THEN \'Oui\' ELSE \'Non\' END',
			'where' => 'u.id_parent IS NOT NULL',
		];

		foreach ($fields->all() as $name => $field)
		{
			/*
			// already included in identity
			if ($field->system & $field::NAME) {
				continue;
			}
			*/

			// nope
			if ($field->system & $field::PASSWORD) {
				continue;
			}

			$identifier = $db->quoteIdentifier($name);

			$column = [
				'label'  => $field->label,
				'type'   => 'text',
				'null'   => true,
				'select' => sprintf('u.%s', $identifier),
				'where'  => sprintf('%s.%s %%s', $field->hasSearchCache() ? 'us' : 'u', $identifier),
			];

			if ($fields->isText($name)) {
				$column['order'] = sprintf('%s COLLATE U_NOCASE %%s', $name);
			}

			if ($field->type == 'checkbox')
			{
				$column['type'] = 'boolean';
				$column['null'] = false;
			}
			elseif ($field->type == 'select')
			{
				$column['type'] = 'enum';
				$column['values'] = array_combine($field->options, $field->options);
			}
			elseif ($field->type == 'multiple')
			{
				$column['type'] = 'bitwise';
				$column['values'] = $field->options;
			}
			elseif ($field->type == 'date' || $field->type == 'datetime')
			{
				$column['type'] = $field->type;
			}
			elseif ($field->type == 'number')
			{
				$column['type'] = 'integer';
			}

			if ($field->type == 'tel') {
				$column['normalize'] = 'tel';
			}

			$columns[$name] = $column;
		}

		$names = $fields::getNameFields();

		if (count($names) == 1) {
			unset($columns[$names[0]]);
		}

		$columns['id_category'] = [
			'label'  => 'Catégorie',
			'type'   => 'enum',
			'null'   => false,
			'values' => $db->getAssoc('SELECT id, name FROM users_categories ORDER BY name COLLATE U_NOCASE;'),
			'select' => '(SELECT name FROM users_categories WHERE id = id_category)',
			'where'  => 'id_category %s',
		];

		$columns['service'] = [
			'label'  => 'Inscrit à l\'activité',
			'type'   => 'enum',
			'null'   => false,
			'values' => $db->getAssoc('SELECT id, label FROM services ORDER BY label COLLATE U_NOCASE;'),
			'select' => '\'Inscrit\'',
			'where'  => 'id IN (SELECT id_user FROM services_users WHERE id_service %s)',
		];

		$columns['service_active'] = [
			'label'  => 'À jour de l\'activité',
			'type'   => 'enum',
			'null'   => false,
			'values' => $db->getAssoc('SELECT id, label FROM services ORDER BY label COLLATE U_NOCASE;'),
			'select' => '\'À jour\'',
			'where'  => 'id IN (SELECT id_user FROM services_users WHERE id_service %s AND (expiry_date IS NULL OR expiry_date > date()))',
		];

		return $columns;
	}

	public function schema(): array
	{
		$db = DB::getInstance();
		$sql = sprintf('SELECT name, sql FROM sqlite_master WHERE %s ORDER BY name;', $db->where('name', ['users', 'users_categories']));
		return $db->getAssoc($sql);
	}

	public function simple(string $query, bool $allow_redirect = false): \stdClass
	{
		$operator = 'LIKE %?%';
		$db = DB::getInstance();

		if (is_numeric(trim($query)))
		{
			$column = DynamicFields::getNumberField();
			$operator = '= ?';
		}
		elseif (strpos($query, '@') !== false)
		{
			$column = DynamicFields::getFirstEmailField();
		}
		else
		{
			$column = 'identity';
		}

		if ($allow_redirect) {
			$c = $column;

			if ($column == 'identity') {
				$c = DynamicFields::getNameFieldsSQL();
			}

			// Try to redirect to user if there is only one user
			if ($operator == '= ?') {
				$sql = sprintf('SELECT id, COUNT(*) AS count FROM users WHERE %s = ?;', $c);
				$single_query = (int) $query;
			}
			else {
				$sql = sprintf('SELECT id, COUNT(*) AS count FROM users WHERE %s LIKE ?;', $c);
				$single_query = '%' . trim($query) . '%';
			}

			if (($row = $db->first($sql, $single_query)) && $row->count == 1) {
				Utils::redirect('!users/details.php?id=' . $row->id);
			}
		}

		$query = [[
			'operator' => 'AND',
			'conditions' => [
				[
					'column'   => $column,
					'operator' => $operator,
					'values'   => [$query],
				],
			],
		]];

		return (object) [
			'groups' => $query,
			'order' => $column,
			'desc'  => false,
		];
	}

	public function make(string $query): DynamicList
	{
		$tables = 'users AS u INNER JOIN users_search AS us USING (id)';
		return $this->makeList($query, $tables, 'identity', false, ['id', 'identity']);
	}

	public function defaults(): \stdClass
	{
		return (object) ['groups' => [[
			'operator' => 'AND',
			'conditions' => [
				[
					'column'   => current(DynamicFields::getNameFields()),
					'operator' => 'LIKE %?%',
					'values'   => [''],
				],
			],
		]]];
	}
}
