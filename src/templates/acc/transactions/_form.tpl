<?php

$is_new = empty($_POST) && !isset($transaction->type) && !$transaction->exists() && !$transaction->label;
$is_quick = count(array_intersect_key($_GET, array_flip(['a', 'l', 'd', 't', 'account']))) > 0;

?>
<form method="post" action="{$self_url}" data-focus="{if $is_new || $is_quick}1{else}#f_date{/if}">
	{form_errors}

	<fieldset>
		<legend>Type d'écriture</legend>
		<dl>
		{foreach from=$types_details item="type"}
			<dd class="radio-btn">
				{input type="radio" name="type" value=$type.id source=$transaction label=null}
				<label for="f_type_{$type.id}">
					<div>
						<h3>{$type.label}</h3>
						{if !empty($type.help)}
							<p class="help">{$type.help}</p>
						{/if}
					</div>
				</label>
			</dd>
		{/foreach}
		</dl>
	</fieldset>

	<fieldset{if $is_new} class="hidden"{/if}>
		<legend>Informations</legend>
		<dl>
			{input type="date" name="date" label="Date" required=1 source=$transaction}
			{input type="text" name="label" label="Libellé" required=1 source=$transaction}
			{input type="text" name="reference" label="Numéro de pièce comptable" help="Numéro de facture, de reçu, de note de frais, etc." source=$transaction}
		</dl>
		<dl data-types="all-but-advanced">
			{input type="money" name="amount" label="Montant" required=1 default=$amount}
		</dl>
	</fieldset>

	{foreach from=$types_details item="type"}
		<fieldset data-types="t{$type.id}"{if $is_new} class="hidden"{/if}>
			<legend>{$type.label}</legend>
			{if $type.id == $transaction::TYPE_ADVANCED}
				{* Saisie avancée *}
				{include file="acc/transactions/_lines_form.tpl" chart_id=$current_year.id_chart}
			{else}
				<dl>
				{foreach from=$type.accounts key="key" item="account"}
					{input type="list" target="!acc/charts/accounts/selector.php?targets=%s&chart=%d"|args:$account.targets_string,$chart_id name=$account.selector_name label=$account.label required=1 default=$account.selector_value}
				{/foreach}
				</dl>
			{/if}
		</fieldset>
	{/foreach}

	<fieldset{if $is_new} class="hidden"{/if}>
		<legend>Détails facultatifs</legend>
		<dl data-types="t{$transaction::TYPE_REVENUE} t{$transaction::TYPE_EXPENSE} t{$transaction::TYPE_TRANSFER}">
			{input type="text" name="payment_reference" label="Référence de paiement" help="Numéro de chèque, numéro de transaction CB, etc." default=$transaction->payment_reference()}
		</dl>
		<dl>
			{input type="list" multiple=true name="users" label="Membres associés" target="!users/selector.php" default=$linked_users}
			{input type="textarea" name="notes" label="Remarques" rows=4 cols=30 source=$transaction}
		</dl>
		<dl data-types="t{$transaction::TYPE_ADVANCED} t{$transaction::TYPE_DEBT} t{$transaction::TYPE_CREDIT}">
			{input type="number" name="id_related" label="Lier à l'écriture numéro" source=$transaction help="Indiquer ici un numéro d'écriture pour faire le lien par exemple avec une dette"}
		</dl>
		<dl data-types="all-but-advanced">
			{if count($projects) > 1}
				{input type="select" name="id_project" label="Projet (analytique)" options=$projects default=$id_project}
			{/if}
		</dl>
	</fieldset>

	<p class="submit{if $is_new} hidden{/if}">
		{csrf_field key=$csrf_key}
		{button type="submit" name="save" label="Enregistrer" shape="right" class="main"}
	</p>

{if $session->canAccess($session::SECTION_ACCOUNTING, $session::ACCESS_WRITE)}
	<p class="submit help{if $is_new} hidden{/if}">
		Vous pourrez ajouter des fichiers à cette écriture une fois qu'elle aura été enregistrée.
	</p>
{/if}

</form>

<script type="text/javascript" async="async">
let is_new = {$is_new|escape:'json'};
{literal}
window.addEventListener('load', () => {
	g.script('scripts/accounting.js', () => { initTransactionForm(is_new && !$('.block').length); });
});
</script>
{/literal}