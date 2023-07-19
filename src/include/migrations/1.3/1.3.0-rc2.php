<?php

namespace Garradin;

use Garradin\Users\DynamicFields;

$db->beginSchemaUpdate();

$fields = DynamicFields::getInstance();

foreach ($fields->all() as $field) {
	if ($field->type == 'generated') {
		$field->delete();
	}
}

$fields->save();

$db->commitSchemaUpdate();
