<?php

declare(strict_types=1);

class MQTTGenericMapper extends IPSModule
{
    // Bestätigt über: IPS_GetModule(IPS_GetInstance($mqttServerID)['ModuleInfo']['ModuleID'])
    // Native MQTT-Server-Instanz (Modul-ID {C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}):
    //   - "Implemented"       => ["{7A1272A4-...}", "{043EA491-...}"]  -> das braucht UNSER "parentRequirements"
    //   - "ChildRequirements" => ["{7F7632D9-...}"]                    -> das braucht UNSER "implemented"
    // (bestätigt gegen ein produktiv laufendes, veröffentlichtes MQTT-Modul mit identischer Kombination)
    private const IF_MQTT_SERVER      = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    private const MODULE_MQTT_SERVER  = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('DiscoverMode', false);
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
            $this->rememberDiscoveredTopic($topic, $payload);
            return;
        }

        foreach ($this->getMappings() as $mapping) {
            if (!($mapping['Active'] ?? true)) {
                continue;
            }
            if ($this->topicMatches($mapping['Topic'], $topic)) {
                $varID = $this->ensureVariable($mapping);
                $this->applyPayload($varID, (int) $mapping['VarType'], $payload);
            }
        }
    }

    // ---------------------------------------------------------------
    // Oeffentliche Funktionen, die vom Konfigurationsformular aufgerufen werden
    // ---------------------------------------------------------------

    public function AddSelectedTopics(array $SelectedRows)
    {
        $mappings = $this->getMappings();
        $existing = array_column($mappings, 'Topic');

        foreach ($SelectedRows as $row) {
            if (!isset($row['Topic']) || in_array($row['Topic'], $existing, true)) {
                continue;
            }
            $mappings[] = $this->buildMappingFromTopic($row['Topic']);
            $existing[] = $row['Topic'];
        }

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

    private function applyPayload(int $varID, int $varType, string $payload): void
    {
        switch ($varType) {
            case 0: // Boolean
                $value = in_array(strtolower(trim($payload)), ['true', '1', 'on', 'yes'], true);
                SetValueBoolean($varID, $value);
                break;
            case 1: // Integer
                SetValueInteger($varID, (int) $payload);
                break;
            case 2: // Float
                SetValueFloat($varID, (float) str_replace(',', '.', $payload));
                break;
            default: // String
                SetValueString($varID, $payload);
        }
    }
}
