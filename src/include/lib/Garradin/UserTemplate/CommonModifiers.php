<?php

namespace Garradin\UserTemplate;

use Garradin\Config;
use Garradin\Utils;

use const Garradin\{ADMIN_URL};

/**
 * Common modifiers and functions used by Template (Smartyer) and UserTemplate
 */
class CommonModifiers
{
	const MODIFIERS_LIST = [
		'money',
		'money_raw',
		'money_currency',
		'relative_date',
		'date_short',
		'date_long',
		'date_hour',
		'date',
		'strftime',
		'size_in_bytes' => [Utils::class, 'format_bytes'],
		'typo',
	];

	const FUNCTIONS_LIST = [
		'pagination',
		'input',
		'button',
		'link',
		'icon',
		'linkbutton',
	];

	static public function money($number, bool $hide_empty = true, bool $force_sign = false): string
	{
		if ($hide_empty && !$number) {
			return '';
		}

		$sign = ($force_sign && $number > 0) ? '+' : '';

		return sprintf('<b class="money">%s</b>', $sign . Utils::money_format($number, ',', '&nbsp;', $hide_empty));
	}

	static public function money_raw($number, bool $hide_empty = true): string
	{
		return Utils::money_format($number, ',', '', $hide_empty);
	}

	static public function money_currency($number, bool $hide_empty = true): string
	{
		$out = self::money($number, $hide_empty);

		if ($out !== '') {
			$out .= '&nbsp;' . Config::getInstance()->get('monnaie');
		}

		return $out;
	}

	static public function date_long($ts, bool $with_hour = false): ?string
	{
		return Utils::strftime_fr($ts, '%A %e %B %Y' . ($with_hour ? ' à %Hh%M' : ''));
	}

	static public function date_short($ts, bool $with_hour = false): ?string
	{
		return Utils::date_fr($ts, 'd/m/Y' . ($with_hour ? ' à H\hi' : ''));
	}

	static public function date_hour($ts, bool $minutes_only_if_required = false): ?string
	{
		$ts = Utils::get_datetime($ts);

		if (null === $ts) {
			return null;
		}

		if ($minutes_only_if_required && $ts->format('i') == '00') {
			return $ts->format('H\h');
		}
		else {
			return $ts->format('H\hi');
		}
	}

	static public function strftime($ts, string $format, string $locale = 'fr'): ?string
	{
		if ($locale == 'fr') {
			return Utils::strftime_fr($ts, $format);
		}

		$ts = Utils::get_datetime($ts);

		if (!$ts) {
			return $ts;
		}

		return strftime($format, $ts->getTimestamp());
	}

	static public function date($ts, string $format = null, string $locale = 'fr'): ?string
	{
		if (preg_match('/^DATE_[\w\d]+$/', $format)) {
			$format = constant('DateTime::' . $format);
		}

		if (null === $format) {
			$format = 'd/m/Y à H:i';
		}

		if ($locale == 'fr') {
			return Utils::date_fr($ts, $format);
		}

		$ts = Utils::get_datetime($ts);
		return date($format, $ts);
	}

	static public function relative_date($ts, bool $with_hour = false): string
	{
		$day = null;

		if (null === $ts) {
			return '';
		}

		$date = Utils::get_datetime($ts);

		if ($date->format('Ymd') == date('Ymd'))
		{
			$day = 'aujourd\'hui';
		}
		elseif ($date->format('Ymd') == date('Ymd', strtotime('yesterday')))
		{
			$day = 'hier';
		}
		elseif ($date->format('Ymd') == date('Ymd', strtotime('tomorrow')))
		{
			$day = 'demain';
		}
		elseif ($date->format('Y') == date('Y'))
		{
			$day = strtolower(Utils::strftime_fr($date, '%A %e %B'));
		}
		else
		{
			$day = strtolower(Utils::strftime_fr($date, '%e %B %Y'));
		}

		if ($with_hour)
		{
			$hour = $date->format('H\hi');
			return sprintf('%s, %s', $day, $hour);
		}

		return $day;
	}

	static public function typo($str, $locale = 'fr')
	{
		$str = preg_replace('/[\h]*([?!:»])(\s+|$)/u', '&nbsp;\\1\\2', $str);
		$str = preg_replace('/(^|\s+)([«])[\h]*/u', '\\1\\2&nbsp;', $str);
		return $str;
	}

