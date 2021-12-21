<?php

namespace Garradin\UserTemplate;

use Garradin\Utils;

use KD2\Brindille_Exception;

class Modifiers
{
	const PHP_MODIFIERS_LIST = [
		'strtolower',
		'strtoupper',
		'ucfirst',
		'ucwords',
		'htmlentities',
		'htmlspecialchars',
		'trim',
		'ltrim',
		'rtrim',
		'lcfirst',
		'md5',
		'sha1',
		'metaphone',
		'nl2br',
		'soundex',
		'str_split',
		'str_word_count',
		'strrev',
		'strlen',
		'strpos',
		'strrpos',
		'wordwrap',
		'strip_tags',
		'strlen',
		'boolval',
		'intval',
		'floatval',
		'substr',
	];

	const MODIFIERS_LIST = [
		'truncate',
		'excerpt',
		'protect_contact',
		'atom_date',
		'xml_escape',
		'replace',
		'regexp_replace',
		'remove_leading_number',
		'get_leading_number',
		'spell_out_number',
		'parse_date',
		'math',
		'money_int' => [Utils::class, 'moneyToInteger'],
	];

	const LEADING_NUMBER_REGEXP = '/^([\d.]+)\s*[.\)]\s*/';

	static public function replace($str, $find, $replace): string
	{
		return str_replace($find, $replace, $str);
	}

	static public function regexp_replace($str, $pattern, $replace)
	{
		return preg_replace($pattern, $replace, $str);
	}

	/**
	 * UTF-8 aware intelligent substr
	 * @param  string  $str         UTF-8 string
	 * @param  integer $length      Maximum string length
	 * @param  string  $placeholder Placeholder text to append at the string if it has been cut
	 * @param  boolean $strict_cut  If true then will cut in the middle of words
	 * @return string 				String cut to $length or shorter
	 * @example |truncate:10:" (click to read more)":true
	 */
	static public function truncate($str, $length = 80, $placeholder = '…', $strict_cut = false): string
	{
		// Don't try to use unicode if the string is not valid UTF-8
		$u = preg_match('//u', $str) ? 'u' : '';

		// Shorter than $length + 1
		if (!preg_match('/^.{' . ((int)$length + 1) . '}/s' . $u, $str))
		{
			return $str;
		}

		// Cut at 80 characters
		$str = preg_replace('/^(.{0,' . (int)$length . '}).*$/s' . $u, '$1', $str);

		if (!$strict_cut)
		{
			$cut = preg_replace('/[^\s.,:;!?]*?$/s' . $u, '', $str);

			if (trim($cut) == '') {
				$cut = $str;
			}

			$str = $cut;
		}

		return trim($str) . $placeholder;
	}

	static public function excerpt($str, $length = 600): string
	{
		$str = strip_tags($str);
		$str = self::truncate($str, $length);
		$str = preg_replace("/\n{2,}/", '</p><p>', $str);
		return '<p>' . $str . '</p>';
	}

	static public function protect_contact(?string $contact): string
	{
		if (!trim($contact))
			return '';

		if (strpos($contact, '@')) {
			$reversed = strrev($contact);
			// https://unicode-table.com/en/FF20/
			$reversed = strtr($reversed, ['@' => '＠']);

			return sprintf('<a href="#error" onclick="this.href = (this.innerText + \':otliam\').split(\'\').reverse().join(\'\').replace(/＠/, \'@\');"><span style="unicode-bidi:bidi-override;direction: rtl;">%s</span></a>',
				htmlspecialchars($reversed));
		}
		else {
			return '<a href="'.htmlspecialchars($contact, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($contact, ENT_QUOTES, 'UTF-8').'</a>';
		}
	}

	static public function atom_date($date)
	{
		return Utils::date_fr($date, DATE_ATOM);
	}

	static public function xml_escape($str)
	{
		return htmlspecialchars($str, ENT_XML1 | ENT_QUOTES);
	}

	static public function remove_leading_number($str): string
	{
		return preg_replace(self::LEADING_NUMBER_REGEXP, '', trim($str));
	}

	static public function get_leading_number($str): ?string
	{
		$match = preg_match(self::LEADING_NUMBER_REGEXP, $str);
		return $match[1] ?? null;
	}

	static public function spell_out_number($number, string $locale = 'fr_FR'): string
	{
		return numfmt_create($locale, \NumberFormatter::SPELLOUT)->format((float) $number);
	}

	static public function parse_date($value)
	{
		if ($value instanceof \DateTimeInterface) {
			return $value->format('Y-m-d');
		}

		if (empty($value) || !is_string($value)) {
			return null;
		}

		if (preg_match('!^\d{2}/\d{2}/\d{2}$!', $value)) {
			return \DateTime::createFromFormat('!d/m/y', $value)->format('Y-m-d');
		}
		elseif (preg_match('!^\d{2}/\d{2}/\d{4}$!', $value)) {
			return \DateTime::createFromFormat('!d/m/Y', $value)->format('Y-m-d');
		}
		elseif (preg_match('!^\d{4}-\d{2}-\d{2}$!', $value)) {
			return $value;
		}
		else {
			return false;
		}
	}

	static public function math($start, ... $params)
	{
		$tuples = array_chunk($params, 2);
		foreach ($tuples as $tuple) {
			if (count($tuple) !== 2) {
				continue;
			}

			list($sign, $value) = $tuple;

			if (!is_numeric($value) && !is_null($value)) {
				throw new Brindille_Exception('Invalid numeric value for math modifier');
			}

			if ($sign == '+') {
				$start += $value;
			}
			elseif ($sign == '-') {
				$start -= $value;
			}
			elseif ($sign == '*') {
				$start *= $value;
			}
			elseif ($sign == '/') {
				$start /= $value;
			}
			else {
				throw new Brindille_Exception('Invalid math operator, only + - * / are supported');
			}
		}

		return $start;
	}
}