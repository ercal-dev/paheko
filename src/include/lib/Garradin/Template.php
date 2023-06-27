<?php

namespace Garradin;

use KD2\Form;
use KD2\HTTP;
use KD2\Smartyer;
use KD2\Translate;
use Garradin\Users\Session;
use Garradin\Users\DynamicFields;
use Garradin\Entities\Accounting\Account;
use Garradin\Entities\Users\Category;
use Garradin\Entities\Users\User;
use Garradin\UserTemplate\CommonModifiers;
use Garradin\UserTemplate\CommonFunctions;
use Garradin\Web\Render\Skriv;
use Garradin\Files\Files;

class Template extends Smartyer
{
	static protected $_instance = null;

	static public function getInstance()
	{
		return self::$_instance ?: self::$_instance = new Template;
	}

	public function display($template = null)
	{
		$session = Session::getInstance();
		$this->assign('table_export', false);
		$this->assign('pdf_export', false);

		if ($session->isLogged(true)) {
			if (isset($_GET['_pdf'])) {
				$this->assign('pdf_export', true);
				return $this->PDF($template);
			}
			elseif (isset($_GET['_export']) && $_GET['_export'] == 'test') {
				$this->assign('table_export', true);
			}
			elseif (isset($_GET['_export'])) {
				$this->assign('table_export', true);
				$html = $this->fetch($template);

				if (!stripos($html, '<table')) {
					throw new UserException('Nothing to export: no table found');
				}

				$title = 'Export';

				if (preg_match('!<title>([^<]+)</title>!', $html, $match)) {
					$title = html_entity_decode(trim($match[1]));
				}

				return CSV::exportHTML($_GET['_export'], $html, $title);
			}
		}

		return parent::display($template);
	}

	public function PDF(?string $template = null, ?string $title = null)
	{
		$out = $this->fetch($template);

		if (!$title && preg_match('!<title>(.*)</title>!U', $out, $match)) {
			$title = trim($match[1]);
		}

		header('Content-type: application/pdf');
		header(sprintf('Content-Disposition: attachment; filename="%s.pdf"', Utils::safeFileName($title ?: 'Page')));
		Utils::streamPDF($out);
		return $this;
	}

	private function __clone()
	{
	}

