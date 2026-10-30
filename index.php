<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postString(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function normalizeTags(string $input): array
{
    $parts = preg_split('/[,;\n]+/u', $input) ?: [];
    $tags = [];

    foreach ($parts as $part) {
        $tag = trim(ltrim($part, "# \t"));
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return array_values(array_unique($tags));
}

function linkCountLabel(int $count): string
{
    $lastTwoDigits = $count % 100;
    if ($lastTwoDigits >= 11 && $lastTwoDigits <= 14) {
        return $count . ' ссылок';
    }

    return match ($count % 10) {
        1 => $count . ' ссылка',
        2, 3, 4 => $count . ' ссылки',
        default => $count . ' ссылок',
    };
}

function redirectToHome(string $notice): never
{
    header('Location: index.php?notice=' . rawurlencode($notice));
    exit;
}

$storageDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0775, true) && !is_dir($storageDirectory)) {
    throw new RuntimeException('Не удалось создать папку storage. Проверьте права на запись.');
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('Для запуска LinkVault нужен PHP с включённым расширением PDO_SQLite.');
}

$database = new PDO('sqlite:' . $storageDirectory . DIRECTORY_SEPARATOR . 'linkvault.sqlite');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(
    'CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        url TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT "",
        collection TEXT NOT NULL DEFAULT "Без коллекции",
        tags TEXT NOT NULL DEFAULT "[]",
        favorite INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )'
);
$database->exec('CREATE TABLE IF NOT EXISTS app_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

$database->beginTransaction();
$seedMarker = $database->exec('INSERT OR IGNORE INTO app_meta (key, value) VALUES ("sample_data_seeded", "1")');
if ($seedMarker === 1) {
    $linkCount = (int) $database->query('SELECT COUNT(*) FROM links')->fetchColumn();
    if ($linkCount === 0) {
        $seedLinks = [
            ['PHP: документация', 'https://www.php.net/manual/ru/', 'Официальное руководство по PHP — всегда под рукой.', 'Разработка', ['php', 'документация'], 1],
            ['MDN Web Docs', 'https://developer.mozilla.org/', 'Надёжный справочник по веб-технологиям.', 'Разработка', ['web', 'справочник'], 1],
            ['A List Apart', 'https://alistapart.com/', 'Идеи о дизайне, доступности и веб-разработке.', 'Дизайн', ['дизайн', 'статьи'], 0],
            ['Unsplash', 'https://unsplash.com/', 'Подборка фотографий для визуального вдохновения.', 'Вдохновение', ['фото', 'вдохновение'], 0],
        ];
        $insertSeed = $database->prepare(
            'INSERT INTO links (title, url, description, collection, tags, favorite, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime("now"), datetime("now"))'
        );
        foreach ($seedLinks as [$title, $url, $description, $collection, $tags, $favorite]) {
            $insertSeed->execute([$title, $url, $description, $collection, json_encode($tags, JSON_THROW_ON_ERROR), $favorite]);
        }
    }
}
$database->commit();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = postString('csrf_token');
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        throw new RuntimeException('Форма устарела или имеет неверный токен. Обновите страницу и попробуйте снова.');
    }

    $action = postString('action');
    $rawId = postString('id');
    $id = filter_var($rawId, FILTER_VALIDATE_INT);

    if ($action === 'save') {
        if ($rawId !== '' && (!is_int($id) || $id < 1)) {
            throw new InvalidArgumentException('Некорректный идентификатор ссылки.');
        }
        $title = postString('title');
        $url = postString('url');
        $description = postString('description');
        $collection = postString('collection');
        $collection = $collection !== '' ? $collection : 'Без коллекции';
        $parsedUrl = filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;

        if ($title === '') {
            throw new InvalidArgumentException('Добавьте название ссылки.');
        }
        if ($parsedUrl === false || !isset($parsedUrl['scheme'], $parsedUrl['host']) || !in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Укажите корректный URL, начинающийся с http:// или https://.');
        }

        $tags = normalizeTags(postString('tags'));
        $favorite = isset($_POST['favorite']) ? 1 : 0;
        if (is_int($id) && $id > 0) {
            $statement = $database->prepare(
                'UPDATE links SET title = ?, url = ?, description = ?, collection = ?, tags = ?, favorite = ?, updated_at = datetime("now") WHERE id = ?'
            );
            $statement->execute([$title, $url, $description, $collection, json_encode($tags, JSON_THROW_ON_ERROR), $favorite, $id]);
            if ($statement->rowCount() === 0) {
                throw new InvalidArgumentException('Ссылка для изменения не найдена.');
            }
            redirectToHome('updated');
        }

        $statement = $database->prepare(
            'INSERT INTO links (title, url, description, collection, tags, favorite, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime("now"), datetime("now"))'
        );
        $statement->execute([$title, $url, $description, $collection, json_encode($tags, JSON_THROW_ON_ERROR), $favorite]);
        redirectToHome('saved');
    }

    if ($action === 'toggle_favorite' && is_int($id) && $id > 0) {
        $statement = $database->prepare('UPDATE links SET favorite = 1 - favorite, updated_at = datetime("now") WHERE id = ?');
        $statement->execute([$id]);
        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException('Ссылка для избранного не найдена.');
        }
        redirectToHome('favorite');
    }

    if ($action === 'delete' && is_int($id) && $id > 0) {
        $statement = $database->prepare('DELETE FROM links WHERE id = ?');
        $statement->execute([$id]);
        if ($statement->rowCount() === 0) {
            throw new InvalidArgumentException('Ссылка для удаления не найдена.');
        }
        redirectToHome('deleted');
    }

    throw new InvalidArgumentException('Неизвестное действие формы.');
}

