# CSV-Transfer für Hosts und Interfaces

## Ablauf

1. Unter **Hosts** optional filtern und **Gefilterte Hosts exportieren** wählen. Unter **CSV-Transfer** gibt es alternativ einen Gesamtexport und eine leere Vorlage.
2. CSV in Excel oder einem Texteditor bearbeiten und als **CSV UTF-8** speichern. Bereits vorhandene Sites, Netze und Pools verwenden; deren Namen und IDs stehen in der Oberfläche unter „Spalten und vorhandene Zielnetze“.
3. Datei unter **CSV-Transfer** laden oder den Inhalt einfügen. Für bearbeitete Exporte „Bestehende IDs aktualisieren“ aktivieren. Ohne diese Option werden vorhandene IDs zurückgewiesen.
4. **Vorschau und Netzcheck erstellen**. Die Tabelle zeigt Vorher-/Nachher-Werte und konkret zugeteilte automatische IPs. Noch nichts wurde gespeichert.
5. Änderungen und Hinweise prüfen, Bestätigungsfeld aktivieren und gemeinsam übernehmen. Bei einem Fehler wird die ganze Datei verworfen.

Kein Hintergrundimport und keine automatische Übernahme: Die Einstellung für automatische Replans gilt hier nicht. Ein CSV-Export ist **kein vollständiges Backup**. SQLite-Backups sind weiterhin notwendig.

## Format

Export: UTF-8 mit BOM, Semikolon, CRLF, eine Zeile pro LAN-/VPN-Interface. Hosts ohne Interfaces erhalten eine Hostzeile. Der Import erkennt zusätzlich Komma, Tabulator und eine optionale `sep=`-Zeile. Mehrzeilige bzw. trennzeichenhaltige Textfelder müssen in CSV-Anführungszeichen stehen; eingebettete Anführungszeichen werden verdoppelt.

Maximal **500 Datensätze und 512 KiB pro Import**. Größere Exporte in kleinere Dateien mit jeweils eigener Kopfzeile aufteilen. Die Nummern in Fehlermeldungen bezeichnen CSV-Datensätze einschließlich Kopfzeile, nicht physische Textzeilen innerhalb mehrzeiliger Felder. Unbekannte/doppelte Spalten, ungültiges UTF-8 und fehlerhafte Anführungszeichen werden abgewiesen.

Minimalbeispiel – Site `Testsite` und Netz `LAN` müssen bereits existieren:

```csv
site;name;hostname;interface_name;network;ips
Testsite;Webserver;web-01;eth0;LAN;
Testsite;Externer Name;office.example.org.;eth0;LAN;
```

Die leere IP wird in der Vorschau konkret vergeben. Für mehrere Interfaces eines neuen Hosts mehrere Zeilen mit identischen Hostfeldern verwenden.

| Spalte | Bedeutung |
| --- | --- |
| `host_id` | Leer: neuer Host. Bestehende ID: genau diesen Host aktualisieren; Update-Option erforderlich. Namen allein ändern nie bestehende Hosts. |
| `site`, `name`, `hostname` | Pflichtspalten und Pflichtwerte. Site-Name, Anzeigename und technischer Hostname. Absolute FQDNs mit abschließendem Punkt. |
| `type`, `status` | Hosttyp bzw. Status wie im Einzeldialog. Neue Hosts: standardmäßig `server` / `planned`. Leere enthaltene Felder verwenden diese Standardwerte (`network` bei expliziter Routerrolle und leerem Typ). |
| `description` | Beschreibung; vorhandene Spalte mit leerem Wert leert die Beschreibung. Nicht enthaltene optionale Hostspalten bleiben bei Updates erhalten. |
| `is_router` | Routerrolle neuer Hosts (1/0). Bestehende Routerrolle bleibt geschützt. |
| `interface_id` | Leer: neues Interface. Bestehende ID muss zum Host und Interface-Typ gehören. |
| `interface_type` | `lan` (Standard) oder `vpn`. Die ID gehört zur jeweiligen Interface-Tabelle. |
| `interface_name` | Beispielsweise `eth0` oder `wg0`. Ohne Interface-Daten ist auch eine reine Hostzeile möglich. |
| `network_id`, `network` | ID oder Name eines vorhandenen L3-/VPN-Netzes. LAN-Namen werden in der Host-Site aufgelöst, VPN-Namen global. Wenn beides angegeben ist, muss beides zusammenpassen. |
| `pool_id`, `pool` | Optionaler bestehender Pool innerhalb des gewählten L3-Netzes. Nur für LAN; leer erlaubt die bestehende automatische Poolzuordnung. |
| `ips` | Primäre IP zuerst, weitere IPs mit ` \| ` getrennt. Leeres LAN-Feld: nächste freie IP; bei Routern das Gateway. VPN benötigt ausdrückliche IPs. Bei Updates erhält eine ganz fehlende Spalte die bisherigen IPs. |
| `mac_address`, `dns_name` | Optionale LAN-Interface-Werte; leere enthaltene Felder leeren den Wert. Nicht für VPN. |
| `is_gateway` | LAN-Gateway-Funktion für Router; keine Entfernung, Netzverschiebung oder Übernahme fremder Gateway-Bindungen per CSV. |
| `confirm_dhcp_overlap` | `1` bestätigt ausdrücklich die statische Vergabe im DHCP-Pool. Bei bestehenden DHCP-Zuordnungen exportiert der Planer diese Bestätigung mit. |

