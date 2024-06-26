<?php

namespace Paheko\Web\Render;

use Paheko\Entities\Files\File;

class Render
{
	const FORMAT_SKRIV = 'skriv';
	const FORMAT_ENCRYPTED = 'encrypted';
	const FORMAT_MARKDOWN = 'markdown';

	static protected $attachments = [];

	static public $cache = [];

	static public function render(string $format, ?string $path, ?string $content = null, ?string $link_prefix = null)
	{
		return self::getRenderer($format, $path, $link_prefix)->render($content);
	}

	static public function getRenderer(string $format, ?string $path, ?string $link_prefix = null, ?string $content = null)
	{
		$hash = md5($format . $path . $content);

		if (array_key_exists($hash, self::$cache)) {
			return self::$cache[$hash];
		}

		if ($format == self::FORMAT_SKRIV) {
			$r = new Skriv($path, $link_prefix);
		}
		// Keep legacy format as it is sometimes used in upgrades
		else if ($format == self::FORMAT_ENCRYPTED) {
			$r = new Encrypted($path, $link_prefix);
		}
		else if ($format == self::FORMAT_MARKDOWN) {
			$r = new Markdown($path, $link_prefix);
		}
		else {
			throw new \LogicException('Invalid format: ' . $format);
		}

		self::$cache[$hash] = $r;

		return $r;
	}

	static public function registerAttachment(string $path, string $uri): void
	{
		$hash = md5($path);

		if (!array_key_exists($hash, self::$attachments)) {
			self::$attachments[$hash] = [];
		}

		self::$attachments[$hash][$uri] = true;
	}

	static public function listAttachments(?string $path) {
		return array_keys(self::$attachments[md5($path)] ?? []);
	}
}