	static public function pagination(array $params): string
	{
		if (!isset($params['url'], $params['page'], $params['bypage'], $params['total'])) {
			throw new \BadFunctionCallException("Paramètre manquant pour pagination");
		}

		if ($params['total'] == -1)
			return '';

		$pagination = self::getGenericPagination($params['page'], $params['total'], $params['bypage']);

		if (empty($pagination))
			return '';

		$out = '<ul class="pagination">';
		$encoded_url = rawurlencode('[ID]');

		foreach ($pagination as &$page)
		{
			$attributes = '';

			if (!empty($page['class']))
				$attributes .= ' class="' . htmlspecialchars($page['class']) . '" ';

			$out .= '<li'.$attributes.'>';

			$attributes = '';

			if (!empty($page['accesskey']))
				$attributes .= ' accesskey="' . htmlspecialchars($page['accesskey']) . '" ';

			$out .= '<a' . $attributes . ' href="' . str_replace(['[ID]', $encoded_url], htmlspecialchars($page['id']), $params['url']) . '">';
			$out .= htmlspecialchars($page['label']);
			$out .= '</a>';
			$out .= '</li>' . "\n";
		}

		$out .= '</ul>';

		return $out;
	}

	/**
	 * Génération pagination à partir de la page courante ($current),
	 * du nombre d'items total ($total), et du nombre d'items par page ($bypage).
	 * $listLength représente la longueur d'items de la pagination à génerer
	 *
	 * @param int $current
	 * @param int $total
	 * @param int $bypage
	 * @param int $listLength
	 * @param bool $showLast Toggle l'affichage du dernier élément de la pagination
	 * @return array|null
	 */
	static public function getGenericPagination($current, $total, $bypage, $listLength = 11, $showLast = true)
	{
		if ($total <= $bypage)
			return null;

		$total = ceil($total / $bypage);

		if ($total < $current)
			return null;

		$length = ($listLength / 2);

		$begin = $current - ceil($length);
		if ($begin < 1)
		{
			$begin = 1;
		}

		$end = $begin + $listLength;
		if($end > $total)
		{
			$begin -= ($end - $total);
			$end = $total;
		}
		if ($begin < 1)
		{
			$begin = 1;
		}
		if($end==($total-1)) {
			$end = $total;
		}
		if($begin == 2) {
			$begin = 1;
		}
		$out = [];

		if ($current > 1) {
			$out[] = ['id' => $current - 1, 'label' =>  '« ' . 'Page précédente', 'class' => 'prev', 'accesskey' => 'a'];
		}

		if ($begin > 1) {
			$out[] = ['id' => 1, 'label' => '1 ...', 'class' => 'first'];
		}

		for ($i = $begin; $i <= $end; $i++)
		{
			$out[] = ['id' => $i, 'label' => $i, 'class' => ($i == $current) ? 'current' : ''];
		}

		if ($showLast && $end < $total) {
			$out[] = ['id' => $total, 'label' => '... ' . $total, 'class' => 'last'];
		}

		if ($current < $total) {
			$out[] = ['id' => $current + 1, 'label' => 'Page suivante' . ' »', 'class' => 'next', 'accesskey' => 'z'];
		}

		return $out;
	}


