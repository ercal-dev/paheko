{include file="_head.tpl" title="Message collectif" current="users/mailing" hide_title=true}

{form_errors}

<form method="post" action="{$self_url}">

	<fieldset class="header">
		<legend>Modifier le message collectif</legend>
		<p>
			{input type="text" name="subject" required=true class="full-width" placeholder="Sujet du message…" source=$mailing}
		</p>
		<div>
			<p class="sender_default{if $mailing.sender_name !== null} hidden{/if}">
				<strong>Expéditeur&nbsp;:</strong> {$config.org_name} &lt;{$config.org_email}&gt;
				{button label="Modifier" shape="edit" onclick="this.parentNode.remove(); g.toggle('#custom_sender', true);"}
			</p>
			<dl id="custom_sender" {if !$mailing.sender_name} class="hidden"{/if}>
				{input type="text" required=true name="sender_name" source=$mailing label="Nom de l'expéditeur" placeholder="Nom de l'expéditeur"} &nbsp;
				{input type="email" required=true name="sender_email" source=$mailing label="Adresse e-mail de l'expéditeur" placeholder="Adresse e-mail de l'expéditeur"}
			</dl>
		</div>
	</fieldset>

	<fieldset class="textEditor">
		{input type="textarea" name="content" cols=35 rows=25 required=true class="full-width"
				data-attachments=0 data-savebtn=0 data-preview-url="!users/mailing/write.php?id=%s&preview"|local_url|args:$mailing.id data-format="markdown" placeholder="Contenu du message…" default=$mailing.body}
	</fieldset>

	<p class="submit">
		{csrf_field key=$csrf_key}
		{button type="submit" name="save" label="Enregistrer" shape="right" class="main"}
	</p>

</form>


{include file="_foot.tpl"}