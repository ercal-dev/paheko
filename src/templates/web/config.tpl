{include file="admin/_head.tpl" title="Configuration" current="web"}

<nav class="tabs">
	<ul>
		<li><a href="./">Gestion du site web</a></li>
		{if $session->canAccess($session::SECTION_WEB, $session::ACCESS_ADMIN)}
			{*<li><a href="theme.php">Thèmes</a></li>*}
			<li class="current"><a href="config.php">Configuration</a></li>
		{/if}
	</ul>
</nav>

{form_errors}

{if $config.desactiver_site}
	<div class="block alert">
		<h3>Site public désactivé</h3>
		<p>Le site public est désactivé, les visiteurs sont redirigés automatiquement vers la page de connexion.</p>
		<form method="post" action="{$self_url}">
			<p class="submit">
				{csrf_field key="config_site"}
				{button type="submit" name="activer_site" label="Réactiver le site public" shape="right" class="main"}
			</p>
		</form>
	</div>
{elseif isset($edit)}
	<form method="post" action="{$self_url}">
		<h3>Éditer un squelette</h3>

		{if $ok}
		<p class="block confirm">
			Modifications enregistrées.
		</p>
		{/if}

		<fieldset class="skelEdit">
			<legend>{$edit.file}</legend>
			<p>
				<textarea name="content" cols="90" rows="50" id="f_content">{form_field name=content data=$edit}</textarea>
			</p>
		</fieldset>

		<p class="submit">
			{csrf_field key=$csrf_key}
			{button type="submit" name="save" label="Enregistrer" shape="right" class="main"}
		</p>

	</form>

	<script type="text/javascript">
	g.script("scripts/code_editor.js");
	</script>
{else}

	<fieldset>
		<legend>Activation du site public</legend>
		<dl>
			<dt>
				<form method="post" action="{$self_url}">
					<p class="submit">
						{button type="submit" name="desactiver_site" label="Désactiver le site public" shape="right" class="main"}
						{csrf_field key="config_site"}
					</p>
				</form>
			</dt>
			<dd class="help">
				En désactivant le site public, les visiteurs seront automatiquement redirigés vers la page de connexion.<br />
				Cette option est utile si vous avez déjà un site web et ne souhaitez pas utiliser la fonctionnalité site web de Garradin.
			</dd>
		</dl>
	</fieldset>

	<form method="post" action="{$self_url}">
	<fieldset class="templatesList">
		<legend>Squelettes du site</legend>

		{if $reset_ok}
		<p class="block confirm">
			Réinitialisation effectuée. Les squelettes ont été remis à jour
		</p>
		{/if}

		<table class="list">
			<thead>
				<tr>
					<td class="check"></td>
					<th>Fichier</th>
					<td>Dernière modification</td>
					<td></td>
				</tr>
			</thead>
			<tbody>
			{foreach from=$sources key="source" item="local"}
				<tr>
					<td>{if $local.modified}<input type="checkbox" name="select[]" value="{$source}" id="f_source_{$iteration}" /><label for="f_source_{$iteration}"></label>{/if}</td>
					<th><a href="?edit={$source|escape:'url'}" title="Éditer">{$source}</a></th>
					<td>{if $local.modified}{$local.modified|date}{else}<em>(fichier non modifié)</em>{/if}</td>
					<td class="actions">
						{linkbutton shape="edit" label="Éditer" href="?edit=%s"|args:$source}
					</td>
				</tr>
			{/foreach}
			</tbody>
		</table>

		<p class="actions">
			Pour les squelettes sélectionnés&nbsp;:
			<input type="submit" name="reset" value="Réinitialiser" onclick="return confirm('Effacer toute modification locale et restaurer les squelettes d\'installation ?');" />
			{csrf_field key="squelettes"}
		</p>
	</fieldset>
	</form>

	<form method="post" enctype="multipart/form-data">
		<fieldset>
			<legend>Ajouter un nouveau squelette</legend>
			<dl>
				{input type="text" name="name" label="Nom de fichier" required=true}
				{input type="file" name="file" label="Fichier" help="Si aucun fichier n'est sélectionné, un fichier texte vide sera créé"}
			</dl>
			<p class="submit">
				{csrf_field key="skel_upload"}
				{button type="submit" name="upload" label="Ajouter" shape="upload"}
			</p>
		</fieldset>
	</form>

	{literal}
	<script type="text/javascript">
	let f = $('#f_file');
	let n = $('#f_name');
	f.onchange = () => {
		if (!f.files.length) {
			return;
		}

		n.value = f.files[0].name.split(/(\\|\/)/g).pop();
	}
	</script>
	{/literal}
{/if}

{include file="admin/_foot.tpl"}