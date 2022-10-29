{include file="admin/_head.tpl" title="Plan comptable"|args:$chart.label current="acc/charts"}

{include file="acc/charts/accounts/_nav.tpl" current="all"}

<p class="help">
	Les comptes marqués comme «&nbsp;<em>Ajouté</em>&nbsp;» ont été ajoutés au plan comptable officiel par vous-même.
</p>

<table class="accounts">
	<tbody>
	{foreach from=$accounts item="account"}
		<tr class="account-level-{$account.code|strlen}">
			<td>{$account.code}</td>
			<th>{$account.label}</th>
			<td>
				{if $account.bookmark}
					{icon shape="star"} Favori
				{/if}
			</td>
			<td>
				{if $account.user}<em>Ajouté</em>{/if}
			</td>
			<td class="actions">
				{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_ADMIN) && !$chart.archived}
					{if $account.user || !$chart.code}
						{linkbutton shape="delete" label="Supprimer" href="!acc/charts/accounts/delete.php?id=%d"|args:$account.id}
					{/if}
					{linkbutton shape="edit" label="Modifier" href="!acc/charts/accounts/edit.php?id=%d"|args:$account.id}
				{/if}
			</td>
		</tr>
	{/foreach}
	</tbody>
</table>


{include file="admin/_foot.tpl"}