if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$collectionFilter = trim(is_string($_GET['collection'] ?? null) ? $_GET['collection'] : '');
$tagFilter = trim(is_string($_GET['tag'] ?? null) ? $_GET['tag'] : '');
$view = is_string($_GET['view'] ?? null) ? $_GET['view'] : 'all';
$view = $view === 'favorites' ? 'favorites' : 'all';

$conditions = [];
$parameters = [];
if ($view === 'favorites') {
    $conditions[] = 'favorite = 1';
}
if ($collectionFilter !== '') {
    $conditions[] = 'collection = ?';
    $parameters[] = $collectionFilter;
}
if ($search !== '') {
    $conditions[] = '(instr(lower(title), lower(?)) > 0 OR instr(lower(url), lower(?)) > 0 OR instr(lower(description), lower(?)) > 0 OR instr(lower(tags), lower(?)) > 0)';
    array_push($parameters, $search, $search, $search, $search);
}
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'newest';
$sort = in_array($sort, ['newest', 'oldest', 'title'], true) ? $sort : 'newest';
$orderBy = match ($sort) {
    'oldest' => 'favorite DESC, updated_at ASC, id ASC',
    'title' => 'favorite DESC, title COLLATE NOCASE ASC',
    default => 'favorite DESC, updated_at DESC, id DESC',
};
$sql = 'SELECT * FROM links' . ($conditions ? ' WHERE ' . implode(' AND ', $conditions) : '') . ' ORDER BY ' . $orderBy;
$statement = $database->prepare($sql);
$statement->execute($parameters);
$links = $statement->fetchAll();

foreach ($links as &$link) {
    $link['decoded_tags'] = json_decode($link['tags'], true, 512, JSON_THROW_ON_ERROR);
}
unset($link);

if ($tagFilter !== '') {
    $links = array_values(array_filter(
        $links,
        static fn (array $link): bool => in_array($tagFilter, $link['decoded_tags'], true)
    ));
}

$totalCount = (int) $database->query('SELECT COUNT(*) FROM links')->fetchColumn();
$favoriteCount = (int) $database->query('SELECT COUNT(*) FROM links WHERE favorite = 1')->fetchColumn();
$collections = $database->query('SELECT collection, COUNT(*) AS amount FROM links GROUP BY collection ORDER BY collection COLLATE NOCASE')->fetchAll();
$tagCounts = [];
foreach ($database->query('SELECT tags FROM links')->fetchAll() as $row) {
    foreach (json_decode($row['tags'], true, 512, JSON_THROW_ON_ERROR) as $tag) {
        $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
    }
}
arsort($tagCounts);
$pageTitle = $view === 'favorites' ? 'Избранное' : ($collectionFilter !== '' ? $collectionFilter : ($tagFilter !== '' ? '#' . $tagFilter : 'Все ссылки'));
$notices = [
    'saved' => 'Ссылка добавлена в библиотеку',
    'updated' => 'Изменения сохранены',
    'deleted' => 'Ссылка удалена',
    'favorite' => 'Избранное обновлено',
];
$notice = $notices[is_string($_GET['notice'] ?? null) ? $_GET['notice'] : ''] ?? '';

