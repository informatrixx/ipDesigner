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
node --test tests/app.test.js
find src public bin -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/app.js
git diff --check
```

SQLite-Dateien aus `data/`, lokale Zugangsdaten und produktive Konfiguration dürfen niemals committed werden. Vor Änderungen am Schema zuerst ein Backup der Datenbank erstellen und `php bin/migrate.php` gegen eine Testkopie ausführen.

## Release

Für einen Release wird ein annotierter Git-Tag im Format `vMAJOR.MINOR.PATCH` erstellt. Vorher müssen Tests, `git diff --check`, die Migrationsprüfung und die Aktualisierung von `README.md` bzw. `CHANGELOG.md` abgeschlossen sein.
