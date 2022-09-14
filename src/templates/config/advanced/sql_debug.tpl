{include file="_head.tpl" title="Journal SQL" current="config" custom_css=["config.css"]}

{include file="config/_menu.tpl" current="advanced" sub_current="sql_debug"}

{if isset($debug)}
	<table class="list multi">
		<thead>
			<tr>
				<th>T.</th>
				<td>Durée</td>
				<td>Trace</td>
			</tr>
		</thead>
		{foreach from=$debug.list item="row"}
		<tbody>
			<tr>
				<th><?=round($row->time / 1000, 2)?></th>
				<td>
					<?php $d = round($row->duration / 1000, 2); ?>
					{if $d > 0.4}
						<h3 class="error">{$d}</h3>
					{else}
						{$d}
					{/if}
				</td>
				<td><pre>{$row.trace}</pre></td>
			</tr>
			<tr>
				<td colspan="3"><h4>Query <a href="sql.php?query={$row.sql|escape:'url'}">[replay]</a></h4><pre>{$row.sql}</pre></td>
			</tr>
			<tr>
				<td colspan="3"><h4>EXPLAIN:</h4><pre>{$row.explain}</pre></td>
			</tr>
		</tbody>
		{/foreach}
	</table>
{elseif isset($list)}
	<p class="help">
		Liste des pages consultées ayant mené à des requêtes SQL.
	</p>

	{if !count($list)}
		<p class="block alert">Aucune requête n'a été trouvée dans le log</p>
	{else}
		<table class="list">
			<thead>
				<tr>
					<th>ID</th>
					<td>Date</td>
					<td>Script</td>
					<td>Membre connecté</td>
					<td>Durée totale des requêtes</td>
					<td>Nombre de requêtes</td>
				</tr>
			</thead>
			<tbody>
				{foreach from=$list item="row"}
				<tr>
					<th><a href="?id={$row.id}">{$row.id}</a></th>
					<td>{$row.date|date_format}</td>
					<td>{$row.script}</td>
					<td>{$row.user}</td>
					<td class="num"><?=$row->duration/1000?> ms</td>
					<td class="num">{$row.count}</td>
				</tr>
				{/foreach}
			</tbody>
		</table>
	{/if}
{/if}

{include file="_foot.tpl"}