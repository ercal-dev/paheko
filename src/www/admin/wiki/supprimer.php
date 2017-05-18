<?php
namespace Garradin;

require_once __DIR__ . '/_inc.php';

qv(['id' => 'required|numeric']);

$session->requireAccess('wiki', Membres::DROIT_ADMIN);

$page = $wiki->getByID(qg('id'));

if (!$page)
{
    throw new UserException("Cette page n'existe pas.");
}

$form_errors = [];

if (f('delete'))
{
    if (fc('delete_wiki_'.$page->id, [], $form_errors))
    {
        if ($wiki->delete($page->id))
        {
            Utils::redirect('/admin/wiki/');
        }
        else
        {
            $form_errors[] = "D'autres pages utilisent cette page comme rubrique parente.";
        }
    }
}

$tpl->assign('form_errors', $form_errors);
$tpl->assign('page', $page);

$tpl->display('admin/wiki/supprimer.tpl');
