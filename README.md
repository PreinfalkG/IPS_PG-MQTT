# MQTT Generic Mapper – IP-Symcon Modul

Bildet beliebige MQTT-Topics auf frei konfigurierbare Variablen ab – **eine**
Instanz für beliebig viele Topics, ohne dass für jedes Topic eine eigene
native "MQTT Server Gerät"-Instanz nötig ist.

## Wichtiger Hinweis zur Schnittstellen-GUID

Diese Version nutzt die Schnittstelle `{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}`,
bestätigt direkt aus einer laufenden IP-Symcon-Installation über:

```php
$moduleID   = IPS_GetInstance($mqttServerInstanceID)['ModuleInfo']['ModuleID'];
$moduleInfo = IPS_GetModule($moduleID);
// $moduleInfo['ChildRequirements'] enthält die geforderte Schnittstelle
```

Diese GUID ist von Symcon nicht offiziell in der Doku veröffentlicht und kann
sich theoretisch mit einem größeren Versionssprung wieder ändern. Solltet ihr
nach einem IP-Symcon-Update erneut "inkompatibel" im Gateway-Dialog sehen,
das Diagnose-Vorgehen oben wiederholen und die GUID in `module.json`
(`parentRequirements`) sowie in `module.php`
(`MODULE_MQTT_SERVER`-Konstante) entsprechend anpassen.

## Installation

1. Ordner `MQTTGenericMapper` (mit `library.json` und Unterordner
   `MQTTGenericMapper/`) nach `/var/lib/symcon/modules/` (bzw. euren
   gemounteten Modulordner) kopieren.
2. IP-Symcon Modulliste aktualisieren.
3. Im Objektbaum: **+ → Instanz**, nach "MQTT Generic Mapper" suchen,
   anlegen. Als Parent sollte automatisch euer nativer MQTT-Server
   vorgeschlagen bzw. verbunden werden.

## Nutzung

### 1) Topics entdecken (optional)
- "Lernmodus aktiv" anhaken, übernehmen.
- Ein paar Sekunden bis Minuten warten (je nachdem, wie oft eure Geräte
  publizieren).
- Formular schließen und neu öffnen.
- Gewünschte Zeilen markieren → "Ausgewählte Topics übernehmen", oder bei
  vielen Topics (Victron) "Alle erkannten Topics übernehmen" und danach in
  Ruhe aufräumen.
- Lernmodus danach wieder ausschalten (spart Last bei Brokern mit sehr
  vielen Topics).

### 2) Mapping-Tabelle
Pro Zeile: Aktiv, MQTT Topic (inkl. Wildcards `+`/`#`), Ident, Name, Typ
(Boolean/Integer/Float/String), Parser, Parser-Parameter, Variablenprofil,
Position. Variablen werden automatisch angelegt/aktualisiert, sobald ein
passendes Topic empfangen wird.

**Parser** (zusätzlich zum Typ, für Rohwerte die nicht 1:1 in den Zieltyp
passen). Das Feld **Parser-Parameter** bedeutet je nach gewähltem Parser
etwas anderes:

| Parser | Parser-Parameter | Beispiel |
|---|---|---|
| Keine (Rohwert) | – (leer) | einfache Umwandlung nur anhand des Typs, z.B. `"true"`/`"1"` → Boolean |
| Zahl aus Text extrahieren | – (leer) | `"80%"` → `80`, `"33.5 A"` → `33.5` |
| Datum/Zeit → Unix-Timestamp | optionaler PHP-Formatstring | `"Y-m-d H:i:s"`, leer = automatische Erkennung via `strtotime()` |
| Enum-Zuordnung (Text → Zahl) | `Label=Zahl` Paare, komma- oder zeilengetrennt | `"Offline=0,Online=1,undefined=-1"` |
| JSON-Feld extrahieren | Punkt-Pfad in den JSON-Payload | `"battery.soc"`, `"devices.0.voltage"` |
| Skalierung (Faktor/Offset) | `Faktor,Offset` | `"0.1,0"` wandelt `235` in `23.5` um |
| Millisekunden → Sekunden | – (leer) | für Unix-Timestamps in ms |

