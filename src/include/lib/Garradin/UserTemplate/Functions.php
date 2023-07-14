<?php

namespace Garradin\UserTemplate;

use KD2\Brindille;
use KD2\Brindille_Exception;
use KD2\ErrorManager;
use KD2\JSONSchema;
use KD2\Security;

use Garradin\Config;
use Garradin\DB;
use Garradin\Plugins;
use Garradin\Template;
use Garradin\Utils;
use Garradin\UserException;
use Garradin\Email\Emails;
use Garradin\Files\Files;
use Garradin\Entities\Files\File;
use Garradin\Entities\Module;
use Garradin\Entities\Email\Email;
use Garradin\Users\DynamicFields;
use Garradin\Users\Session;

use const Garradin\{ROOT, WWW_URL, SECRET_KEY};

class Functions
{
	const FUNCTIONS_LIST = [
		'include',
		'http',
		'debug',
		'error',
		'read',
		'save',
		'delete',
		'admin_header',
		'admin_footer',
		'password_input',
		'signature',
		'captcha',
		'mail',
		'button',
		'delete_form',
		'form_errors',
		'redirect',
	];

	const COMPILE_FUNCTIONS_LIST = [
		':break' => [self::class, 'break'],
		':continue' => [self::class, 'continue'],
	];

	/**
	 * Compile function to break inside a loop
	 */
	static public function break(string $name, string $params, Brindille $tpl, int $line)
	{
		$in_loop = false;
		foreach ($tpl->_stack as $element) {
			if ($element[0] == $tpl::SECTION) {
				$in_loop = true;
				break;
			}
		}

		if (!$in_loop) {
			throw new Brindille_Exception(sprintf('Error on line %d: break can only be used inside a section', $line));
		}

		return '<?php break; ?>';
	}

	/**
	 * Compile function to continue inside a loop
	 */
	static public function continue(string $name, string $params, Brindille $tpl, int $line)
	{
		$in_loop = 0;
		foreach ($tpl->_stack as $element) {
			if ($element[0] == $tpl::SECTION) {
				$in_loop++;
			}
		}

		$i = ctype_digit(trim($params)) ? (int)$params : 1;

		if ($in_loop < $i) {
			throw new Brindille_Exception(sprintf('Error on line %d: continue can only be used inside a section', $line));
		}

		return sprintf('<?php continue(%d); ?>', $i);
	}

	static public function admin_header(array $params): string
	{
		$tpl = Template::getInstance();
		$tpl->assign($params);

		if (Session::getInstance()->isLogged()) {
			$tpl->assign('plugins_menu', Plugins::listModulesAndPluginsMenu(Session::getInstance()));
		}

		return $tpl->fetch('_head.tpl');
	}

	static public function admin_footer(array $params): string
	{
		$tpl = Template::getInstance();
		$tpl->assign($params);
		return $tpl->fetch('_foot.tpl');
	}

	static public function password_input(): string
	{
		$tpl = Template::getInstance();
		return $tpl->fetch('users/_password_form.tpl');
	}

