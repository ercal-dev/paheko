<?php

namespace Garradin\Entities\Services;

use Garradin\DB;
use Garradin\DynamicList;
use Garradin\Entity;
use Garradin\Form;
use Garradin\ValidationException;
use Garradin\Utils;
use Garradin\Users\DynamicFields;
use Garradin\Entities\Accounting\Account;
use Garradin\Entities\Accounting\Project;
use Garradin\Entities\Accounting\Year;
use KD2\DB\EntityManager;
use KD2\DB\DB_Exception;

class Fee extends Entity
{
	const NAME = 'Tarif';
	const PRIVATE_URL = '!services/fees/details.php?id=%d';

	const TABLE = 'services_fees';

	protected ?int $id;
	protected string $label;
	protected ?string $description = null;
	protected ?int $amount = null;
	protected ?string $formula = null;
	protected int $id_service;
	protected ?int $id_account = null;
	protected ?int $id_year = null;
	protected ?int $id_project = null;

	public function filterUserValue(string $type, $value, string $key)
	{
		if ($key == 'amount' && $value !== null) {
			$value = Utils::moneyToInteger($value);
		}

		return $value;
	}

	public function importForm(array $source = null)
	{
		if (null === $source) {
			$source = $_POST;
		}

		if (isset($source['account'])) {
			$source['id_account'] = Form::getSelectorValue($source['account']);
		}

		if (isset($source['amount_type'])) {
			if ($source['amount_type'] == 2) {
				$source['amount'] = null;
			}
			elseif ($source['amount_type'] == 1) {
				$source['formula'] = null;
			}
			else {
				$source['amount'] = $source['formula'] = null;
			}
		}

		if (empty($source['accounting'])) {
			$source['id_account'] = $source['id_year'] = null;
		}
		elseif (!empty($source['accounting']) && empty($source['id_account'])) {
			$source['id_account'] = null;
		}

		return parent::importForm($source);
	}

	public function selfCheck(): void
	{
		$db = DB::getInstance();
		parent::selfCheck();

		$this->assert(trim($this->label) !== '', 'Le libellé doit être renseigné');
		$this->assert(strlen((string) $this->label) <= 200, 'Le libellé doit faire moins de 200 caractères');
		$this->assert(strlen((string) $this->description) <= 2000, 'La description doit faire moins de 2000 caractères');
		$this->assert(null === $this->amount || $this->amount > 0, 'Le montant est invalide : ' . $this->amount);
		$this->assert($this->id_service, 'Aucun service n\'a été indiqué pour ce tarif.');
		$this->assert((null === $this->id_account && null === $this->id_year)
			|| (null !== $this->id_account && null !== $this->id_year), 'Le compte doit être indiqué avec l\'exercice');
		$this->assert(null === $this->id_account || $db->test(Account::TABLE, 'id = ?', $this->id_account), 'Le compte indiqué n\'existe pas');
		$this->assert(null === $this->id_year || $db->test(Year::TABLE, 'id = ?', $this->id_year), 'L\'exercice indiqué n\'existe pas');
		$this->assert(null === $this->id_account || $db->test(Account::TABLE, 'id = ? AND id_chart = (SELECT id_chart FROM acc_years WHERE id = ?)', $this->id_account, $this->id_year), 'Le compte sélectionné ne correspond pas à l\'exercice');
		$this->assert(null === $this->id_project || $db->test(Project::TABLE, 'id = ?', $this->id_project), 'Le projet sélectionné n\'existe pas.');

		if (null !== $this->formula && ($error = $this->checkFormula())) {
			throw new ValidationException('Formule de calcul invalide: ' . $error);
		}

		$this->assert(null === $this->amount || null === $this->formula, 'Il n\'est pas possible de spécifier à la fois une formule et un montant');
	}

	public function getAmountForUser(int $user_id): ?int
	{
		if ($this->amount) {
			return $this->amount;
		}
		elseif ($this->formula) {
			$db = DB::getInstance();
			return (int) $db->firstColumn($this->getFormulaSQL(), $user_id);
		}

		return null;
	}

