<?php

namespace Garradin;

use Garradin\Form;
use KD2\DB\AbstractEntity;

class Entity extends AbstractEntity
{
	/**
	 * Valider les champs avant enregistrement
	 * @throws ValidationException Si une erreur de validation survient
	 */
	public function importForm(array $source = null)
	{
		if (null === $source) {
			$source = $_POST;
		}

		return $this->import($source);
	}

	static public function filterUserDateValue(?string $value): ?\DateTime
	{
		if (!trim((string) $value)) {
			return null;
		}

		if (preg_match('!^\d{2}/\d{2}/\d{2}$!', $value)) {
			return \DateTime::createFromFormat('d/m/y', $value);
		}
		elseif (preg_match('!^\d{2}/\d{2}/\d{4}$!', $value)) {
			return \DateTime::createFromFormat('d/m/Y', $value);
		}
		elseif (preg_match('!^\d{4}/\d{2}/\d{2}$!', $value)) {
			return \DateTime::createFromFormat('Y/m/d', $value);
		}
		elseif (preg_match('!^20\d{2}[01]\d[0123]\d$!', $value)) {
			return \DateTime::createFromFormat('Ymd', $value);
		}
		elseif (preg_match('!^\d{4}-\d{2}-\d{2}$!', $value)) {
			return \DateTime::createFromFormat('Y-m-d', $value);
		}
		elseif (null !== $value) {
			throw new ValidationException('Format de date invalide (merci d\'utiliser le format JJ/MM/AAAA) : ' . $value);
		}
	}

	protected function filterUserValue(string $type, $value, string $key)
	{
		if ($type == 'date') {
			return self::filterUserDateValue($value);
		}
		elseif ($type == 'DateTime') {
			if (preg_match('!^\d{2}/\d{2}/\d{4}\s\d{1,2}:\d{2}$!', $value)) {
				return \DateTime::createFromFormat('d/m/Y H:i', $value);
			}
		}

		return parent::filterUserValue($type, $value, $key);
	}

	protected function assert($test, string $message = null, int $code = 0): void
	{
		if ($test) {
			return;
		}

		if (null === $message) {
			$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
			$caller_class = array_pop($backtrace);
			$caller = array_pop($backtrace);
			$message = sprintf('Entity assertion fail from class %s on line %d', $caller_class['class'], $caller['line']);
			throw new \UnexpectedValueException($message);
		}
		else {
			throw new ValidationException($message, $code);
		}
	}

	// Add plugin signals to save/delete
	public function save(bool $selfcheck = true): bool
	{
		$name = get_class($this);
		$name = str_replace('Garradin\Entities\\', '', $name);
		$name = 'entity.' . $name . '.save';

		// Specific entity signal
		if (Plugin::fireSignal($name . '.before', ['entity' => $this])) {
			return true;
		}

		// Generic entity signal
		if (Plugin::fireSignal('entity.save.before', ['entity' => $this])) {
			return true;
		}

		$return = parent::save($selfcheck);
		Plugin::fireSignal($name . '.after', ['entity' => $this, 'success' => $return]);

		Plugin::fireSignal('entity.save.after', ['entity' => $this, 'success' => $return]);

		return $return;
	}

	public function delete(): bool
	{
		$name = get_class($this);
		$name = str_replace('Garradin\Entities\\', '', $name);
		$name = 'entity.' . $name . '.delete';

		if (Plugin::fireSignal($name . '.before', ['entity' => $this])) {
			return true;
		}

		// Generic entity signal
		if (Plugin::fireSignal('entity.delete.before', ['entity' => $this])) {
			return true;
		}

		$return = parent::delete();
		Plugin::fireSignal($name . '.after', ['entity' => $this, 'success' => $return]);
		Plugin::fireSignal('entity.delete.after', ['entity' => $this, 'success' => $return]);

		return $return;
	}
}
