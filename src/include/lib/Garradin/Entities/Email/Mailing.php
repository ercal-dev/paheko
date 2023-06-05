<?php

namespace Garradin\Entities\Email;

use Garradin\Config;
use Garradin\CSV;
use Garradin\DB;
use Garradin\DynamicList;
use Garradin\Entity;
use Garradin\Log;
use Garradin\UserException;
use Garradin\Email\Emails;
use Garradin\Users\DynamicFields;
use Garradin\Users\Users;
use Garradin\UserTemplate\UserTemplate;
use Garradin\Web\Render\Render;

use DateTime;
use stdClass;

class Mailing extends Entity
{
	const TABLE = 'mailings';
	const NAME = 'Message collectif';
	const PRIVATE_URL = '!users/mailing/details.php?id=%d';

	protected ?int $id = null;
	protected string $subject;
	protected ?string $body;

	/**
	 * Leave sender name and email NULL to use org name + email
	 */
	protected ?string $sender_name;
	protected ?string $sender_email;

	/**
	 * NULL when the mailing has not been sent yet
	 */
	protected ?DateTime $sent;

	/**
	 * TRUE when the list of recipients has been anonymized
	 * @var boolean
	 */
	protected bool $anonymous = false;

	public function selfCheck(): void
	{
		parent::selfCheck();

		$this->assert(trim($this->subject) !== '', 'Le sujet ne peut rester vide.');
		$this->assert(!isset($this->body) || trim($this->body) !== '', 'Le corps du message ne peut rester vide.');

		if (isset($this->sender_name) || isset($this->sender_email)) {
			$this->assert(trim($this->sender_name) !== '', 'Le nom d\'expéditeur est vide.');
			$this->assert(trim($this->sender_email) !== '', 'L\'adresse e-mail de l\'expéditeur est manquante.');
			$this->assert(Email::isAddressValid($this->sender_email), 'L\'adresse e-mail de l\'expéditeur est invalide.');
		}
	}

	public function populate(string $target, ?int $target_id = null): void
	{
		if ($target !== 'all' && empty($target_id)) {
			throw new \InvalidArgumentException('Missing target ID');
		}

		if ($target == 'all') {
			$recipients = Users::iterateEmailsByCategory(null);
		}
		elseif ($target == 'category') {
			$recipients = Users::iterateEmailsByCategory($target_id);
		}
		elseif ($target == 'search') {
			$recipients = Users::iterateEmailsBySearch($target_id);
		}
		elseif ($target == 'service') {
			$recipients = Users::iterateEmailsByActiveService($target_id);
		}
		else {
			throw new \InvalidArgumentException('Invalid target');
		}

		$list = [];

		foreach ($recipients as $recipient) {
			if (empty($recipient->email)) {
				continue;
			}

			$list[$recipient->email] = $recipient;
		}

		if (!count($list)) {
			throw new UserException('La liste de destinataires sélectionnée ne comporte aucun membre, ou aucun avec une adresse e-mail renseignée.');
		}

		$db = DB::getInstance();
		$db->begin();

		foreach ($list as $email => $data) {
			$this->addRecipient($email, $data);
		}

		$db->commit();
	}

	public function addRecipient(string $email, $data = null): void
	{
		if (!$this->exists()) {
			throw new \LogicException('Mailing does not exist');
		}

		$email = strtolower(trim($email));
		$e = Emails::getEmail($email);

		if ($e && !$e->canSend()) {
			$data = null;
		}
		else {
			try {
				// Validate e-mail address, but not MX (quick check)
				Email::validateAddress($email, false);
			}
			catch (UserException $ex) {
				$e = Emails::createEmail($email);
				$e->setFailedValidation($ex->getMessage());
				$data = null;
			}
		}

		DB::getInstance()->insert('mailings_recipients', [
			'id_mailing' => $this->id,
			'id_email'   => $e ? $e->id : null,
			'email'      => $email,
			'extra_data' => $data ? json_encode($data) : null,
		]);
	}

	public function listRecipients(): \Generator
	{
		$db = DB::getInstance();

		foreach ($db->iterate('SELECT email, extra_data AS data FROM mailings_recipients WHERE id_mailing = ? ORDER BY id;', $this->id) as $row) {
			$data = $row->data ? json_decode($row->data) : null;
			yield $row->email => ['data' => $data, 'pgp_key' => $data->pgp_key ?? null];
		}
	}

