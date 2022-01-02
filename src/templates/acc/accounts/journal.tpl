{include file="admin/_head.tpl" title="Journal : %s - %s"|args:$account.code:$account.label current="acc/accounts" body_id="rapport"}

{if empty($year)}
	{include file="acc/_year_select.tpl"}
{else}
	<nav class="acc-year">
		<h4>Exercice sélectionné&nbsp;:</h4>
		<h3>{$year.label} — {$year.start_date|date_short} au {$year.end_date|date_short}</h3>
	</nav>
{/if}

{if $account.type}

	{if $simple && $account::isReversed($account.type)}
		{include file="acc/_simple_help.tpl" link="?id=%d&simple=0&year=%d"|args:$account.id,$year.id type=$account.type}
	{/if}

	{if $simple}
		{if $account.type == $account::TYPE_THIRD_PARTY}
			{if $sum < 0}
				<p class="alert block">Vous devez <strong>{$sum|abs|raw|money_currency}</strong> à ce tiers.</p>
			{elseif $sum > 0}
				<p class="alert block">Ce tiers vous doit <strong>{$sum|abs|raw|money_currency}</strong>.</p>
			{else}
				<p class="confirm block">Vous ne devez pas d'argent à ce tiers, et il ne vous en doit pas non plus.</p>
			{/if}
		{elseif $account.type == $account::TYPE_BANK}
			{if $sum < 0}
				<p class="error block">Ce compte est à découvert de <strong>{$sum|abs|raw|money_currency}</strong> à la banque.</p>
			{elseif $sum > 0}
				<p class="confirm block">Ce compte est créditeur de <strong>{$sum|abs|raw|money_currency}</strong> à la banque.</p>
			{/if}
		{elseif $account.type == $account::TYPE_CASH}
			{if $sum < 0}
				<p class="error block">Cette caisse est débiteur de <strong>{$sum|abs|raw|money_currency}</strong>. Est-ce normal&nbsp;? Une vérification est peut-être nécessaire&nbsp;?</p>
			{elseif $sum > 0}
				<p class="confirm block">Cette caisse est créditrice de <strong>{$sum|abs|raw|money_currency}</strong>.</p>
			{/if}
		{elseif $account.type == $account::TYPE_OUTSTANDING}
			{if $sum < 0}
				<p class="error block">Ce compte est débiteur <strong>{$sum|abs|raw|money_currency}</strong>. Est-ce normal&nbsp;? Une vérification est peut-être nécessaire&nbsp;?</p>
			{elseif $sum > 0}
				<p class="confirm block">Ce compte d'attente est créditeur de <strong>{$sum|abs|raw|money_currency}</strong>. {if $sum > 200}Un dépôt à la banque serait peut-être une bonne idée&nbsp;?{/if}</p>
			{/if}
		{elseif $account.type == $account::TYPE_REVENUE && $sum < 0}
			<p class="alert block">Ce compte présente un solde négatif de <strong>{$sum|raw|money_currency}</strong>. Est-ce normal&nbsp;? Cette situation ne devrait se produire que si vous avez dû procéder à des remboursements par exemple, et que ceux-ci couvrent des recettes perçues sur un exercice précédent.</p>
		{elseif $account.type == $account::TYPE_EXPENSE && $sum < 0}
			<p class="alert block">Ce compte présente un solde négatif de <strong>{$sum|raw|money_currency}</strong>. Est-ce normal&nbsp;? Cette situation ne devrait se produire que si vous avez reçu des remboursements par exemple, et que ceux-ci couvrent des dépenses réglées sur un exercice précédent.</p>
		{/if}
	{/if}


	<nav class="tabs">
		<aside>
		{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_ADMIN)}
			{linkbutton href="%s&export=csv"|args:$self_url label="Export CSV" shape="export"}
			{linkbutton href="%s&export=ods"|args:$self_url label="Export tableur" shape="export"}
		{/if}
			{linkbutton shape="search" href="!acc/search.php?year=%d&account=%s"|args:$year.id,$account.code label="Recherche"}
		{if $year.id == CURRENT_YEAR_ID}
			{linkbutton href="!acc/transactions/new.php?account=%d"|args:$account.id label="Saisir une écriture dans ce compte" shape="plus"}
		{/if}
		</aside>
		<ul>
			<li{if $simple} class="current"{/if}><a href="?id={$account.id}&amp;simple=1&amp;year={$year.id}">Vue simplifiée</a></li>
			<li{if !$simple} class="current"{/if}><a href="?id={$account.id}&amp;simple=0&amp;year={$year.id}">Vue comptable</a></li>
		</ul>

	{if !$filter.start && !$filter.end}
	<aside>
		{linkbutton shape="search" href="?start=1" label="Filtrer" onclick="g.toggle('#filterForm', true); this.remove(); return false;"}
	</aside>
	{/if}

	</nav>
{/if}