	public function __construct($template = null, Template &$parent = null)
	{
		parent::__construct($template, $parent);

		if (null === $parent) {
			if (self::$_instance !== null) {
				throw new \LogicException('Instance already exists');
			}
		}
		// For included templates just return a new instance,
		// the singleton is only to get the 'master' Template object
		else {
			return $this;
		}

		Translate::extendSmartyer($this);

		$cache_dir = SMARTYER_CACHE_ROOT;

		if (!file_exists($cache_dir)) {
			Utils::safe_mkdir($cache_dir, 0777, true);
		}

		$this->setTemplatesDir(ROOT . '/templates');
		$this->setCompiledDir($cache_dir);
		$this->setNamespace('Garradin');

		$this->assign('version_hash', Utils::getVersionHash());

		$this->assign('www_url', WWW_URL);
		$this->assign('admin_url', ADMIN_URL);
		$this->assign('help_pattern_url', HELP_PATTERN_URL);
		$this->assign('help_url', sprintf(HELP_URL, str_replace('/admin/', '', Utils::getSelfURI(false))));
		$this->assign('admin_url', ADMIN_URL);
		$this->assign('self_url', Utils::getSelfURI());
		$this->assign('self_url_no_qs', Utils::getSelfURI(false));

		$session = null;

		if (!defined('Garradin\INSTALL_PROCESS')) {
			$session = Session::getInstance();
			$this->assign('config', Config::getInstance());
		}
		else {
			$this->assign('config', null);
		}

		$is_logged = $session ? $session->isLogged() : null;

		$this->assign('session', $session);
		$this->assign('is_logged', $is_logged);
		$this->assign('logged_user', $is_logged ? $session->getUser() : null);

		$this->assign('dialog', isset($_GET['_dialog']) ? ($_GET['_dialog'] ?: true) : false);

		$this->register_compile_function('continue', function (Smartyer $s, $pos, $block, $name, $raw_args) {
			if ($block == 'continue')
			{
				return 'continue;';
			}
		});

		$this->register_compile_function('use', function (Smartyer $s, $pos, $block, $name, $raw_args) {
			if ($name == 'use')
			{
				return sprintf('use %s;', $raw_args);
			}
		});

		$this->register_function('form_errors', [$this, 'formErrors']);

		$this->register_function('copy_button', [$this, 'copyButton']);
		$this->register_function('custom_colors', [$this, 'customColors']);
		$this->register_function('plugin_url', ['Garradin\Utils', 'plugin_url']);
		$this->register_function('diff', [$this, 'diff']);
		$this->register_function('display_permissions', [$this, 'displayPermissions']);
		$this->register_function('display_dynamic_field', [$this, 'displayDynamicField']);
		$this->register_function('edit_dynamic_field', [$this, 'editDynamicField']);

		$this->register_function('csrf_field', function ($params) {
			return Form::tokenHTML($params['key']);
		});

		$this->register_function('exportmenu', [$this, 'widgetExportMenu']);
		$this->register_block('linkmenu', [$this, 'widgetLinkMenu']);

		$this->register_modifier('strlen', fn($a) => strlen($a ?? ''));
		$this->register_modifier('dump', ['KD2\ErrorManager', 'dump']);
		$this->register_modifier('get_country_name', ['Garradin\Utils', 'getCountryName']);
		$this->register_modifier('format_tel', [$this, 'formatPhoneNumber']);
		$this->register_modifier('abs', function($a) { return abs($a ?? 0); });


		$this->register_modifier('linkify_transactions', function ($str) {
			$str = preg_replace_callback('/(?<=^|\s)(https?:\/\/.*?)(?=\s|$)/', function ($m) {
				return sprintf('<a href="%s" target="_blank">%1$s</a>', htmlspecialchars($m[1]));
			}, $str);

			return preg_replace_callback('/(?<=^|\s)#(\d+)(?=\s|$)/', function ($m) {
				return sprintf('<a href="%s%d">#%2$d</a>',
					Utils::getLocalURL('!acc/transactions/details.php?id='),
					$m[1]
				);
			}, $str);
		});


		$this->register_modifier('restore_snippet_markup', function ($str) {
			return preg_replace('!&lt;(/?mark)&gt;!', '<$1>', $str);
		});

		$this->register_modifier('format_skriv', function ($str) {
			$skriv = new Skriv;
			return $skriv->render((string) $str);
		});

		foreach (CommonModifiers::PHP_MODIFIERS_LIST as $name) {
			$this->register_modifier($name, [CommonModifiers::class, $name]);
		}

		foreach (CommonModifiers::MODIFIERS_LIST as $key => $name) {
			$this->register_modifier(is_int($key) ? $name : $key, is_int($key) ? [CommonModifiers::class, $name] : $name);
		}

		foreach (CommonFunctions::FUNCTIONS_LIST as $key => $name) {
			$this->register_function(is_int($key) ? $name : $key, is_int($key) ? [CommonFunctions::class, $name] : $name);
		}

		$this->register_modifier('local_url', [Utils::class, 'getLocalURL']);

		// Overwrite default money modifiers
		$this->register_modifier('money', [CommonModifiers::class, 'money_html']);
		$this->register_modifier('money_currency', [CommonModifiers::class, 'money_currency_html']);
	}


