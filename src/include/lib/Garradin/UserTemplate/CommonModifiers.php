<?php

namespace Garradin\UserTemplate;

use Garradin\Config;
use Garradin\Utils;

/**
 * Common modifiers and functions used by Template (Smartyer) and UserTemplate
 */
class CommonModifiers
{
	const MODIFIERS_LIST = [
		'money',
		'money_currency',
		'relative_date',
		'date_short',
		'date_long',
		'date_hour',
		'date',
		'strftime',
		'size_in_bytes' => [Utils::class, 'format_bytes'],
		'typo',
		'css_hex_to_rgb',
	];

	const FUNCTIONS_LIST = [
		'pagination',
	];

	/**
	 * See also money/money_currency in UserTemplate (overriden)
	 */
	static public function money($number, bool $hide_empty = true, bool $force_sign = false): string
	{
		if ($hide_empty && !$number) {
			return '';
		}

		$sign = ($force_sign && $number > 0) ? '+' : '';

		return sprintf('<b class="money">%s</b>', $sign . Utils::money_format($number, ',', '&nbsp;', $hide_empty));
	}

	static public function money_currency($number, bool $hide_empty = true): string
	{
		$out = self::money($number, $hide_empty);

		if ($out !== '') {
			$out .= '&nbsp;' . Config::getInstance()->get('currency');
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
		if (null === $format) {
			$format = 'd/m/Y à H:i';
		}
		elseif (preg_match('/^DATE_[\w\d]+$/', $format)) {
			$format = constant('DateTime::' . $format);
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

			if (!empty($params['use_buttons'])) {
				$out .= sprintf('<button type="submit" name="_dl_page" value="%d">%s</button>', $page['id'], htmlspecialchars($page['label']));
			}
			else {
				$url = str_replace(['[ID]', $encoded_url], $page['id'], $params['url']);
				$out .= sprintf('<a %s href="%s">%s</a>', $attributes, $url, htmlspecialchars($page['label']));
			}

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

	static public function css_hex_to_rgb($str): ?string {
		$hex = sscanf((string)$str, '#%02x%02x%02x');

		if (empty($hex)) {
			return null;
		}

		return implode(', ', $hex);
	}
}
