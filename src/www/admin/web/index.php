<?php

namespace Paheko;

use Paheko\Web\Web;
use Paheko\Entities\Web\Page;

require_once __DIR__ . '/_inc.php';

$page = null;

if (qg('p')) {
	$page = Web::get(qg('p'));

	if (!$page) {
		throw new UserException('Page inconnue : inexistante ou supprimée');
	}
}
elseif (qg('uri')) {
	$page = Web::getByURI(qg('uri'));

	if (!$page) {
		throw new UserException('Page inconnue : inexistante ou supprimée');
	}
}

$links_errors = null;

if ($page) {
	$links_errors = $page->checkInternalLinks();

	if (qg('toggle_type') !== null && $session->canAccess($session::SECTION_WEB, $session::ACCESS_ADMIN)) {
		$page->toggleType();
		$page->save();
		Utils::redirect('!web/?p=' . $page->path);
	}
}
elseif (isset($_GET['check'])) {
	$links_errors = Web::checkAllInternalLinks();
}

$cat = $page && $page->isCategory() ? $page : null;

$categories = Web::listCategories($cat ? $cat->path : null);
$pages = Web::getPagesList($cat ? $cat->path : null);
$drafts = Web::getDraftsList($cat ? $cat->path : null);

$pages->loadFromQueryString();
$drafts->loadFromQueryString();

$title = $page ? sprintf('%s — Gestion du site web', $page->title) : 'Gestion du site web';

$type_page = Page::TYPE_PAGE;
$type_category = Page::TYPE_CATEGORY;
$breadcrumbs = $page ? $page->getBreadcrumbs() : [];
$can_edit = $session->canAccess($session::SECTION_WEB, $session::ACCESS_WRITE);

$tpl->assign('custom_js', ['web_gallery.js']);
$tpl->assign('custom_css', ['web.css', '!web/css.php']);

$tpl->assign(compact('categories', 'pages', 'drafts', 'title', 'type_page', 'type_category', 'breadcrumbs', 'page', 'links_errors', 'can_edit'));

$tpl->display('web/index.tpl');
