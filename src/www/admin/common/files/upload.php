<?php
namespace Garradin;

use Garradin\Entities\Files\File;
use Garradin\Files\Files;

require __DIR__ . '/../../_inc.php';

$parent = qg('p');

if (!File::checkCreateAccess($parent, $session)) {
	throw new UserException('Vous n\'avez pas le droit d\'ajouter de fichier.');
}

$csrf_key = 'upload_file_' . md5($parent);

$form->runIf('upload', function () use ($parent) {
	Files::uploadMultiple($parent, 'file');
}, $csrf_key, '!docs/?path=' . $parent);

$tpl->assign(compact('parent', 'csrf_key'));

$tpl->display('common/files/upload.tpl');
