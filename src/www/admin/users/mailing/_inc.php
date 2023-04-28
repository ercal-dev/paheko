<?php

namespace Garradin;

use Garradin\Users\Session;

require_once __DIR__ . '/../_inc.php';

$session = Session::getInstance();
$session->requireAccess($session::SECTION_USERS, $session::ACCESS_WRITE);
