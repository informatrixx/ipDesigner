# Changelog

Alle nennenswerten Änderungen werden hier pro Release kurz dokumentiert.

## Unreleased

- Benannte Planungssnapshots mit schreibfreier Wiederherstellungsvorschau, Netzcheck und atomarem Rettungssnapshot vor der vollständigen Wiederherstellung.
- Explizite Migration 16 für den Snapshot-Katalog; Schutz vor veralteten Browser-Tabs und konkurrierenden API-Schreibzugriffen bei Restores.
- CSV-Import/Export für Hosts, LAN-/VPN-Interfaces und mehrere IPs, mit gefiltertem Export aus der Hostliste.
- Isolierte CSV-Vorschau mit Netzcheck, expliziten ID-Updates, atomarer Übernahme und Schutz vor veralteten Vorschauen sowie Spreadsheet-Formeln.
- Zentraler Netzcheck mit Filtern, Objektlinks, Dashboard-Zusammenfassung und Topologiehinweisen.
- Gemeinsame Kapazitätsberechnung: Gateways werden einmal berücksichtigt, L3-Pools ohne Doppelzählung ausgewertet.
- Migrationen vom Verbindungsaufbau getrennt; veraltetes Schema liefert eine verständliche Meldung.
- PHP-API-, Modul- und Chromium-Verhaltenstests für die neuen Abläufe.
- Repository-, CI- und Deployment-Dokumentation für den Solo-Developer-Workflow ergänzt.
