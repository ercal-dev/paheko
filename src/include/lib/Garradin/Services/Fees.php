<?php

namespace Garradin\Services;

use Garradin\DB;
use Garradin\UserException;
use Garradin\Users\Categories;
use Garradin\Entities\Services\Fee;
use Garradin\Entities\Accounting\Year;
use KD2\DB\EntityManager;
use KD2\DB\DB_Exception;

class Fees
{
	protected $service_id;

	public function __construct(int $id)
	{
		$this->service_id = $id;
	}

	static public function get(int $id)
	{
		return EntityManager::findOneById(Fee::class, $id);
	}

	static public function updateYear(Year $old, Year $new): bool
	{
		$db = DB::getInstance();

		if ($new->id_chart == $old->id_chart) {
			$db->preparedQuery('UPDATE services_fees SET id_year = ? WHERE id_year = ?;', $new->id(), $old->id());
			return true;
		}
		else {
			$db->preparedQuery('UPDATE services_fees SET id_year = NULL, id_account = NULL WHERE id_year = ?;', $old->id());
			return false;
		}
	}

	/**
	 * If $user_id is specified, then it will return a column 'user_amount' containing the amount that this specific user should pay
	 */
	static public function listAllByService(?int $user_id = null)
	{
		$db = DB::getInstance();

		$sql = 'SELECT *, CASE WHEN amount THEN amount ELSE NULL END AS user_amount
			FROM services_fees ORDER BY id_service, amount IS NULL, label COLLATE U_NOCASE;';
		$result = $db->get($sql);

		if (!$user_id) {
			return $result;
		}

		foreach ($result as &$row) {
			if (!$row->formula) {
				continue;
			}

			try {
				$sql = sprintf('SELECT %s FROM users WHERE id = %d;', $row->formula, $user_id);
				$row->user_amount = $db->firstColumn($sql);
			}
			catch (DB_Exception $e) {
				$row->label .= sprintf(' (**FORMULE DE CALCUL INVALIDE: %s**)', $e->getMessage());
				$row->description .= "\n\n**MERCI DE CORRIGER LA FORMULE**";
				$row->user_amount = -1;
			}
		}

		return $result;
	}

	public function listWithStats()
	{
		$db = DB::getInstance();
		$hidden_cats = array_keys(Categories::listHidden());

		$condition = sprintf('SELECT COUNT(DISTINCT su.id_user) FROM services_users su
			INNER JOIN (SELECT id, MAX(date) FROM services_users GROUP BY id_user, id_fee) su2 ON su2.id = su.id
			INNER JOIN users u ON u.id = su.id_user WHERE su.id_fee = f.id AND u.id_category NOT IN (%s)',
			implode(',', $hidden_cats));

		$sql = sprintf('SELECT f.*,
			(%s AND (expiry_date IS NULL OR expiry_date >= date()) AND paid = 1) AS nb_users_ok,
			(%1$s AND expiry_date < date()) AS nb_users_expired,
			(%1$s AND paid = 0) AS nb_users_unpaid
			FROM services_fees f
			WHERE id_service = ?
			ORDER BY amount, label COLLATE U_NOCASE;', $condition);

		return $db->get($sql, $this->service_id);
	}
}