	static public function input(array $params)
	{
		static $params_list = ['value', 'default', 'type', 'help', 'label', 'name', 'options', 'source', 'no_size_limit'];

		// Extract params and keep attributes separated
		$attributes = array_diff_key($params, array_flip($params_list));
		$params = array_intersect_key($params, array_flip($params_list));
		extract($params, \EXTR_SKIP);

		if (!isset($name, $type)) {
			throw new \InvalidArgumentException('Missing name or type');
		}

		$suffix = null;

		if ($type == 'datetime') {
			$type = 'date';
			$tparams = func_get_arg(0);
			$tparams['type'] = 'time';
			$tparams['name'] = sprintf('%s_time', $name);
			unset($tparams['label']);
			$suffix = self::input($tparams);
		}

		$current_value = null;
		$current_value_from_user = false;

		if (isset($_POST[$name])) {
			$current_value = $_POST[$name];
			$current_value_from_user = true;
		}
		elseif (isset($source) && is_object($source) && isset($source->$name) && !is_null($source->$name)) {
			$current_value = $source->$name;
		}
		elseif (isset($source) && is_array($source) && isset($source[$name])) {
			$current_value = $source[$name];
		}
		elseif (isset($default) && ($type != 'checkbox' || empty($_POST))) {
			$current_value = $default;
		}

		if ($type == 'date' && is_object($current_value) && $current_value instanceof \DateTimeInterface) {
			$current_value = $current_value->format('d/m/Y');
		}
		elseif ($type == 'time' && is_object($current_value) && $current_value instanceof \DateTimeInterface) {
			$current_value = $current_value->format('H:i');
		}
		elseif ($type == 'date' && is_string($current_value)) {
			if ($v = \DateTime::createFromFormat('!Y-m-d', $current_value)) {
				$current_value = $v->format('d/m/Y');
			}
		}


		$attributes['id'] = 'f_' . str_replace(['[', ']'], '', $name);
		$attributes['name'] = $name;

		if (!isset($attributes['autocomplete']) && ($type == 'money' || $type == 'password')) {
			$attributes['autocomplete'] = 'off';
		}

		if ($type == 'radio' || $type == 'checkbox') {
			$attributes['id'] .= '_' . $value;

			if ($current_value == $value && $current_value !== null) {
				$attributes['checked'] = 'checked';
			}

			$attributes['value'] = $value;
		}
		elseif ($type == 'date') {
			$type = 'text';
			$attributes['placeholder'] = 'JJ/MM/AAAA';
			$attributes['data-input'] = 'date';
			$attributes['size'] = 12;
			$attributes['maxlength'] = 10;
			$attributes['pattern'] = '\d\d?/\d\d?/\d{4}';
		}
		elseif ($type == 'time') {
			$type = 'text';
			$attributes['placeholder'] = 'HH:MM';
			$attributes['data-input'] = 'time';
			$attributes['size'] = 8;
			$attributes['maxlength'] = 5;
			$attributes['pattern'] = '\d\d?:\d\d?';
		}

		// Create attributes string
		if (!empty($attributes['required'])) {
			$attributes['required'] = 'required';
		}
		else {
			unset($attributes['required']);
		}

		if (!empty($attributes['disabled'])) {
			$attributes['disabled'] = 'disabled';
			unset($attributes['required']);
		}
		else {
			unset($attributes['disabled']);
		}

		if (array_key_exists('required', $attributes) || array_key_exists('fake_required', $attributes)) {
			$required_label =  ' <b title="Champ obligatoire">(obligatoire)</b>';
		}
		else {
			$required_label =  ' <i>(facultatif)</i>';
		}

		// Fake required: doesn't set the required attribute, just the label
		// (useful for form elements that are hidden by JS)
		unset($attributes['fake_required']);

		$attributes_string = $attributes;

		array_walk($attributes_string, function (&$v, $k) {
			$v = sprintf('%s="%s"', $k, $v);
		});

		$attributes_string = implode(' ', $attributes_string);

		if ($type == 'select') {
			$input = sprintf('<select %s>', $attributes_string);

			foreach ($options as $_key => $_value) {
				$input .= sprintf('<option value="%s"%s>%s</option>', $_key, $current_value == $_key ? ' selected="selected"' : '', htmlspecialchars($_value));
			}

			$input .= '</select>';
		}
		elseif ($type == 'select_groups') {
			$input = sprintf('<select %s>', $attributes_string);

			foreach ($options as $optgroup => $suboptions) {
				$input .= sprintf('<optgroup label="%s">', htmlspecialchars($optgroup));

				foreach ($suboptions as $_key => $_value) {
					$input .= sprintf('<option value="%s"%s>%s</option>', $_key, $current_value == $_key ? ' selected="selected"' : '', htmlspecialchars($_value));
				}

				$input .= '</optgroup>';
			}

			$input .= '</select>';
		}
		elseif ($type == 'textarea') {
			$input = sprintf('<textarea %s>%s</textarea>', $attributes_string, htmlspecialchars($current_value));
		}
		elseif ($type == 'list') {
			$multiple = !empty($attributes['multiple']);
			$values = '';
			$delete_btn = self::button(['shape' => 'delete']);

			if (null !== $current_value && is_iterable($current_value)) {
				foreach ($current_value as $v => $l) {
					$values .= sprintf('<span class="label"><input type="hidden" name="%s[%s]" value="%s" /> %3$s %s</span>', htmlspecialchars($name), htmlspecialchars($v), htmlspecialchars($l), $multiple ? $delete_btn : '');
				}
			}

			$button = self::button([
				'shape' => $multiple ? 'plus' : 'menu',
				'value' => (substr($attributes['target'], 0, 4) === 'http') ? $attributes['target'] : ADMIN_URL . $attributes['target'],
				'label' => $multiple ? 'Ajouter' : 'Sélectionner',
				'data-multiple' => $multiple ? '1' : '0',
				'data-name' => $name,
			]);

			$input = sprintf('<span id="%s_container" class="input-list">%s%s</span>', htmlspecialchars($attributes['id']), $button, $values);
		}
		elseif ($type == 'money') {
			if (null !== $current_value && !$current_value_from_user) {
				$current_value = Utils::money_format($current_value, ',', '');
			}

			$currency = Config::getInstance()->get('monnaie');
			$input = sprintf('<nobr><input type="text" pattern="[0-9]*([.,][0-9]{1,2})?" inputmode="decimal" size="8" class="money" %s value="%s" /><b>%s</b></nobr>', $attributes_string, htmlspecialchars($current_value), $currency);
		}
		else {
			$value = isset($attributes['value']) ? '' : sprintf(' value="%s"', htmlspecialchars($current_value));
			$input = sprintf('<input type="%s" %s %s />', $type, $attributes_string, $value);
		}

		// No label? then we only want the input without the widget
		if (empty($label)) {
			if (!array_key_exists('label', $params) && ($type == 'radio' || $type == 'checkbox')) {
				$input .= sprintf('<label for="%s"></label>', $attributes['id']);
			}

			return $input;
		}

		if ($type == 'file') {
			$input .= sprintf('<input type="hidden" name="MAX_FILE_SIZE" value="%d" id="f_maxsize" />', Utils::return_bytes(Utils::getMaxUploadSize()));
		}

		$input .= $suffix;

		$label = sprintf('<label for="%s">%s</label>', $attributes['id'], htmlspecialchars($label));

		if ($type == 'radio' || $type == 'checkbox') {
			$out = sprintf('<dd>%s %s', $input, $label);

			if (isset($help)) {
				$out .= sprintf(' <em class="help">(%s)</em>', htmlspecialchars($help));
			}

			$out .= '</dd>';
		}
		else {
			$out = sprintf('<dt>%s%s</dt><dd>%s</dd>', $label, $required_label, $input);

			if ($type == 'file' && empty($params['no_size_limit'])) {
				$out .= sprintf('<dd class="help"><small>Taille maximale : %s</small></dd>', Utils::format_bytes(Utils::getMaxUploadSize()));
			}

			if (isset($help)) {
				$out .= sprintf('<dd class="help">%s</dd>', htmlspecialchars($help));
			}
		}

		return $out;
	}


