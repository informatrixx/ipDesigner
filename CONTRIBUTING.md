# Mitwirken

Dieses Repository wird zunächst von einem Solo-Developer gepflegt. Ein schlanker, aber reproduzierbarer Ablauf verhindert trotzdem, dass funktionierende Infrastrukturänderungen und Dokumentation auseinanderlaufen.

## Arbeitsablauf

1. `main` aktualisieren: `git switch main && git pull --ff-only`.
2. Einen Branch mit Präfix `feature/`, `fix/` oder `docs/` anlegen.
3. Änderung klein halten und die passende Dokumentation mitpflegen.
4. Tests und Syntaxprüfungen lokal ausführen.
5. Branch pushen und einen Pull Request nach `main` öffnen. CI muss grün sein.
6. Nach dem Merge den Branch löschen und lokal auf `main` zurückkehren.

Für dringende lokale Wartung ist ein direkter Commit auf `main` möglich, sollte aber die Ausnahme bleiben.

## Commits

Commit-Betreffs sind kurz und im Imperativ, zum Beispiel `Fix automatic free IP assignment` oder `Docs: describe nginx subpath deployment`. Ein Commit beschreibt möglichst eine zusammenhängende Änderung.

## Qualitätscheck

```bash
php tests/run.php
npm ci
npm test
npx playwright install chromium
npm run test:browser
find src public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
npm run check
git diff --check
```

SQLite-Dateien aus `data/`, lokale Zugangsdaten und produktive Konfiguration dürfen niemals committed werden. Vor Änderungen am Schema zuerst ein Backup der Datenbank erstellen und `php bin/migrate.php` gegen eine Testkopie ausführen.

Die Browsertests verwenden die echte Oberfläche und synthetische, mit PHP erzeugte API-Daten. HTTP wird im Browser abgefangen; die produktive Datenbank und der nginx-Dienst werden nicht angesprochen. PHP-API-Tests prüfen den Frontcontroller separat mit temporären Datenbanken. Unter Linux können für Chromium einmalig Systembibliotheken nötig sein: `npx playwright install --with-deps chromium`.

Neue Logik als eigenständiges Modul ergänzen. Die Netzcheck-Regeln und gemeinsame Kapazitätsberechnung sind ein Beispiel; Quelltextmuster-Tests allein gelten nicht als Verhaltensnachweis.

## Release

Für einen Release wird ein annotierter Git-Tag im Format `vMAJOR.MINOR.PATCH` erstellt. Vorher müssen Tests, `git diff --check`, die Migrationsprüfung und die Aktualisierung von `README.md` bzw. `CHANGELOG.md` abgeschlossen sein.