	static public function save(array $params, Brindille $tpl, int $line): void
	{
		if (!$tpl->module) {
			throw new Brindille_Exception('Module name could not be found');
		}

		$table = 'module_data_' . $tpl->module->name;

		if (!empty($params['key'])) {
			if ($params['key'] == 'uuid') {
				$params['key'] = Utils::uuid();
			}

			$field = 'key';
			$where_value = $params['key'];
		}
		elseif (!empty($params['id'])) {
			$field = 'id';
			$where_value = $params['id'];
		}
		else {
			$field = null;
		}

		$key = $params['key'] ?? null;
		$id = $params['id'] ?? null;
		$assign_new_id = $params['assign_new_id'] ?? null;
		$validate = $params['validate_schema'] ?? null;
		$validate_only = $params['validate_only'] ?? null;

		unset($params['key'], $params['id'], $params['assign_new_id'], $params['validate_schema'], $params['validate_only']);

		$db = DB::getInstance();

		if ($key == 'config') {
			$result = $db->firstColumn(sprintf('SELECT config FROM %s WHERE name = ?;', Module::TABLE), $tpl->module->name);
		}
		else {
			$db->exec(sprintf('
				CREATE TABLE IF NOT EXISTS %s (
					id INTEGER NOT NULL PRIMARY KEY,
					key TEXT NULL,
					document TEXT NOT NULL
				);
				CREATE UNIQUE INDEX IF NOT EXISTS %1$s_key ON %1$s (key);', $table));

			if ($field) {
				$result = $db->firstColumn(sprintf('SELECT document FROM %s WHERE %s;', $table, ($field . ' = ?')), $where_value);
			}
			else {
				$result = null;
			}
		}

		// Merge before update
		if ($result) {
			$result = json_decode((string) $result, true);
			$params = array_merge($result, $params);
		}

		if ($validate) {
			$schema = self::read(['file' => $validate], $tpl, $line);

			if ($validate_only && is_string($validate_only)) {
				$validate_only = explode(',', $validate_only);
				$validate_only = array_map('trim', $validate_only);
			}
			else {
				$validate_only = null;
			}

			try {
				$s = JSONSchema::fromString($schema);

				if ($validate_only) {
					$s->validateOnly($params, $validate_only);
				}
				else {
					$s->validate($params);
				}
			}
			catch (\RuntimeException $e) {
				throw new Brindille_Exception(sprintf("ligne %d: impossible de valider le schéma:\n%s\n\n%s",
					$line, $e->getMessage(), json_encode($params, JSON_PRETTY_PRINT)));
			}
		}

		$value = json_encode($params);

		if ($key == 'config') {
			$db->update(Module::TABLE, ['config' => $value], 'name = :name', ['name' => $tpl->module->name]);
			return;
		}

		$document = $value;
		if (!$result) {
			$db->insert($table, compact('document', 'key'));

			if ($assign_new_id) {
				$tpl->assign($assign_new_id, $db->lastInsertId());
			}
		}
		else {
			$db->update($table, compact('document'), sprintf('%s = :match', $field), ['match' => $where_value]);
		}
	}

	static public function delete(array $params, Brindille $tpl, int $line): void
	{
		if (!$tpl->module) {
			throw new Brindille_Exception('Module name could not be found');
		}

		$table = 'module_data_' . $tpl->module->name;
		$where = [];
		$args = [];
		$i = 0;

		foreach ($params as $key => $value) {
			if ($key[0] == ':') {
				$args[substr($key, 1)] = $value;
			}
			elseif ($key == 'where') {
				$where[] = Sections::_moduleReplaceJSONExtract($value);
			}
			else {
				if ($key == 'id') {
					$value = (int) $value;
				}

				if ($key !== 'id' && $key !== 'key') {
					$args['key_' . $i] = '$.' . $key;
					$key = sprintf('json_extract(document, :key_%d)', $i);
				}

				$where[] = $key . ' = :value_' . $i;
				$args['value_' . $i] = $value;
				$i++;
			}
		}

		$where = implode(' AND ', $where);

		$db = DB::getInstance();
		$db->delete($table, $where, $args);
	}

	static public function captcha(array $params, Brindille $tpl, int $line)
	{
		$secret = md5(SECRET_KEY . Utils::getSelfURL(false));

		if (isset($params['html'])) {
			$c = Security::createCaptcha($secret, $params['lang'] ?? 'fr');
			return sprintf('<label for="f_c_42">Merci d\'écrire <strong><q>&nbsp;%s&nbsp;</q></strong> en chiffres&nbsp;:</label>
				<input type="text" name="f_c_42" id="f_c_42" placeholder="Exemple : 1234" />
				<input type="hidden" name="f_c_43" value="%s" />',
				$c['spellout'], $c['hash']);
		}
		elseif (isset($params['assign_hash']) && isset($params['assign_number'])) {
			$c = Security::createCaptcha($secret, $params['lang'] ?? 'fr');
			$tpl->assign($params['assign_hash'], $c['hash']);
			$tpl->assign($params['assign_number'], $c['spellout']);
		}
		elseif (isset($params['verify'])) {
			$hash = $_POST['f_c_43'] ?? '';
			$number = $_POST['f_c_42'] ?? '';
		}
		elseif (array_key_exists('verify_number', $params)) {
			$hash = $params['verify_hash'] ?? '';
			$number = $params['verify_number'] ?? '';
		}
		else {
			throw new Brindille_Exception(sprintf('Line %d: no valid arguments supplied for "captcha" function', $line));
		}

		$error = 'Réponse invalide à la vérification anti-robot';

		if (!Security::checkCaptcha($secret, trim($hash), trim($number))) {
			if (isset($params['assign_error'])) {
				$tpl->assign($params['assign_error'], $error);
			}
			else {
				throw new UserException($error);
			}
		}
	}

	static public function mail(array $params, UserTemplate $ut, int $line)
	{
		if (empty($params['to'])) {
			throw new Brindille_Exception(sprintf('Ligne %d: argument "to" manquant pour la fonction "mail"', $line));
		}

		if (empty($params['subject'])) {
			throw new Brindille_Exception(sprintf('Ligne %d: argument "subject" manquant pour la fonction "mail"', $line));
		}

		if (empty($params['body'])) {
			throw new Brindille_Exception(sprintf('Ligne %d: argument "body" manquant pour la fonction "mail"', $line));
		}

		if (!empty($params['block_urls']) && preg_match('!https?://!', $params['subject'] . $params['body'])) {
			throw new UserException('Merci de ne pas inclure d\'adresse web (http:…) dans le message');
		}

		$attachments = [];

		if (!empty($params['attach_file'])) {
			$attachments = (array) $params['attach_file'];

			foreach ($attachments as &$file) {
				$f = Files::get($file);

				if (!$f) {
					throw new UserException(sprintf('Le fichier à joindre "%s" n\'existe pas', $file));
				}

				if (!$f->canRead()) {
					throw new UserException(sprintf('Vous n\'avez pas le droit d\'accéder au fichier à joindre "%s"', $file));
				}

				$file = $f;
			}

			unset($file);
		}
		elseif (!empty($params['attach_from'])) {
			if (empty($ut->module)) {
				throw new UserException('"attach_from" can only be called from within a module');
			}

			$attachments = (array) $params['attach_from'];

			foreach ($attachments as &$file) {
				$file = self::getFilePath($file, 'attach_from', $ut, $line);
				$file = $ut->fetchToAttachment($file);
			}

			unset($file);
		}

		static $external = 0;
		static $internal = 0;

		if (is_string($params['to'])) {
			$params['to'] = [$params['to']];
		}

		if (!count($params['to'])) {
			throw new Brindille_Exception(sprintf('Ligne %d: aucune adresse destinataire n\'a été précisée pour la fonction "mail"', $line));
		}

		foreach ($params['to'] as &$to) {
			$to = trim($to);
			Email::validateAddress($to);
		}

		unset($to);

		$db = DB::getInstance();
		$email_field = DynamicFields::getFirstEmailField();
		$internal_count = $db->count('users', $db->where($email_field, 'IN', $params['to']));
		$external_count = count($params['to']) - $internal_count;

		if (($external_count + $external) > 1) {
			throw new Brindille_Exception(sprintf('Ligne %d: l\'envoi d\'email à une adresse externe est limité à un envoi par page', $line));
		}

		if (($internal_count + $internal) > 10) {
			throw new Brindille_Exception(sprintf('Ligne %d: l\'envoi d\'email à une adresse interne est limité à 10 envois par page', $line));
		}

		if ($external_count && preg_match_all('!(https?://.*?)(?=\s|$)!', $params['subject'] . ' ' . $params['body'], $match, PREG_PATTERN_ORDER)) {
			foreach ($match[1] as $m) {
				if (0 !== strpos($m, WWW_URL) && 0 !== strpos($m, ADMIN_URL)) {
					throw new Brindille_Exception(sprintf('Ligne %d: l\'envoi d\'email à une adresse externe interdit l\'utilisation d\'une adresse web autre que le site de l\'association : %s', $line, $m));
				}
			}
		}

		$context = count($params['to']) == 1 ? Emails::CONTEXT_PRIVATE : Emails::CONTEXT_BULK;
		Emails::queue($context, $params['to'], null, $params['subject'], $params['body'], $attachments);

		$internal += $internal_count;
		$external_count += $external_count;
	}

	static public function debug(array $params, Brindille $tpl)
	{
		if (!count($params)) {
			$params = $tpl->getAllVariables();
		}

		$dump = htmlspecialchars(ErrorManager::dump($params));

		// FIXME: only send back HTML when content-type is text/html, or send raw text
		$out = sprintf('<pre style="background: yellow; color: black; padding: 5px; overflow: auto">%s</pre>', $dump);

		if (!empty($params['stop'])) {
			echo $out; exit;
		}

		return $out;
	}

	static public function error(array $params, Brindille $tpl)
	{
		throw new UserException($params['message'] ?? 'Erreur du module');
	}

	static protected function getFilePath(?string $path, string $arg_name, UserTemplate $ut, int $line)
	{
		if (empty($path)) {
			throw new Brindille_Exception(sprintf('Ligne %d: argument "%s" manquant', $arg_name, $line));
		}

		if (strpos($path, '..') !== false) {
			throw new Brindille_Exception(sprintf('Ligne %d: argument "%s" invalide', $line, $arg_name));
		}

		if (substr($path, 0, 2) == './') {
			$path = Utils::dirname($ut->_tpl_path) . substr($path, 1);
		}
		elseif (substr($path, 0, 1) != '/') {
			$path = Utils::dirname($ut->_tpl_path) . '/' . $path;
		}

		$parts = explode('/', $path);
		$out = [];

		foreach ($parts as $part) {
			if ($part == '..') {
				array_pop($out);
			}
			else {
				$out[] = $part;
			}
		}

		$out = implode('/', $out);

		return $out;
	}

	static public function read(array $params, UserTemplate $ut, int $line): string
	{
		$path = self::getFilePath($params['file'] ?? null, 'file', $ut, $line);

		$file = Files::get(File::CONTEXT_MODULES . '/' . $path);

		if ($file) {
			$content = $file->fetch();
		}
		else {
			$content = file_get_contents(ROOT . '/modules/' . $path);
		}

		if (!empty($params['base64'])) {
			return base64_encode($content);
		}

		return $content;
	}

	static public function signature(): string
	{
		$file = Config::getInstance()->file('signature');

		if (!$file) {
			return '';
		}

		// We can't just use the image URL as it would not be accessible by PDF programs
		$url = 'data:image/png;base64,' . base64_encode($file->fetch());

		return sprintf('<figure class="signature"><img src="%s" alt="Signature" /></figure>', $url);
	}

	static public function include(array $params, UserTemplate $ut, int $line): void
	{
		$path = self::getFilePath($params['file'] ?? null, 'file', $ut, $line);

		// Avoid recursive loops
		$from = $ut->get('included_from') ?? [];

		if (in_array($path, $from)) {
			throw new Brindille_Exception(sprintf('Ligne %d : boucle infinie d\'inclusion détectée : %s', $line, $path));
		}

		try {
			$include = new UserTemplate($path);
			$include->setParent($ut);
		}
		catch (\InvalidArgumentException $e) {
			throw new Brindille_Exception(sprintf('Ligne %d : fonction "include" : le fichier à inclure "%s" n\'existe pas', $line, $path));
		}

		$params['included_from'] = array_merge($from, [$path]);

		$include->assignArray(array_merge($ut->getAllVariables(), $params));

		if (!empty($params['capture']) && preg_match('/^[a-z0-9_]+$/', $params['capture'])) {
			$ut::__assign([$params['capture'] => $include->fetch()], $ut, $line);
		}
		else {
			$include->display();
		}

		if (isset($params['keep'])) {
			$keep = explode(',', $params['keep']);
			$keep = array_map('trim', $keep);

			foreach ($keep as $name) {
				// Transmit variables
				$ut::__assign(['var' => $name, 'value' => $include->get($name)], $ut, $line);
			}
		}

		// Transmit nocache to parent template
		if ($include->get('nocache')) {
			$ut::__assign(['nocache' => true], $ut, $line);
		}
	}

	static public function http(array $params, UserTemplate $tpl): void
	{
		if (headers_sent()) {
			return;
		}

		if (isset($params['redirect'])) {
			Utils::redirectDialog($params['redirect']);
		}

		if (isset($params['code'])) {
			$tpl->setHeader('code', $params['code']);
		}

		if (!empty($params['type'])) {
			if ($params['type'] == 'pdf') {
				$params['type'] = 'application/pdf';
			}

			$tpl->setHeader('type', $params['type']);
		}

		if (isset($params['download'])) {
			$tpl->setHeader('disposition', 'attachment');
			$tpl->setHeader('filename', $params['download']);
		}
		elseif (isset($params['inline'])) {
			$tpl->setHeader('disposition', 'inline');
			$tpl->setHeader('filename', $params['inline']);
		}
	}

	static public function _getFormKey(): string
	{
		return 'form_' . md5(Utils::getSelfURI(false));
	}

	/**
	 * @override
	 */
	static public function button(array $params): string
	{
		// Always add CSRF protection when a submit button is present in the form
		if (isset($params['type']) && $params['type'] == 'submit') {
			$key = self::_getFormKey();
			$params['csrf_key'] = $key;
		}

		return CommonFunctions::button($params);
	}

	/**
	 * @override
	 */
	static public function delete_form(array $params, UserTemplate $tpl): string
	{
		$params['csrf_key'] = self::_getFormKey();
		return self::form_errors([], $tpl) . CommonFunctions::delete_form($params);
	}

	static public function form_errors(array $params, UserTemplate $tpl): string
	{
		if (($e = $tpl->get('form_errors')) && is_array($e)) {
			return sprintf('<p class="error block">%s</p>', nl2br(htmlspecialchars(implode("\n", $e))));
		}

		return '';
	}

	static public function redirect(array $params): void
	{
		if (isset($params['force'])) {
			Utils::redirectDialog($params['force']);
		}
		elseif (isset($_GET['_dialog'])) {
			Utils::reloadParentFrame();
		}
		else {
			Utils::redirectDialog($params['to'] ?? null);
		}
	}
}
