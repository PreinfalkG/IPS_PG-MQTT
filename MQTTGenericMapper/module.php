<?php

declare(strict_types=1);

class MQTTGenericMapper extends IPSModule
{
    // Bestaetigt ueber: IPS_GetModule(IPS_GetInstance($mqttServerID)['ModuleInfo']['ModuleID'])
    // Native MQTT-Server-Instanz (Modul-ID {C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}):
    //   - "Implemented"       => ["{7A1272A4-...}", "{043EA491-...}"]  -> das braucht UNSER "parentRequirements"
    //   - "ChildRequirements" => ["{7F7632D9-...}"]                    -> das braucht UNSER "implemented"
    private const IF_MQTT_SERVER      = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    private const MODULE_MQTT_SERVER  = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    // Parser-Enum fuer die Mapping-Tabelle
    private const PARSER_NONE               = 0; // Rohwert gemaess VarType
    private const PARSER_STRING_TO_INT      = 1; // erste Zahl aus dem String extrahieren
    private const PARSER_DATETIME_TO_STAMP  = 2; // Datum/Zeit-String -> Unix-Timestamp (Integer)

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('DiscoverMode', false);
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyString('Mappings', '[]');
        $this->RegisterPropertyString('BaseFilter', '.*');

        // Cache fuer im Lernmodus gesehene Topics. Als Attribute (nicht Property), damit er
        // Neustarts uebersteht, aber nicht als "geaenderte Konfiguration" markiert wird.
        $this->RegisterAttributeString('DiscoveredTopicsCache', '[]');

        // Standardmaessig an den nativen MQTT-Server haengen (legt bei Bedarf automatisch eine
        // passende Instanz an bzw. verbindet mit einer vorhandenen). Kann im Instanzbaum
        // ("Gateway aendern") jederzeit umgestellt werden.
        $this->ConnectParent(self::MODULE_MQTT_SERVER);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Serverseitiger Vorfilter (Performance). Die eigentliche, exakte Zuordnung erfolgt
        // unabhaengig davon in ReceiveData() anhand der Mapping-Liste.
        $filter = $this->ReadPropertyString('BaseFilter');
        $this->SetReceiveDataFilter($filter === '' ? '.*' : $filter);
        $this->debug('ApplyChanges: ReceiveDataFilter gesetzt auf "' . ($filter === '' ? '.*' : $filter) . '"');

        // Fuer alle aktiven Mappings die Zielvariable sicherstellen, auch bevor der erste
        // Payload eintrifft (z.B. gleich nach dem Anlegen sichtbar).
        foreach ($this->getMappings() as $mapping) {
            if ($mapping['Active'] ?? true) {
                $this->ensureVariable($mapping);
            }
        }
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        if ($data === null || !isset($data->Topic)) {
            return; // z.B. CONNECT/SUBACK-Pakete ohne Topic ignorieren
        }

        $topic   = (string) $data->Topic;
        $payload = isset($data->Payload) ? (string) $data->Payload : '';

        if ($this->ReadPropertyBoolean('DiscoverMode')) {
            $this->debug('Lernmodus: speichere Topic "' . $topic . '"');
            $this->rememberDiscoveredTopic($topic, $payload);
            return;
        }

        $mappings = $this->getMappings();

