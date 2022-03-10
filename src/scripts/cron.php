<?php

namespace Garradin;

use Garradin\Services\Reminders;

if (PHP_SAPI != 'cli') {
	die("Wrong call");
}

require_once __DIR__ . '/../include/init.php';

// Exécution des tâches automatiques

$config = Config::getInstance();

if (ENABLE_AUTOMATIC_BACKUPS && $config->get('frequence_sauvegardes') && $config->get('nombre_sauvegardes'))
{
	$s = new Sauvegarde;
	$s->auto();
}

// Exécution des rappels automatiques
Reminders::sendPending();

Plugin::fireSignal('cron');