<form method="get" action="{$self_url}"{if !$filter.start && !$filter.end} class="hidden"{/if} id="filterForm">
	<fieldset>
		<legend>Filtrer par date</legend>
		<p>
			Du
			{input type="date" name="start" source=$filter default=$year.start_date}
			au
			{input type="date" name="end" source=$filter default=$year.end_date}
			<input type="hidden" name="id" value="{$account.id}" />
			<input type="hidden" name="year" value="{$year.id}" />
			<input type="hidden" name="simple" value="{$simple}" />
			<input type="submit" value="Filtrer" />
		</p>
	</fieldset>
</form>

<form method="post" action="{$admin_url}acc/transactions/actions.php">

{include file="common/dynamic_list_head.tpl" check=$can_edit}

	{foreach from=$list->iterate() item="line"}
		<tr>
			{if $can_edit}
			<td class="check">
				{input type="checkbox" name="check[%s]"|args:$line.id_line value=$line.id default=0}
			</td>
			{/if}
			<td class="num"><a href="{$admin_url}acc/transactions/details.php?id={$line.id}">#{$line.id}</a></td>
			<td>{$line.date|date_short}</td>
			{if $simple}
			<td class="money">{if $line.change > 0}+{else}-{/if}{$line.change|abs|raw|money}</td>
			{else}
			<td class="money">{$line.debit|raw|money}</td>
			<td class="money">{$line.credit|raw|money}</td>
			{/if}
			{if isset($line->sum)}
				<td class="money">{$line.sum|raw|money:false}</td>
			{/if}
			<td>{$line.reference}</td>
			<th>{$line.label}</th>
			{if !$simple}<td>{$line.line_label}</td>{/if}
			<td>{$line.line_reference}</td>
			<td class="num">{if $line.id_analytical}<a href="{$admin_url}acc/reports/statement.php?analytical={$line.id_analytical}">{$line.code_analytical}</a>{/if}</td>
			<td class="actions">
			{if ($line.status & Entities\Accounting\Transaction::STATUS_WAITING)}
				{if $line.type == Entities\Accounting\Transaction::TYPE_DEBT}
					{linkbutton shape="check" label="Régler cette dette" href="!acc/transactions/payoff.php?for=%d"|args:$line.id}
				{elseif $line.type == Entities\Accounting\Transaction::TYPE_CREDIT}
					{linkbutton shape="export" label="Régler cette créance" href="!acc/transactions/payoff.php?for=%d"|args:$line.id}
				{/if}
			{/if}

				{linkbutton href="!acc/transactions/details.php?id=%d"|args:$line.id label="Détails" shape="search"}
			</td>
		</tr>
	{/foreach}
	</tbody>
	<tfoot>
		<tr>
			{if $can_edit}
				<td class="check"><input type="checkbox" value="Tout cocher / décocher" id="f_all2" /><label for="f_all2"></label></td>
			{/if}
			{if !$simple}<td></td>{/if}
			{if null !== $sum}
				<td colspan="3">Solde</td>
				<td class="money">{$sum|raw|money:false}</td>
			{else}
				<td colspan="4"></td>
			{/if}
			{if !$simple}<td></td>{/if}
			<td class="actions" colspan="5">
				{if $can_edit}
					<em>Pour les écritures cochées :</em>
					<input type="hidden" name="from" value="{$self_url}" />
					<input type="hidden" name="year" value="{$year.id}" />
					{csrf_field key="projects_action"}
					<select name="action">
						<option value="">— Choisir une action à effectuer —</option>
						<option value="change_analytical">Ajouter/enlever d'un projet</option>
						<option value="delete">Supprimer les écritures</option>
					</select>
					<noscript>
						{button type="submit" value="OK" shape="right" label="Valider"}
					</noscript>
				{/if}
			</td>
		</tr>
	</tfoot>
</table>

</form>

{include file="admin/_foot.tpl"}