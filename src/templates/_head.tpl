<?php
if (!isset($current)) {
    $current = '';
}
?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="fr" lang="fr" class="{if $dialog}dialog{/if}{if $logged_user.preferences.dark_theme} dark{/if}" data-version="{$version_hash}" data-url="{$admin_url}">
<head>
	<meta charset="utf-8" />
	<meta name="v" content="{$version_hash}" />
	<title>{$title}</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<link rel="stylesheet" type="text/css" href="{$admin_url}static/admin.css?{$version_hash}" media="all" />
	<script type="text/javascript" src="{$admin_url}static/scripts/global.js?{$version_hash}"></script>
	{if isset($custom_js)}
		<?php $custom_js = (array)$custom_js; ?>
		{foreach from=$custom_js item="js_url"}
			<script type="text/javascript" src="{$js_url|local_url:"!static/scripts/"}?{$version_hash}"></script>
		{/foreach}
	{/if}
	{if isset($custom_css)}
		<?php $custom_css = (array)$custom_css; ?>
		{foreach from=$custom_css item="css_url"}
			<link rel="stylesheet" type="text/css" href="{$css_url|local_url:"!static/styles/"}?{$version_hash}" media="all" />
		{/foreach}
	{/if}
	{if isset($plugin_css)}
		{foreach from=$plugin_css item="css"}
			<link rel="stylesheet" type="text/css" href="{plugin_url file=$css}?{$version_hash}" />
		{/foreach}
	{/if}
	{if isset($plugin_js)}
		{foreach from=$plugin_js item="js"}
			<script type="text/javascript" src="{plugin_url file=$js}?{$version_hash}"></script>
		{/foreach}
	{/if}
	<link rel="stylesheet" type="text/css" href="{$admin_url}static/print.css?{$version_hash}" media="print" />
	{if isset($logged_user) && $logged_user.preferences.force_handheld}
		<link rel="stylesheet" type="text/css" href="{$admin_url}static/handheld.css?{$version_hash}" media="handheld,screen" />
	{else}
		<link rel="stylesheet" type="text/css" href="{$admin_url}static/handheld.css?{$version_hash}" media="handheld,screen and (max-width:981px)" />
	{/if}
	<link rel="manifest" href="{$admin_url}manifest.php" />
	{if isset($config)}
		<link rel="icon" type="image/png" href="{$config->fileURL('favicon')}" />
	{/if}
	{custom_colors config=$config}
</head>

<body{if !empty($layout)} class="{$layout}"{/if}>

