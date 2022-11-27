<?php

namespace Garradin\Files\WebDAV;

use KD2\WebDAV\NextCloud as WebDAV_NextCloud;
use KD2\WebDAV\Exception as WebDAV_Exception;

use Garradin\Files\Files;
use Garradin\Entities\Files\File;

use const Garradin\{SECRET_KEY, ADMIN_URL, CACHE_ROOT, WWW_URL, ROOT};

class NextCloud extends WebDAV_NextCloud
{
	protected string $temporary_chunks_path;
	protected string $prefix = 'documents/';

	public function __construct()
	{
		$this->temporary_chunks_path =  CACHE_ROOT . '/webdav.chunks';
		$this->setRootURL(WWW_URL);
	}

	public function auth(?string $login, ?string $password): bool
	{
		$session = Session::getInstance();

		if ($session->isLogged()) {
			return true;
		}

		if (!$login || !$password) {
			return false;
		}

		if ($session->checkAppCredentials($login, $password)) {
			return true;
		}

		if ($session->login($login, $password)) {
			return true;
		}

		return false;
	}

	public function getUserName(): ?string
	{
		$s = Session::getInstance();
		return $s->isLogged() ? $s->user()->name() : null;
	}

	public function setUserName(string $login): bool
	{
		return true;
	}

	public function getUserQuota(): array
	{
		return [
			'free'  => Files::getRemainingQuota(),
			'used'  => Files::getUsedQuota(),
			'total' => Files::getQuota(),
		];
	}

	public function generateToken(): string
	{
		return Session::getInstance()->generateAppToken();
	}

	public function validateToken(string $token): ?array
	{
		return Session::getInstance()->verifyAppToken($_POST['token']);
	}

	public function getLoginURL(?string $token): string
	{
		if ($token) {
			return sprintf('%slogin.php?app=%s', ADMIN_URL, $token);
		}
		else {
			return sprintf('%slogin.php?app=redirect', ADMIN_URL);
		}
	}

	public function getDirectDownloadSecret(string $uri, string $login): string
	{
		return hash_hmac('sha1', $uri, SECRET_KEY);
	}

	protected function cleanChunks(): void
	{
		// 36 hours
		$expire = time() - 36*3600;

		foreach (glob($this->temporary_chunks_path . '/*') as $dir) {
			$first_file = current(glob($dir . '/*'));

			if (filemtime($first_file) < $expire) {
				Utils::deleteRecursive($dir, true);
			}
		}
	}

	public function storeChunk(string $login, string $name, string $part, $pointer): void
	{
		$this->cleanChunks();

		$path = $this->temporary_chunks_path . '/' . $name;
		@mkdir($path, 0777, true);

		$file_path = $path . '/' . $part;
		$out = fopen($file_path, 'wb');
		$quota = $this->getUserQuota();

		$used = array_sum(array_map(fn($a) => filesize($a), glob($path . '/*')));
		$used += $quota['used'];

		while (!feof($pointer)) {
			$data = fread($pointer, 8192);
			$used += strlen($used);

			if ($used > $quota['free']) {
				$this->deleteChunks($login, $name);
				throw new WebDAV_Exception('Your quota does not allow for the upload of this file', 403);
			}

			fwrite($out, $data);
		}

		fclose($out);
		fclose($pointer);
	}

	public function deleteChunks(string $login, string $name): void
	{
		$path = $this->temporary_chunks_path . '/' . $name;
		Utils::deleteRecursive($path, true);
	}

	public function assembleChunks(string $login, string $name, string $target, ?int $mtime): array
	{
		$parent = Utils::dirname($target);
		$parent = Files::get($parent);

		if (!$parent || $parent->type != $parent::TYPE_DIRECTORY) {
			throw new WebDAV_Exception('Target parent directory does not exist', 409);
		}

		$path = $this->temporary_chunks_path . '/' . $name;
		$tmp_file = $path . '/__complete';

		$exists = Files::exists($target);

		try {
			$out = fopen($tmp_file, 'wb');

			foreach (glob($path . '/*') as $file) {
				$in = fopen($file, 'rb');

				while (!feof($in)) {
					fwrite($out, fread($in, 8192));
				}

				fclose($in);
			}

			$file = Files::createFromPointer($target, $out);

			fclose($out);

			if ($mtime) {
				$file->touch($mtime);
			}
		}
		finally {
			$this->deleteChunks($login, $name);
			Utils::safe_unlink($tmp_file);
		}

		return ['created' => !$exists, 'etag' => $file->etag()];
	}


	/**
	 * File preview, large
	 * @see https://help.nextcloud.com/t/getting-image-preview-with-android-library-or-via-webdav/75743
	 */
	protected function nc_preview(string $uri): void
	{
		$width = $_GET['x'] ?? null;
		$height = $_GET['y'] ?? null;
		$crop = !($_GET['a'] ?? null);

		if (!preg_match('/\.(?:jpe?g|gif|png|webp)$/', $uri)) {
			http_response_code(404);
			return;
		}

		$url = str_replace('%2F', '/', rawurlencode(rawurldecode($_GET['file'] ?? '')));
		$url = ltrim($url, '/');

		$this->_thumbnail($uri, (int) $width, $crop);
	}

	protected function _thumbnail(string $uri, int $width, bool $crop = false): void
	{
		$this->requireAuth();
		$file = Files::get(File::CONTEXT_DOCUMENTS . '/' . $uri);

		if (!$file) {
			throw new WebDAV_Exception('Not found', 404);
		}

		if (!$file->image) {
			throw new WebDAV_Exception('Not an image', 404);
		}

		if ($crop) {
			$size = 'crop-256px';
		}
		elseif ($width >= 500) {
			$size = '500px';
		}
		else {
			$size = '150px';
		}

		$file->serveThumbnail(Session::getInstance(), $size);
	}

	protected function nc_thumbnail(string $uri): void
	{
		// Remove "/index.php/apps/files/api/v1/thumbnail/"
		$uri = str_replace(array_search('thumbnail', self::ROUTES), '', $uri);
		$uri = trim($uri, '/');

		list($width, $height, $uri) = array_pad(explode('/', $uri, 3), 3, null);

		$this->_thumbnail($uri, (int)$width, false);
	}

	/*
	protected function nc_avatar(): ?array
	{
		// Doesn't work
		header('Content-Type: image/png');
		header('X-NC-IsCustomAvatar: 1');
		header('Content-Disposition: inline; filename="icon.png"');
		header('Last-Modified: ' . gmdate(DATE_ISO8601));
		header('Content-Length: ' . filesize(ROOT . '/www/admin/static/icon.png'));
		readfile(ROOT . '/www/admin/static/icon.png');
		return null;
	}
	*/
}
