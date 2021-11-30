<?php

namespace Garradin\Accounting;

use Garradin\Entities\Accounting\Chart;
use Garradin\Utils;
use Garradin\DB;
use KD2\DB\EntityManager;

class Charts
{
	static public function get(int $id)
	{
		return EntityManager::findOneById(Chart::class, $id);
	}

	static public function list()
	{
		$em = EntityManager::getInstance(Chart::class);
		return $em->all('SELECT * FROM @TABLE ORDER BY country, label;');
	}

	static public function listAssoc()
	{
		return DB::getInstance()->getAssoc(sprintf('SELECT id, country || \' - \' || label FROM %s ORDER BY country, label;', Chart::TABLE));
	}

	static public function listByCountry()
	{
		$sql = sprintf('SELECT id, country, label FROM %s ORDER BY country, code DESC, label;', Chart::TABLE);
		$list = DB::getInstance()->getGrouped($sql);
		$out = [];

		foreach ($list as $row) {
			$country = Utils::getCountryName($row->country);

			if (!array_key_exists($country, $out)) {
				$out[$country] = [];
			}

			$out[$country][$row->id] = $row->label;
		}

		return $out;
	}
}