	static public function icon(array $params): string
	{
		$attributes = array_diff_key($params, ['shape']);
		$attributes = array_map(fn($v, $k) => sprintf('%s="%s"', $k, htmlspecialchars($v)),
			$attributes, array_keys($attributes));

		$attributes = implode(' ', $attributes);

		return sprintf('<b class="icn" %s>%s</b>', $attributes, Utils::iconUnicode($params['shape']));
	}

	static public function link(array $params): string
	{
		$href = $params['href'];
		$label = $params['label'];

		// href can be prefixed with '!' to make the URL relative to ADMIN_URL
		if (substr($href, 0, 1) == '!') {
			$href = ADMIN_URL . substr($params['href'], 1);
		}

		// propagate _dialog param if we are in an iframe
		if (isset($_GET['_dialog']) && !isset($params['target'])) {
			$href .= (strpos($href, '?') === false ? '?' : '&') . '_dialog';
		}

		if (!isset($params['class'])) {
			$params['class'] = '';
		}

		unset($params['href'], $params['label']);

		array_walk($params, function (&$v, $k) {
			$v = sprintf('%s="%s"', $k, htmlspecialchars($v));
		});

		$params = implode(' ', $params);

		return sprintf('<a href="%s" %s>%s</a>', htmlspecialchars($href), $params, htmlspecialchars($label));
	}

	static public function button(array $params): string
	{
		$icon = Utils::iconUnicode($params['shape']);
		$label = isset($params['label']) ? htmlspecialchars($params['label']) : '';
		unset($params['label'], $params['shape']);

		if (!isset($params['type'])) {
			$params['type'] = 'button';
		}

		if (!isset($params['class'])) {
			$params['class'] = '';
		}

		if (isset($params['name']) && !isset($params['value'])) {
			$params['value'] = 1;
		}

		$params['class'] .= ' icn-btn';

		array_walk($params, function (&$v, $k) {
			$v = sprintf('%s="%s"', $k, htmlspecialchars($v));
		});

		$params = implode(' ', $params);

		return sprintf('<button %s data-icon="%s">%s</button>', $params, $icon, $label);
	}

	static public function linkbutton(array $params): string
	{
		$params['data-icon'] = Utils::iconUnicode($params['shape']);
		unset($params['shape']);

		if (!isset($params['class'])) {
			$params['class'] = '';
		}

		$params['class'] .= ' icn-btn';

		return self::link($params);
	}

}
