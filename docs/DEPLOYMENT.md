# Deployment hinter nginx

## Voraussetzungen

- PHP-FPM 8.2+ mit `pdo_sqlite` und `mbstring`
- nginx mit aktivierter PHP-FPM-Weiterleitung
- Schreibbarer `data/`-Ordner für den PHP-FPM-Benutzer

Der öffentliche Root ist ausschließlich `public/`. `src/`, `bin/`, `tests/` und `data/` dürfen nicht direkt ausgeliefert werden.

## Unterpfad `/codex/ipDesigner`

Beispiel innerhalb eines Serverblocks (Socket an die Umgebung anpassen). Die Regex-Locations vor allgemeinen PHP-Regeln des Servers einfügen:

```nginx
location = /codex/ipDesigner { return 301 /codex/ipDesigner/; }
location ~ ^/codex/ipDesigner/assets/([a-zA-Z0-9._-]+\.(?:css|js))$ {
    alias /var/codex/ipDesigner/public/assets/$1;
}
location = /codex/ipDesigner/index.php {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/codex/ipDesigner/public/index.php;
    fastcgi_param SCRIPT_NAME /codex/ipDesigner/index.php;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}
location ~ ^/codex/ipDesigner/.*\.php(?:/|$) { return 404; }
location /codex/ipDesigner/ {
    rewrite ^ /codex/ipDesigner/index.php last;
}
```

Nur CSS/JS-Assets werden statisch ausgeliefert; PHP wird ausschließlich über den Frontcontroller ausgeführt. Insbesondere darf `/codex/ipDesigner/api.php` weder als Quelltext ausgeliefert noch direkt aufgerufen werden. API-Aufrufe verwenden `/codex/ipDesigner/api/...`. Die Anwendung erkennt den Unterpfad aus der ursprünglichen Anfrage. Für abweichende Proxy-Setups kann `IPDESIGNER_BASE_PATH` gesetzt werden.

## Initialisierung und Berechtigungen

```bash
cd /var/codex/ipDesigner
install -d -m 2770 -g www-data data
sudo -u www-data php bin/migrate.php
```

Bestehende SQLite-Dateien müssen ebenfalls für den PHP-FPM-Benutzer schreibbar sein. Die Migration unter diesem Benutzer verhindert neue, nur für root schreibbare Dateien. `Database::connect()` migriert nicht mehr automatisch. Eine fehlende oder veraltete Datenbank liefert HTTP 503 mit dem Hinweis auf den Migrationsbefehl.

Vor Updates ein konsistentes Backup erstellen, danach `git pull --ff-only` und die Migration ausführen. Schemaänderungen in einem Wartungsfenster durchführen; bei einem Code-Rollback gegebenenfalls auch das zugehörige Backup zurückspielen. SQLite-Backups mit der Backup-Funktion erstellen:

```bash
sqlite3 data/ipdesigner.sqlite '.backup data/ipdesigner.sqlite.backup'
```

Nach dem Update PHP-FPM/OPcache nach Bedarf neu laden. Bei nginx-Konfigurationsänderungen zuerst `nginx -t`, dann kontrolliert reloaden. Beim Smoke-Test Oberfläche, CSS/JS und `/api/network-check` unter dem konfigurierten Unterpfad prüfen; direkte PHP-Dateien wie `/api.php` müssen abgewiesen werden.

Node.js/npm und Chromium sind ausschließlich Entwicklungs- und CI-Abhängigkeiten. Die Laufzeit benötigt weiterhin nur nginx, PHP-FPM und SQLite. Der Netzcheck benötigt keine neue Migration oder zusätzliche Datenbanktabellen.

## Sicherheit

Die Anwendung enthält keine Benutzerverwaltung. Sie muss deshalb durch nginx-Authentifizierung, VPN oder einen anderen vertrauenswürdigen Reverse Proxy geschützt werden. Weitere Hinweise stehen in [SECURITY.md](../SECURITY.md).
