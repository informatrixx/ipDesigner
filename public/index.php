<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Http.php';

use function IpDesigner\Http\routePath;
use function IpDesigner\Http\url;

$route = routePath();
if ($route === '/api' || str_starts_with($route, '/api/')) {
    require __DIR__ . '/api.php';
    exit;
}

session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict']);
$_SESSION['csrf'] ??= bin2hex(random_bytes(24));
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
$assetVersion = max((int) filemtime(__DIR__ . '/assets/app.css'), (int) filemtime(__DIR__ . '/assets/app.js'));
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES) ?>">
  <meta name="app-base" content="<?= htmlspecialchars(url(''), ENT_QUOTES) ?>">
  <title>IP Designer</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(url('/assets/app.css'), ENT_QUOTES) ?>?v=<?= $assetVersion ?>">
</head>
<body>
  <aside class="sidebar">
    <a class="brand" href="#dashboard"><span class="brand-mark">IP</span><span>Designer<small>IPv4 Infrastructure</small></span></a>
    <nav aria-label="Hauptnavigation">
      <a href="#dashboard" data-view="dashboard">Übersicht</a>
      <a href="#sites" data-view="sites">Sites</a>
      <a href="#ipam" data-view="ipam">Adressplanung</a>
      <a href="#hosts" data-view="hosts">Hosts</a>
      <a href="#routers" data-view="routers">Router</a>
      <a href="#services" data-view="services">Dienste</a>
      <a href="#topology" data-view="topology">Topologie</a>
      <a href="#relations" data-view="relations">Beziehungen</a>
      <a href="#tools" data-view="tools">Tools</a>
    </nav>
    <div class="sidebar-note"><span class="live-dot"></span> Lokale SQLite-Instanz</div>
  </aside>
  <main>
    <header class="topbar">
      <button class="mobile-menu" aria-label="Navigation öffnen">☰</button>
      <div><p class="eyebrow">INFRASTRUKTUR</p><h1 id="page-title">Übersicht</h1></div>
      <label class="global-search"><span>⌕</span><input id="global-search" type="search" placeholder="Aktuelle Ansicht filtern …"></label>
    </header>
    <div id="notice" role="status" aria-live="polite"></div>
    <section id="app" class="content" aria-live="polite"><div class="loading">Daten werden geladen …</div></section>
  </main>
  <dialog id="modal">
    <form method="dialog" id="modal-form">
      <header><div><p class="eyebrow" id="modal-kicker">DATENSATZ</p><h2 id="modal-title">Anlegen</h2></div><button type="button" class="icon-button modal-cancel" aria-label="Schließen">×</button></header>
      <div id="modal-body" class="form-grid"></div>
      <footer><button type="button" class="button secondary modal-cancel">Abbrechen</button><button type="submit" id="modal-submit" class="button primary">Speichern</button></footer>
    </form>
  </dialog>
  <div id="context-menu" class="context-menu" role="menu" hidden></div>
  <script type="module" src="<?= htmlspecialchars(url('/assets/app.js'), ENT_QUOTES) ?>?v=<?= $assetVersion ?>"></script>
</body>
</html>
