# IP Designer

IP Designer ist eine schlanke, lokale Webanwendung zur Planung einer vollständigen IPv4-Infrastruktur. Sites, globale Adressbereiche, dynamisch dimensionierte VLSM-Subnetze, Gruppen, Router, Hosts, Dienste, Pools und Beziehungen werden in einer SQLite-Datenbank verwaltet und in einer interaktiven Topologie visualisiert.

Das Projekt ist für den Betrieb hinter nginx/PHP-FPM ausgelegt. Ein separater PHP-Server ist für den produktiven Betrieb nicht erforderlich.

Sites definieren eine Standard-Domain. Hosts besitzen einen getrennten Anzeigenamen und technischen Hostnamen; der primäre FQDN und kurze DNS-Aliase werden daraus automatisch gebildet. Vollständige externe Aliase sowie Interface-DNS-Aliase werden ebenfalls unterstützt und global auf Eindeutigkeit geprüft.

## Bedarfsorientierte Planung

Unter **Adressplanung** beginnt ein Adressraum nur mit einer Start-IP, beispielsweise `172.20.0.0`. End-IP und Präfix sind für den globalen Adressraum nicht erforderlich. Der Planer berechnet aus Hostbedarf, tatsächlicher Belegung, Gateway und Wachstumsreserve die Subnetzgrößen und ein dynamisch wachsendes Gesamt-CIDR. Einzelne Subnetze können optional einen festen Präfix oder eine feste Start-IP erhalten.

- Optionale Gruppen können beliebig verschachtelt oder vollständig übersprungen werden.
- Nur echte Subnetze nehmen Hosts und Reservierungen auf.
- Die erste nutzbare Adresse ist das Gateway; Hosts beginnen mit der nächsten freien Adresse.
- Die Analyse zeigt freie Kapazität, gleichmäßige Splits und mögliche Buddy-Erweiterungen.
- Jeder Replan zeigt vorab sämtliche CIDR- und IP-Änderungen und wird atomar übernommen.
- Die manuelle Reihenfolge der Gruppen und Subnetze bestimmt die Packreihenfolge.
- Replan-Läufe werden historisiert und können bei unverändertem Folgezustand nach Vorschau zurückgerollt werden.
- IPv4-Zuweisungen sind global eindeutig. VRF gehört nicht zum aktuellen Bedienmodell; ältere Datenbankfelder bleiben aus Kompatibilitätsgründen erhalten.

## Zentraler Netzcheck

Der Menüpunkt **Netzcheck** prüft gespeicherte Adressen, Netzstruktur, Gateways, Pools, Kapazitäten und statische Routen. Ergebnisse lassen sich nach Site, Adressraum, Schweregrad und Prüfart filtern. Das Dashboard zeigt eine Zusammenfassung; anklickbare Symbole in der Topologie führen zu den betroffenen Befunden.

Der Check läuft beim Aufrufen einer Prüf-/Übersichtsansicht und nach relevanten Änderungen in dieser Browsersitzung. „Erneut prüfen“ berücksichtigt auch externe Änderungen. Zoom und Verschieben lösen keine Prüfung aus. Fehler beim Laden bleiben sichtbar; frühere Ergebnisse werden als veraltet markiert.

Es werden keine Geräte gescannt oder automatisch korrigiert. Ein geplanter Gateway ohne Router ist ein Hinweis, bei aktiven Hosts eine Warnung. Auslastungswarnungen beginnen bei 80 %. [Prüfregeln, API und Architektur](docs/NETWORK-CHECK.md) beschreiben Details und Grenzen.

## CSV-Import / Export

Unter **CSV-Transfer** können Hosts und LAN-/VPN-Interfaces in Excel-kompatiblen CSV-Dateien bearbeitet werden. Die Hostliste exportiert auf Wunsch nur die aktuell gefilterten Hosts. Der Import zeigt zuerst sämtliche Änderungen, automatisch vergebene IPs und einen Netzcheck des geplanten Ergebnisses; erst nach ausdrücklicher Bestätigung wird die ganze Datei atomar gespeichert.

Bestehende Datensätze werden ausschließlich über IDs und eine aktivierte Update-Option verändert. Fehlende Zeilen löschen nichts. Mehrere IPs pro Interface, bestehende Pools und absolute FQDNs werden unterstützt. [Format, Bedienung und Grenzen](docs/CSV-TRANSFER.md) beschreiben die Details. CSV ersetzt kein vollständiges SQLite-Backup.

## Planungssnapshots

Unter **Snapshots** lassen sich benannte Gesamtstände sichern und nach einer Änderungsvorschau wiederherstellen. Vor jeder Wiederherstellung entsteht automatisch ein Rettungssnapshot. Veraltete Browser-Tabs dürfen danach nicht weiter speichern. Die Funktion benötigt die explizite Migration 16; die übrige Anwendung bleibt mit Schema 15 nutzbar. Snapshots liegen in derselben Datenbank und ersetzen kein externes Backup. [Bedienung, Schutzmechanismen und Aktivierung](docs/PLANNING-SNAPSHOTS.md).

## Voraussetzungen

- PHP 8.2 oder neuer mit `pdo_sqlite` und `mbstring`
- Ein moderner Browser
- Node.js 22+ und npm für Entwicklungs-/Browsertests; im Webbetrieb nicht benötigt

## Installation

```bash
git clone https://github.com/informatrixx/ipDesigner.git
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

Die Anwendung besitzt bewusst keine Benutzerverwaltung. Sie darf nur lokal oder hinter einem anderweitig abgesicherten Reverse Proxy betrieben werden. Konsistente SQLite-Backups und Updates sind in [Deployment](docs/DEPLOYMENT.md) beschrieben. Schemaänderungen erfolgen ausschließlich über `php bin/migrate.php`, nicht beim Laden der Seite.

## Projektablauf für Solo-Development

Der Standard-Branch ist `main`. Für jede Änderung wird ein kurzer Feature-Branch angelegt, lokal getestet und anschließend per Pull Request (auch bei einem Ein-Personen-Projekt) nach `main` zusammengeführt. Dadurch bleiben Review, CI und Release-Historie nachvollziehbar. Die Regeln für Branches, Commits, Tests und Releases stehen in [CONTRIBUTING.md](CONTRIBUTING.md).

GitHub Actions führt bei Pull Requests und Pushes auf `main` die PHP-, JavaScript- und Browsertests aus. Laufzeitdaten, SQLite-Dateien und lokale Konfiguration werden nicht versioniert.

## Tests

```bash
php tests/run.php
npm ci
npm test
npx playwright install chromium
npm run test:browser
```

Vor einem Commit zusätzlich die wichtigsten Syntaxprüfungen ausführen:

```bash
find src public bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
npm run check
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
