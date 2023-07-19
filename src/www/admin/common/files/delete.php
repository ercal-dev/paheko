<?php
namespace Garradin;

use Garradin\Entities\Files\File;
use Garradin\Files\Files;

require __DIR__ . '/../../_inc.php';

$file = Files::get(qg('p'));

if (!$file) {
	throw new UserException('Fichier inconnu');
}

if (!$file->canDelete()) {
	throw new UserException('Vous n\'avez pas le droit de supprimer ce fichier.');
}

$trash = !(qg('trash') === 'no' && $session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN));

$csrf_key = 'file_delete_' . $file->pathHash();
$parent = $file->parent;

$form->runIf('delete', function () use ($file, $trash) {
	if ($trash) {
		$file->moveToTrash();
	}
	else {
		$file->delete();
	}
}, $csrf_key, '!docs/?path=' . $parent);

$tpl->assign(compact('file', 'csrf_key', 'trash'));

$tpl->display('common/files/delete.tpl');