	protected function formErrors($params)
	{
		$form = $this->getTemplateVars('form');

		if (!$form->hasErrors())
		{
			return '';
		}

		$errors = $form->getErrorMessages(!empty($params['membre']) ? true : false);

		foreach ($errors as &$error) {
			if ($error instanceof UserException) {
				if ($html = $error->getHTMLMessage()) {
					$message = $html;
				}
				else {
					$message = nl2br($this->escape($error->getMessage()));
				}

				if ($error->hasDetails()) {
					$message = '<h3>' . $message . '</h3>' . $error->getDetailsHTML();
				}

				$error = $message;
			}
			else {
				$error = nl2br($this->escape($error));
			}

			/* Not used currently
			$error = preg_replace_callback('/\[([^\]]*)\]\(([^\)]+)\)/',
				fn ($m) => sprintf('<a href="%s">%s</a>', Utils::getLocalURL($m[2]), $m[1] ?? $m[2])
			);
			*/
		}

		return '<div class="block error"><ul><li>' . implode('</li><li>', $errors) . '</li></ul></div>';
	}

	public function widgetExportMenu(array $params): string
	{
		$url = $params['href'] ?? Utils::getSelfURL();
		$suffix = $params['suffix'] ?? 'export=';

		if (false !== strpos($url, '?')) {
			$url .= '&';
		}
		else {
			$url .= '?';
		}

		$url .= $suffix;

		$xlsx = $params['xlsx'] ?? null;

		if (null === $xlsx) {
			$xlsx = !empty(CALC_CONVERT_COMMAND);
		}

		if (!empty($params['form'])) {
			$name = $params['name'] ?? 'export';
			$out = CommonFunctions::button(['value' => 'csv', 'shape' => 'export', 'label' => 'Export CSV', 'name' => $name, 'type' => 'submit']);
			$out .= CommonFunctions::button(['value' => 'ods', 'shape' => 'export', 'label' => 'Export LibreOffice', 'name' => $name, 'type' => 'submit']);

			if ($xlsx) {
				$out .= CommonFunctions::button(['value' => 'xlsx', 'shape' => 'export', 'label' => 'Export Excel', 'name' => $name, 'type' => 'submit']);
			}
		}
		else {
			$out  = CommonFunctions::linkButton(['href' => $url . 'csv', 'label' => 'Export CSV', 'shape' => 'export']);
			$out .= ' ' . CommonFunctions::linkButton(['href' => $url . 'ods', 'label' => 'Export LibreOffice', 'shape' => 'export']);

			if ($xlsx !== false) {
				$out .= ' ' . CommonFunctions::linkButton(['href' => $url . 'xlsx', 'label' => 'Export Excel', 'shape' => 'export']);
			}
		}

		$params = array_merge($params, ['shape' => 'export', 'label' => $params['label'] ?? 'Export…']);
		return $this->widgetLinkMenu($params, $out);
	}

	protected function widgetLinkMenu(array $params, ?string $content): string
	{
		if (null === $content) {
			return '';
		}

		if (!empty($params['right'])) {
			$params['class'] = 'menu-btn-right';
		}

		$out = sprintf('
			<span class="menu-btn %s">
				<b data-icon="%s" class="btn" ondblclick="this.parentNode.querySelector(\'a, button\').click();" onclick="this.parentNode.classList.toggle(\'active\');">%s</b>
				<span><span>',
			htmlspecialchars($params['class'] ?? ''),
			Utils::iconUnicode($params['shape']),
			htmlspecialchars($params['label'])
		);

		$out .= $content . '</span></span>
			</span>';

		return $out;
	}

	protected function formatPhoneNumber($n)
	{
		if (empty($n)) {
			return '';
		}

		$country = Config::getInstance()->get('country');

		if ($country !== 'FR') {
			return $n;
		}

		if ('FR' === $country && $n[0] === '0' && strlen($n) === 10) {
			$n = preg_replace('!(\d{2})!', '\\1 ', $n);
		}

		return $n;
	}

