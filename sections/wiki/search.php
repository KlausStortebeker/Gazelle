<?php

$wikiMan = new Gazelle\Manager\Wiki;

if (empty($_GET['nojump'])) {
    $ArticleID = $wikiMan->alias($_GET['search'] ?? '');
    if ($ArticleID) {
        header("Location: wiki.php?action=article&id={$ArticleID}");
        exit;
    }
}

$header = new \Gazelle\Util\SortableTableHeader('created', [
    'created' => ['dbColumn' => 'ID',    'defaultSort' => 'desc'],
    'title'   => ['dbColumn' => 'Title', 'defaultSort' => 'asc',  'text' => 'Article'],
    'edited'  => ['dbColumn' => 'Date',  'defaultSort' => 'desc', 'text' => 'Last updated'],
]);

$TypeMap = [
    'title' => 'Title',
    'body'  => 'Body',
];
$Type = $TypeMap[$_GET['type'] ?? 'title'];

$Viewer = new Gazelle\User($LoggedUser['ID']);
$search = new Gazelle\Search\Wiki($Viewer, $Type, $_GET['search'] ?? '');
$search->setOrderBy($header->getOrderBy())->setOrderDir($header->getOrderDir());

$paginator = new Gazelle\Util\Paginator(WIKI_ARTICLES_PER_PAGE, (int)($_GET['page'] ?? 1));
$paginator->setTotal($search->total());
$page =

View::show_header('Search articles');
echo $Twig->render('wiki/search.twig', [
    'header'    => $header,
    'paginator' => $paginator,
    'page'      => $search->page($paginator->limit(), $paginator->offset()),
    'alias'     => $wikiMan->normalizeAlias($_GET['search'] ?? ''),
    'order'     => $_GET['order'] ?? 'asc',
    'search'    => $_GET['search'],
    'sort'      => $_GET['sort'] ?? 'title',
    'type'      => $Type,
]);
View::show_footer();
