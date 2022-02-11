{include file="admin/_head.tpl" title="Écriture n°%d"|args:$transaction.id current="acc"}

<nav class="tabs">
{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_ADMIN) && !$transaction->validated && !$tr_year->closed}
	{linkbutton href="edit.php?id=%d"|args:$transaction.id shape="edit" label="Modifier cette écriture"}
	{linkbutton href="delete.php?id=%d"|args:$transaction.id shape="delete" label="Supprimer cette écriture"}
{/if}
{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE)}
	{linkbutton href="new.php?copy=%d"|args:$transaction.id shape="plus" label="Dupliquer cette écriture"}
{/if}
</nav>

{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE) && $transaction.status & $transaction::STATUS_WAITING}
<div class="block alert">
	<form method="post" action="{$self_url}">
	{if $transaction.type == $transaction::TYPE_DEBT}
		<h3>Dette en attente</h3>
		{linkbutton shape="check" label="Enregistrer le règlement de cette dette" href="!acc/transactions/payoff.php?for=%d"|args:$transaction.id}
	{else}
		<h3>Créance en attente</h3>
		{linkbutton shape="export" label="Enregistrer le règlement de cette créance" href="!acc/transactions/payoff.php?for=%d"|args:$transaction.id}
	{/if}
		{button type="submit" shape="check" label="Marquer manuellement comme réglée" name="mark_paid" value="1"}
		{csrf_field key=$csrf_key}
	</form>
</div>
{/if}

{if $transaction.status & $transaction::STATUS_ERROR}
<div class="error block">
	<p>Cette écriture est erronée suite à un bug. Il est conseillé de la modifier pour remettre les comptes manquants, ou la supprimer et la re-créer.
	Voir <a href="https://fossil.kd2.org/garradin/wiki?name=Changelog#1_0_1" target="_blank">cette page pour plus d'explications</a></p>
	<p>Les lignes erronées sont affichées en bas de cette page.</p>
	<p><em>(Ce message disparaîtra si vous modifiez l'écriture pour la corriger.)</em></p>
</div>
{/if}

<dl class="describe">
	{if $transaction.id_related}
	<dt>Écriture liée à</dt>
	<dd><a href="{$admin_url}acc/transactions/details.php?id={$transaction.id_related}">#{$transaction.id_related}</a>
		{if $transaction.type == $transaction::TYPE_DEBT || $transaction.type == $transaction::TYPE_CREDIT}(en règlement de){/if}
	</dd>
	{/if}
	<dt>Type</dt>
	<dd>
		{$transaction->getTypeName()}
	</dd>
	<dt>Libellé</dt>
	<dd><h2>{$transaction.label}</h2></dd>
	<dt>Date</dt>
	<dd>{$transaction.date|date:'l j F Y (d/m/Y)'}</dd>
	<dt>Numéro pièce comptable</dt>
	<dd>{if $transaction.reference}{$transaction.reference}{else}-{/if}</dd>

	<dt>Exercice</dt>
	<dd>
		<a href="{$admin_url}acc/reports/ledger.php?year={$transaction.id_year}">{$tr_year.label}</a>
		| Du {$tr_year.start_date|date_short} au {$tr_year.end_date|date_short}
		| <strong>{if $tr_year.closed}Clôturé{else}En cours{/if}</strong>
	</dd>

	<dt>Écriture créée par</dt>
	<dd>
		{if $transaction.id_creator}
			{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_READ)}
				<a href="{$admin_url}membres/fiche.php?id={$transaction.id_creator}">{$creator_name}</a>
			{else}
				{$creator_name}
			{/if}
		{else}
			<em>membre supprimé</em>
		{/if}
	</dd>

	<dt>Écriture liée à</dt>
	{if empty($related_users)}
		<dd><em>Aucun membre n'est lié à cette écriture.</em></dd>
	{else}
		{foreach from=$related_users item="u"}
			<dd>
				<a href="{$admin_url}membres/fiche.php?id={$u.id}">{$u.identity}</a>
				{if $u.id_service_user}— en règlement d'une <a href="{$admin_url}services/user/?id={$u.id}&amp;only={$u.id_service_user}">activité</a>{/if}
			</dd>
		{/foreach}
	{/if}

	<dt>Remarques</dt>
	<dd>{if $transaction.notes}{$transaction.notes|escape|nl2br}{else}-{/if}</dd>
</dl>

<table class="list">
	<thead>
		<tr>
			<td class="num">N° compte</td>
			<th>Compte</th>
			<td class="money">Débit</td>
			<td class="money">Crédit</td>
			<td>Libellé</td>
			<td>Référence</td>
			<td>Projet</td>
		</tr>
	</thead>
	<tbody>
		{foreach from=$transaction->getLinesWithAccounts(false) item="line"}
		<tr>
			<td class="num"><a href="{$admin_url}acc/accounts/journal.php?id={$line.id_account}&amp;year={$transaction.id_year}">{$line.account_code}</a></td>
			<td>{$line.account_name}</td>
			<td class="money">{if $line.debit}{$line.debit|escape|money}{/if}</td>
			<td class="money">{if $line.credit}{$line.credit|escape|money}{/if}</td>
			<td>{$line.label}</td>
			<td>{$line.reference}</td>
			<td>
				{if $line.id_analytical}
					<a href="{$admin_url}acc/reports/statement.php?analytical={$line.id_analytical}">{$line.analytical_name}</a>
				{/if}
			</td>
		</tr>
		{/foreach}
	</tbody>
</table>

{if $files_edit || count($files)}
<div class="attachments">
	<h3 class="ruler">Fichiers joints</h3>
	{include file="common/files/_context_list.tpl" files=$files edit=$files_edit path=$file_parent}
</div>
{/if}

{include file="admin/_foot.tpl"}