**Enum-Zuordnung im Detail:** Trifft ein bisher unbekannter Text-Wert ein
(z.B. ein neuer Status-String, den ihr noch nicht in der Liste habt), wird
er automatisch mit der nächsten freien Zahl ergänzt und direkt in das
Parser-Parameter-Feld dieser Mapping-Zeile zurückgeschrieben – beim nächsten
Öffnen des Formulars steht er dann schon drin. Bei Bedarf die
automatisch vergebene Zahl im Formular manuell anpassen.

Bei "Datum/Zeit", "Enum-Zuordnung" und "Millisekunden → Sekunden" den Typ auf
**Integer** stellen, bei "Skalierung" i.d.R. auf **Float**. Fürs
Timestamp-Profil bietet sich das eingebaute `~UnixTimestamp` an, damit
IP-Symcon den Wert als Datum/Zeit darstellt statt als nackte Zahl.

### 3) Debug-Meldungen
Checkbox oben im Formular schaltet ausführliches Logging ein (empfangene
Topics, Mapping-Treffer, gesetzte Werte, Fehler, automatisch ergänzte
Enum-Werte) – sichtbar im Debug-Panel der Instanz in der Konsole (nicht im
globalen Meldungslog, um dieses nicht zuzumüllen).

### 3) Import / Export
Export-Button gibt die aktuelle Mapping-Liste als JSON zum Kopieren aus;
Import ersetzt die komplette Liste (mit Sicherheitsabfrage).

### 4) Performance-Feintuning
Optionaler Regex-Vorfilter (`ReceiveDataFilter`) für Broker mit sehr vielen
Topics. Die eigentliche Zuordnung läuft davon unabhängig immer über die
Mapping-Liste.


Der Regex im "ReceiveDataFilter" wird gegen die komplette rohe JSON-Zeile geprüft (also `{"DataID":"...","Topic":"...","Payload":"...",...}`), nicht nur gegen das Topic selbst – deshalb muss das Pattern den `"Topic":"..."`-Teil mit einschließen. Wichtig: Da IP-Symcon intern `/` als Regex-Delimiter verwendet, müssen literale Schrägstriche im Pattern als `\/` maskiert werden.

**Fall 1 – Topic beginnt mit `carconnectivity/0/garage/VSSZZZK1ZNP005104/`:**
```
.*"Topic":"carconnectivity\/0\/garage\/VSSZZZK1ZNP005104\/.*
```

**Fall 2 – Topic enthält `/garage/` irgendwo:**
```
.*"Topic":"[^"]*\/garage\/[^"]*"
```

Kurz erklärt:
- `.*"Topic":"` – irgendwas davor, dann öffnendes Anführungszeichen des Topic-Werts
- Fall 1: der feste Präfix, danach `.*` für den Rest (kein Zeilenende-Anker nötig, da Präfix-Match reicht)
- Fall 2: `[^"]*` = "beliebig viele Zeichen außer Anführungszeichen" davor und danach, damit `/garage/` an beliebiger Stelle im Topic gefunden wird, ohne über das schließende `"` hinauszulaufen

**Zwei Dinge zu beachten:**

1. Dieser Filter wirkt auf **Transportebene für alles**, auch für den Lernmodus – d. h. im Lernmodus würdest du mit Fall 1 dann nur noch Topics eures VW/Cupra-Fahrzeugs zum Entdecken sehen, nicht mehr die anderen `carconnectivity`-Topics (Connector-Status etc.). Falls du zum Entdecken breiter filtern willst als für den späteren Dauerbetrieb, einfach den Filter während der Discovery-Phase auf `.*` lassen und erst danach einengen.

