{include file="admin/_head.tpl" title="Configuration — Fiche membres" current="config"}

{if $error}
    {if $error == 'OK'}
    <p class="confirm">
        La configuration a bien été enregistrée.
    </p>
    {else}
    <p class="error">
        {$error|escape}
    </p>
    {/if}
{/if}

<ul class="actions">
    <li><a href="{$www_url}admin/config/">Général</a></li>
    <li class="current"><a href="{$www_url}admin/config/membres.php">Fiche membres</a></li>
    <li><a href="{$www_url}admin/config/site.php">Site public</a></li>
</ul>

<form method="post" action="{$self_url|escape}">

    <p class="help">
        Cette page vous permet de personnaliser les fiches d'information des membres de l'association.<br />
        <strong>Attention :</strong> Les champs supprimés de la fiche seront effacés de toutes les fiches de tous les membres, et les données qu'ils contenaient seront perdues.
    </p>

    <fieldset>
        <legend>Champs non-personnalisables</legend>
        <dl>
            <dt>Numéro unique</dt>
            <dd>Ce numéro identifie de manière unique chacun des membres. 
                Il est incrémenté à chaque nouveau membre ajouté.</dd>
            <dt>Catégorie</dt>
            <dd>Identifie la catégorie du membre.</dd>
            <dt>Mot de passe</dt>
            <dd>Le mot de passe permet de se connecter à l'administration de Garradin.</dd>
        </dl>
    </fieldset>

    <fieldset>
        <legend>Ajouter un champ pré-défini</legend>
    </fieldset>

    <fieldset>
        <legend>Ajouter un champ personnalisé</legend>
        <dl>
            <dt><label for="f_type">Type</label></dt>
            <dd>
                <select name="new[type]" id="f_type">
                    {foreach from=$types key="type" value="name"}
                    <option value="{$type|escape}"{if (!empty($new.type) && $new.type == $type) || (empty($new.type) && $type == 'text')} selected="selected"{/if}>{$name|escape}</option>
                    {/foreach}
                </select>
            </dd>
            <dt><label for="f_title">Titre</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
            <dd><input type="text" name="new[title]" id="f_title" value="{form_field data=$new name=title}" size="60" /></dd>
            <dt><label for="f_help">Aide</label></dt>
            <dd><input type="text" name="new[help]" id="f_help" value="{form_field data=$new name=help}" size="100" /></dd>
            <dt><label><input type="checkbox" name="new[editable]" value="1" {form_field data=$new name=editable checked="1"} /> Modifiable par les membres</label></dt>
            <dd class="help">Si coché, les membres pourront changer cette information depuis leur espace personnel.</dd>
            <dt><label><input type="checkbox" name="new[mandatory]" value="1" {form_field data=$new name=mandatory checked="1"} /> Champ obligatoire</label></dt>
            <dd class="help">Si coché, ce champ ne pourra rester vide.</dd>
            <dt><label><input type="checkbox" name="new[private]" value="1" {form_field data=$new name=private checked="1"} /> Champ privé</label></dt>
            <dd class="help">Si coché, ce champ ne sera visible et modifiable que par les personnes pouvant gérer les membres, mais pas les membres eux-même.</dd>
        </dl>
    </fieldset>

    <div id="orderFields">
        {foreach from=$champs item="champ" key="nom"}
        <fieldset>
            <legend>{$nom|escape}</legend>
            <dl>
                <dt><label for="f_{$nom|escape}_type">Type</label></dt>
                <dd>
                    {if $nom == 'email'}
                        <input type="hidden" name="champs[{$nom|escape}][type]" value="{$champ.type|escape}" />
                        Adresse E-Mail (non modifiable)
                    {else}
                        <select name="champs[{$nom|escape}][type]" id="f_{$nom|escape}_type">
                            {foreach from=$types key="type" value="name"}
                            <option value="{$type|escape}"{if (!empty($champ.type) && $champ.type == $type)} selected="selected"{/if}>{$name|escape}</option>
                            {/foreach}
                        </select>
                    {/if}
                </dd>
                <dt><label for="f_{$nom|escape}_title">Titre</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
                <dd><input type="text" name="champs[{$nom|escape}][title]" id="f_{$nom|escape}_title" value="{form_field data=$champs[$nom] name=title}" size="60" /></dd>
                <dt><label for="f_{$nom|escape}_help">Aide</label></dt>
                <dd><input type="text" name="champs[{$nom|escape}][help]" id="f_{$nom|escape}_help" value="{form_field data=$champs[$nom] name=help}" size="100" /></dd>
                <dt><label><input type="checkbox" name="champs[{$nom|escape}][editable]" value="1" {form_field data=$champs[$nom] name=editable checked="1"} /> Modifiable par les membres</label></dt>
                <dd class="help">Si coché, les membres pourront changer cette information depuis leur espace personnel.</dd>
                <dt><label><input type="checkbox" name="champs[{$nom|escape}][mandatory]" value="1" {form_field data=$champs[$nom] name=mandatory checked="1"} /> Champ obligatoire</label></dt>
                <dd class="help">Si coché, ce champ ne pourra rester vide.</dd>
                <dt><label><input type="checkbox" name="champs[{$nom|escape}][private]" value="1" {form_field data=$champs[$nom] name=private checked="1"} /> Champ privé</label></dt>
                <dd class="help">Si coché, ce champ ne sera visible et modifiable que par les personnes pouvant gérer les membres, mais pas les membres eux-même.</dd>
            </dl>
        </fieldset>
        {/foreach}
    </div>

    <p class="submit">
        {csrf_field key="config_membres"}
        <input type="submit" name="save" value="Enregistrer &rarr;" />
        (un récapitulatif sera présenté et une confirmation sera demandée)
    </p>

