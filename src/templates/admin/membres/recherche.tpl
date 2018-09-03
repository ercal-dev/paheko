{include file="admin/_head.tpl" title="Recherche de membre" current="membres" js=1 custom_js=['sql_query_builder.js']}

{if $session->canAccess('membres', Garradin\Membres::DROIT_ADMIN)}
<ul class="actions">
	<li><a href="{$admin_url}membres/">Liste des membres</a></li>
	<li class="current"><a href="{$admin_url}membres/recherche.php">Recherche avancée</a></li>
	<li><a href="{$admin_url}membres/recherche_sql.php">Recherche par requête SQL</a></li>
</ul>
{/if}

<form method="post" action="{$admin_url}membres/recherche.php" id="queryBuilderForm">
	<fieldset>
		<legend>Rechercher un membre</legend>
		<div class="queryBuilder" id="queryBuilder"></div>
		<p class="actions">
			<label>Trier par 
				<select name="order">
					{foreach from=$colonnes key="colonne" item="config"}
					<option value="{$colonne}"{form_field name="order" selected=$colonne}>{$config.label}</option>
					{/foreach}
				</select>
			</label>
			<label><input type="checkbox" name="desc" value="1" {form_field name="desc" checked=1 default=$desc} /> Tri inversé</label>
			<label>Limiter à <input type="number" value="{$limit}" name="limit" size="5" /> résultats</label>
		</p>
		<p class="submit">
			<input type="submit" value="Chercher &rarr;" id="send" />
			<input type="hidden" name="q" id="jsonQuery" />
		</p>
	</fieldset>
	<p class="help">{$sql_query}</p>
</form>

<script type="text/javascript">
var colonnes = {$colonnes|escape:'json'};

{literal}
var traductions = {
	"after": "après",
	"before": "avant",
	"is equal to": "est égal à",
	"is equal to one of": "est égal à une des ces options",
	"is not equal to one of": "n'est pas égal à une des ces options",
	"is not equal to": "n'est pas égal à",
	"is greater than": "est supérieur à",
	"is greater than or equal to": "est supérieur ou égal à",
	"is less than": "est inférieur à",
	"is less than or equal to": "est inférieur ou égal à",
	"is between": "est situé entre",
	"is not between": "n'est pas situé entre",
	"is null": "est nul",
	"is not null": "n'est pas nul",
	"begins with": "commence par",
	"doesn't begin with": "ne commence pas par",
	"ends with": "se termine par",
	"doesn't end with": "ne se termine pas par",
	"contains": "contient",
	"doesn't contain": "ne contient pas",
	"matches one of": "correspond à",
	"is true": "oui",
	"is false": "non",
	"Matches ALL of the following conditions:": "Correspond à TOUS les critères suivants :",
	"Matches ANY of the following conditions:": "Correspond à UN SEUL des critères suivants :",
	"Add a new set of conditions below this one": "-- Ajouter un groupe de critères",
	"Remove this set of conditions": "-- Supprimer ce groupe de critères"
};

var q = new SQLQueryBuilder(colonnes);
q.__ = function (str) {
	return traductions[str];
};
q.loadDefaultOperators();
q.buildInput = function (type, label, column) {
	if (label == '+')
	{
		label = '➕';
	}
	else if (label == '-')
	{
		label = '➖';
	}

	var i = document.createElement('input');
	console.log(type);
	i.type = type == 'integer' ? 'number' : type;
	i.value = label;

	if (type == 'button')
	{
		i.className = 'icn action';
	}

	return i;
};
q.init(document.getElementById('queryBuilder'));

$('#queryBuilderForm').onsubmit = function () {
	$('#jsonQuery').value = JSON.stringify(q.export());
};
{/literal}
q.import({$query|escape:'json'});
</script>


{if $session->canAccess('membres', Garradin\Membres::DROIT_ECRITURE)}
	<form method="post" action="{$admin_url}membres/action.php" class="memberList">
{/if}

{if !empty($result)}
	<table class="list search">
		<thead>
			<tr>
				{if $session->canAccess('membres', Garradin\Membres::DROIT_ADMIN)}<td class="check"><input type="checkbox" value="Tout cocher / décocher" onclick="g.checkUncheck();" /></td>{/if}
				{foreach from=$result_header key="c" item="cfg"}
					<td>{$cfg.title}</td>
				{/foreach}
				<td></td>
			</tr>
		</thead>
		<tbody>
			{foreach from=$result item="row"}
				<tr>
					{if $session->canAccess('membres', Garradin\Membres::DROIT_ADMIN)}<td class="check"><input type="checkbox" name="selected[]" value="{$row.id}" /></td>{/if}
					{foreach from=$row key="key" item="value"}
						{if isset($result_header[$key])}
							<td>{$value|raw|display_champ_membre:$result_header[$key]}</td>
						{/if}
					{/foreach}
					<td class="actions">
						<a class="icn" href="{$admin_url}membres/fiche.php?id={$row.id}" title="Fiche membre">👤</a>
						{if $session->canAccess('membres', Garradin\Membres::DROIT_ECRITURE)}
						<a class="icn" href="{$admin_url}membres/modifier.php?id={$row.id}" title="Modifier la fiche membre">✎</a>
						{/if}
					</td>
				</tr>
			{/foreach}
		</tbody>
	</table>

	{if $session->canAccess('membres', Garradin\Membres::DROIT_ADMIN)}
	<p class="checkUncheck">
		<input type="button" value="Tout cocher / décocher" onclick="g.checkUncheck();" />
	</p>
	<p class="actions">
		<em>Pour les membres cochés :</em>
		<input type="submit" name="move" value="Changer de catégorie" />
		<input type="submit" name="delete" value="Supprimer" />
		{csrf_field key="membres_action"}
	</p>
	{/if}

{elseif $result !== null}

	<p class="alert">
		Aucun membre trouvé.
	</p>

	</form>
{/if}

{if $session->canAccess('membres', Garradin\Membres::DROIT_ECRITURE)}
	</form>
{/if}

{include file="admin/_foot.tpl"}