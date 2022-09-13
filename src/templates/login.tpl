{if $api_login}<?php $layout = 'public'; ?>{/if}
{include file="_head.tpl" title="Connexion"}

{form_errors}

{if $api_login == 'ok'}
	<p class="block confirm">Vous avez bien été connecté.</p>
	<div class="progressing block"></div>
	<p class="help">Vous pourrez fermer cette fenêtre quand l'application aura terminé l'autorisation.</p>
{else}
	{if $changed}
		<p class="block confirm">
			Votre mot de passe a bien été modifié.<br />
			Vous pouvez maintenant l'utiliser pour vous reconnecter.
		</p>
	{/if}

	<p class="block error" style="display: none;" id="old_browser">
		Le navigateur que vous utilisez n'est pas supporté. Des fonctionnalités peuvent ne pas fonctionner.<br />
		Merci d'utiliser un navigateur web moderne comme <a href="https://www.getfirefox.com/" target="_blank">Firefox</a> ou <a href="https://vivaldi.com/fr/" target="_blank">Vivaldi</a>.
	</p>

	<form method="post" action="{$self_url}">
		{if $api_login && $api_login != 'ok'}
			<p class="alert block">Une application tiers demande à accéder aux données de l'association.</p>
			<p class="help">L'application aura accès à tous vos fichiers.</p>
		{/if}

		<fieldset>
			<legend>
				{if $ssl_enabled}
					<span class="confirm">{icon shape="lock"} Connexion sécurisée</span>
				{else}
					<span class="alert">{icon shape="unlock"} Connexion non-sécurisée</span>
				{/if}
			</legend>
			<dl>
				{input type=$id_field.type label=$id_field.label required=true name="id"}
				{input type="password" name="password" label="Mot de passe" required=true}
				{if !$api_login}
				{input type="checkbox" name="permanent" value="1" label="Rester connecté⋅e" help="recommandé seulement sur ordinateur personnel"}
				{/if}
			</dl>
		</fieldset>

		<p class="submit">
			{csrf_field key="login"}
			{button type="submit" name="login" label="Se connecter" shape="right" class="main"}
			{if $api_login}
				<input type="hidden" name="token" value="{$api_login}" />
			{else}
				{linkbutton href="!password.php" label="Mot de passe perdu ?" shape="help"}
				{linkbutton href="!password.php?new" label="Première connexion ?" shape="user"}
			{/if}
		</p>

	</form>

	{literal}
	<script type="text/javascript" async="async">
	if (window.navigator.userAgent.match(/MSIE|Trident\/|Edge\//)) {
		document.getElementById('old_browser').style.display = 'block';
	}
	</script>
	{/literal}
{/if}

{include file="_foot.tpl"}