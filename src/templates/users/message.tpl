{include file="_head.tpl" title="Contacter un membre" current="membres"}

{form_errors}

<form method="post" action="{$self_url}">
	<fieldset class="mailing">
		<legend>Message</legend>
		<dl>
			<dt>Expéditeur</dt>
			<dd>{$self->getNameAndEmail()}</dd>
			<dt>Destinataire</dt>
			<dd>{$recipient->getNameAndEmail()}</dd>
			{input type="text" name="subject" required=true label="Sujet"}
			{input type="textarea" name="message" required=true label="Message" rows=15}
			{input type="checkbox" name="send_copy" value=1 label="Recevoir par e-mail une copie du message envoyé"}
		</dl>
	</fieldset>

	<p class="submit">
		{csrf_field key=$csrf_key}
		{button type="submit" name="send" label="Envoyer" shape="mail" class="main"}
	</p>
</form>


{include file="_foot.tpl"}