2. Falls ihr mehrere Fahrzeuge/Bereiche gleichzeitig abdecken wollt, lassen sich mehrere Muster per `|` kombinieren, z. B.:
```
.*"Topic":"(carconnectivity\/0\/garage\/VSSZZZK1ZNP005104\/.*|.*\/garage\/.*)"
```

Am besten nach dem Setzen kurz mit Debug-Meldungen aktiv testen (die vorhandene `debug()`-Ausgabe in `ApplyChanges()` bestätigt euch den tatsächlich aktiven Filter-String), damit ihr seht, dass noch die erwarteten Topics durchkommen.

Beispiel Victron
Für Victron mit variabler Geräte-Instanznummer in der Mitte des Topics gibt's zwei Stellen, wo du das eintragen kannst – je nachdem, was du erreichen willst:

## A) Im Mapping-Tabellen-Feld "MQTT Topic" (empfohlen)

Dort unterstützt das Modul schon die MQTT-Standard-Wildcards `+` (genau ein Segment) und `#` (Rest-Pfad) – das ist der natürlichere Ort für genau euren Anwendungsfall, weil ihr damit **eine einzige Mapping-Zeile** für alle Solarcharger-Instanzen bekommt, statt für jede Instanznummer eine eigene:

**Fall 1** – beliebige Solarcharger-Instanz, `Pv/V`:
```
N/c0619ab82e3c/solarcharger/+/Pv/V
```

**Fall 2** – alles unter `grid` (beliebige Instanz + beliebiger Rest-Pfad):
```
N/c0619ab82e3c/grid/#
```

## B) Im "ReceiveDataFilter" (Performance-Vorfilter)

Falls ihr zusätzlich auf Transportebene einschränken wollt (z. B. weil der Victron-Broker hunderte Topics hat und ihr nur Solarcharger/Grid überhaupt an die Instanz durchlassen wollt), braucht's echten Regex gegen die rohe JSON-Zeile, `/` maskiert:

**Fall 1:**
```
.*"Topic":"N\/c0619ab82e3c\/solarcharger\/[^\/]+\/Pv\/V"
```
`[^\/]+` = "ein beliebiges Segment ohne weiteren Schrägstrich" – entspricht dem MQTT-`+`.

**Fall 2:**
```
.*"Topic":"N\/c0619ab82e3c\/grid\/.*
```

**Kombiniert (beides gleichzeitig durchlassen):**
```
.*"Topic":"N\/c0619ab82e3c\/(solarcharger\/[^\/]+\/Pv\/V|grid\/.*)
```

## Praktischer Tipp für euren Fall

Da ihr vermutlich mehrere Solarcharger-Instanzen (290, 291, ...) habt und alle auf dieselbe Variable abbilden wollt (oder pro Instanz eine eigene?) – überlegt euch das kurz:

- **Eine Variable für alle Instanzen** (letzter Wert gewinnt, überschreibt sich gegenseitig) → Mapping-Zeile mit `+`-Wildcard wie oben reicht.
- **Eine Variable pro Instanz** (z. B. `SolarCharger_290_V`, `SolarCharger_291_V`) → braucht pro Instanznummer eine eigene Mapping-Zeile mit exaktem Topic, da der `Ident` sonst kollidieren würde. Dafür wäre der Lernmodus + "Ausgewählte Topics übernehmen" der schnellste Weg, weil dort jede tatsächlich gesehene Instanznummer einzeln als eigene Zeile auftaucht.

Was passt eher zu eurem Setup – wollt ihr die Solarcharger einzeln oder aggregiert sehen?


## Bekannte Grenzen

- Reines Auslesen (Broker → Symcon-Variable), kein Rückschreiben. Ließe sich
  über `RequestAction()` + `SendDataToParent()` ergänzen.
- Variablenprofile werden nur referenziert, nicht automatisch angelegt.
- Verschachtelte JSON-Payloads landen komplett als String in einer Variable;
  ein "JSON-Pfad"-Feld pro Mapping wäre der nächste sinnvolle Ausbauschritt,
  falls gewünscht.