{if !array_key_exists('_dialog', $_GET) && empty($layout)}
<header class="header">
	<nav class="menu">
		<figure class="logo">
		{if isset($config) && ($url = $config->fileURL('logo', '150px'))}
				<a href="{$admin_url}"><img src="{$url}" alt="" /></a>
		{/if}
		</figure>
	<ul>
	{if $is_logged}
	<?php
	$current_parent = substr($current, 0, strpos($current, '/'));
	?>
		<li class="home{if $current == 'home'} current{elseif $current_parent == 'home'} current_parent{/if}"><h3><a href="{$admin_url}">{icon shape="home"}<b>Accueil</b></a></h3>
			{if !empty($plugins_menu)}
				<ul>
				{foreach from=$plugins_menu key="key" item="html"}
					<li{if $current == $key} class="current"{/if}>{$html|raw}</li>
				{/foreach}
				</ul>
			{/if}
		</li>
		{if $session->canAccess($session::SECTION_USERS, $session::ACCESS_READ)}
			<li class="{if $current == 'users'} current{elseif $current_parent == 'users'} current_parent{/if}"><h3><a href="{$admin_url}users/">{icon shape="users"}<b>Membres</b></a></h3>
			<ul>
			{if $session->canAccess($session::SECTION_USERS, $session::ACCESS_WRITE)}
				<li{if $current == 'users/new'} class="current"{/if}><a href="{$admin_url}users/new.php">Ajouter</a></li>
			{/if}
				<li{if $current == 'users/services'} class="current"{/if}><a href="{$admin_url}services/">Activités &amp; cotisations</a></li>
			{if !DISABLE_EMAIL && $session->canAccess($session::SECTION_USERS, $session::ACCESS_WRITE)}
				<li{if $current == 'users/mailing'} class="current"{/if}><a href="{$admin_url}users/mailing.php">Messages collectifs</a></li>
			{/if}
			</ul>
			</li>
		{/if}
		{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ)}
			<li class="{if $current == 'acc'} current{elseif $current_parent == 'acc'} current_parent{/if}"><h3><a href="{$admin_url}acc/">{icon shape="money"}<b>Comptabilité</b></a></h3>
			<ul>
			{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE)}
				<li class="{if $current == 'acc/new'} current{/if}"><a href="{$admin_url}acc/transactions/new.php">Saisie</a></li>
			{/if}
				<li class="{if $current == 'acc/accounts'} current{/if}"><a href="{$admin_url}acc/accounts/">Comptes</a></li>
				<li class="{if $current == 'acc/simple'} current{/if}"><a href="{$admin_url}acc/accounts/simple.php">Suivi des écritures</a></li>
				<li class="{if $current == 'acc/years'} current{/if}"><a href="{$admin_url}acc/years/">Exercices &amp; rapports</a></li>
			</ul>
			</li>
		{/if}

		{if $session->canAccess($session::SECTION_DOCUMENTS, $session::ACCESS_READ)}
			<li class="{if $current == 'docs'} current{elseif $current_parent == 'docs'} current_parent{/if}"><h3><a href="{$admin_url}docs/">{icon shape="folder"}<b>Documents</b></a></h3>
			</li>
		{/if}

		{if $session->canAccess($session::SECTION_WEB, $session::ACCESS_READ)}
			<li class="{if $current == 'web'} current{elseif $current_parent == 'web'} current_parent{/if}"><h3><a href="{$admin_url}web/">{icon shape="globe"}<b>Site web</b></a></h3>
			</li>
		{/if}

		{if $session->canAccess($session::SECTION_CONFIG, $session::ACCESS_ADMIN)}
			<li class="{if $current == 'config'} current{elseif $current_parent == 'config'} current_parent{/if}"><h3><a href="{$admin_url}config/">{icon shape="settings"}<b>Configuration</b></a></h3>
		{/if}

		{if $logged_user->exists()}
		<li class="{if $current == 'me'} current{elseif $current_parent == 'me'} current_parent{/if}"><h3><a href="{$admin_url}me/">{icon shape="user"}<b>Mes infos personnelles</b></a></h3>
			<ul>
				<li{if $current == 'me/services'}  class="current"{/if}><a href="{$admin_url}me/services.php">Mes activités &amp; cotisations</a></li>
			</ul>
		</li>
		{/if}

		{if !defined('Garradin\LOCAL_LOGIN') || !LOCAL_LOGIN}
			<li><h3><a href="{$admin_url}logout.php">{icon shape="logout"}<b>Déconnexion</b></a></h3></li>
		{/if}

		{if $help_url}
		<li>
			<h3><a href="{$help_url}" target="_dialog">{icon shape="help"}<b>Aide</b></a></h3>
		</li>
		{/if}

	{elseif !defined('Garradin\INSTALL_PROCESS')}
        {if $config.org_web || !$config.site_disabled}
		<li><h3><a href="{if $config.site_disabled && $config.org_web}{$config.org_web}{else}{$www_url}{/if}">{icon shape="left"}<b>Retour au site</b></a></h3></li>
        {/if}
		<li{if $current == 'login'} class="current"{/if}><h3><a href="{$admin_url}">{icon shape="login"}<b>Connexion</b></a></h3></li>
	{/if}
	</ul>
	</nav>

	{if empty($hide_title)}
	<h1>{$title}</h1>
	{/if}
</header>
{/if}

<main>