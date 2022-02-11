{include file="admin/_head.tpl" title="Saisie d'une écriture" current="acc/new"}

{include file="acc/_year_select.tpl"}

<form method="post" action="{$self_url}" data-focus="1">
	{form_errors}

	<input type="hidden" name="type" value="{$transaction.type}" />
	<input type="hidden" name="{$payoff_for.form_account_name}[{$payoff_for.id_account}]" value="-" />
	<fieldset>
		<legend>{if $payoff_for.type == $transaction::TYPE_DEBT}Règlement de dette{else}Règlement de créance{/if}</legend>
		<dl>
			<dt>Écriture d'origine</dt>
			<dd>{link class="num" href="!acc/transactions/details.php?id=%d"|args:$payoff_for.id label="#%d"|args:$payoff_for.id}</dd>
			{input type="checkbox" name="mark_paid" value="1" default="1" label="Marquer comme payée"}
			{input type="list" target="!acc/charts/accounts/selector.php?targets=%s&chart=%d"|args:$payoff_targets,$chart_id name=$payoff_for.form_target_name label="Compte de règlement" required=1}
		</dl>
	</fieldset>

	<fieldset>
		<legend>Informations</legend>
		<dl>
			{input type="date" name="date" label="Date" required=1 source=$transaction}
			{input type="text" name="label" label="Libellé" required=1 source=$transaction}
			{input type="text" name="reference" label="Numéro de pièce comptable" help="Numéro de facture, de reçu, de note de frais, etc."}
		</dl>
		<dl data-types="all-but-advanced">
			{input type="money" name="amount" label="Montant" required=1 default=$amount}
		</dl>
	</fieldset>

	<fieldset>
		<legend>Détails facultatifs</legend>
		<dl data-types="t{$transaction::TYPE_REVENUE} t{$transaction::TYPE_EXPENSE} t{$transaction::TYPE_TRANSFER}">
			{input type="text" name="payment_reference" label="Référence de paiement" help="Numéro de chèque, numéro de transaction CB, etc." source=$transaction}
		</dl>
		<dl>
			{input type="list" multiple=true name="users" label="Membres associés" target="!membres/selector.php"}
			{input type="textarea" name="notes" label="Remarques" rows=4 cols=30}
		</dl>
		<dl data-types="all-but-advanced">
			{if count($analytical_accounts) > 1}
				{input type="select" name="id_analytical" label="Projet (compte analytique)" options=$analytical_accounts default=$id_analytical}
			{/if}
		</dl>
	</fieldset>

	<p class="submit">
		{csrf_field key="acc_transaction_new"}
		{button type="submit" name="save" label="Enregistrer" shape="right" class="main"}
	</p>

</form>

<script type="text/javascript" defer="defer" async="async">
{literal}
g.script('scripts/accounting.js', () => { initTransactionForm(false); });
</script>
{/literal}

{include file="admin/_foot.tpl"}