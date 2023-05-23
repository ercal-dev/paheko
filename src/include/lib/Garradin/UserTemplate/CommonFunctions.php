<?php

namespace Garradin\UserTemplate;

use Garradin\Config;
use Garradin\Utils;

use KD2\Form;

use const Garradin\{ADMIN_URL, CALC_CONVERT_COMMAND};

/**
 * Common functions used by Template (Smartyer) and UserTemplate
 */
class CommonFunctions
{
	const FUNCTIONS_LIST = [
		'input',
		'button',
		'link',
		'icon',
		'linkbutton',
	];

	static public function input(array $params)
	{
		static $params_list = ['value', 'default', 'type', 'help', 'label', 'name', 'options', 'source', 'no_size_limit', 'copy'];

		// Extract params and keep attributes separated
		$attributes = array_diff_key($params, array_flip($params_list));
		$params = array_intersect_key($params, array_flip($params_list));
		extract($params, \EXTR_SKIP);

		if (!isset($name, $type)) {
			throw new \RuntimeException('Missing name or type');
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

		if ($type == 'file' && isset($attributes['accept']) && $attributes['accept'] == 'csv') {
			if (CALC_CONVERT_COMMAND) {
				$help = ($help ?? '') . PHP_EOL . 'Formats acceptés : CSV, LibreOffice Calc (ODS), ou Excel (XLSX)';
				$attributes['accept'] = '.ods,application/vnd.oasis.opendocument.spreadsheet,.xls,application/vnd.ms-excel,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,.csv,text/csv,application/csv';
			}
			else {
				$help = ($help ?? '') . PHP_EOL . 'Format accepté : CSV';
				$attributes['accept'] = '.csv,text/csv,application/csv';
			}
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
		elseif ($type == 'password') {
			$current_value = null;
		}
		// FIXME: is this still needed?
		elseif ($type == 'date' && is_string($current_value)) {
			if ($v = \DateTime::createFromFormat('!Y-m-d', $current_value)) {
				$current_value = $v->format('d/m/Y');
			}
			elseif ($v = \DateTime::createFromFormat('!Y-m-d H:i:s', $current_value)) {
				$current_value = $v->format('d/m/Y');
			}
			elseif ($v = \DateTime::createFromFormat('!Y-m-d H:i', $current_value)) {
				$current_value = $v->format('d/m/Y');
			}
		}
		elseif ($type == 'time' && is_string($current_value)) {
			if ($v = \DateTime::createFromFormat('!Y-m-d H:i:s', $current_value)) {
				$current_value = $v->format('H:i');
			}
			elseif ($v = \DateTime::createFromFormat('!Y-m-d H:i', $current_value)) {
				$current_value = $v->format('H:i');
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
		elseif ($type == 'money') {
			$attributes['class'] = rtrim('money ' . ($attributes['class'] ?? ''));
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

		if (!empty($attributes['readonly'])) {
			$attributes['readonly'] = 'readonly';
		}
		else {
			unset($attributes['readonly']);
		}

		if (array_key_exists('required', $attributes)) {
			$required_label =  ' <b title="Champ obligatoire">(obligatoire)</b>';
		}
		else {
			$required_label =  ' <i>(facultatif)</i>';
		}

		$attributes_string = $attributes;

		array_walk($attributes_string, function (&$v, $k) {
			$v = sprintf('%s="%s"', $k, $v);
		});

		$attributes_string = implode(' ', $attributes_string);

		if ($type == 'radio-btn') {
			$radio = self::input(array_merge($params, ['type' => 'radio', 'label' => null, 'help' => null]));
			$out = sprintf('<dd class="radio-btn">%s
				<label for="f_%s_%s"><div><h3>%s</h3>%s</div></label>
			</dd>', $radio, htmlspecialchars((string)$name), htmlspecialchars((string)$value), htmlspecialchars((string)$label), isset($params['help']) ? '<p class="help">' . htmlspecialchars($params['help']) . '</p>' : '');
			return $out;
		}
		if ($type == 'select') {
			$input = sprintf('<select %s>', $attributes_string);

			if (empty($attributes['required']) || isset($attributes['default_empty'])) {
				$input .= sprintf('<option value="">%s</option>', $attributes['default_empty'] ?? '');
			}

			foreach ($options as $_key => $_value) {
				$input .= sprintf('<option value="%s"%s>%s</option>', $_key, $current_value == $_key ? ' selected="selected"' : '', htmlspecialchars((string)$_value));
			}

			$input .= '</select>';
		}
		elseif ($type == 'select_groups') {
			$input = sprintf('<select %s>', $attributes_string);

			if (empty($attributes['required'])) {
				$input .= '<option value=""></option>';
			}

			foreach ($options as $optgroup => $suboptions) {
				if (is_array($suboptions)) {
					$input .= sprintf('<optgroup label="%s">', htmlspecialchars((string)$optgroup));

					foreach ($suboptions as $_key => $_value) {
						$input .= sprintf('<option value="%s"%s>%s</option>', $_key, $current_value == $_key ? ' selected="selected"' : '', htmlspecialchars((string)$_value));
					}

					$input .= '</optgroup>';
				}
				else {
					$input .= sprintf('<option value="%s"%s>%s</option>', $optgroup, $current_value == $optgroup ? ' selected="selected"' : '', htmlspecialchars((string)$suboptions));
				}
			}

			$input .= '</select>';
		}
		elseif ($type == 'textarea') {
			$input = sprintf('<textarea %s>%s</textarea>', $attributes_string, htmlspecialchars((string)$current_value));
		}
		elseif ($type == 'list') {
			$multiple = !empty($attributes['multiple']);
			$can_delete = $multiple || !empty($attributes['can_delete']);
			$values = '';
			$delete_btn = self::button(['shape' => 'delete']);

			if (null !== $current_value && (is_array($current_value) || is_object($current_value))) {
				foreach ($current_value as $v => $l) {
					if (trim($l) === '') {
						continue;
					}

					$values .= sprintf('<span class="label"><input type="hidden" name="%s[%s]" value="%s" /> %3$s %s</span>', htmlspecialchars((string)$name), htmlspecialchars((string)$v), htmlspecialchars((string)$l), $can_delete ? $delete_btn : '');
				}
			}

			$button = self::button([
				'shape' => $multiple ? 'plus' : 'menu',
				'label' => $multiple ? 'Ajouter' : 'Sélectionner',
				'required' => $attributes['required'] ?? null,
				'value' => Utils::getLocalURL($attributes['target']),
				'data-multiple' => $multiple ? '1' : '0',
				'data-can-delete' => (int) $can_delete,
				'data-name' => $name,
			]);

			$input = sprintf('<span id="%s_container" class="input-list">%s%s</span>', htmlspecialchars($attributes['id']), $button, $values);
		}
		elseif ($type == 'money') {
			if (null !== $current_value && !$current_value_from_user) {
				$current_value = Utils::money_format($current_value, ',', '');
			}

			if ((string) $current_value === '0') {
				$current_value = '';
			}

			$currency = Config::getInstance()->currency;
			$input = sprintf('<nobr><input type="text" pattern="-?[0-9]+([.,][0-9]{1,2})?" inputmode="decimal" size="8" %s value="%s" /><b>%s</b></nobr>', $attributes_string, htmlspecialchars((string) $current_value), $currency);
		}
		else {
			$value = isset($attributes['value']) ? '' : sprintf(' value="%s"', htmlspecialchars((string)$current_value));
			$input = sprintf('<input type="%s" %s %s />', $type, $attributes_string, $value);
		}

		if ($type == 'file') {
			$input .= sprintf('<input type="hidden" name="MAX_FILE_SIZE" value="%d" id="f_maxsize" />', Utils::return_bytes(Utils::getMaxUploadSize()));
		}
		elseif (!empty($copy)) {
			$input .= sprintf('<input type="button" onclick="var a = $(\'#f_%s\'); a.focus(); a.select(); document.execCommand(\'copy\'); this.value = \'Copié !\'; this.focus(); return false;" onblur="this.value = \'Copier\';" value="Copier" title="Copier dans le presse-papier" />', $params['name']);
		}

		$input .= $suffix;

		// No label? then we only want the input without the widget
		if (empty($label)) {
			if (!array_key_exists('label', $params) && ($type == 'radio' || $type == 'checkbox')) {
				$input .= sprintf('<label for="%s"></label>', $attributes['id']);
			}

			return $input;
		}

		$label = sprintf('<label for="%s">%s</label>', $attributes['id'], htmlspecialchars((string)$label));

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
		if (isset($params['shape']) && isset($params['html']) && $params['html'] == false) {
			return Utils::iconUnicode($params['shape']);
		}

		if (!isset($params['shape']) && !isset($params['url'])) {
			throw new \RuntimeException('Missing parameter: shape or url');
		}

		$html = '';

		if (isset($params['url'])) {
			$html = self::getIconHTML(['icon' => $params['url']]);
			unset($params['url']);
		}

		$html .= htmlspecialchars($params['label'] ?? '');
		unset($params['label']);

		self::setIconAttribute($params);

		$attributes = array_diff_key($params, ['shape']);
		$attributes = array_map(fn($v, $k) => sprintf('%s="%s"', $k, htmlspecialchars($v)),
			$attributes, array_keys($attributes));

		$attributes = implode(' ', $attributes);

		return sprintf('<span %s>%s</span>', $attributes, $html);
	}

	static public function link(array $params): string
	{
		$href = $params['href'];
		$label = $params['label'];
		$prefix = $params['prefix'] ?? '';

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

		unset($params['href'], $params['label'], $params['prefix']);

		array_walk($params, function (&$v, $k) {
			$v = sprintf('%s="%s"', $k, htmlspecialchars($v));
		});

		$params = implode(' ', $params);

		$label = $label ? sprintf('<span>%s</span>', htmlspecialchars($label)) : '';

		return sprintf('<a href="%s" %s>%s%s</a>', htmlspecialchars($href), $params, $prefix, $label);
	}

	static public function button(array $params): string
	{
		$label = isset($params['label']) ? htmlspecialchars($params['label']) : '';
		unset($params['label']);

		self::setIconAttribute($params);

		if (!isset($params['type'])) {
			$params['type'] = 'button';
		}

		if (!isset($params['class'])) {
			$params['class'] = '';
		}

		if (isset($params['name']) && !isset($params['value'])) {
			$params['value'] = 1;
		}

		$prefix = '';
		$suffix = '';

		if (isset($params['icon'])) {
			$prefix = self::getIconHTML($params);
			unset($params['icon'], $params['icon_html']);
		}

		if (isset($params['csrf_key'])) {
			$suffix .= Form::tokenHTML($params['csrf_key']);
			unset($params['csrf_key']);
		}

		$params['class'] .= ' icn-btn';

		// Remove NULL params
		$params = array_filter($params);

		array_walk($params, function (&$v, $k) {
			$v = sprintf('%s="%s"', $k, htmlspecialchars($v));
		});

		$params = implode(' ', $params);

		return sprintf('<button %s>%s%s</button>%s', $params, $prefix, $label, $suffix);
	}

	static public function linkbutton(array $params): string
	{
		self::setIconAttribute($params);

		if (isset($params['icon']) || isset($params['icon_html'])) {
			$params['prefix'] = self::getIconHTML($params);
			unset($params['icon'], $params['icon_html']);
		}

		if (!isset($params['class'])) {
			$params['class'] = '';
		}

		$params['class'] .= ' icn-btn';

		return self::link($params);
	}

	static protected function getIconHTML(array $params): string
	{
		if (isset($params['icon_html'])) {
			return '<i class="icon">' . $params['icon_html'] . '</i>';
		}

		return sprintf('<svg class="icon"><use xlink:href="%s#img" href="%1$s#img"></use></svg> ',
			htmlspecialchars(Utils::getLocalURL($params['icon']))
		);
	}

	static protected function setIconAttribute(array &$params): void
	{
		if (isset($params['shape'])) {
			$params['data-icon'] = Utils::iconUnicode($params['shape']);
		}

		unset($params['shape']);
	}
}
