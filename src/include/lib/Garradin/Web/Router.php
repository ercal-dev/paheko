<?php

namespace Garradin\Web;

use Garradin\Files\Files;
use Garradin\Entities\Files\File;
use Garradin\Files\WebDAV\Server as WebDAV_Server;

use Garradin\Web\Web;

use Garradin\API;
use Garradin\Config;
use Garradin\Plugins;
use Garradin\UserException;
use Garradin\Utils;
use Garradin\UserTemplate\Modules;

use Garradin\Users\Session;

use \KD2\HTML\Markdown;

use const Garradin\{WWW_URI, ADMIN_URL, ROOT, HTTP_LOG_FILE, ENABLE_XSENDFILE};

class Router
{
	const DAV_ROUTES = [
		'dav',
		'wopi',
		'remote.php',
		'index.php',
		'status.php',
		'ocs',
		'avatars',
	];

	static public function route(string $uri = null): void
	{
		$uri ??= !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

		$uri = parse_url($uri, \PHP_URL_PATH);

		// WWW_URI inclus toujours le slash final, mais on veut le conserver ici
		$uri = substr($uri, strlen(WWW_URI) - 1);

		// This might be changed later
		http_response_code(200);

		$uri = substr($uri, 1);

		$first = ($pos = strpos($uri, '/')) ? substr($uri, 0, $pos) : null;
		$method = $_SERVER['REQUEST_METHOD'] ?? $_SERVER['REDIRECT_REQUEST_METHOD'];

		if (HTTP_LOG_FILE) {
			$qs = $_SERVER['QUERY_STRING'] ?? null;
			$headers = apache_request_headers();

			self::log("===== ROUTER: Got new request: %s from %s =====", date('d/m/Y H:i:s'), $_SERVER['REMOTE_ADDR']);

			self::log("ROUTER: <= %s %s\nRequest headers:\n  %s",
				$method,
				$uri . ($qs ? '?' : '') . $qs,
				implode("\n  ", array_map(fn ($v, $k) => $k . ': ' . $v, $headers, array_keys($headers)))
			);

			if ($method != 'GET' && $method != 'OPTIONS' && $method != 'HEAD') {
				self::log("ROUTER: <= Request body:\n%s", file_get_contents('php://input'));
			}
		}

		// Redirect old URLs (pre-1.1)
		if ($uri == 'feed/atom/') {
			Utils::redirect('/atom.xml');
		}
		elseif ($uri == 'favicon.ico') {
			header('Location: ' . Config::getInstance()->fileURL('favicon'), true);
			return;
		}
		elseif (preg_match('!^(?:admin/p|p|m)/\w+$!', $uri)) {
			Utils::redirect('/' . $uri . '/');
		}
		elseif (preg_match('!^(admin/p|p)/(' . Plugins::NAME_REGEXP . ')/(.*)$!', $uri, $match)
			&& ($plugin = Plugins::get($match[2])) && $plugin->enabled) {
			$uri = ($match[1] == 'admin/p' ? 'admin/' : '') . $match[3];
			$plugin->route($uri);
			return;
		}
		// Other admin/plugin routes are not found
		elseif ($first === 'admin' || $first === 'p') {
			http_response_code(404);
			throw new UserException('Cette page n\'existe pas.');
		}
		elseif ('api' === $first) {
			API::dispatchURI(substr($uri, 4));
			return;
		}
		elseif ((in_array($uri, self::DAV_ROUTES) || in_array($first, self::DAV_ROUTES))
			&& WebDAV_Server::route($uri)) {
			return;
		}
		elseif ($method == 'PROPFIND') {
			header('Location: /dav/documents/');
			return;
		}
		elseif ((Files::isContextRoutable($uri) && ($file = Files::getFromURI($uri)))
				|| ($file = Web::getAttachmentFromURI($uri))) {
			$size = null;

			if ($file->image) {
				foreach ($_GET as $key => $v) {
					if (array_key_exists($key, File::ALLOWED_THUMB_SIZES)) {
						$size = $key;
						break;
					}
				}
			}

			$session = Session::getInstance();

			if (Plugins::fireSignal('http.request.file.before', compact('file', 'uri', 'session'))) {
				// If a plugin handled the request, let's stop here
				return;
			}

			if ($size) {
				$file->serveThumbnail($session, $size);
			}
			else {
				$file->serve($session, isset($_GET['download']), $_GET['s'] ?? null, $_POST['p'] ?? null);
			}

			Plugins::fireSignal('http.request.file.after', compact('file', 'uri', 'session'));

			return;
		}

		Modules::route($uri);
	}

	static public function markdown(string $text)
	{
		$md = new Markdown;
		header('Content-Type: text/html');

		$text = $md->text($text);
		$title = '';

		if (preg_match('!<h1[^>]*>(.*?)</h1>!is', $text, $match)) {
			$title = strip_tags($match[1]);
		}

		printf('<!DOCYPE html><head><title>%s</title>
			<style type="text/css">body { font-family: Verdana, sans-serif; padding: .5em; margin: 0; background: #fff; color: #000; }</style>
			<link rel="stylesheet" type="text/css" href="%scss.php" /></head><body>', $title, ADMIN_URL);

		echo $text;

	}

	static public function log(string $message, ...$params)
	{
		if (!HTTP_LOG_FILE) {
			return;
		}

		static $log = '';

		if (!$log) {
			register_shutdown_function(function () use (&$log) {
				file_put_contents(HTTP_LOG_FILE, $log, FILE_APPEND);
			});
		}

		$log .= vsprintf($message, $params) . "\n\n";
	}

	static public function xSendFile(string $path): bool
	{
		// Utilisation de XSendFile si disponible
		if (ENABLE_XSENDFILE && isset($_SERVER['SERVER_SOFTWARE']))
		{
			if (stristr($_SERVER['SERVER_SOFTWARE'], 'apache')
				&& function_exists('apache_get_modules')
				&& in_array('mod_xsendfile', apache_get_modules()))
			{
				header('X-Sendfile: ' . $path);
				return true;
			}
			else if (stristr($_SERVER['SERVER_SOFTWARE'], 'lighttpd'))
			{
				header('X-Sendfile: ' . $path);
				return true;
			}
		}

		return false;
	}
}
