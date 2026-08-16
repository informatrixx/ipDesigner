# Deployment hinter nginx

## Voraussetzungen

- PHP-FPM 8.2+ mit `pdo_sqlite` und `mbstring`
- nginx mit aktivierter PHP-FPM-Weiterleitung
- Schreibbarer `data/`-Ordner für den PHP-FPM-Benutzer

Der öffentliche Root ist ausschließlich `public/`. `src/`, `bin/`, `tests/` und `data/` dürfen nicht direkt ausgeliefert werden.

## Unterpfad `/codex/ipDesigner`

Beispiel für einen Serverblock (Socket und Domain an die Umgebung anpassen):

```nginx
location = /codex/ipDesigner { return 301 /codex/ipDesigner/; }
location /codex/ipDesigner/ {
    alias /var/codex/ipDesigner/public/;
    try_files $uri $uri/ /codex/ipDesigner/index.php?$query_string;
}
location ~ ^/codex/ipDesigner/index\.php(/|$) {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/codex/ipDesigner/public/index.php;
    fastcgi_param SCRIPT_NAME /codex/ipDesigner/index.php;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;
}
```

Die Anwendung erkennt den Unterpfad aus der Anfrage. Für abweichende Proxy-Setups kann `IPDESIGNER_BASE_PATH` gesetzt werden.

## Initialisierung und Berechtigungen

```bash
cd /var/codex/ipDesigner
php bin/migrate.php
chgrp -R www-data data
chmod 2770 data
```

Bei Updates zuerst `git pull --ff-only`, anschließend die Migration ausführen und danach PHP-FPM bzw. den Cache kontrolliert neu laden. SQLite-Backups ausschließlich aus einer konsistenten Kopie erstellen:

```bash
sqlite3 data/ipdesigner.sqlite '.backup data/ipdesigner.sqlite.backup'
```

## Sicherheit

Die Anwendung enthält keine Benutzerverwaltung. Sie muss deshalb durch nginx-Authentifizierung, VPN oder einen anderen vertrauenswürdigen Reverse Proxy geschützt werden. Weitere Hinweise stehen in [SECURITY.md](../SECURITY.md).
