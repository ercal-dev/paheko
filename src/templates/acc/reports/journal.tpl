{include file="admin/_head.tpl" title="%sJournal général"|args:$project_title current="acc/years"}

{include file="acc/reports/_header.tpl" current="journal" title="Journal général" allow_filter=true}

{include file="acc/reports/_journal.tpl"}

<p class="help">Toutes les écritures sont libellées en {$config.monnaie}.</p>

{include file="admin/_foot.tpl"}