# Zentraler Netzcheck

## Bedienung und Grenzen

Die Prüfliste ist über `#network-check` erreichbar. Dashboard und Topologie zeigen dieselben Ergebnisse. Filter werden im URL-Fragment gespeichert (`site`, `space`, `severity`, `category`, `entity`). Objektlinks öffnen die passende Site, Adressplanung, Host- oder Routeransicht. Topologiesymbole beeinflussen weder gespeicherte Positionen noch Containergrößen.

Fehler kennzeichnen widersprüchliche Daten, Warnungen beispielsweise knappe Kapazität oder ein ungebundenes Gateway bei aktiven Hosts. Geplante Gateways und bewusst statische Adressen in DHCP-Pools erzeugen Hinweise. Ein leerer Bericht ist kein Nachweis für Erreichbarkeit oder korrekte Gerätekonfiguration. Der Check führt keine Netzwerkabfragen oder Reparaturen aus.

Die aktuelle Adressplanung (`network_nodes`) wird vollständig durch die unten aufgeführten Regeln geprüft. Alte Tabellen (`address_blocks`, `subnets`, `interfaces`) bleiben unverändert erhalten. Alte Interface-Adressen fließen in die Dublettenprüfung ein; vorhandene Altbestände erhalten ausdrücklich einen Hinweis auf die eingeschränkte Abdeckung. VRF-Isolation wird nicht angeboten. Docker-Bridges dürfen ihr CIDR auf unterschiedlichen Besitzgeräten wiederverwenden.

## Prüfregeln

| Kategorie | Regeln |
| --- | --- |
| Adressen | Globale Dubletten einschließlich sekundärer und VPN-Adressen, ungültige Zahlenwerte, nutzbarer Netzbereich, zugehöriger Host und L3-Bereich |
| Netzstruktur | CIDR und gespeicherte Grenzen, Eltern-/Site-/Adressraum-Zuordnung, Hierarchiezyklen, Überlappung eigenständiger Netze und Pools |
| Gateways | Nutzbare Gateway-Adresse, passender Router und Interface, konsistente Bindung und Offset; reserviertes Gateway nicht zusätzlich vergeben |
| Pools | Gültiger gemeinsamer L3-Vorfahre, Bereichsgrenzen, Poolzuordnung jeder Adresse, statische Adressen im DHCP-Bereich |
| Kapazität | Warnung ab 80 % oder ohne freie Hostadresse; kein separates Addieren von Pools zur Site-/Dashboard-Kapazität |
| Routen | Router und Ausgangsinterface, Next Hop im Ausgangsnetz und nicht auf eigener Interface-IP, gültiges Ziel-CIDR und Übereinstimmung mit referenziertem Netz |

Gruppen/Supernetze dürfen ihre Kinder enthalten. Pools überlagern ihr gemeinsames L3-Netz absichtlich, nicht jedoch andere Pools. Gleichlautende VLAN-IDs sind für sich allein kein Fehler. Kapazität zählt gespeicherte Adressbelegungen und Reservierungen, nicht beobachtete DHCP-Leases. Netz-/Broadcast-Adressen ergeben sich für Pools aus dem L3-Netz; Pool-CIDR-Grenzen bleiben nutzbar, sofern das L3-Netz dies erlaubt. Das Gateway wird genau einmal von der Hostkapazität abgezogen. `/31` und `/32` folgen der IPv4-Mathematik; die Berechnung zählt keine großen Adressräume einzeln auf.

## API

`GET /api/network-check` nutzt die vorhandene JSON-Hülle `{ "data": ... }`. Die Antwort enthält:

- `checked_at`: UTC-Zeitpunkt des Prüflaufs.
- `revision`: SHA-256 des eingelesenen Datenstands; keine Schreibfreigabe oder Replan-Revision.
- `summary`: Anzahl `error`, `warning`, `info`.
- `coverage`: `complete` und Anzahl `legacy_records`.
- `findings`: stabiler `id`, `code`, `severity`, `category`, `message`, `action`, `objects`.
- `capacity`: Netz-ID → `host_capacity`, `assigned_count`, `reserved_count`, `occupied_count`, `free_count`, `utilization`.
- `sites` und `address_spaces`: ID-/Namenslisten für die Filter, auch wenn normale Planungsansichten beschädigte Daten nicht mehr rendern können.

Objektverweise enthalten `kind`, `id`, `entity_id`, `name`, Site-/Adressraum-ID und -Name sowie gegebenenfalls `host_id` und `network_id`. `entity_id` bezeichnet den sichtbaren Topologieknoten; Interface- und Routen-IDs sind keine Entity-IDs. Fehlende Zuordnungen bleiben `null`. Befunde sind nach Schweregrad, Prüfcode und stabiler ID sortiert.

Der Endpoint ist lesend und verwendet eine SQLite-Lesetransaktion für einen konsistenten Datenstand. Ergebnisse werden nicht in der Datenbank gespeichert. Fehlendes/veraltetes Schema liefert HTTP 503 mit dem Hinweis auf `php bin/migrate.php`; technische Fehler liefern keinen leeren Erfolgsbericht.

## Architektur und Entwicklung

`NetworkCheck` liest Rohdatensätze und wertet sie unabhängig von Repository-Ansichten und Replanner aus. Damit bleibt die Prüfung bei Hierarchiefehlern oder ungültigen CIDRs verfügbar. `NetworkCapacity` wird gemeinsam von Netzcheck, Dashboard, Sites und Netzansichten genutzt. Neue Regeln ergänzen stabile Prüfcodes und aussagekräftige, synthetische Fixtures.

Das Browsermodul `network-check.js` enthält filterbare Darstellung, Objektlinks, Cache und Markerberechnung. Erfolgreiche fachliche Schreiboperationen machen Ergebnisse ungültig; neue Prüf-/Übersichtsansichten laden erneut. Koordinatenspeicherung, Kameraaktionen und Rechner/Vorschauen sind ausgenommen. Eine fehlgeschlagene Prüfung erhält den letzten Bericht sichtbar als veraltet. Andere Browser oder externe Datenänderungen werden bei einem neuen Prüflauf erkannt.

Tests: `php tests/run.php`, `npm test`, `npm run test:browser`. Die Browserprüfung verwendet [Playwrights HTTP-Abfangmöglichkeiten](https://playwright.dev/docs/network) mit synthetischen PHP-Daten; sie ersetzt keinen Live-nginx-Smoke-Test. Produktion benötigt weder Node.js noch einen Build-Schritt.
