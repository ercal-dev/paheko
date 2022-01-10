<?php
namespace Garradin;

use Garradin\Users\Categories;

require_once __DIR__ . '/_inc.php';

$session->requireAccess($session::SECTION_USERS, $session::ACCESS_WRITE);

$champs = $config->get('champs_membres');

if (f('save'))
{
    $form->check('new_member', [
        'passe' => 'confirmed',
        // FIXME: ajouter les règles pour les champs membres
    ]);

    if (!$form->hasErrors())
    {
        try
        {
            if ($session->canAccess($session::SECTION_USERS, $session::ACCESS_ADMIN))
            {
                $id_category = f('id_category');
            }
            else
            {
                $id_category = $config->get('categorie_membres');
            }

            $data = ['id_category' => $id_category];

            foreach ($champs->getAll() as $key=>$dismiss)
            {
                $data[$key] = f($key);
            }

            $id = $membres->add($data);

            Utils::redirect(ADMIN_URL . 'membres/fiche.php?id='.(int)$id);
        }
        catch (UserException $e)
        {
            $form->addError($e->getMessage());
        }
    }
}

$tpl->assign('id_field_name', $config->get('champ_identifiant'));

$tpl->assign('id_field_name', $config->get('champ_identifiant'));

$tpl->assign('passphrase', Utils::suggestPassword());
$tpl->assign('champs', $champs->getAll());

$tpl->assign('membres_cats', Categories::listSimple());
$tpl->assign('current_cat', f('id_category') ?: $config->get('categorie_membres'));

$tpl->display('admin/membres/ajouter.tpl');