</form>

<script type="text/javascript">
{literal}
(function () {
    if (!document.querySelector || !document.querySelectorAll)
    {
        return false;
    }

    var fields = document.querySelectorAll('#orderFields fieldset');

    for (i = 0; i < fields.length; i++)
    {
        var field = fields[i];
        field.querySelector('dl').style.display = 'none';

        var legend = field.querySelector('legend');

        legend.onclick = function () {
            var content = this.parentNode.querySelector('dl');
            if (content.style.display.toLowerCase() == 'none')
                content.style.display = 'block';
            else
                content.style.display = 'none';
        }

        legend.className = 'interactive';
        legend.title = 'Cliquer pour modifier ce champ';

        var actions = document.createElement('div');
        actions.className = 'actions';
        field.appendChild(actions);

        var up = document.createElement('span');
        up.className = 'icn up';
        up.innerHTML = '&uarr;';
        up.title = 'Déplacer vers le haut';
        up.onclick = function (e) {
            var field = this.parentNode.parentNode;
            var p = field.previousSibling;
            while (p.nodeType == 3) { p = p.previousSibling; }
            field.parentNode.insertBefore(field, p);
            return false;
        };
        actions.appendChild(up);

        var down = document.createElement('span');
        down.className = 'icn down';
        down.innerHTML = '&darr;';
        down.title = 'Déplacer vers le bas';
        down.onclick = function (e) {
            var field = this.parentNode.parentNode;
            var p = field.nextSibling;

            if (!p.nextSibling)
            {
                field.parentNode.appendChild(field);
            }
            else
            {
                while (p.nodeType == 3) { p = p.nextSibling; }
                p = p.nextSibling;
                while (p.nodeType == 3) { p = p.nextSibling; }
                field.parentNode.insertBefore(field, p);
            }
            return false;
        };
        actions.appendChild(down);

        var rem = document.createElement('span');
        rem.className = 'icn remove';
        rem.innerHTML = '&#10005;';
        rem.title = 'Enlever ce champ de la fiche';
        rem.onclick = function (e) {
            if (!window.confirm('Êtes-vous sûr de supprimer ce champ des fiches de membre ?'))
            {
                return false;
            }

            var field = this.parentNode.parentNode;
            field.parentNode.removeChild(field);
            return false;
        };
        actions.appendChild(rem);

        var edit = document.createElement('span');
        edit.className = 'icn edit';
        edit.innerHTML = '&#x270e;';
        edit.title = 'Modifier ce champ';
        edit.onclick = function (e) {
            var content = this.parentNode.parentNode.querySelector('dl');
            if (content.style.display.toLowerCase() == 'none')
                content.style.display = 'block';
            else
                content.style.display = 'none';
            return false;
        };
        actions.appendChild(edit);
    }
}());
{/literal}
</script>

{include file="admin/_foot.tpl"}