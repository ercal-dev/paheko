<?php
namespace Garradin;

use Garradin\Users\Session;

require_once __DIR__ . '/_inc.php';

$user = Session::getLoggedUser();
$user->downloadExport();
