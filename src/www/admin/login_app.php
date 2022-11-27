<?php
namespace Garradin;

use Garradin\Files\WebDAV\Session as AppSession;
use Garradin\Entities\Files\File;

const LOGIN_PROCESS = true;

require_once __DIR__ . '/_inc.php';

$session = AppSession::getInstance();

$session->requireAccess($session::SECTION_DOCUMENTS, $session::ACCESS_READ);

$app_token = $_GET['app'] ?? null;

if (!$app_token) {
	die("No app token was supplied.");
}

$csrf_key = 'app_confirm_' . $app_token;

$form->runIf('cancel', function () use ($app_token, $session) {
	$session->logout();
	Utils::redirect('!login.php?app=' . $app_token);
});

$form->runIf('confirm', function () use ($app_token, $session) {
	if ($app_token == 'redirect') {
		$data = $session->createAppCredentials();
	}
	else {
		$session->validateAppToken($app_token);
	}

	if ($data->redirect ?? null) {
		http_response_code(303);
		header('Location: ' . $data->redirect);
		exit;
	}

	Utils::redirect('!login_app.php?app=ok');
}, $csrf_key);

$permissions = $session->getFilePermissions(File::CONTEXT_DOCUMENTS);

$tpl->assign(compact('app_token', 'csrf_key', 'permissions'));

$tpl->display('login_app.tpl');
