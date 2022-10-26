<?php

namespace Garradin;

use Garradin\Files\Files;
use Garradin\Entities\Files\File;
use Garradin\Web\Render\Render;
use Garradin\Web\Web;

require_once __DIR__ . '/../../_inc.php';

$page = null;
$content = f('content');

if (null == $content) {
	throw new UserException('Aucun contenu à prévisualiser');
}

if ($path = qg('f')) {
	$file = Files::get($path);

	if (!$file || !$file->canRead()) {
		throw new UserException('Vous n\'avez pas le droit de lire ce fichier.');
	}
}
elseif ($web = qg('w')) {
	$page = Web::get($web);

	if (!$page || !$page->file() || !$page->file()->canRead()) {
		throw new UserException('Vous n\'avez pas le droit de lire ce fichier.');
	}

	$file = $page->file();
}
else {
	throw new UserException('Fichier inconnu');
}

$prefix = $page ? 'web/page.php?uri=' : 'common/files/_preview.php?p=';

$content = Render::render(f('format'), $file, f('content'), ADMIN_URL . $prefix);

$tpl->assign(compact('file', 'content'));

$tpl->assign('custom_css', ['!web/css.php']);

$tpl->display('common/files/_preview.tpl');