function queryLink(array $overrides = []): string
{
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }
    return 'index.php' . ($query ? '?' . http_build_query($query) : '');
}

function hostnameFor(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? preg_replace('/^www\./i', '', $host) : $url;
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f7fb">
    <title><?= h($pageTitle) ?> — LinkVault</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php" aria-label="LinkVault — главная">
            <span class="brand-mark"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 13.5a4.5 4.5 0 0 0 6.36 0l3-3A4.5 4.5 0 0 0 13 4.14l-1.72 1.72"/><path d="M14 10.5a4.5 4.5 0 0 0-6.36 0l-3 3A4.5 4.5 0 0 0 11 19.86l1.72-1.72"/></svg></span>
            <span>link<span class="brand-light">vault</span><small>YOUR LITTLE CORNER OF THE WEB</small></span>
        </a>

        <button class="new-link-button" type="button" data-open-modal>
            <span class="plus-icon">+</span> Добавить ссылку <kbd>Ctrl K</kbd>
        </button>

        <nav class="side-nav" aria-label="Главная навигация">
            <p class="nav-label">БИБЛИОТЕКА</p>
            <a class="nav-item <?= $view === 'all' && $collectionFilter === '' && $tagFilter === '' ? 'active' : '' ?>" href="index.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h13A1.5 1.5 0 0 1 20 5.5v13a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg></span>
                Все ссылки <span class="nav-count"><?= $totalCount ?></span>
            </a>
            <a class="nav-item <?= $view === 'favorites' ? 'active' : '' ?>" href="<?= h(queryLink(['view' => 'favorites', 'collection' => '', 'tag' => ''])) ?>">
                <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></svg></span>
                Избранное <span class="nav-count"><?= $favoriteCount ?></span>
            </a>

            <div class="nav-section-heading"><p class="nav-label">КОЛЛЕКЦИИ</p><button class="subtle-icon" type="button" data-open-modal aria-label="Добавить ссылку">+</button></div>
            <div class="collection-list">
                <?php foreach ($collections as $collection): ?>
                    <a class="nav-item <?= $collectionFilter === $collection['collection'] ? 'active' : '' ?>" href="<?= h(queryLink(['collection' => $collection['collection'], 'view' => 'all', 'tag' => ''])) ?>">
                        <span class="collection-dot"></span>
                        <span class="collection-name"><?= h($collection['collection']) ?></span>
                        <span class="nav-count"><?= (int) $collection['amount'] ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($tagCounts): ?>
                <div class="nav-section-heading tag-heading"><p class="nav-label">ПОПУЛЯРНЫЕ ТЕГИ</p></div>
                <div class="tag-list">
                    <?php foreach (array_slice($tagCounts, 0, 8, true) as $tag => $amount): ?>
                        <a class="tag-link <?= $tagFilter === $tag ? 'selected' : '' ?>" href="<?= h(queryLink(['tag' => $tag, 'view' => 'all'])) ?>">#<?= h($tag) ?><span><?= $amount ?></span></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-bottom">
            <div class="storage-card"><span class="storage-icon">✳</span><div><strong>Твоя веб-библиотека</strong><p>Все полезное — в одном месте.</p></div></div>
            <div class="profile-row"><span class="avatar">L</span><span class="profile-name">Личная библиотека<small>Работает локально</small></span><span class="profile-menu">···</span></div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="breadcrumbs"><span>Библиотека</span><span class="breadcrumb-slash">/</span><strong><?= h($pageTitle) ?></strong></div>
            <div class="topbar-actions"><span class="private-badge"><span></span> ЛИЧНОЕ ПРОСТРАНСТВО</span><button class="mobile-add" type="button" data-open-modal>+</button></div>
        </header>

        <section class="content">
            <div class="page-intro">
                <div><p class="eyebrow"><?= $view === 'favorites' ? 'СОХРАНЁННОЕ' : 'ТВОЯ ПОДБОРКА' ?></p><h1><?= h($pageTitle) ?><span class="title-period">.</span></h1>
                    <p class="intro-copy"><?= $search !== '' ? 'Результаты поиска по запросу «' . h($search) . '»' : 'Хорошие находки не должны теряться в бесконечных вкладках.' ?></p>
                </div>
                <button class="primary-button intro-add" type="button" data-open-modal><span>+</span> Новая ссылка</button>
            </div>

            <form class="toolbar" method="get" action="index.php">
                <?php if ($view === 'favorites'): ?><input type="hidden" name="view" value="favorites"><?php endif; ?>
                <?php if ($collectionFilter !== ''): ?><input type="hidden" name="collection" value="<?= h($collectionFilter) ?>"><?php endif; ?>
                <?php if ($tagFilter !== ''): ?><input type="hidden" name="tag" value="<?= h($tagFilter) ?>"><?php endif; ?>
                <?php if ($sort !== 'newest'): ?><input type="hidden" name="sort" value="<?= h($sort) ?>"><?php endif; ?>
                <label class="search-box"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4 4"/></svg><input type="search" name="q" value="<?= h($search) ?>" placeholder="Поиск по названию, тегам, URL..." aria-label="Поиск по ссылкам"><kbd>/</kbd></label>
                <button class="filter-button" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10m-7 6h4"/></svg> Поиск</button>
                <div class="view-switch"><span>Вид</span><button class="view-button selected" type="button" aria-label="Сетка" data-layout="grid"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/></svg></button><button class="view-button" type="button" aria-label="Список" data-layout="list"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r=".7"/><circle cx="4.5" cy="12" r=".7"/><circle cx="4.5" cy="18" r=".7"/></svg></button></div>
            </form>

            <?php if ($tagFilter !== ''): ?><div class="active-filter">Фильтр: <a href="<?= h(queryLink(['tag' => ''])) ?>">#<?= h($tagFilter) ?> <span>×</span></a></div><?php endif; ?>

            <div class="results-heading"><div><h2><?= $view === 'favorites' ? 'То, что нравится' : 'Недавно сохранённое' ?></h2><span><?= linkCountLabel(count($links)) ?></span></div>
                <form method="get" action="index.php" class="sort-form">
                    <?php if ($view === 'favorites'): ?><input type="hidden" name="view" value="favorites"><?php endif; ?>
                    <?php if ($collectionFilter !== ''): ?><input type="hidden" name="collection" value="<?= h($collectionFilter) ?>"><?php endif; ?>
                    <?php if ($tagFilter !== ''): ?><input type="hidden" name="tag" value="<?= h($tagFilter) ?>"><?php endif; ?>
                    <?php if ($search !== ''): ?><input type="hidden" name="q" value="<?= h($search) ?>"><?php endif; ?>
                    <label class="sort-button">Сортировка <select name="sort" aria-label="Сортировка" onchange="this.form.submit()"><option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Сначала новые</option><option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Сначала старые</option><option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>По названию</option></select></label>
                </form>
            </div>

            <?php if ($links): ?>
                <div class="link-grid">
                    <?php foreach ($links as $link): ?>
                        <?php $host = hostnameFor($link['url']); ?>
                        <article class="link-card">
                            <div class="card-top"><span class="site-mark"><?= h(strtoupper(substr($host, 0, 1))) ?></span><form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="toggle_favorite"><input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
                                <button class="favorite-button <?= (int) $link['favorite'] === 1 ? 'is-favorite' : '' ?>" type="submit" aria-label="<?= (int) $link['favorite'] === 1 ? 'Убрать из избранного' : 'Добавить в избранное' ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></svg></button>
                            </form></div>
                            <a class="card-main" href="<?= h($link['url']) ?>" target="_blank" rel="noopener noreferrer">
                                <span class="site-host"><?= h($host) ?><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h6v6m-1-5-9 9"/><path d="M18 13v5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h5"/></svg></span>
                                <h3><?= h($link['title']) ?></h3>
                                <p><?= h($link['description'] !== '' ? $link['description'] : $link['url']) ?></p>
                            </a>
                            <div class="card-tags"><span class="collection-pill"><span></span><?= h($link['collection']) ?></span><?php foreach (array_slice($link['decoded_tags'], 0, 3) as $tag): ?><a href="<?= h(queryLink(['tag' => $tag])) ?>" class="tiny-tag">#<?= h($tag) ?></a><?php endforeach; ?></div>
                            <div class="card-footer"><span><?= h(date('d.m.Y', strtotime($link['updated_at']))) ?></span><div class="card-actions">
                                <button class="card-action edit-link" type="button" aria-label="Изменить ссылку" data-id="<?= (int) $link['id'] ?>" data-title="<?= h($link['title']) ?>" data-url="<?= h($link['url']) ?>" data-description="<?= h($link['description']) ?>" data-collection="<?= h($link['collection']) ?>" data-tags="<?= h(implode(', ', $link['decoded_tags'])) ?>" data-favorite="<?= (int) $link['favorite'] ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 5 4 4M4 20l4-.8L19 8a2.1 2.1 0 0 0-3-3L5 16z"/></svg></button>
                                <form method="post" class="inline-form" data-confirm-delete><input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $link['id'] ?>"><button class="card-action delete-action" type="submit" aria-label="Удалить ссылку"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6m4-6v6M5 7l1 13h12l1-13M9 7V4h6v3"/></svg></button></form>
                            </div></div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state"><div class="empty-illustration"><span>↗</span><span>✳</span><span>⌁</span></div><h2><?= $search !== '' || $tagFilter !== '' ? 'Ничего не нашлось' : 'Здесь пока тихо' ?></h2><p><?= $search !== '' || $tagFilter !== '' ? 'Попробуй изменить запрос или сбросить фильтр.' : 'Сохрани первую полезную ссылку — и пусть она больше не потеряется.' ?></p><?php if ($search !== '' || $tagFilter !== ''): ?><a class="secondary-button" href="index.php">Сбросить фильтры</a><?php else: ?><button class="primary-button" type="button" data-open-modal>Добавить первую ссылку</button><?php endif; ?></div>
            <?php endif; ?>
        </section>
    </main>
</div>

<div class="modal-backdrop" id="link-modal" aria-hidden="true">
    <section class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-heading"><div><p class="eyebrow">НОВАЯ НАХОДКА</p><h2 id="modal-title">Сохранить ссылку</h2></div><button class="modal-close" type="button" data-close-modal aria-label="Закрыть">×</button></div>
        <form method="post" class="link-form" id="link-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="link-id">
            <label>Название <span>*</span><input type="text" name="title" id="link-title" placeholder="Например, статья про хороший дизайн" required maxlength="180"></label>
            <label>URL <span>*</span><input type="url" name="url" id="link-url" placeholder="https://example.com" required></label>
            <label>Короткая заметка<textarea name="description" id="link-description" rows="3" placeholder="Чем эта ссылка полезна?"></textarea></label>
            <div class="form-row"><label>Коллекция<input type="text" name="collection" id="link-collection" list="collection-options" placeholder="Например, Работа"></label><datalist id="collection-options"><?php foreach ($collections as $collection): ?><option value="<?= h($collection['collection']) ?>"><?php endforeach; ?></datalist><label>Теги<input type="text" name="tags" id="link-tags" placeholder="дизайн, идеи"></label></div>
            <label class="favorite-check"><input type="checkbox" name="favorite" id="link-favorite"><span>Добавить в избранное</span></label>
            <div class="modal-footer"><button class="secondary-button" type="button" data-close-modal>Отмена</button><button class="primary-button" type="submit">Сохранить ссылку <span>↗</span></button></div>
        </form>
    </section>
</div>
<?php if ($notice !== ''): ?><div class="toast" role="status"><?= h($notice) ?><span>✓</span></div><?php endif; ?>
<script src="assets/app.js" defer></script>
</body>
</html>
