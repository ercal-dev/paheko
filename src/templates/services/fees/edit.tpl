{include file="_head.tpl" title="%s — Modifier le tarif"|args:$fee.label current="users/services"}

{include file="services/_nav.tpl" current="index" current_service=$service service_page="index"}

{include file="services/fees/_fee_form.tpl" legend="Modifier un tarif" submit_label="Enregistrer"}

{include file="_foot.tpl"}