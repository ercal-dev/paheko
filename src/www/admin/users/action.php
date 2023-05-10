<?php
namespace Garradin;

use Garradin\Users\Categories;
use Garradin\Users\Users;

require_once __DIR__ . '/_inc.php';

$session->requireAccess($session::SECTION_USERS, $session::ACCESS_ADMIN);

if (!f('selected') || !is_array(f('selected')) || !count(f('selected'))) {
    throw new UserException("Aucun membre sélectionné.");
}

$action = f('action');
$list = f('selected');

if (!$action) {
    throw new UserException('Aucune action sélectionnée.');
}

if ($action == 'ods' || $action == 'csv' || $action == 'xlsx') {
    Users::exportSelected($action, $list);
    return;
}
elseif ($action == 'move' || $action == 'delete')
{
    foreach (f('selected') as &$id)
    {
        $id = (int) $id;

        // On ne permet pas d'action collective sur l'utilisateur courant pour éviter les risques
        // d'erreur genre "oh je me suis supprimé du coup j'ai plus accès à rien"
        if ($id == $user->id)
        {
            throw new UserException("Il n'est pas possible de se modifier ou supprimer soi-même.");
        }
    }
}

if ($action == 'move' && f('confirm'))
{
    $form->check('membres_action', [
        'selected' => 'required|array',
        'id_category' => 'required|numeric',
    ]);

    if (!$form->hasErrors())
    {
        $membres->changeCategorie(f('id_category'), f('selected'));
        Utils::redirect(ADMIN_URL . 'membres/');
    }
}
elseif ($action == 'delete' && f('confirm'))
{
    $form->check('membres_action', [
        'selected' => 'required|array',
    ]);

    if (!$form->hasErrors())
    {
        $membres->delete(f('selected'));
        Utils::redirect(ADMIN_URL . 'membres/');
    }
}

$tpl->assign('selected', $list);
$tpl->assign('nb_selected', count($list));

if ($action == 'move')
{
    $tpl->assign('membres_cats', Categories::listAssoc());
}

$tpl->assign('action', $action);

$tpl->display('admin/users/action.tpl');