	public function getRecipientsList(): DynamicList
	{
		$fields = DynamicFields::getNameFields();
		$fields = array_map(fn($a) => sprintf('json_extract(r.extra_data, \'$.%s\')', $a), $fields);
		$fields = implode(' || \' \' || ', $fields);

		$columns = [
			'id' => [
				'select' => 'r.id',
			],
			'id_email' => [
				'select' => 'r.id_email',
			],
			'email' => [
				'label' => 'Adresse',
				'order' => 'r.email COLLATE NOCASE %s',
				'select' => 'r.email',
			],
			'name' => [
				'label' => 'Nom',
				'select' => $fields,
			],
			'status' => [
				'label' => 'Erreur',
				'select' => Emails::getRejectionStatusClause('e'),
			],
		];

		$tables = 'mailings_recipients AS r LEFT JOIN emails e ON e.id = r.id_email';
		$conditions = 'id_mailing = ' . $this->id;

		$list = new DynamicList($columns, $tables);
		$list->orderBy('email', false);
		return $list;
	}

	public function countRecipients(): int
	{
		return DB::getInstance()->count('mailings_recipients', 'id_mailing = ?', $this->id);
	}

	public function anonymize(): void
	{
		DB::getInstance()->preparedQuery('UPDATE mailings_recipients SET email = NULL, extra_data = NULL WHERE id_mailing = ?;', $this->id);
	}

	public function deleteRecipient(int $id): void
	{
		DB::getInstance()->delete('mailings_recipients', 'id = ? AND id_mailing = ?', $id, $this->id);
	}

/*
	public function populateFromCSV(string $list): void
	{
		$list = explode("\n", $list);
		$emails = [];

		foreach ($list as $line) {
			$line = trim($line);

			$address = strtok(';')
		}
	}
*/

	public function getFrom(): string
	{
		$config = Config::getInstance();
		return sprintf('"%s" <%s>', $this->sender_name ?? $config->org_name, $this->sender_email ?? $config->org_email);
	}

	/**
	 * @return UserTemplate|string
	 */
	public function getBody()
	{
		if (false !== strpos($this->body, '{{')) {
			$tpl = new UserTemplate(null);
			$tpl->setCode($this->body);
			$tpl->toggleSafeMode(true);
			$tpl->setEscapeDefault(null);
			return $tpl;
		}

		return $this->body;
	}

	public function getPreview(string $address = null): string
	{
		$db = DB::getInstance();

		$where = $address ? 'email = ?' : '1 ORDER BY RANDOM()';
		$sql = sprintf('SELECT extra_data FROM mailings_recipients WHERE %s LIMIT 1;', $where);
		$args = $address ? (array)$address : [];

		$r = $db->firstColumn($sql, ...$args);

		if (!$r) {
			throw new UserException('Cette adresse ne fait pas partie des destinataires: ' . $address);
		}

		$r = json_decode($r, true);

		$body = $this->getBody();

		if ($body instanceof UserTemplate) {
			$body->assignArray($r);

			try {
				$body = $body->fetch();
			}
			catch (\KD2\Brindille_Exception $e) {
				throw new UserException('Erreur de syntaxe dans le corps du message :' . PHP_EOL . $e->getPrevious()->getMessage(), 0, $e);
			}
		}

		$render = Render::FORMAT_MARKDOWN;
		return Render::render($render, null, $body);
	}

	public function getHTMLPreview(string $address = null, bool $append_footer = false): string
	{
		$html = $this->getPreview($address);
		$tpl = new UserTemplate('email.html');
		$tpl->assignArray(compact('html'));

		$out = $tpl->fetch();

		if ($append_footer) {
			$out = Emails::appendHTMLOptoutFooter($out, 'javascript:alert(\'--\');');
		}

		return $out;
	}

	public function send(): void
	{
		$this->selfCheck();

		if (!isset($this->body)) {
			throw new UserException('Le corps du message est vide.');
		}

		$sender = null;

		if (isset($this->sender_name, $this->sender_email)) {
			$sender = Emails::getFromHeader($this->sender_name, $this->sender_email);
		}

		Emails::queue(Emails::CONTEXT_BULK,
			$this->listRecipients(),
			$sender,
			$this->subject,
			$this->getBody(),
			Render::FORMAT_MARKDOWN
		);

		$this->set('sent', new DateTime);

		$this->save();

		Log::add(Log::SENT, ['entity' => get_class($this), 'id' => $this->id()]);
	}

	public function export(string $format): void
	{
		$rows = [];

		foreach ($this->listRecipients() as $row) {
			$rows[] = [$row->email ?? '(Anonymisée)', $row->name];
		}

		CSV::export($format, 'Destinataires message collectif', $rows, ['Adresse e-mail', 'Identité']);
	}
}
