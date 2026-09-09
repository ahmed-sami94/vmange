<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/docs-renderer.php';
secure_session_start();
require_login();
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
$docs = [
    'getting-started'=>['Getting Started','Foundation'],
    'installation'=>['Installation','Foundation'],
    'agent-installation'=>['Agent Enrollment','Foundation'],
    'hosts'=>['Host Operations','Operations'],
    'virtual-machines'=>['Virtual Machines','Operations'],
    'containers-compose'=>['Containers And Compose','Operations'],
    'console-terminal'=>['Console And Terminal','Operations'],
    'wol-host-tools'=>['WOL And Host Tools','Operations'],
    'audit-logs'=>['Audit And Logs','Operations'],
    'alarms-notifications'=>['Alarms And Notifications','Operations'],
    'monitoring-retention'=>['Monitoring And Retention','Operations'],
    'command-lifecycle'=>['Commands And Results','Administration'],
    'maintenance-socket'=>['Local Maintenance Service','Administration'],
    'agent-releases'=>['Agent Releases And Rollouts','Administration'],
    'configuration-backups'=>['Configuration Backups','Administration'],
    'security'=>['Security','Administration'],
    'troubleshooting'=>['Troubleshooting','Reference'],
    'about'=>['About','Reference'],
];
$slug = (string)($_GET['page'] ?? 'getting-started');
$slug = ['virtualbox'=>'virtual-machines','docker-compose'=>'containers-compose'][$slug] ?? $slug;
if (!isset($docs[$slug])) $slug = 'getting-started';
$book = isset($_GET['book']);
$slugs = array_keys($docs);
$index = array_search($slug,$slugs,true);
$search = [];
foreach ($docs as $key=>[$name,$group]) {
    $path = __DIR__ . '/docs/' . $key . '.md';
    $search[] = ['slug'=>$key,'title'=>$name,'text'=>is_file($path)?file_get_contents($path):''];
}
if (isset($_GET['search'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($search,JSON_THROW_ON_ERROR); exit;
}
$parser = new HandbookMarkdown();
$html = $parser->text($search[$index]['text']);
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($book?'Complete Handbook':$docs[$slug][0]) ?> | VMange</title>
<link rel="icon" href="assets/img/vmange-symbol.png">
<link rel="stylesheet" href="assets/css/app.css?v=2.0.0">
<link rel="stylesheet" href="assets/css/handbook.css?v=2.0.0">
<script src="assets/js/handbook.js?v=2.0.0" defer></script>
</head>
<body class="handbook">
<a class="skip-link" href="#article">Skip to content</a>
<header class="book-header">
<a class="brand identity" href="index.php"><img src="assets/img/vmange-symbol.png" alt=""><strong>VMange</strong></a>
<span class="book-edition">Administration Handbook <span class="badge">2.0</span></span>
<div class="actions"><button class="icon-btn" id="book-menu" aria-label="Toggle chapters" aria-expanded="false" title="Chapters">&#9776;</button><button class="icon-btn" id="book-theme" aria-label="Toggle theme" title="Theme">&#9680;</button><a class="btn ghost" href="index.php">Dashboard</a></div>
</header>
<div class="book-layout">
<aside class="book-nav" id="book-nav">
<label for="docs-search">Search handbook</label><input type="search" id="docs-search" placeholder="Search all chapters">
<div id="docs-results" aria-live="polite"></div>
<nav aria-label="Chapters">
<?php $group=''; foreach($docs as $key=>[$name,$section]): if($section!==$group): $group=$section; ?>
<h2><?= e($section) ?></h2>
<?php endif; ?>
<a href="docs.php?page=<?= e($key) ?>" <?= $key===$slug?'aria-current="page"':'' ?>><span><?= array_search($key,$slugs,true)+1 ?>.</span> <?= e($name) ?></a>
<?php endforeach; ?>
</nav>
<a class="btn ghost" href="docs.php?book=1">Complete book / Print</a>
</aside>
<main id="article" tabindex="-1">
<nav class="breadcrumbs" aria-label="Breadcrumb"><a href="docs.php">Handbook</a><span>/</span><span><?= e($book?'Complete book':$docs[$slug][1]) ?></span></nav>
<?php if($book): ?>
<div class="book-cover"><p>VMange 2.0</p><h1>Administration Handbook</h1><p>Installation, operations, security and recovery</p><button class="btn primary" id="book-print">Print handbook</button></div>
<?php foreach($search as $chapter): $chapterParser=new HandbookMarkdown($chapter['slug'].'-'); ?>
<article class="book-chapter doc-content"><?= $chapterParser->text($chapter['text']) ?></article>
<?php endforeach; else: ?>
<p class="eyebrow">Chapter <?= $index+1 ?> / <?= count($docs) ?></p>
<article class="doc-content"><?= $html ?></article>
<nav class="book-pager" aria-label="Chapter navigation">
<?= $index>0?'<a href="docs.php?page='.e($slugs[$index-1]).'"><small>Previous chapter</small><strong>'.e($docs[$slugs[$index-1]][0]).'</strong></a>':'<span></span>' ?>
<?= $index<count($docs)-1?'<a href="docs.php?page='.e($slugs[$index+1]).'"><small>Next chapter</small><strong>'.e($docs[$slugs[$index+1]][0]).'</strong></a>':'' ?>
</nav>
<?php endif; ?>
</main>
<aside class="book-toc"><h2>In this chapter</h2><?php foreach($parser->contents as $item): if($item['level']<2)continue; ?><a href="#<?= e($item['id']) ?>"><?= e($item['title']) ?></a><?php endforeach; ?></aside>
</div>
</body></html>
