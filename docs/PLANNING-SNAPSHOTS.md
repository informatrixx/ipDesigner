# Planungssnapshots

Snapshots sichern einen benannten **Gesamtstand der Planung** in der lokalen SQLite-Datenbank. Sie sind die Grundlage für spätere Planungsvarianten, noch keine parallelen Arbeitskopien oder ein Variantenvergleich.

## Bedienung

1. Unter **Snapshots** einen Namen und optional eine Beschreibung eingeben und den aktuellen Stand sichern.
2. Einen gespeicherten Stand auswählen und die Wiederherstellungsvorschau öffnen. Die laufende Planung wird dabei nicht verändert.
3. Hinzugefügte, geänderte und entfernte Datensätze sowie den Netzcheck des Zielstands prüfen. Detailänderungen sind auf 500 begrenzt; die Tabellenzähler bleiben vollständig.
4. Die Ersetzung des **gesamten** Planungsstands ausdrücklich bestätigen. Enthält der gespeicherte Stand Netzcheck-Fehler, ist eine zusätzliche Bestätigung erforderlich. Es erfolgt keine automatische Korrektur.
5. Wiederherstellen. Unmittelbar davor wird der aktuelle Stand als Rettungssnapshot gesichert. Sicherung und Wiederherstellung sind eine gemeinsame Transaktion. Anschließend wird die Seite vollständig neu geladen.

Der Rettungssnapshot kann über denselben Vorschau-/Bestätigungsablauf wiederhergestellt werden. Scheitert die Wiederherstellung, bleiben sowohl Planung als auch Snapshot-Katalog unverändert. Eine zwischenzeitlich geänderte Planung erfordert eine neue Vorschau.

## Umfang und Grenzen

Enthalten sind sämtliche 22 fachlichen Tabellen: Sites, globale Adressräume, Gruppen/Subnetze/Pools, Hosts, LAN-/VPN-Interfaces samt Zusatzadressen, DNS-Namen/Aliasse, Gateways, statische Routen, virtuelle Netze einschließlich Docker, Dienste, Reservierungen, Beziehungen, Topologiepositionen und Replan-Historie. Auch die älteren Tabellen `address_blocks`, `subnets`, `interfaces` und `reservations` werden erfasst.

Nicht zurückgesetzt werden Anwendungscode, Schema/Migrationshistorie, Snapshot-Katalog, PHP-Sessions oder browserlokale Einstellungen. Sequenzen behalten ihren bisherigen Höchststand, damit neue Objekte nach einem Restore keine inzwischen verwendeten IDs erneut erhalten. Snapshotdaten werden nicht als SQL ausgeführt; Prüfsumme, Format, aktuelles Schema und Fremdschlüssel werden vor der Übernahme geprüft.

- Snapshots sind unveränderlich; diese erste Version bietet keinen Import, Download oder Löschmechanismus für Snapshots.
- Sie liegen in **derselben Datenbank** und sind daher kein unabhängiges Backup. Bei Verlust der SQLite-Datei gehen auch die Snapshots verloren. Externe konsistente SQLite-Backups bleiben notwendig.
- Jeder Snapshot enthält den vollständigen Planungsstand einschließlich Historie (maximal 32 MiB JSON pro Snapshot). Datenbankgröße und Speicherplatz im Blick behalten; keine automatische Bereinigung.
- Nur mit passendem Datenbankschema wiederherstellbar. Eine spätere Migration kann ältere Snapshots inkompatibel machen; vor Schemaänderungen immer extern sichern.

## Veraltete Tabs und gleichzeitige Anfragen

Jede Wiederherstellung ändert eine serverseitige Kennung (`restore_epoch`). Browser behalten die beim Laden gelesene Kennung bei und senden sie als `X-Planning-Epoch`. Veraltete oder fehlende Kennungen werden nach Aktivierung der Funktion bei schreibenden API-Anfragen zurückgewiesen. Die Oberfläche fordert zum Neuladen auf; sie übernimmt die neue Kennung nicht stillschweigend in einen alten Planungsstand.

API-Mutationen halten eine gemeinsame Dateisperre, Wiederherstellungen eine exklusive Sperre. Die Kennung wird erst nach Erwerb der Sperre geprüft. So kann eine bereits wartende Änderung aus einem alten Tab nicht nach dem Restore durchrutschen. Die Sperrdatei liegt neben der SQLite-Datei mit dem Suffix `.planning.lock`; das Datenverzeichnis muss wie bisher für PHP-FPM schreibbar sein.

Diese Koordination gilt für die API dieser Installation. Direkte SQLite-/CLI-Schreibzugriffe oder andere Programme umgehen sie und dürfen nicht gleichzeitig mit der Anwendung planen/wiederherstellen. Migrationen und externe Restores gehören in ein Wartungsfenster. Eine externe Rücksicherung der gesamten Datei setzt auch die Kennung zurück: danach alle Tabs schließen/neu laden.

## Aktivierung / Migration 16

Die Funktion benötigt die neuen Tabellen `planning_snapshots` und `planning_state`. Die vorhandenen Ansichten bleiben mit Schema 15 lauffähig; ausschließlich die Snapshot-Endpunkte melden bis zur expliziten Migration HTTP 503. Ein Seitenaufruf migriert nichts.

Vor der Aktivierung: vollständiges SQLite-Backup, Migration zuerst auf einer Testkopie prüfen, danach im Wartungsfenster als PHP-FPM-Benutzer ausführen. Beispielbefehle und Berechtigungen stehen in [Deployment](DEPLOYMENT.md). Anschließend alle Browser-Tabs neu laden. Die Implementierung allein führt keine Migration oder Wiederherstellung produktiver Daten aus.

## API

Alle Antworten verwenden die bestehende JSON-Hülle `{ "data": ... }`; Schreibzugriffe benötigen CSRF und nach Migration 16 `X-Planning-Epoch`. Die Kennung steht im gleichnamigen Antwortheader der API; zuerst einen GET ausführen.

| Endpunkt | Bedeutung |
| --- | --- |
| `GET /api/snapshots` | Katalog mit Metadaten und Objektzahlen, ohne Snapshot-Payloads |
| `POST /api/snapshots` | Aktuellen Stand sichern: `name`, optional `description` |
| `POST /api/snapshots/{id}/preview` | Schreibfreie Vorschau mit Deltas, Netzcheck, `revision` und `plan_hash` |
| `POST /api/snapshots/{id}/restore` | `revision`, `plan_hash`, `confirm_restore: true`, gegebenenfalls `confirm_errors: true` |

`PlanningSnapshots` kapselt die Sicherung/Wiederherstellung, `PlanningWriteGuard` die Request-Koordination. Der CSV-Transfer kopiert keine Snapshot-Payloads; das Anlegen einer Sicherung allein entwertet seine Vorschau nicht. Eine Wiederherstellung entwertet dagegen alte Vorschauen auch dann, wenn der fachliche Inhalt wieder identisch ist.

## Prüfungen

Automatisierte Tests verwenden ausschließlich synthetische In-Memory-/temporäre Datenbanken. Sie prüfen den Roundtrip aller 22 Tabellen, den Rettungs-Restore, Read-only-Vorschauen, beschädigte Payloads, veraltete Vorschauen, vollständigen Rollback, konkurrierende Verbindungen/Prozesse und den Schutz alter Tabs. Frontcontroller-Tests prüfen Read-only-Katalog, Schema-15-Kompatibilität und abgewiesene Schreibanfragen. Browsertests verwenden echte PHP-erzeugte Vorschauantworten mit abgefangenem HTTP; eine erfolgreiche Testausführung ist keine produktive Restore-Abnahme.
