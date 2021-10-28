<nav class="tabs">
	<ul>
		<li{if $current == 'index'} class="current"{/if}><a href="{$admin_url}membres/">Liste des membres</a></li>
		<li{if $current == 'recherche'} class="current"{/if}><a href="{$admin_url}membres/recherche.php">Recherche avancée</a></li>
		<li{if $current == 'recherches'} class="current"{/if}><a href="{$admin_url}membres/recherches.php">Recherches enregistrées</a></li>
		{if $session->canAccess($session::SECTION_USERS, $session::ACCESS_ADMIN)}
			<li{if $current == 'import'} class="current"{/if}><a href="{$admin_url}membres/import.php">Import &amp; export</a></li>
		{/if}
	</ul>
</nav>