	protected function getFormulaSQL()
	{
		return sprintf('SELECT (%s) FROM users WHERE id = ?;', $this->formula);
	}

	protected function checkFormula(): ?string
	{
		try {
			$db = DB::getInstance();
			$sql = $this->getFormulaSQL();
			$db->protectSelect(['users' => null], $sql);
			return null;
		}
		catch (DB_Exception $e) {
			return $e->getMessage();
		}
	}

	public function service()
	{
		return EntityManager::findOneById(Service::class, $this->id_service);
	}

	public function allUsersList(): DynamicList
	{
		$identity = DynamicFields::getNameFieldsSQL('u');

		$columns = [
			'id_user' => [
				'select' => 'su.id_user',
			],
			'user_number' => [
				'label' => 'Numéro de membre',
				'select' => 'u.' . DynamicFields::getNumberField(),
				'export_only' => true,
			],
			'identity' => [
				'label' => 'Membre',
				'select' => $identity,
			],
			'paid' => [
				'label' => 'Payé ?',
				'select' => 'su.paid',
				'order' => 'su.paid %s, su.date %1$s',
			],
			'paid_amount' => [
				'label' => 'Montant payé',
				'select' => 'CASE WHEN tu.id_service_user IS NOT NULL THEN SUM(l.credit) ELSE NULL END',
			],
			'date' => [
				'label' => 'Date',
				'select' => 'su.date',
			],
		];

		$tables = 'services_users su
			INNER JOIN users u ON u.id = su.id_user
			INNER JOIN services_fees sf ON sf.id = su.id_fee
			INNER JOIN (SELECT id, MAX(date) FROM services_users GROUP BY id_user, id_fee) AS su2 ON su2.id = su.id
			LEFT JOIN acc_transactions_users tu ON tu.id_service_user = su.id
			LEFT JOIN acc_transactions_lines l ON l.id_transaction = tu.id_transaction';
		$conditions = sprintf('su.id_fee = %d
			AND u.id_category NOT IN (SELECT id FROM users_categories WHERE hidden = 1)', $this->id());

		$list = new DynamicList($columns, $tables, $conditions);
		$list->groupBy('su.id_user');
		$list->orderBy('paid', true);
		$list->setCount('COUNT(DISTINCT su.id_user)');

		$list->setExportCallback(function (&$row) {
			$row->paid_amount = $row->paid_amount ? Utils::money_format($row->paid_amount, '.', '', false) : null;
		});

		return $list;
	}

	public function activeUsersList(): DynamicList
	{
		$list = $this->allUsersList();
		$conditions = sprintf('su.id_fee = %d AND (su.expiry_date >= date() OR su.expiry_date IS NULL)
			AND su.paid = 1
			AND u.id_category NOT IN (SELECT id FROM users_categories WHERE hidden = 1)', $this->id());
		$list->setConditions($conditions);
		return $list;
	}

	public function unpaidUsersList(): DynamicList
	{
		$list = $this->allUsersList();
		$conditions = sprintf('su.id_fee = %d AND su.paid = 0 AND u.id_category NOT IN (SELECT id FROM users_categories WHERE hidden = 1)', $this->id());
		$list->setConditions($conditions);
		return $list;
	}

	public function expiredUsersList(): DynamicList
	{
		$list = $this->allUsersList();
		$conditions = sprintf('su.id_fee = %d AND su.expiry_date < date() AND u.id_category NOT IN (SELECT id FROM users_categories WHERE hidden = 1)', $this->id());
		$list->setConditions($conditions);
		return $list;
	}


	public function getUsers(bool $paid_only = false): array
	{
		$where = $paid_only ? 'AND paid = 1' : '';
		$id_field = Config::getInstance()->champ_identite;
		$sql = sprintf('SELECT su.id_user, u.%s FROM services_users su INNER JOIN membres u ON u.id = su.id_user WHERE su.id_fee = ? %s;', $id_field, $where);
		return DB::getInstance()->getAssoc($sql, $this->id());
	}
}
