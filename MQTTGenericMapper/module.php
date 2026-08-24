<?php

declare(strict_types=1);

class MQTTGenericMapper extends IPSModule
{
    // Bestaetigt ueber: IPS_GetModule(IPS_GetInstance($mqttServerID)['ModuleInfo']['ModuleID'])
    private const IF_MQTT_SERVER      = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    private const MODULE_MQTT_SERVER  = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    // Parser-Enum fuer die Mapping-Tabelle
    private const PARSER_NONE      = 0; // Rohwert gemaess VarType
    private const PARSER_NUMBER    = 1; // erste Zahl (auch Dezimal) aus dem String extrahieren
    private const PARSER_DATETIME  = 2; // Datum/Zeit-String -> Unix-Timestamp (Integer)
    private const PARSER_ENUM      = 3; // Text -> Zahl, ueber ParserParam gepflegt, Auto-Erweiterung
    private const PARSER_JSONPATH  = 4; // Feld aus einem JSON-Payload extrahieren (Punkt-Pfad)
    private const PARSER_SCALE     = 5; // numerischer Rohwert * Faktor + Offset
    private const PARSER_MS_TO_S   = 6; // Unix-Millisekunden -> Sekunden
    private const PARSER_DURATION  = 7; // Dauer-String "H:MM:SS.ffffff" -> Sekunden (float)    

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('DiscoverMode', false);
        $this->RegisterPropertyBoolean('DebugMode', false);
        $this->RegisterPropertyString('Mappings', '[]');
        $this->RegisterPropertyString('BaseFilter', '.*');

        $this->RegisterAttributeString('DiscoveredTopicsCache', '[]');

