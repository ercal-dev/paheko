<?php

namespace Paheko\Web\Render;

use Paheko\Entities\Files\File;

use Paheko\Plugins;
use Paheko\UserTemplate\CommonModifiers;

use KD2\SkrivLite;

class Skriv extends AbstractRender
{
	static protected $skriv = null;
	protected Extensions $extensions;

	public function __construct(?File $file = null, ?string $user_prefix = null)
	{
		parent::__construct($file, $user_prefix);

		self::$skriv ??= new SkrivLite;

		$this->extensions = new Extensions($this);
		self::$skriv->registerExtensions($this->extensions->getList());
	}

	public function render(?string $content = null): string
	{
		$str = $content ?? $this->file->fetch();

		// Old file URLs, FIXME/TODO remove
		$str = preg_replace_callback('/#file:\[([^\]\h]+)\]/', function ($match) {
			return $this->resolveAttachment($match[1]);
		}, $str);

		$str = CommonModifiers::typo($str);
		$str = self::$skriv->render($str);

		// Resolve internal links
		$str = preg_replace_callback(';<a href="((?!https?://|\w+:).+?)">;i', function ($matches) {
			return sprintf('<a href="%s">', htmlspecialchars($this->resolveLink(htmlspecialchars_decode($matches[1]))));
		}, $str);

		return sprintf('<div class="web-content">%s</div>', $str);
	}
}