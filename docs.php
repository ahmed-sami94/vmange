<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

secure_session_start();
require_login();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'");

$docs = [
    'getting-started' => ['Getting Started', 'Welcome'],
    'installation' => ['Installation', 'Getting Started'],
    'agent-installation' => ['Agent Installation', 'Getting Started'],
    'hosts' => ['Hosts', 'Operations'],
    'virtual-machines' => ['Virtual Machines', 'Operations'],
    'containers-compose' => ['Containers And Compose', 'Operations'],
    'wol-host-tools' => ['WOL And Host Tools', 'Operations'],
    'audit-logs' => ['Audit And Logs', 'Operations'],
    'alarms-notifications' => ['Alarms And Notifications', 'Operations'],
    'troubleshooting' => ['Troubleshooting', 'Support'],
    'security' => ['Security', 'Support'],
    'about' => ['About', 'Support'],
];

$slug = (string) ($_GET['page'] ?? 'getting-started');
if (!isset($docs[$slug])) {
    $slug = 'getting-started';
}
$slugs = array_keys($docs);
$index = array_search($slug, $slugs, true);
$previous = $index > 0 ? $slugs[$index - 1] : null;
$next = $index < count($slugs) - 1 ? $slugs[$index + 1] : null;
$file = __DIR__ . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . $slug . '.md';
$body = is_file($file) ? (string) file_get_contents($file) : "# Documentation\n\nDocumentation is not available in this package.";

function docs_asset_url(string $path): string
{
    $fullPath = __DIR__ . '/' . ltrim($path, '/');
    $version = is_file($fullPath) ? (string) filemtime($fullPath) : '1';
    return $path . '?v=' . rawurlencode($version);
}

function docs_anchor(string $title): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? ''));
    return trim($slug, '-') ?: 'section';
}

function docs_markdown(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = preg_replace_callback('/^(###|##|#) (.*)$/m', static function (array $match): string {
        $level = strlen($match[1]);
        return '<h' . $level . ' id="' . docs_anchor(html_entity_decode($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '">' . $match[2] . '</h' . $level . '>';
    }, $escaped);
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    $html = preg_replace('/^\- (.*)$/m', '<li>$1</li>', $html);
    $html = preg_replace('/(<li>.*<\/li>)/sU', '<ul>$1</ul>', $html);
    $paragraphs = preg_split('/\R{2,}/', $html) ?: [];
    return implode('', array_map(static function (string $chunk): string {
        $trim = trim($chunk);
        if ($trim === '') {
            return '';
        }
        if (preg_match('/^<(h1|h2|h3|ul)/', $trim)) {
            return $trim;
        }
        return '<p>' . nl2br($trim) . '</p>';
    }, $paragraphs));
}

function docs_toc(string $text): array
{
    preg_match_all('/^(##|###)\s+(.+)$/m', $text, $matches, PREG_SET_ORDER);
    $items = [];
    foreach ($matches as $match) {
        $items[] = [
            'level' => strlen($match[1]),
            'title' => $match[2],
            'id' => docs_anchor($match[2]),
        ];
    }
    return $items;
}

$toc = docs_toc($body);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($docs[$slug][0]) ?> - VMange Docs</title>
    <link rel="stylesheet" href="<?= e(docs_asset_url('assets/css/app.css')) ?>">
</head>
<body class="docs-site-body">
    <div class="docs-site-shell">
        <aside class="docs-site-sidebar">
            <a class="brand" href="index.php#overview"><img src="<?= e(docs_asset_url('assets/img/vmange-logo.png')) ?>" alt="VMange"></a>
            <label class="field-block">
                <span>Search docs</span>
                <input id="docs-search" type="search" placeholder="Search documentation">
            </label>
            <?php
            $currentGroup = '';
            foreach ($docs as $docSlug => [$title, $group]):
                if ($group !== $currentGroup):
                    if ($currentGroup !== '') {
                        echo '</nav>';
                    }
                    $currentGroup = $group;
                    echo '<h2>' . e($group) . '</h2><nav>';
                endif;
            ?>
                <a data-doc-link href="docs.php?page=<?= e($docSlug) ?>" class="<?= $docSlug === $slug ? 'active' : '' ?>"><?= e($title) ?></a>
            <?php endforeach; ?>
            </nav>
        </aside>
        <main class="docs-site-main">
            <header class="docs-site-topbar">
                <div>
                    <p class="eyebrow">VMange documentation</p>
                    <nav class="breadcrumbs"><a href="docs.php">Docs</a><span>/</span><span><?= e($docs[$slug][1]) ?></span><span>/</span><strong><?= e($docs[$slug][0]) ?></strong></nav>
                </div>
                <a class="btn ghost" href="index.php#overview">Back to dashboard</a>
            </header>
            <div class="docs-site-grid">
                <article class="panel doc-content docs-article"><?= docs_markdown($body) ?></article>
                <aside class="panel docs-toc">
                    <h2>On this page</h2>
                    <?php foreach ($toc as $item): ?>
                        <a class="<?= $item['level'] === 3 ? 'nested' : '' ?>" href="#<?= e($item['id']) ?>"><?= e($item['title']) ?></a>
                    <?php endforeach; ?>
                </aside>
            </div>
            <nav class="docs-pager">
                <?= $previous ? '<a class="btn ghost" href="docs.php?page=' . e($previous) . '">Previous: ' . e($docs[$previous][0]) . '</a>' : '<span></span>' ?>
                <?= $next ? '<a class="btn ghost" href="docs.php?page=' . e($next) . '">Next: ' . e($docs[$next][0]) . '</a>' : '' ?>
            </nav>
        </main>
    </div>
    <script>
    (() => {
      const input = document.getElementById('docs-search');
      const links = [...document.querySelectorAll('[data-doc-link]')];
      input?.addEventListener('input', () => {
        const term = input.value.trim().toLowerCase();
        links.forEach((link) => {
          link.hidden = term !== '' && !link.textContent.toLowerCase().includes(term);
        });
      });
    })();
    </script>
</body>
</html>