	protected function customColors()
	{
		$config = defined('Garradin\INSTALL_PROCESS') ? null : Config::getInstance();

		$c1 = ADMIN_COLOR1;
		$c2 = ADMIN_COLOR2;
		$bg = ADMIN_BACKGROUND_IMAGE;

		if (!FORCE_CUSTOM_COLORS && $config) {
			$c1 = $config->get('color1') ?: $c1;
			$c2 = $config->get('color2') ?: $c2;

			if ($url = $config->fileURL('admin_background')) {
				$bg = $url;
			}
		}

		$out = '
		<style type="text/css">
		:root {
			--gMainColor: %s;
			--gSecondColor: %s;
			--gBgImage: url("%s");
		}
		</style>';

		if ($config && $url = $config->fileURL('admin_css')) {
			$out .= "\n" . sprintf('<link rel="stylesheet" type="text/css" href="%s" />', $url);
		}

		return sprintf($out, CommonModifiers::css_hex_to_rgb($c1), CommonModifiers::css_hex_to_rgb($c2), $bg);
	}

	protected function displayDynamicField(array $params): string
	{
		$field = $params['field'] ?? DynamicFields::get($params['key']);
		$v = $params['value'];

		if (!$field) {
			return htmlspecialchars((string)$v);
		}

		if ($field->type == 'checkbox') {
			return $v ? 'Oui' : 'Non';
		}
		elseif ($field->type == 'file' && isset($params['thumb_url'])) {
			$session = Session::getInstance();

			foreach (Files::listForUser($params['user_id'], $field->name) as $file) {
				return '<aside class="file">'
					. $file->link($session, 'auto', false, $params['thumb_url'])
					. '</aside>';
			}

			return '';
		}

		if (empty($v)) {
			return '';
		}

		switch ($field->type)
		{
			case 'password':
				return '*****';
			case 'email':
				return '<a href="mailto:' . rawurlencode($v) . '">' . htmlspecialchars($v) . '</a>';
			case 'tel':
				return '<a href="tel:' . rawurlencode($v) . '">' . htmlspecialchars($this->formatPhoneNumber($v)) . '</a>';
			case 'url':
				return '<a href="' . htmlspecialchars($v) . '" target="_blank">' . htmlspecialchars($v) . '</a>';
			case 'country':
				return Utils::getCountryName($v);
			case 'date':
				return Utils::date_fr($v, 'd/m/Y');
			case 'datetime':
				return Utils::date_fr($v, 'd/m/Y à H:i');
			case 'number':
				return str_replace('.', ',', htmlspecialchars($v));
			case 'multiple':
				// Useful for search results, if a value is not a number
				if (!is_numeric($v)) {
					return htmlspecialchars($v);
				}

				$out = [];

				foreach ($field->options as $b => $name)
				{
					if ($v & (0x01 << $b))
						$out[] = $name;
				}

				return htmlspecialchars(implode(', ', $out));
			default:
				return nl2br(htmlspecialchars(rtrim((string) $v)));
		}
	}