        foreach ($mappings as $mapping) {
            if (!($mapping['Active'] ?? true)) {
                continue;
            }
            if (!$this->topicMatches($mapping['Topic'], $topic)) {
                continue;
            }

            $this->debug('Match: "' . $topic . '" -> Mapping "' . $mapping['Topic'] . '", Payload: "' . $payload . '"');

            try {
                $varID = $this->ensureVariable($mapping);
                $varType = (int) $mapping['VarType'];
                $parser  = (int) ($mapping['Parser'] ?? self::PARSER_NONE);
                $value   = $this->parsePayload($payload, $parser, $varType);
                $this->applyPayload($varID, $varType, $value);
                $this->debug('-> Variable ' . $varID . ' gesetzt auf: ' . var_export($value, true));
            } catch (Exception $e) {
                $this->debug('FEHLER bei Mapping "' . $mapping['Topic'] . '": ' . $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------
    // Oeffentliche Funktionen, die vom Konfigurationsformular aufgerufen werden
    // ---------------------------------------------------------------

    public function AddSelectedTopics($SelectedRows)
    {
        $rows     = $this->toArray($SelectedRows);
        $mappings = $this->getMappings();
        $existing = array_column($mappings, 'Topic');

        foreach ($rows as $row) {
            $row = $this->toArray($row);
            if (!isset($row['Topic']) || in_array($row['Topic'], $existing, true)) {
                continue;
            }
            $mappings[] = $this->buildMappingFromTopic($row['Topic']);
            $existing[] = $row['Topic'];
        }

        $this->debug('AddSelectedTopics: ' . count($rows) . ' Zeile(n) uebergeben, Mapping-Liste jetzt ' . count($mappings) . ' Eintrag/Eintraege');
        $this->saveMappings($mappings);
        $this->ReloadForm();
    }

    public function AddAllDiscoveredTopics()
    {
        $discovered = json_decode($this->ReadAttributeString('DiscoveredTopicsCache'), true) ?: [];
        $mappings   = $this->getMappings();
        $existing   = array_column($mappings, 'Topic');

        foreach ($discovered as $entry) {
            if (in_array($entry['Topic'], $existing, true)) {
                continue;
            }
            $mappings[] = $this->buildMappingFromTopic($entry['Topic']);
            $existing[] = $entry['Topic'];
        }

        $this->debug('AddAllDiscoveredTopics: Mapping-Liste jetzt ' . count($mappings) . ' Eintrag/Eintraege');
        $this->saveMappings($mappings);
        $this->ReloadForm();
    }

    public function ClearDiscovered()
    {
        $this->WriteAttributeString('DiscoveredTopicsCache', '[]');
        $this->ReloadForm();
    }

    public function DoExport()
    {
        $json = json_encode($this->getMappings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->UpdateFormField('ExportBox', 'value', $json);
    }

    public function DoImport(string $ImportJSON)
    {
        $decoded = json_decode($ImportJSON, true);

        if (!is_array($decoded)) {
            $this->UpdateFormField('ImportBox', 'value', 'FEHLER: Kein gueltiges JSON - Import abgebrochen. Urspruenglicher Inhalt wurde nicht veraendert.');
            return;
        }

        // Minimal-Validierung + Defaults ergaenzen, falls Felder fehlen
        $clean = [];
        foreach ($decoded as $row) {
            if (!isset($row['Topic']) || $row['Topic'] === '') {
                continue;
            }
            $clean[] = array_merge($this->buildMappingFromTopic($row['Topic']), $row);
        }

        $this->debug('DoImport: ' . count($clean) . ' Eintrag/Eintraege importiert');
        $this->saveMappings($clean);
        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $discovered = json_decode($this->ReadAttributeString('DiscoveredTopicsCache'), true) ?: [];
        // Neueste zuerst anzeigen
        usort($discovered, fn ($a, $b) => strcmp($b['LastSeen'], $a['LastSeen']));

        $this->injectListValues($form['elements'], 'DiscoveredList', $discovered);

        return json_encode($form);
    }

    // ---------------------------------------------------------------
    // Interne Hilfsfunktionen
    // ---------------------------------------------------------------

    /**
     * Loggt nur, wenn "Debug-Meldungen aktiv" im Formular angehakt ist.
     * Landet im Debug-Panel der Instanz (Konsole -> Instanz -> "Debug"), nicht im globalen Meldungslog.
     */
    private function debug(string $message): void
    {
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->SendDebug('MQTTMAP', $message, 0);
        }
    }

    /**
     * Normalisiert IPSList-Objekte, stdClass-Objekte etc. zu einem echten PHP-Array,
     * da IP-Symcon Formular-Rueckgaben nicht immer als array liefert.
     */
    private function toArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            // funktioniert fuer stdClass, IPSList und aehnliche iterierbare/JSON-serialisierbare Objekte
            $decoded = json_decode(json_encode($value), true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function injectListValues(array &$elements, string $name, array $values): bool
    {
        foreach ($elements as &$el) {
            if (($el['name'] ?? '') === $name) {
                $el['values'] = $values;
                return true;
            }
            if (isset($el['items']) && $this->injectListValues($el['items'], $name, $values)) {
                return true;
            }
        }
        return false;
    }

    private function getMappings(): array
    {
        $decoded = json_decode($this->ReadPropertyString('Mappings'), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function saveMappings(array $mappings): void
    {
        IPS_SetProperty($this->InstanceID, 'Mappings', json_encode($mappings));
        @IPS_ApplyChanges($this->InstanceID);
    }

    private function buildMappingFromTopic(string $topic): array
    {
        $segments    = explode('/', trim($topic, '/'));
        $lastSegment = end($segments) ?: $topic;

        return [
            'Active'   => true,
            'Topic'    => $topic,
            'Ident'    => $this->topicToIdent($topic),
            'Name'     => str_replace(['_', '-'], ' ', $lastSegment),
            'VarType'  => 3, // String als sicherer Default, danach in der Tabelle anpassbar
            'Parser'   => self::PARSER_NONE,
            'Profile'  => '',
            'Position' => 0
        ];
    }

    private function topicToIdent(string $topic): string
    {
        $ident = preg_replace('/[^A-Za-z0-9_]/', '_', $topic);
        $ident = trim($ident, '_');
        // Idents duerfen in IP-Symcon nicht mit einer Ziffer beginnen
        if ($ident === '' || ctype_digit($ident[0])) {
            $ident = 'T_' . $ident;
        }
        return $ident;
    }

    /**
     * Wandelt ein Mapping-Topic-Pattern in einen PHP-Regex um.
     * Unterstuetzt MQTT-Wildcards: '#' (beliebiger Rest-Pfad) und '+' (genau ein Segment).
     */
    private function topicToRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '/');
        $quoted = str_replace('\\#', '.*', $quoted);
        $quoted = str_replace('\\+', '[^/]+', $quoted);
        return $quoted;
    }

    private function topicMatches(string $pattern, string $topic): bool
    {
        return (bool) preg_match('/^' . $this->topicToRegex($pattern) . '$/', $topic);
    }

    private function rememberDiscoveredTopic(string $topic, string $payload): void
    {
        $cache = json_decode($this->ReadAttributeString('DiscoveredTopicsCache'), true);
        if (!is_array($cache)) {
            $cache = [];
        }

        // Topic als Schluessel nutzen, um Duplikate zu vermeiden und den letzten Stand zu behalten
        $indexed = [];
        foreach ($cache as $entry) {
            $indexed[$entry['Topic']] = $entry;
        }

        $indexed[$topic] = [
            'Topic'    => $topic,
            'Payload'  => mb_substr($payload, 0, 120),
            'LastSeen' => date('Y-m-d H:i:s')
        ];

        // Auf eine sinnvolle Menge begrenzen (aelteste zuerst raus), auch bei hunderten Victron-Topics genug
        if (count($indexed) > 2000) {
            $indexed = array_slice($indexed, -2000, null, true);
        }

        $this->WriteAttributeString('DiscoveredTopicsCache', json_encode(array_values($indexed)));
    }

    private function ensureVariable(array $mapping): int
    {
        $ident    = $mapping['Ident'] !== '' ? $mapping['Ident'] : $this->topicToIdent($mapping['Topic']);
        $name     = $mapping['Name'] !== '' ? $mapping['Name'] : $ident;
        $profile  = $mapping['Profile'] ?? '';
        $position = (int) ($mapping['Position'] ?? 0);

        switch ((int) $mapping['VarType']) {
            case 0:
                $varID = $this->RegisterVariableBoolean($ident, $name, $profile, $position);
                break;
            case 1:
                $varID = $this->RegisterVariableInteger($ident, $name, $profile, $position);
                break;
            case 2:
                $varID = $this->RegisterVariableFloat($ident, $name, $profile, $position);
                break;
            default:
                $varID = $this->RegisterVariableString($ident, $name, $profile, $position);
        }

        return $varID;
    }

    /**
     * Wandelt den rohen Payload-String gemaess gewaehltem Parser in den Zielwert um.
     * PARSER_NONE faellt auf die bisherige, VarType-basierte einfache Umwandlung zurueck.
     */
    private function parsePayload(string $payload, int $parser, int $varType)
    {
        switch ($parser) {
            case self::PARSER_STRING_TO_INT:
                if (preg_match('/-?\d+/', $payload, $matches)) {
                    return (int) $matches[0];
                }
                return 0;

            case self::PARSER_DATETIME_TO_STAMP:
                $timestamp = strtotime($payload);
                return $timestamp !== false ? $timestamp : 0;

            default:
                switch ($varType) {
                    case 0:
                        return in_array(strtolower(trim($payload)), ['true', '1', 'on', 'yes'], true);
                    case 1:
                        return (int) $payload;
                    case 2:
                        return (float) str_replace(',', '.', $payload);
                    default:
                        return $payload;
                }
        }
    }

    private function applyPayload(int $varID, int $varType, $value): void
    {
        switch ($varType) {
            case 0:
                SetValueBoolean($varID, (bool) $value);
                break;
            case 1:
                SetValueInteger($varID, (int) $value);
                break;
            case 2:
                SetValueFloat($varID, (float) $value);
                break;
            default:
                SetValueString($varID, (string) $value);
        }
    }
}