        $this->ConnectParent(self::MODULE_MQTT_SERVER);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $filter = $this->ReadPropertyString('BaseFilter');
        $this->SetReceiveDataFilter($filter === '' ? '.*' : $filter);
        $this->debug('ApplyChanges: ReceiveDataFilter gesetzt auf "' . ($filter === '' ? '.*' : $filter) . '"');

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
            return;
        }

        $topic   = (string) $data->Topic;
        $payload = isset($data->Payload) ? (string) $data->Payload : '';

        if ($this->ReadPropertyBoolean('DiscoverMode')) {
            $this->debug('Lernmodus: speichere Topic "' . $topic . '"');
            $this->rememberDiscoveredTopic($topic, $payload);
            return;
        }

        $mappings = $this->getMappings();

        foreach ($mappings as $index => $mapping) {
            if (!($mapping['Active'] ?? true)) {
                continue;
            }
            if (!$this->topicMatches($mapping['Topic'], $topic)) {
                continue;
            }

            $this->debug('Match: "' . $topic . '" -> Mapping "' . $mapping['Topic'] . '", Payload: "' . $payload . '"');

            try {
                $varID       = $this->ensureVariable($mapping);
                $varType     = (int) $mapping['VarType'];
                $parser      = (int) ($mapping['Parser'] ?? self::PARSER_NONE);
                $parserParam = (string) ($mapping['ParserParam'] ?? '');

                if ($parser === self::PARSER_ENUM) {
                    // Sonderfall: kann die Mapping-Liste persistent erweitern (neuer Enum-Wert)
                    $value = $this->resolveEnumAndMaybeExtend($mappings, $index, $payload);
                } else {
                    $value = $this->parsePayload($payload, $parser, $varType, $parserParam);
                }

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

    public function AddSelectedTopics(string $SelectedRowsJSON)
    {
        $rows     = $this->toArray(json_decode($SelectedRowsJSON));
        $mappings = $this->getMappings();
        $existing = array_column($mappings, 'Topic');
        $added    = 0;

        foreach ($rows as $row) {
            $row = $this->toArray($row);
            if (!($row['Selected'] ?? false)) {
                continue;
            }
            if (!isset($row['Topic']) || in_array($row['Topic'], $existing, true)) {
                continue;
            }
            $mappings[] = $this->buildMappingFromTopic($row['Topic']);
            $existing[] = $row['Topic'];
            $added++;
        }

        $this->debug('AddSelectedTopics: ' . count($rows) . ' Zeile(n) im Formular, ' . $added . ' davon ausgewählt und uebernommen');
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
        usort($discovered, fn ($a, $b) => strcmp($b['LastSeen'], $a['LastSeen']));
        foreach ($discovered as &$entry) {
            $entry['Selected'] = false;
        }
        unset($entry);

        $this->injectListValues($form['elements'], 'DiscoveredList', $discovered);

        return json_encode($form);
    }

    // ---------------------------------------------------------------
    // Interne Hilfsfunktionen
    // ---------------------------------------------------------------

    private function debug(string $message): void
    {
        if ($this->ReadPropertyBoolean('DebugMode')) {
            $this->SendDebug('MQTTMAP', $message, 0);
        }
    }

    private function toArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
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
            'Active'      => true,
            'Topic'       => $topic,
            'Ident'       => $this->topicToIdent($topic),
            'Name'        => str_replace(['_', '-'], ' ', $lastSegment),
            'VarType'     => 3, // String als sicherer Default, danach in der Tabelle anpassbar
            'Parser'      => self::PARSER_NONE,
            'ParserParam' => '',
            'Profile'     => '',
            'Position'    => 0
        ];
    }

    private function topicToIdent(string $topic): string
    {
        $ident = preg_replace('/[^A-Za-z0-9_]/', '_', $topic);
        $ident = trim($ident, '_');
        if ($ident === '' || ctype_digit($ident[0])) {
            $ident = 'T_' . $ident;
        }
        return $ident;
    }

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

        $indexed = [];
        foreach ($cache as $entry) {
            $indexed[$entry['Topic']] = $entry;
        }

        $indexed[$topic] = [
            'Topic'    => $topic,
            'Payload'  => mb_substr($payload, 0, 120),
            'LastSeen' => date('Y-m-d H:i:s')
        ];

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
     * (PARSER_ENUM wird separat in resolveEnumAndMaybeExtend() behandelt, da es die
     * Mapping-Liste persistent erweitern kann.)
     */
    private function parsePayload(string $payload, int $parser, int $varType, string $parserParam)
    {
        switch ($parser) {
            case self::PARSER_NUMBER:
                if (preg_match('/-?\d+(\.\d+)?/', $payload, $matches)) {
                    return (float) $matches[0];
                }
                return 0;

            case self::PARSER_DATETIME:
                if ($parserParam !== '') {
                    $dt = DateTime::createFromFormat($parserParam, $payload);
                    if ($dt !== false) {
                        return $dt->getTimestamp();
                    }
                    // Format passte nicht -> Fallback auf automatische Erkennung
                }
                $timestamp = strtotime($payload);
                return $timestamp !== false ? $timestamp : 0;

            case self::PARSER_JSONPATH:
                $decoded = json_decode($payload, true);
                return $this->extractJsonPath($decoded, $parserParam);

            case self::PARSER_SCALE:
                [$factor, $offset] = $this->parseScaleParam($parserParam);
                return ((float) str_replace(',', '.', $payload)) * $factor + $offset;

            case self::PARSER_MS_TO_S:
                return intdiv((int) $payload, 1000);

            case self::PARSER_DURATION:
                return $this->parseDurationToSeconds($payload);                

            default: // PARSER_NONE
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

    /**
     * Enum-Zuordnung: ParserParam enthaelt "Label=Zahl"-Paare, getrennt durch Komma oder Zeilenumbruch,
     * z.B. "Offline=0,Online=1,undefined=-1". Unbekannte Labels werden automatisch mit der naechsten
     * freien Zahl ergaenzt und in die Mapping-Liste zurueckgeschrieben.
     */
    private function resolveEnumAndMaybeExtend(array $mappings, int $index, string $rawPayload): int
    {
        $label = trim($rawPayload);
        $map   = $this->parseEnumMap($mappings[$index]['ParserParam'] ?? '');

        if (array_key_exists($label, $map)) {
            return $map[$label];
        }

        $nextValue = empty($map) ? 0 : (max($map) + 1);
        $map[$label] = $nextValue;

        $mappings[$index]['ParserParam'] = $this->encodeEnumMap($map);
        $this->debug('Enum-Map "' . $mappings[$index]['Topic'] . '": neuer Wert "' . $label . '" -> ' . $nextValue . ' automatisch ergaenzt');
        $this->saveMappings($mappings);

        return $nextValue;
    }

    private function parseEnumMap(string $raw): array
    {
        $map = [];
        foreach (preg_split('/[\n,]+/', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || strpos($part, '=') === false) {
                continue;
            }
            [$label, $value] = array_map('trim', explode('=', $part, 2));
            if ($label !== '' && is_numeric($value)) {
                $map[$label] = (int) $value;
            }
        }
        return $map;
    }

    private function encodeEnumMap(array $map): string
    {
        $parts = [];
        foreach ($map as $label => $value) {
            $parts[] = $label . '=' . $value;
        }
        return implode(',', $parts);
    }

    /**
     * Extrahiert einen Wert aus einem dekodierten JSON-Payload anhand eines Punkt-Pfads,
     * z.B. "battery.soc" oder "devices.0.voltage" (numerische Segmente = Array-Index).
     */
    private function extractJsonPath($data, string $path)
    {
        if ($path === '') {
            return is_string($data) ? $data : json_encode($data);
        }

        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (is_array($current) && array_key_exists($segment, $current)) {
                $current = $current[$segment];
            } else {
                return null;
            }
        }

        return is_array($current) ? json_encode($current) : $current;
    }

    /**
     * ParserParam fuer PARSER_SCALE: "Faktor,Offset", z.B. "0.1,0" oder nur "0.1" (Offset dann 0).
     */
    private function parseScaleParam(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [1.0, 0.0];
        }
        $parts  = array_map('trim', explode(',', $raw));
        $factor = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 1.0;
        $offset = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 0.0;
        return [$factor, $offset];
    }

    /**
     * Wandelt einen Dauer-String im Format "[-]H:MM:SS[.ffffff]" (z.B. Python-timedelta-Ausgabe
     * wie "0:05:06.932660") in eine Sekunden-Fließkommazahl um. Stunden duerfen mehrstellig sein.
     */
    private function parseDurationToSeconds(string $payload): float
    {
        if (preg_match('/^(-)?(\d+):(\d{2}):(\d{2})(?:\.(\d+))?$/', trim($payload), $matches)) {
            $sign     = $matches[1] === '-' ? -1 : 1;
            $hours    = (int) $matches[2];
            $minutes  = (int) $matches[3];
            $seconds  = (int) $matches[4];
            $fraction = isset($matches[5]) ? (float) ('0.' . $matches[5]) : 0.0;

            return $sign * ($hours * 3600 + $minutes * 60 + $seconds + $fraction);
        }

        return 0.0;
    }

    private function applyPayload(int $varID, int $varType, $value): void
    {
        switch ($varType) {
            case 0:
                SetValueBoolean($varID, (bool) $value);
                break;
            case 1:
                //SetValueInteger($varID, is_scalar($value) ? (int) $value : 0);
                SetValueInteger($varID, is_scalar($value) ? (int) round((float) $value) : 0);
                break;
            case 2:
                SetValueFloat($varID, is_scalar($value) ? (float) $value : 0.0);
                break;
            default:
                SetValueString($varID, is_array($value) ? json_encode($value) : (string) $value);
        }
    }
}