Ja/Nein-Felder akzeptieren `1/0`, `true/false` oder `ja/nein`. Ein leeres Ja/Nein-Feld bedeutet Nein. Neue Sites, Netze und Pools werden nicht automatisch angelegt. Gleichnamige Netze in unterschiedlichen Sites sind zulässig. Bei einer ID-/Namensabweichung wird nichts stillschweigend umgedeutet.

## Grenzen und Schutzmechanismen

- Keine Löschungen durch fehlende Zeilen. Aliasse, Notizen, Dienste, Routen, Beziehungen, Topologiepositionen und Docker-Netze werden nicht exportiert oder ersetzt. Legacy-Interfaces aus dem alten Tabellenmodell sind ebenfalls nicht enthalten.
- Site-Wechsel und Änderungen der Routerrolle bestehender Hosts sowie Änderungen bestehender Gateway-Zuordnungen gehören in die entsprechenden Einzeldialoge.
- Ein bestehendes Interface darf nur einmal pro Datei vorkommen. LAN-Interfacenamen sind pro Host eindeutig; derselbe VPN-Interfacename darf für unterschiedliche VPN-Netze auftreten.
- Keine automatische Umnummerierung oder Netzerweiterung bei Platzmangel. IP-Tausch zwischen bereits belegten Interfaces ist kein unterstützter Sammelvorgang; vorab eine freie Zwischenadresse verwenden.
- Alle Netzcheck-Fehler im geplanten Gesamtstand blockieren den Import, auch bereits bestehende Fehler. Warnungen und Hinweise sind sichtbar, blockieren aber nicht.
- Formelartig beginnende Textwerte werden beim Export mit einem Apostroph geschützt. Der Import entfernt diese reversible Schutzmarkierung. Excel-Formatierungen werden nicht importiert; die CSV bleibt Text.
- Vorschau und Export lesen einen konsistenten SQLite-Snapshot. Die Vorschau wird ausschließlich in einer flüchtigen In-Memory-Kopie simuliert, nicht in der laufenden Datenbank.
- Übernehmen prüft in einer eigenen Transaktion erneut CSV, Optionen, vollständigen Datenstand und Vorschau-Hash. Auch Layout-/DNS-/Reservierungsänderungen seit der Vorschau machen diese ungültig. Bei Abweichung, Konflikt oder Abbruch: Rollback, erneut prüfen.
- Ein unveränderter Export kann mit aktivierter Update-Option ohne fachliche Änderung wieder eingelesen werden. CSVs mit neuen Objekten ohne IDs sind nicht wiederholt anwendbar; nach dem Import neu exportieren.

## API und Entwicklung

Alle Endpunkte verwenden die bestehende JSON-Hülle `{ "data": ... }`. Downloads werden im Browser aus dem zurückgegebenen CSV-Text erzeugt; kein Export wird serverseitig abgelegt.

- `GET /api/host-csv/template`: `filename`, `csv` mit Kopfzeile ohne Datensätze.
- `GET /api/host-csv/export`: `filename`, `csv`, `hosts`, `rows`. Optional `host_ids=1,2,3` für die in der Hostliste gefilterten IDs; ohne Filter alle Hosts.
- `POST /api/host-csv/preview`: `{ "csv": "...", "allow_updates": false }`. Liefert `revision`, `plan_hash`, `can_apply`, Zähler, Zeilendeltas, Zeilenfehler und den Netzcheck des simulierten Gesamtstands.
- `POST /api/host-csv/apply`: dieselben Inhalte plus die `revision` und den `plan_hash` der Vorschau. Bei Erfolg `saved: true`; andernfalls bestehende HTTP-422-/409-Fehlerhülle. POST benötigt wie alle Schreibzugriffe das CSRF-Token.

`Csv` kapselt das Textformat; `HostCsvTransfer` orchestriert Snapshot, Vorschau und atomaren Import über die vorhandenen Repository-Validierungen. `host-csv.js` bleibt ein separates Browsermodul. Keine Migration, neue Tabelle oder Laufzeitabhängigkeit notwendig.

Tests decken Format, Formelschutz, automatische IPs, Multi-IP, LAN/VPN, Poolgrenzen, DHCP-Bestätigung, Schutz bestehender Gateways, ID-Zuordnung, Read-only-Vorschau, Roundtrip, Aliaserhalt, manipulierte/veraltete Vorschauen und Rollback ab. Browserprüfungen verwenden echte, in PHP erzeugte Importvorschauen mit abgefangenem HTTP und prüfen auch den Seitenwechsel während eines laufenden Imports; keine produktiven Daten werden beschrieben.
