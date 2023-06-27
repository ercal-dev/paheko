{include file="_head.tpl" title="Documents" current="config"}

{include file="config/_menu.tpl" current="backup"}

{include file="config/backup/_menu.tpl" current="documents"}

{if $ok}
<p class="confirm block">La restauration a été effectuée.</p>
{/if}

{if $failed}
<p class="alert block">{$failed} fichiers n'ont pas pu être restaurés car ils dépassaient la taille autorisée.</p>
{/if}

{form_errors}

<form method="post" action="{$self_url_no_qs}">

<fieldset>
	<legend>Téléchargement des documents</legend>
	<p class="help">
		Les documents font {$files_size|size_in_bytes}.
	</p>
	{if $files_size > 0}
	<p class="submit">
		{csrf_field key="files_download"}
		{button type="submit" name="download_files" label="Télécharger une archive ZIP des documents sur mon ordinateur" shape="download" class="main"}
	</p>
	{/if}
</fieldset>

</form>

<form method="post" action="{$self_url_no_qs}" id="restoreDocuments" style="display: none;" enctype="multipart/form-data" data-disable-progress="1">

<fieldset>
	<legend>Restaurer les documents</legend>
	<p class="help">
		Sélectionner ici une sauvegarde (archive ZIP) des documents pour les restaurer.
	</p>
	<dl>
		{input type="file" name="file" label="Archive ZIP à restaurer" no_size_limit=true required=true}
	</dl>
	<p class="alert block">
		Les fichiers existants qui portent le même nom seront écrasés. Les documents existants qui ne figurent pas dans la sauvegarde ne seront pas affectés.
	</p>
	<p class="submit">
		{csrf_field key="files_restore"}
		{button type="submit" name="restore" label="Restaurer cette sauvegarde des documents" shape="upload" class="main"}
	</p>
</fieldset>

</form>

<script type="text/javascript">
g.script('scripts/lib/unzipit.min.js');
g.script('scripts/unzip_restore.js');
</script>

{include file="_foot.tpl"}