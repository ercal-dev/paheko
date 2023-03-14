{include file="_head.tpl" title="Extensions" current="config"}

{include file="config/_menu.tpl" current="ext"}

<nav class="tabs">

	<ul class="sub">
		<li{if !$installable} class="current"{/if}><a href="./">Activées</a></li>
		<li{if $installable} class="current"{/if}><a href="./?install=1">Inactives</a></li>
	</ul>
</nav>

{if !empty($url_plugins)}
<p class="actions">
	{linkbutton shape="help" href=$url_plugins label="Trouver d'autres extensions à installer" target="_blank"}
</p>
{/if}

<p class="help">Les extensions apportent des fonctionnalités supplémentaires, et peuvent être activées selon vos besoins.</p>

{form_errors}

<form method="post" action="">
	<table class="list">
		<thead>
			<td></td>
			<td>Extension</td>
			<td>Accès restreint</td>
			<td></td>
			<td></td>
			<td></td>
			<td></td>
		</thead>
		<tbody>
			{foreach from=$list item="item"}
			<tr {if $_GET.focus == $item.name}class="highlight"{/if}>
			{if $item.broken_message}
				<td></td>
				<td colspan="6">
					<strong class="error">Extension cassée : {$item.name}</strong><br />
					{$item.broken_message}
				</td>
			{else}
				<td class="icon">
					{if $item.icon_url}
						<svg><use xlink:href='{$item.icon_url}#img' href="{$item.icon_url}#img"></use></svg>
						{if $item.url}
							</a>
						{/if}
					{/if}
				</td>
				<td>
					<h3>{if $item.label}{$item.label}{else}{$item.name}{/if}
						{if $item.module && $item.module->canDelete()}
							<strong class="tag">Modifiée</strong>
						{elseif $item.module}
							<span class="tag">Modifiable</span>
						{/if}
					</h3>
					<small>{$item.description|escape|nl2br}</small><br />
					<small class="help">
						{if $item.author}
							Par {link label=$item.author href=$item.author_url target="_blank"}
						{/if}
						{if $item.plugin && $item.plugin.version}— Version {$item.plugin.version}{/if}
						{if $item.readme_url}
							— {link href=$item.readme_url label="Documentation" target="_dialog"}
						{/if}
					</small>
				</td>
				{if $item.broken}
					<td colspan="5">
						{if ENABLE_TECH_DETAILS}
							<strong>Le code source de l'extension est absent du répertoire <tt>…/data/plugins/</tt></strong>
						{else}
							<strong>Cette extension n'est pas installée sur ce serveur.</strong><br />
						{/if}
						<br />
						<small>Il n'est pas possible de la supprimer non plus, le code source est nécessaire pour pouvoir la supprimer.</small>
					</td>
				{else}
					<td>
						{if $item.restrict_section}
							<span class="permissions">{display_permissions section=$item.restrict_section level=$item.restrict_level}</span>
						{/if}
					</td>
					<td>
						{if $item.enabled && $item.url}
							{linkbutton shape="right" label="Ouvrir" href=$item.url}
						{/if}
					</td>
					<td class="actions">
						{if $item.module && $item.enabled}
							{if $item.module->hasLocal() && $item.module->hasDist()}
								{linkbutton label="Remettre à zéro" href="delete.php?module=%s"|args:$item.name shape="reset" target="_dialog"}
							{/if}
							{*FIXME{linkbutton label="Modifier" href="edit.php?module=%s"|args:$item.name shape="edit" target="_dialog"}*}
						{elseif $item.module && !$item.enabled && $item.module->canDelete()}
							{linkbutton label="Supprimer" href="delete.php?module=%s"|args:$item.name shape="delete" target="_dialog"}
						{elseif $item.plugin && !$item.enabled && $item.installed}
							{linkbutton label="Supprimer" href="delete.php?plugin=%s"|args:$item.name shape="delete" target="_dialog"}
						{/if}
					</td>
					<td class="actions">
						{if $item.config_url && $item.enabled}
							{linkbutton label="Configurer" href=$item.config_url shape="settings" target="_dialog"}
						{/if}
					</td>
					<td class="actions">
						{if $item.module}
							{if $item.enabled}
								{button type="submit" label="Désactiver" shape="eye-off" name="disable_module" value=$item.name}
							{else}
								{button type="submit" label="Activer" shape="eye" name="enable" value=$item.name}
							{/if}
						{else}
							{if $item.enabled}
								{button type="submit" label="Désactiver" shape="eye-off" name="disable_plugin" value=$item.name}
							{else}
								{button type="submit" label="Activer" shape="eye" name="install" value=$item.name}
							{/if}
						{/if}
					</td>
				{/if}
			{/if}
			</tr>
			{/foreach}
		</tbody>
	</table>
	{csrf_field key=$csrf_key}
</form>

<p class="help">
	La mention <em class="tag">Modifiable</em> indique que cette extension est un module que vous pouvez modifier. {linkbutton shape="help" label="Comment modifier et développer des modules" href="!static/doc/modules.html" target="_dialog"}
</p>

{include file="_foot.tpl"}