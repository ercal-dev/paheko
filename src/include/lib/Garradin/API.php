<?php

namespace Garradin;

use KD2\ErrorManager;

class API
{
	protected $body;
	protected $params;
	protected $method;

	protected function body(): string
	{
		if (null == $this->body) {
			$this->body = trim(file_get_contents('php://input'));
		}

		return $this->body;
	}

	protected function hasParam(string $param): bool
	{
		return array_key_exists($param, $_GET);
	}

	protected function download()
	{
		if ($this->method != 'GET') {
			throw new APIException('Wrong request method', 400);
		}

		(new Sauvegarde)->dump();
		return null;
	}

	protected function sql()
	{
		if ($this->method != 'POST') {
			throw new APIException('Wrong request method', 400);
		}

		$body = $this->body();

		if ($body === '') {
			throw new APIException('Missing SQL statement', 400);
		}

		try {
			return ['results' => Recherche::rawSQL($body)];
		}
		catch (\Exception $e) {
			http_response_code(400);
			return ['error' => 'Error in SQL statement', 'sql_error' => $e->getMessage()];
		}
	}

	protected function user(string $uri): ?array
	{
		$fn = strtok($uri, '/');

		// CSV import
		if ($fn == 'import') {
			if ($this->method != 'PUT') {
				throw new APIException('Wrong request method', 400);
			}

			$admin_user_id = 1; // FIXME: should be NULL here

			$file = tempnam(CACHE_ROOT, 'tmp-import-api');

			try {
				$stdin = fopen('php://input', 'r');
				$fp = fopen($file, 'w');
				stream_copy_to_stream($stdin, $fp);
				fclose($fp);
				fclose($stdin);

				if (!filesize($file)) {
					throw new APIException('Empty CSV file', 400);
				}

				$import = new Membres\Import;
				$import->fromGarradinCSV($file, $admin_user_id);
			}
			finally {
				Utils::safe_unlink($file);
			}

			return null;
		}
		else {
			throw new APIException('Unknown user action', 404);
		}
	}

	protected function web(string $uri): ?array
	{
		if ($this->method != 'GET') {
			throw new APIException('Wrong request method', 400);
		}

		$fn = strtok($uri, '/');
		$param = strtok('');

		switch ($fn) {
			case 'list':
				return ['categories' => Web::listCategories($param), 'pages' => Web::listPages($param)];
			case 'attachment':
				$attachment = Web::getAttachmentFromURI($param);

				if (!$attachment) {
					throw new APIException('Page not found', 404);
				}

				$attachment->serve();
				return null;
			case 'html':
			case 'page':
				$page = Web::getByURI($param);

				if (!$page) {
					throw new APIException('Page not found', 404);
				}

				if ($fn == 'page') {
					$out = compact('page');

					if ($this->hasParam('html')) {
						$out['html'] = $page->render();
					}

					return $out;
				}

				// HTML render
				echo $page->render();
				return null;
			default:
				throw new APIException('Unknown web action', 404);
		}
	}

	public function errors(string $uri)
	{
		$fn = strtok($uri, '/');

		if (!ini_get('error_log')) {
			throw new APIException('The error log is disabled', 404);
		}

		if ($uri == 'report') {
			if ($this->method != 'POST') {
				throw new APIException('Wrong request method', 400);
			}

			$body = $this->body();
			$report = json_decode($body);

			if (!isset($report->context->id)) {
				throw new APIException('Invalid JSON body', 400);
			}

			$log = sprintf('=========== Error ref. %s ===========', $report->context->id)
				. PHP_EOL . PHP_EOL . "Report from API" . PHP_EOL . PHP_EOL
				. '<errorReport>' . PHP_EOL . json_encode($report, \JSON_PRETTY_PRINT)
				. PHP_EOL . '</errorReport>' . PHP_EOL;

			error_log($log);

			return null;
		}
		elseif ($uri == 'log') {
			if ($this->method != 'GET') {
				throw new APIException('Wrong request method', 400);
			}

			return ErrorManager::getReportsFromLog(null, null);
		}
		else {
			throw new APIException('Unknown errors action', 404);
		}
	}

	public function checkAuth(): void
	{
		if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
			throw new APIException('No username or password supplied', 401);
		}

		if ($_SERVER['PHP_AUTH_USER'] !== API_USER || $_SERVER['PHP_AUTH_PW'] !== API_PASSWORD) {
			throw new APIException('Invalid username or password', 403);
		}
	}

	public function dispatch(string $fn, string $uri)
	{
		$this->checkAuth();

		switch ($fn) {
			case 'sql':
				return $this->sql();
			case 'download':
				return $this->download();
			case 'web':
				return $this->web($uri);
			case 'user':
				return $this->user($uri);
			case 'errors':
				return $this->errors($uri);
			default:
				throw new APIException('Unknown path', 404);
		}
	}

	static public function dispatchURI(string $uri)
	{
		$fn = strtok($uri, '/');

		$api = new self;

		$api->method = $_SERVER['REQUEST_METHOD'] ?? null;

		http_response_code(200);

		try {
			$return = $api->dispatch($fn, strtok(''));

			if (null !== $return) {
				echo json_encode($return, JSON_PRETTY_PRINT);
			}
		}
		catch (\Exception $e) {
			if ($e instanceof APIException) {
				http_response_code($e->getCode());
				echo json_encode(['error' => $e->getMessage()]);
			}
			elseif ($e instanceof UserException || $e instanceof ValidationException) {
				http_response_code(400);
				echo json_encode(['error' => $e->getMessage()]);
			}
			else {
				throw $e;
			}
		}
	}
}