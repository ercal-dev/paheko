{include file="admin/_head.tpl" title="Configuration" current="config"}

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
    <li class="current"><a href="{$www_url}admin/config/">Général</a></li>
    <li><a href="{$www_url}admin/config/membres.php">Membres</a></li>
    <li><a href="{$www_url}admin/config/site.php">Site public</a></li>
</ul>

<form method="post" action="{$self_url|escape}">

    <fieldset>
        <legend>Informations sur l'association</legend>
        <dl>
            <dt><label for="f_nom_asso">Nom</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
            <dd><input type="text" name="nom_asso" id="f_nom_asso" value="{form_field data=$config name=nom_asso}" /></dd>
            <dt><label for="f_email_asso">Adresse E-Mail</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
            <dd><input type="email" name="email_asso" id="f_email_asso" value="{form_field data=$config name=email_asso}" /></dd>
            <dt><label for="f_adresse_asso">Adresse postale</label></dt>
            <dd><textarea cols="50" rows="5" name="adresse_asso" id="f_adresse_asso">{form_field data=$config name=adresse_asso}</textarea></dd>
            <dt><label for="f_site_asso">Site web</label></dt>
            <dd><input type="url" name="site_asso" id="f_site_asso" value="{form_field name=site_asso data=$config}" /></dd>
        </dl>
    </fieldset>

    <fieldset>
        <legend>Envois par E-Mail</legend>
        <dl>
            <dt><label for="f_email_envoi_automatique">Adresse E-Mail expéditeur des messages automatiques</label></dt>
            <dd><input type="text" name="email_envoi_automatique" id="f_email_envoi_automatique" value="{form_field data=$config name=email_envoi_automatique}" /></dd>
        </dl>
    </fieldset>

    <fieldset>
        <legend>Wiki</legend>
        <dl>
            <dt><label for="f_accueil_wiki">Page d'accueil</label> <b title="(Champ obligatoire)">obligatoire</b></dt>
            <dd>Indiquer ici l'adresse unique de la page qui sera utilisée comme page d'accueil du wiki.</dd>
            <dd><input type="text" name="accueil_wiki" id="f_accueil_wiki" value="{form_field data=$config name=accueil_wiki}" /></dd>
        </dl>
    </fieldset>

    <p class="submit">
        {csrf_field key="config"}
        <input type="submit" name="save" value="Enregistrer &rarr;" />
    </p>

</form>

{include file="admin/_foot.tpl"}