<?php

namespace Garradin;

use Garradin\Files\Files;
use Garradin\Entities\Files\File;

require_once __DIR__ . '/_inc.php';

$parent = trim(qg('path'));

if (!File::checkCreateAccess(File::CONTEXT_DOCUMENTS, $session)) {
	throw new UserException('Vous n\'avez pas le droit de créer de répertoire ici.');
}

$csrf_key = 'create_file';

$form->runIf('create', function () use ($parent) {
	$name = trim(f('name'));

	if (!strpos($name, '.')) {
		$name .= '.skriv';
	}

	File::validatePath($parent . '/' . $name);
	$name = File::filterName($name);

	$file = File::createAndStore($parent, $name, null, '');
}, $csrf_key, '!docs/?path=' . $parent);

$tpl->assign(compact('csrf_key'));

$tpl->display('docs/new_file.tpl');
