# IP Designer

IP Designer ist eine schlanke, lokale Webanwendung zur Planung einer vollständigen IPv4-Infrastruktur. Sites, globale Adressbereiche, dynamisch dimensionierte VLSM-Subnetze, Gruppen, Router, Hosts, Dienste, Pools und Beziehungen werden in einer SQLite-Datenbank verwaltet und in einer interaktiven Topologie visualisiert.

Das Projekt ist für den Betrieb hinter nginx/PHP-FPM ausgelegt. Ein separater PHP-Server ist für den produktiven Betrieb nicht erforderlich.

Sites definieren eine Standard-Domain. Hosts besitzen einen getrennten Anzeigenamen und technischen Hostnamen; der primäre FQDN und kurze DNS-Aliase werden daraus automatisch gebildet. Vollständige externe Aliase sowie Interface-DNS-Aliase werden ebenfalls unterstützt und global auf Eindeutigkeit geprüft.

## Bedarfsorientierte Planung

Unter **Adressplanung** beginnt ein Adressraum nur mit einer Start-IP, beispielsweise `172.20.0.0`, sowie einer Routing-Domäne/VRF. End-IP und Präfix werden nicht eingegeben. Der Planer berechnet aus Hostbedarf, tatsächlicher Belegung, Gateway und Wachstumsreserve die Subnetzgrößen und ein dynamisch wachsendes Gesamt-CIDR.

- Optionale Gruppen können beliebig verschachtelt oder vollständig übersprungen werden.
- Nur echte Subnetze nehmen Hosts und Reservierungen auf.
- Die erste nutzbare Adresse ist das Gateway; Hosts beginnen mit der nächsten freien Adresse.
- Die Analyse zeigt freie Kapazität, gleichmäßige Splits und mögliche Buddy-Erweiterungen.
- Jeder Replan zeigt vorab sämtliche CIDR- und IP-Änderungen und wird atomar übernommen.
- Die manuelle Reihenfolge der Gruppen und Subnetze bestimmt die Packreihenfolge.
- Replan-Läufe werden historisiert und können bei unverändertem Folgezustand nach Vorschau zurückgerollt werden.
- Überlappende IPv4-Adressen sind nur in unterschiedlichen Routing-Domänen zulässig.

## Voraussetzungen

- PHP 8.2 oder neuer mit `pdo_sqlite` und `mbstring`
- Ein moderner Browser
- Optional Node.js 18+ für die JavaScript-Tests

## Installation

```bash
git clone https://github.com/<account>/ipDesigner.git
cd ipDesigner
php bin/migrate.php
```

Die SQLite-Datei liegt standardmäßig unter `data/ipdesigner.sqlite`. Der Webserver muss `public/` als Document Root verwenden. Die vollständige nginx-Konfiguration und die Unterpfad-Installation unter `/codex/ipDesigner` stehen in [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

## nginx-Betrieb

Der produktive Webroot ist `public/`. Die Anwendung erkennt einen Pfad nach dem Schema `/codex/<projekt>` automatisch und setzt Asset- und API-URLs entsprechend. nginx muss alle nicht-statischen Projektpfade an `public/index.php` übergeben; dieser Frontcontroller verarbeitet sowohl die Oberfläche als auch `/api/...`.

Der PHP-FPM-Benutzer benötigt Schreibrechte auf `data/`, beispielsweise:

```bash
chgrp www-data data
chmod 2770 data
```

Für Installationen an einem anderen Unterpfad kann `IPDESIGNER_BASE_PATH` gesetzt werden.

## Lokaler Entwicklungsserver (optional)

```bash
php bin/migrate.php
php -S 127.0.0.1:8080 router.php
```

Danach `http://127.0.0.1:8080` öffnen. Der Entwicklungsserver ist für den nginx-Betrieb nicht erforderlich. Die Datenbank wird standardmäßig als `data/ipdesigner.sqlite` angelegt. Ein anderer Pfad kann über `IPDESIGNER_DB_PATH` gesetzt werden:

```bash
IPDESIGNER_DB_PATH=/srv/ipdesigner/data.sqlite php -S 127.0.0.1:8080 router.php
```

Die Anwendung besitzt bewusst keine Benutzerverwaltung. Sie darf nur lokal oder hinter einem anderweitig abgesicherten Reverse Proxy betrieben werden. Für ein konsistentes Backup den PHP-Prozess kurz beenden und anschließend die SQLite-Datei kopieren.

## Projektablauf für Solo-Development

Der Standard-Branch ist `main`. Für jede Änderung wird ein kurzer Feature-Branch angelegt, lokal getestet und anschließend per Pull Request (auch bei einem Ein-Personen-Projekt) nach `main` zusammengeführt. Dadurch bleiben Review, CI und Release-Historie nachvollziehbar. Die Regeln für Branches, Commits, Tests und Releases stehen in [CONTRIBUTING.md](CONTRIBUTING.md).

GitHub Actions führt bei jedem Push und Pull Request die PHP- und JavaScript-Tests aus. Laufzeitdaten, SQLite-Dateien und lokale Konfiguration werden nicht versioniert.

## Tests

```bash
php tests/run.php
node --test tests/app.test.js
```

Vor einem Commit zusätzlich die wichtigsten Syntaxprüfungen ausführen:

```bash
find src public bin -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/app.js
```

## Verzeichnisübersicht

| Pfad | Zweck |
| --- | --- |
| `public/` | Öffentlicher Webroot, Frontcontroller und Browser-Assets |
| `src/` | Domänenlogik, SQLite-Repository und Planungsalgorithmen |
| `bin/migrate.php` | Datenbankschema initialisieren bzw. aktualisieren |
| `tests/` | PHP- und JavaScript-Tests |
| `data/` | Laufzeitdaten (nicht versioniert) |
| `docs/` | Betrieb und technische Hinweise |

## Fachliche Regeln

- Eigenständige Adressbereiche sind global über alle Sites kollisionsfrei.
- Subnetze müssen vollständig in ihrem Bereich liegen und dürfen einander nicht überschneiden.
- Host-Interfaces und Reservierungen verwenden global eindeutige IP-Adressen.
- `/31` stellt zwei nutzbare Punkt-zu-Punkt-Adressen bereit, `/32` eine einzelne Adresse.
- VLSM-Pläne werden zunächst berechnet und anschließend in einer Transaktion vollständig übernommen.
