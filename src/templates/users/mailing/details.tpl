{include file="_head.tpl" title="Message collectif : %s"|args:$mailing.subject current="users/mailing" custom_css=["!web/css.php"]}

<nav class="tabs">
	<aside>
		{linkbutton shape="plus" label="Nouveau message" href="new.php" target="_dialog"}
	</aside>
	<ul>
		<li><a href="./">Messages collectifs</a></li>
		<li><a href="rejected.php">Adresses rejetées</a></li>
	</ul>
</nav>

{if $sent}
	<p class="confirm block">L'envoi du message a bien commencé. Il peut prendre quelques minutes avant d'avoir été expédié à tous les destinataires.</p>
{/if}

{form_errors}

<form method="post" action="">
	<dl class="describe">
		{if $mailing.sent}
			<dt>Envoyé le</dt>
			<dd>{$mailing.sent|date_long:true}</dd>
		{else}
			<dt>Statut</dt>
			<dd>
				Brouillon<br />
				{linkbutton shape="edit" label="Modifier" href="write.php?id=%d"|args:$mailing.id}
				{linkbutton shape="delete" label="Supprimer" href="delete.php?id=%d"|args:$mailing.id}
				{if $mailing.body}
				{button shape="right" label="Envoyer" class="main" name="send" type="submit"}
				{/if}
			</dd>
			<dt>Expéditeur</dt>
			<dd>
				{$mailing->getFrom()}<br/>
				<span class="help">Cette adresse peut être modifiée dans la {link label="configuration" href="!config/"}.</span>
			</dd>
		{/if}
		<dt>Destinataires</dt>
		<dd>
			{{%n destinataire}{%n destinataires} n=$mailing->countRecipients()}<br />
			{linkbutton shape="users" label="Voir la liste des destinataires" href="recipients.php?id=%d"|args:$mailing.id}
		</dd>
		<dt>Sujet</dt>
		<dd><strong>{$mailing.subject}</strong></dd>
		<dt>Message</dt>
		<dd><pre>{$mailing.body}</pre></dd>
	</dl>
	{csrf_field key=$csrf_key}
</form>

{include file="_foot.tpl"}