	protected function editDynamicField(array $params): string
	{
		// context = user_edit/new/edit
		assert(isset($params['field'], $params['user'], $params['context']));
		extract($params);
		$key = $field->name;
		$type = $field->type;

		// The password must be changed using a specific field
		if ($field->system & $field::PASSWORD) {
			return '';
		}

		// Files are managed out of the form
		if ($type == 'file') {
			return '';
		}

		// GENERATED columns cannot be edited
		if ($type == 'generated') {
			return '';
		}

		if ($context == 'user_edit' && !$field->read_access) {
			return '';
		}

		if ($context == 'user_edit' && !$field->write_access) {
			$v = $this->displayDynamicField(['key' => $key, 'value' => $params['user']->$key]);
			return sprintf('<dt>%s</dt><dd>%s</dd>', $field->label, $v ?: '<em>Non renseigné</em>');
		}

		$params = [
			'type'     => $type,
			'name'     => $key,
			'label'    => $field->label,
			'required' => $field->required,
			'source'   => $params['user'],
			'disabled' => !empty($disabled),
			'required' => $field->required && $type != 'checkbox',
			'help'     => $field->help,
			// Fix for autocomplete, lpignore is for Lastpass
			'autocomplete' => 'off',
			'data-lpignore' => 'true',
		];

		// Multiple choice checkboxes is a specific thingy
		if ($type == 'multiple') {
			$options = $field->options;

			if (isset($_POST[$key]) && is_array($_POST[$key])) {
				$value = 0;

				foreach ($_POST[$key] as $k => $v) {
					if (array_key_exists($k, $options) && !empty($v)) {
						$value |= 0x01 << $k;
					}
				}
			}
			else {
				$value = $params['source']->$key ?? null;
			}

			// Forcer la valeur à être un entier (depuis PHP 7.1)
			$value = (int)$value;

			if ($field->required) {
				$required_label =  ' <b title="Champ obligatoire">(obligatoire)</b>';
			}
			else {
				$required_label =  ' <i>(facultatif)</i>';
			}

			$out  = sprintf('<dt><label for="f_%s_0">%s</label>%s<input type="hidden" name="%s_present" value="1" /></dt>', $key, htmlspecialchars($field->label), $required_label, $key);

			if ($field->help ?? null) {
				$out .= sprintf('<dd class="help">%s</dd>', htmlspecialchars($field->help));
			}

			foreach ($options as $k => $v)
			{
				$b = 0x01 << (int)$k;

				$p = [
					'type'    => 'checkbox',
					'label'   => $v,
					'value'   => 1,
					'default' => ($value & $b) ? 1 : 0,
					'name'    => sprintf('%s[%d]', $key, $k),
				];

				$out .= CommonFunctions::input($p);
			}

			return $out;
		}
		elseif ($type == 'select') {
			$params['options'] = array_combine($field->options, $field->options);
		}
		elseif ($type == 'country') {
			$params['type'] = 'select';
			$params['options'] = Utils::getCountryList();
			$params['default'] = Config::getInstance()->get('country');
		}
		elseif ($type == 'checkbox') {
			$params['value'] = 1;
			$params['label'] = 'Oui';

			if ($field->required) {
				$required_label =  ' <b title="Champ obligatoire">(obligatoire)</b>';
			}
			else {
				$required_label =  ' <i>(facultatif)</i>';
			}

			return sprintf('<dt><label for="f_%s_1">%s %s</label><input type="hidden" name="%1$s_present" value="1" /></dt>%s', $field->name, htmlspecialchars($field->label), $required_label, CommonFunctions::input($params));
		}
		elseif ($field->system & $field::NUMBER && $context == 'new') {
			$params['default'] = DB::getInstance()->firstColumn(sprintf('SELECT MAX(%s) + 1 FROM %s;', $key, User::TABLE));
			$params['required'] = false;
		}
		elseif ($type == 'number') {
			$params['step'] = 'any';
		}

		if ($field->default_value == '=NOW') {
			$params['default'] = new \DateTime;
		}

		$out = CommonFunctions::input($params);

		if ($context != 'edit' && $field->system & $field::LOGIN) {
			$out .= '<dd class="help"><small>(Sera utilisé comme identifiant de connexion si le membre a le droit de se connecter.)</small></dd>';
		}

		if ($context == 'new' && $field->system & $field::NUMBER) {
			$out .= '<dd class="help"><small>Doit être unique, laisser vide pour que le numéro soit attribué automatiquement.</small></dd>';
		}
		elseif ($context == 'edit' && $field->system & $field::NUMBER) {
			$out .= '<dd class="help"><small>Doit être unique pour chaque membre.</small></dd>';
		}

		return $out;
	}

