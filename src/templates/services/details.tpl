{include file="admin/_head.tpl" title="%s — Liste des membres inscrits"|args:$service.label current="membres/services"}

{include file="services/_nav.tpl" current="index" current_service=$service service_page=$type}

<dl class="cotisation">
	<dt>Nombre de membres trouvés</dt>
	<dd>
		{$list->count()}
		<em class="help">(N'apparaît ici que l'inscription la plus récente de chaque membre.)</em>
		{if $session->canAccess($session::SECTION_USERS, $session::ACCESS_ADMIN)}
		<nav class="menu">
			<b data-icon="↷" class="btn">Export</b>
			<span>
				{linkbutton href="%s&export=csv"|args:$self_url shape="export" label="Export CSV"}
				{linkbutton href="%s&export=ods"|args:$self_url shape="export" label="Export LibreOffice"}
				{if CALC_CONVERT_COMMAND}
					{linkbutton href="%s&export=xlsx"|args:$self_url shape="export" label="Export Excel"}
				{/if}
			</span>
		</nav>
		{/if}
	</dd>
</dl>

<?php
$can_action = $session->canAccess($session::SECTION_USERS, $session::ACCESS_ADMIN);
?>

{if $can_action}
	<form method="post" action="{"!membres/action.php"|local_url}">
{/if}

{include file="common/dynamic_list_head.tpl" check=$can_action}

	{foreach from=$list->iterate() item="row"}
		<tr>
			{if $can_action}
			<td class="check">{input type="checkbox" name="selected[]" value=$row.id_user}</td>
			{/if}

			<th><a href="../membres/fiche.php?id={$row.id_user}">{$row.identity}</a></th>
			<td>
				{if $row.status == 1 && $row.end_date}
					En cours
				{elseif $row.status == 1}
					<b class="confirm">À jour</b>
				{elseif $row.status == -1 && $row.end_date}
					Terminée
				{elseif $row.status == -1}
					<b class="error">En retard</b>
				{else}
					Pas d'expiration
				{/if}
			</td>
			<td>{if $row.paid}<b class="confirm">Oui</b>{else}<b class="error">Non</b>{/if}</td>
			<td>{$row.expiry|date_short}</td>
			<td>{$row.fee}</td>
			<td>{$row.date|date_short}</td>
			<td class="actions">
				{linkbutton shape="user" label="Toutes les activités de ce membre" href="!services/user/?id=%d"|args:$row.id_user}
				{linkbutton shape="alert" label="Rappels envoyés" href="!services/reminders/user.php?id=%d"|args:$row.id_user}
			</td>
		</tr>
	{/foreach}

	</tbody>
	{if $can_action}
		{include file="admin/membres/_list_actions.tpl" colspan=7 export=false hide_delete=true}
	{/if}

</table>

{if $can_action}
</form>
{/if}

{pagination url=$list->paginationURL() page=$list.page bypage=$list.per_page total=$list->count()}


{include file="admin/_foot.tpl"}