# MQTT Generic Mapper – IP-Symcon Modul (v2)

Bildet beliebige MQTT-Topics auf frei konfigurierbare Variablen ab, ohne dass
ihr für jedes Topic manuell im Konfigurator klicken müsst. Eine Instanz dieses
Moduls kann beliebig viele Topics (z.B. alle Victron-Topics) auf eigene,
sauber benannte Variablen mappen – inklusive optionaler Konvertierung von
String-Zeitstempeln in UnixTimestamp und von String-Status in Integer.

## Architektur (v2 – wichtige Änderung gegenüber v1!)

v1 hing sich selbst als Splitter-Kind mit einer privaten, undokumentierten
Interface-GUID unter den MQTT-Server/-Client und wertete `ReceiveData()` aus.
Das war fragil und in dieser Symcon-Version bereits inkompatibel
("Gateway ändern"-Fehler).

v2 verzichtet komplett darauf. Die Modul-Instanz selbst braucht **keinen**
Eltern-Parent mehr und kann frei im Objektbaum stehen. Stattdessen legt sie
für jede aktive Mapping-Zeile automatisch eine ganz normale, native
**"MQTT Server Gerät"**-Instanz an (öffentliche Modul-ID
`{01C00ADD-D04E-452E-B66A-D253278743FE}`, bestätigt durch Symcon-Entwickler
paresy im Community-Forum), verbindet sie per `IPS_ConnectInstance()` mit dem
von euch gewählten MQTT-Server/-Client und konfiguriert Topic/Type/Retain per
`IPS_SetConfiguration()` – exakt das, was der native Konfigurator im
Hintergrund auch macht, nur automatisiert aus eurer Tabelle heraus.

Diese Rohdaten-Geräte werden im Baum ausgeblendet. Auf Wertänderungen ihrer
"Value"-Variable reagiert der Mapper über das offizielle, dokumentierte
`RegisterMessage()`/`MessageSink()`-Mechanismus (statt über ein rohes
Datenpaket), konvertiert den Payload nach Bedarf und schreibt ihn in die von
euch konfigurierte, "schöne" Zielvariable.

## Installation

1. In IP-Symcon: **Kern Instanzen → Modules**, Repository-URL eintragen (oder
   für lokale Installation ohne Git den kompletten Ordner nach
   `/var/lib/symcon/modules/` kopieren).
2. IP-Symcon Modulliste aktualisieren.
3. Im Objektbaum: **+ → Instanz**, nach "MQTT Generic Mapper" suchen, anlegen.
   Es erscheint diesmal **kein** "Gateway ändern"-Dialog mehr, da die Instanz
   keinen Parent benötigt.
4. Oben im Formular unter "MQTT-Server / -Client" die passende, bereits
   bestehende Instanz auswählen (euren nativen MQTT-Server oder einen
   MQTT-Client-Splitter).

## Nutzung

### 1) Themen entdecken (optional)
Button "Themen vom MQTT-Server/-Client abfragen" liest best-effort aus der
Konfigurationsform der gewählten Instanz aus, welche Themen dort bereits
gesehen wurden. Da dieses interne Format von Symcon nicht offiziell
dokumentiert ist, kann es in Einzelfällen nichts liefern – dann einfach
Themen manuell eintragen oder aus dem nativen MQTT-Konfigurator per
Copy & Paste übernehmen (dort werden sie ja ohnehin bereits angezeigt, siehe
euer Screenshot).

### 2) Mapping-Tabelle
Pro Zeile: Aktiv, MQTT Topic (inkl. Wildcards `+`/`#`), Ident, Name, Typ
(Boolean/Integer/Float/String), Variablenprofil, Position, sowie:
- **Konvertierung = Keine**: Payload wird 1:1 gemäß Typ interpretiert.
- **Konvertierung = Datum/Zeit-String → UnixTimestamp**: Zielvariable wird
  automatisch Integer mit Profil `~UnixTimestamp`. Feld "Datumsformat" leer
  lassen für automatische Erkennung (`strtotime`, deckt ISO 8601 & Co ab),
  oder ein PHP-Datumsformat angeben (z.B. `Y-m-d\TH:i:sP`).
- **Konvertierung = Text-Status → Integer (Tabelle)**: Feld
  `Textwert=Zahl;...` ausfüllen, z.B. `Bulk=0;Absorption=1;Float=2`. Dazu
  passend ein eigenes Integer-Variablenprofil mit Assoziationen anlegen und im
  Feld "Variablenprofil" eintragen. Unbekannte Textwerte ergeben `-1`.

### 3) Import / Export
Wie gehabt: Export-Button gibt die aktuelle Mapping-Liste als JSON aus, Import
ersetzt die komplette Liste (mit Sicherheitsabfrage) – praktisch bei
mehreren hundert Victron-Topics, um eine Vorlage zu sichern oder zu teilen.

## Bekannte Grenzen / mögliche Erweiterungen

- Reines Auslesen (Broker → Symcon-Variable). Rückschreiben (Symcon → MQTT
  Publish) ist nicht implementiert, ließe sich aber ergänzen, indem man beim
  `RequestAction()` der Zielvariable den Wert an die zugehörige native
  "Value"-Variable des Rohdaten-Geräts weiterreicht (`RequestAction()` dort
  publiziert automatisch).
- Die Themen-Erkennung ist best-effort (siehe oben) und kein offiziell
  garantierter API-Vertrag.
- JSON-verschachtelte Payloads (ein komplettes Objekt in einem Topic) landen
  aktuell als kompletter String; ein zusätzliches "JSON-Pfad"-Feld zum
  Herauspicken einzelner Unterfelder wäre ein sinnvoller nächster Ausbauschritt.