	protected function diff(array $params)
	{
		if (isset($params['old'], $params['new'])) {
			$diff = \KD2\SimpleDiff::diff_to_array(false, $params['old'], $params['new'], $params['context'] ?? 3);
		}
		else {
			throw new \BadFunctionCallException('Paramètres old et new requis.');
		}

		$out = '<table class="diff">';
		$prev = key($diff);

		foreach ($diff as $i=>$line)
		{
			if ($i > $prev + 1)
			{
				$out .= '<tr class="separator"><td colspan="5"><hr /></td></tr>';
			}

			list($type, $old, $new) = $line;

			$class1 = $class2 = '';
			$t1 = $t2 = '';

			if ($type == \KD2\SimpleDiff::INS)
			{
				$class2 = 'ins';
				$t2 = '<span data-icon="➕"></span>';
				$old = htmlspecialchars($old, ENT_QUOTES, 'UTF-8');
				$new = htmlspecialchars($new, ENT_QUOTES, 'UTF-8');
			}
			elseif ($type == \KD2\SimpleDiff::DEL)
			{
				$class1 = 'del';
				$t1 = '<span data-icon="➖"></span>';
				$old = htmlspecialchars($old, ENT_QUOTES, 'UTF-8');
				$new = htmlspecialchars($new, ENT_QUOTES, 'UTF-8');
			}
			elseif ($type == \KD2\SimpleDiff::CHANGED)
			{
				$class1 = 'del';
				$class2 = 'ins';
				$t1 = '<span data-icon="➖"></span>';
				$t2 = '<span data-icon="➕"></span>';

				$lineDiff = \KD2\SimpleDiff::wdiff($old, $new);
				$lineDiff = htmlspecialchars($lineDiff, ENT_QUOTES, 'UTF-8');

				// Don't show new things in deleted line
				$old = preg_replace('!\{\+(?:.*)\+\}!U', '', $lineDiff);
				$old = str_replace('  ', ' ', $old);
				$old = str_replace('-] [-', ' ', $old);
				$old = preg_replace('!\[-(.*)-\]!U', '<del>\\1</del>', $old);

				// Don't show old things in added line
				$new = preg_replace('!\[-(?:.*)-\]!U', '', $lineDiff);
				$new = str_replace('  ', ' ', $new);
				$new = str_replace('+} {+', ' ', $new);
				$new = preg_replace('!\{\+(.*)\+\}!U', '<ins>\\1</ins>', $new);
			}
			else
			{
				$old = htmlspecialchars($old, ENT_QUOTES, 'UTF-8');
				$new = htmlspecialchars($new, ENT_QUOTES, 'UTF-8');
			}

			$out .= '<tr>';
			$out .= '<td class="line">'.($i+1).'</td>';
			$out .= '<td class="leftChange">'.$t1.'</td>';
			$out .= '<td class="leftText '.$class1.'">'.$old.'</td>';
			$out .= '<td class="rightChange">'.$t2.'</td>';
			$out .= '<td class="rightText '.$class2.'">'.$new.'</td>';
			$out .= '</tr>';

			$prev = $i;
		}

		$out .= '</table>';
		return $out;
	}

	protected function displayPermissions(array $params): string
	{
		$out = [];

		if (isset($params['section'], $params['level'])) {
			$list = [$params['section'] => Category::PERMISSIONS[$params['section']]];
			$perms = (object) ['perm_' . $params['section'] => $params['level']];
		}
		else {
			$perms = $params['permissions'];
			$list = Category::PERMISSIONS;
		}

		foreach ($list as $name => $config) {
			$access = $perms->{'perm_' . $name};
			$label = sprintf('%s : %s', $config['label'], $config['options'][$access]);
			$out[$name] = sprintf('<b class="access_%s %s" title="%s">%s</b>', $access, $name, htmlspecialchars($label), $config['shape']);
		}

		return implode(' ', $out);
	}

	protected function copyButton(array $params): string
	{
		return sprintf('<samp class="copy" onclick="this.nextSibling.click();" title="Cliquer pour copier dans le presse-papier">%s</samp><input type="button" onclick="var a = this.previousSibling; a.focus(); navigator.clipboard.writeText(a.innerText); this.value = \'Copié !\'; this.focus(); return false;" onblur="this.value = \'Copier\';" value="Copier" title="Copier dans le presse-papier" />', htmlspecialchars($params['label']));
	}
}
