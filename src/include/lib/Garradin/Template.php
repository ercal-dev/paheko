<?php

namespace Garradin;

use KD2\Form;
use KD2\HTTP;
use KD2\Translate;
use Garradin\Membres\Session;
use Garradin\Entities\Accounting\Account;
use Garradin\Entities\Users\Category;
use Garradin\UserTemplate\CommonModifiers;
use Garradin\Web\Render\Skriv;
use Garradin\Files\Files;

class Template extends \KD2\Smartyer
{
	static protected $_instance = null;

	static public function getInstance()
	{
		return self::$_instance ?: self::$_instance = new Template;
	}

	public function display($template = null)
	{
		if (isset($_GET['_pdf'])) {
			return $this->PDF($template);
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

	public function __construct()
	{
		parent::__construct();

		$cache_dir = SMARTYER_CACHE_ROOT;

		if (!file_exists($cache_dir)) {
			Utils::safe_mkdir($cache_dir, 0777, true);
		}

		$this->setTemplatesDir(ROOT . '/templates');
		$this->setCompiledDir($cache_dir);
		$this->setNamespace('Garradin');

		// Hash de la version pour les éléments statiques (cache)
		// On ne peut pas utiliser la version directement comme query string
		// pour les éléments statiques (genre /admin/static/admin.css?v0.9.0)
		// car cela dévoilerait la version de Garradin utilisée, posant un souci
		// en cas de faille, on cache donc la version utilisée, chaque instance
		// aura sa propre version
		$this->assign('version_hash', substr(sha1(garradin_version() . garradin_manifest() . ROOT . SECRET_KEY), 0, 10));

		$this->assign('www_url', WWW_URL);
		$this->assign('admin_url', ADMIN_URL);
		$this->assign('help_url', HELP_URL);
		$this->assign('self_url', Utils::getSelfURI());
		$this->assign('self_url_no_qs', Utils::getSelfURI(false));

		$session = Session::getInstance();
		$this->assign('config', Config::getInstance());
		$this->assign('session', $session);

		$this->assign('is_logged', $session->isLogged());
		$this->assign('dialog', isset($_GET['_dialog']));

		$this->assign('password_pattern', sprintf('.{%d,}', Session::MINIMUM_PASSWORD_LENGTH));
		$this->assign('password_length', Session::MINIMUM_PASSWORD_LENGTH);

		$this->register_compile_function('continue', function ($pos, $block, $name, $raw_args) {
			if ($block == 'continue')
			{
				return 'continue;';
			}
		});

		$this->register_compile_function('use', function ($pos, $block, $name, $raw_args) {
			if ($name == 'use')
			{
				return sprintf('use %s;', $raw_args);
			}
		});

		$this->register_function('form_errors', [$this, 'formErrors']);
		$this->register_function('show_error', [$this, 'showError']);
		$this->register_function('form_field', [$this, 'formField']);
		$this->register_function('html_champ_membre', [$this, 'formChampMembre']);
		$this->register_function('password_change', [$this, 'passwordChangeInput']);

		$this->register_function('custom_colors', [$this, 'customColors']);
		$this->register_function('plugin_url', ['Garradin\Utils', 'plugin_url']);
		$this->register_function('diff', [$this, 'diff']);
		$this->register_function('display_permissions', [$this, 'displayPermissions']);

		$this->register_function('csrf_field', function ($params) {
			return Form::tokenHTML($params['key']);
		});

		$this->register_modifier('strlen', 'strlen');
		$this->register_modifier('dump', ['KD2\ErrorManager', 'dump']);
		$this->register_modifier('get_country_name', ['Garradin\Utils', 'getCountryName']);
		$this->register_modifier('format_tel', [$this, 'formatPhoneNumber']);
		$this->register_modifier('abs', function($a) { return abs($a ?? 0); });
		$this->register_modifier('display_champ_membre', [$this, 'displayChampMembre']);

		$this->register_modifier('linkify_transactions', function ($str) {
			return preg_replace_callback('/(?<=^|\s)#(\d+)(?=\s|$)/', function ($m) {
				return sprintf('<a href="%s%d">#%2$d</a>',
					Utils::getLocalURL('!acc/transactions/details.php?id='),
					$m[1]
				);
			}, $str);
		});

		$this->register_modifier('format_skriv', function ($str) {
			$skriv = new Skriv;
			return $skriv->render((string) $str);
		});

		foreach (CommonModifiers::MODIFIERS_LIST as $key => $name) {
			$this->register_modifier(is_int($key) ? $name : $key, is_int($key) ? [CommonModifiers::class, $name] : $name);
		}

		foreach (CommonModifiers::FUNCTIONS_LIST as $key => $name) {
			$this->register_function(is_int($key) ? $name : $key, is_int($key) ? [CommonModifiers::class, $name] : $name);
		}

		$this->register_modifier('local_url', [Utils::class, 'getLocalURL']);
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
		}

		return '<div class="block error"><ul><li>' . implode('</li><li>', $errors) . '</li></ul></div>';
	}

	protected function showError($params)
	{
		if (!$params['if'])
		{
			return '';
		}

		return '<p class="block error">' . $this->escape($params['message']) . '</p>';
	}

	protected function passwordChangeInput(array $params)
	{
		$out = $this->formInput(array_merge($params, [
			'type' => 'password',
			'help' => sprintf('(Minimum %d caractères)', Session::MINIMUM_PASSWORD_LENGTH),
			'minlength' => Session::MINIMUM_PASSWORD_LENGTH,
		]));

		$out.= '<dd class="help">Astuce : un mot de passe de quatre mots choisis au hasard dans le dictionnaire est plus sûr et plus simple à retenir qu\'un mot de passe composé de 10 lettres et chiffres.</dd>';

		$suggestion = Utils::suggestPassword();

		$out .= sprintf('<dd class="help">Pas d\'idée&nbsp;? Voici une suggestion choisie au hasard&nbsp;:
                <input type="text" readonly="readonly" title="Cliquer pour utiliser cette suggestion comme mot de passe" id="f_%s_suggest" value="%s" autocomplete="off" size="%d" /></dd>', $params['name'], $suggestion, strlen($suggestion));

		$out .= $this->formInput([
			'type' => 'password',
			'label' => 'Répéter le mot de passe',
			'required' => true,
			'name' => $params['name'] . '_confirm',
			'minlength' => Session::MINIMUM_PASSWORD_LENGTH,
		]);

		return $out;
	}

	/**
	 * @deprecated
	 */
	protected function formField(array $params, $escape = true)
	{
		if (!isset($params['name']))
		{
			throw new \BadFunctionCallException('name argument is mandatory');
		}

		$name = $params['name'];

		if (isset($_POST[$name]))
			$value = $_POST[$name];
		elseif (isset($params['data']) && is_array($params['data']) && array_key_exists($name, $params['data']))
		{
			$value = $params['data'][$name];
		}
		elseif (isset($params['data']) && is_object($params['data']) && property_exists($params['data'], $name))
		{
			$value = $params['data']->$name;
		}
		elseif (isset($params['default']))
			$value = $params['default'];
		else
			$value = '';

		if (is_array($value))
		{
			return $value;
		}

		if (isset($params['checked']))
		{
			if ($value == $params['checked'])
				return ' checked="checked" ';

			return '';
		}
		elseif (isset($params['selected']))
		{
			if ($value == $params['selected'])
				return ' selected="selected" ';

			return '';
		}

		return $escape ? htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') : $value;
	}

	protected function formatPhoneNumber($n)
	{
		if (empty($n)) {
			return '';
		}

		$country = Config::getInstance()->get('pays');

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
		$config = Config::getInstance();

		$couleur1 = $config->get('couleur1') ?: ADMIN_COLOR1;
		$couleur2 = $config->get('couleur2') ?: ADMIN_COLOR2;
		$admin_background = ADMIN_BACKGROUND_IMAGE;

		if ($url = $config->fileURL('admin_background')) {
			$admin_background = $url;
		}

		// Transformation Hexa vers décimal
		$couleur1 = implode(', ', sscanf($couleur1, '#%02x%02x%02x'));
		$couleur2 = implode(', ', sscanf($couleur2, '#%02x%02x%02x'));

		$out = '
		<style type="text/css">
		:root {
			--gMainColor: %s;
			--gSecondColor: %s;
			--gBgImage: url("%s");
		}
		</style>';

		if ($url = $config->fileURL('admin_css')) {
			$out .= "\n" . sprintf('<link rel="stylesheet" type="text/css" href="%s" />', $url);
		}

		return sprintf($out, $couleur1, $couleur2, $admin_background);
	}

	protected function displayChampMembre($v, $config = null)
	{
		if (is_string($config)) {
			$config = Config::getInstance()->get('champs_membres')->get($config);
		}

		if (null === $config) {
			return htmlspecialchars((string)$v);
		}

		if ($config->type == 'checkbox') {
			return $v ? 'Oui' : 'Non';
		}

		if (empty($v)) {
			return '';
		}

		switch ($config->type)
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

				foreach ($config->options as $b => $name)
				{
					if ($v & (0x01 << $b))
						$out[] = $name;
				}

				return htmlspecialchars(implode(', ', $out));
			default:
				return nl2br(htmlspecialchars(rtrim((string) $v)));
		}
	}

	protected function formChampMembre($params)
	{
		if (empty($params['config']) || empty($params['name']))
			throw new \BadFunctionCallException('Paramètres type et name obligatoires.');

		$config = $params['config'];
		$type = $config->type;

		if ($params['name'] == 'passe' || (!empty($params['user_mode']) && !empty($config->private)))
		{
			return '';
		}

		// Files are managed out of the form
		if ($config->type == 'file') {
			return '';
		}

		$options = [];

		if ($type == 'select' || $type == 'multiple')
		{
			if (empty($config->options))
			{
				throw new \BadFunctionCallException('Paramètre options obligatoire pour champ de type ' . $type);
			}

			$options = (array) $config->options;
		}
		elseif ($type == 'country')
		{
			$type = 'select';
			$options = Utils::getCountryList();
			$params['default'] = Config::getInstance()->get('pays');
		}
		elseif ($type == 'date')
		{
			$params['pattern'] = '\d{4}-\d{2}-\d{2}';
		}

		$field = '';
		$value = $this->formField($params, false);
		$attributes = 'name="' . htmlspecialchars($params['name'], ENT_QUOTES, 'UTF-8') . '" ';
		$attributes .= 'id="f_' . htmlspecialchars($params['name'], ENT_QUOTES, 'UTF-8') . '" ';

		if ($params['name'] == 'numero' && $config->type == 'number' && !$value)
		{
			$value = DB::getInstance()->firstColumn('SELECT MAX(numero) + 1 FROM membres;');
		}

		if (!empty($params['disabled']))
		{
			$attributes .= 'disabled="disabled" ';
		}

		if (!empty($config->mandatory) && $type != 'checkbox' && $type != 'multiple')
		{
			$attributes .= 'required="required" ';
		}

		// Fix for autocomplete, lpignore is for Lastpass
		$attributes .= 'autocomplete="off" data-lpignore="true" ';

		if (!empty($params['user_mode']) && empty($config->editable))
		{
			$out = '<dt>' . htmlspecialchars($config->title, ENT_QUOTES, 'UTF-8') . '</dt>';
			$out .= '<dd>' . (trim((string) $value) === '' ? 'Non renseigné' : $this->displayChampMembre($value, $config)) . '</dd>';
			return $out;
		}

		if ($type == 'select')
		{
			$field .= '<select '.$attributes.'>';
			foreach ($options as $k=>$v)
			{
				if (is_int($k))
					$k = $v;

				$field .= '<option value="' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '"';

				if ($value == $k || empty($value) && !empty($params['default']))
					$field .= ' selected="selected"';

				$field .= '>' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '</option>';
			}
			$field .= '</select>';
		}
		elseif ($type == 'multiple')
		{
			if (is_array($value))
			{
				$binary = 0;

				foreach ($value as $k => $v)
				{
					if (array_key_exists($k, $options) && !empty($v))
					{
						$binary |= 0x01 << $k;
					}
				}

				$value = $binary;
			}

			// Forcer la valeur à être un entier (depuis PHP 7.1)
			$value = (int)$value;

			foreach ($options as $k=>$v)
			{
				$b = 0x01 << (int)$k;
				$field .= sprintf('<input type="checkbox" name="%s[%d]" id="f_%1$s_%2$d" value="1" %s %s /> <label for="f_%1$s_%2$d">%s</label><br />',
					htmlspecialchars($params['name']), $k, ($value & $b) ? 'checked="checked"' : '', $attributes, htmlspecialchars($v));
			}
		}
		elseif ($type == 'textarea')
		{
			$field .= '<textarea ' . $attributes . 'cols="30" rows="5">' . htmlspecialchars((string) $value, ENT_QUOTES) . '</textarea>';
		}
		elseif ($type == 'date') {
			$field = self::formInput(['required' => $config->mandatory, 'name' => $params['name'], 'value' => $value, 'type' => 'date', 'default' => $value]);
		}
		else
		{
			if ($type == 'checkbox')
			{
				if (!empty($value))
				{
					$attributes .= 'checked="checked" ';
				}

				$value = '1';
			}
			elseif ($type == 'number') {
				$attributes .= 'step="any" ';
			}

			$field .= '<input type="' . $type . '" ' . $attributes . ' value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '" />';
		}

		$out = '
		<dt>';

		if ($type == 'checkbox')
		{
			$out .= $field . ' ';
		}

		$out .= '<label for="f_' . htmlspecialchars($params['name'], ENT_QUOTES, 'UTF-8') . '">'
			. htmlspecialchars($config->title, ENT_QUOTES, 'UTF-8') . '</label>';

		if (!empty($config->mandatory))
		{
			$out .= ' <b title="(Champ obligatoire)">obligatoire</b>';
		}

		$out .= '</dt>';

		if (!empty($config->help))
		{
			$out .= '
		<dd class="help">' . htmlspecialchars($config->help, ENT_QUOTES, 'UTF-8') . '</dd>';
		}

		$id_field = Config::getInstance()->get('champ_identifiant');

		if ($params['name'] == $id_field && empty($params['user_mode'])) {
			$out .= '<dd class="help"><small>(Sera utilisé comme identifiant de connexion si le membre a le droit de se connecter.)</small></dd>';
		}

		if ($type != 'checkbox')
		{
			$out .= '
		<dd>' . $field . '</dd>';
		}

		return $out;
	}

	protected function diff(array $params)
	{
		if (!isset($params['old']) || !isset($params['new']))
		{
			throw new \BadFunctionCallException('Paramètres old et new requis.');
		}

		$old = $params['old'];
		$new = $params['new'];

		$diff = \KD2\SimpleDiff::diff_to_array(false, $old, $new, 3);

		$out = '<table class="diff">';
		$prev = key($diff);

		foreach ($diff as $i=>$line)
		{
			if ($i > $prev + 1)
			{
				$out .= '<tr><td colspan="5" class="separator"><hr /></td></tr>';
			}

			list($type, $old, $new) = $line;

			$class1 = $class2 = '';
			$t1 = $t2 = '';

			if ($type == \KD2\SimpleDiff::INS)
			{
				$class2 = 'ins';
				$t2 = '<b class="icn">➕</b>';
				$old = htmlspecialchars($old, ENT_QUOTES, 'UTF-8');
				$new = htmlspecialchars($new, ENT_QUOTES, 'UTF-8');
			}
			elseif ($type == \KD2\SimpleDiff::DEL)
			{
				$class1 = 'del';
				$t1 = '<b class="icn">➖</b>';
				$old = htmlspecialchars($old, ENT_QUOTES, 'UTF-8');
				$new = htmlspecialchars($new, ENT_QUOTES, 'UTF-8');
			}
			elseif ($type == \KD2\SimpleDiff::CHANGED)
			{
				$class1 = 'del';
				$class2 = 'ins';
				$t1 = '<b class="icn">➖</b>';
				$t2 = '<b class="icn">➕</b>';

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
		$perms = $params['permissions'];

		$out = [];

		foreach (Category::PERMISSIONS as $name => $config) {
			$access = $perms->{'perm_' . $name};
			$label = $config['options'][$access];
			$out[$name] = sprintf('<b class="access_%s %s" title="%s">%s</b>', $access, $name, htmlspecialchars($label), $config['shape']);
		}

		return implode(' ', $out);
	}
}
