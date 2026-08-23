<?php

declare(strict_types=1);

class MQTTGenericMapper extends IPSModule
{
    // Öffentlich dokumentierte Modul-ID der nativen "MQTT Server Gerät"-Instanz.
    // Bestätigt durch Symcon-Entwickler paresy im Community-Forum (Thread
    // "Auf MQTT-Topic publishen leicht gemacht"). Im Gegensatz zur alten,
    // undokumentierten Splitter-Schnittstelle ist das hier stabil über Versionen hinweg.
    private const MODULE_MQTT_DEVICE = '{01C00ADD-D04E-452E-B66A-D253278743FE}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('MQTTServerID', 0);
        $this->RegisterPropertyString('Mappings', '[]');

        // Zwischenspeicher für den Lernmodus / die Topic-Übernahme
        $this->RegisterAttributeString('DiscoveredTopicsCache', '[]');

        // Zuordnung "Rohwert-Variablen-ID des nativen MQTT-Gerätes" -> zugehöriges Mapping,
        // damit MessageSink() weiß, welche Konvertierung auf welche Zielvariable anzuwenden ist.
        $this->RegisterAttributeString('RawVarMap', '{}');

        // Für sauberes Aufräumen verwaister Objekte zwischen zwei ApplyChanges-Läufen.
        $this->RegisterAttributeString('ManagedFinalIdents', '[]');
        $this->RegisterAttributeString('ManagedChildIdents', '[]');

        // WICHTIG: Diese Instanz hängt sich NICHT mehr selbst als Kind unter einen
        // MQTT-Server/-Client. Sie braucht keinen Gateway-Elternteil und kann daher
        // frei im Objektbaum stehen. Pro Mapping-Zeile wird stattdessen unten eine
        // eigene, ganz normale native MQTT-Geräte-Instanz erzeugt und an den in
        // MQTTServerID gewählten MQTT-Server/-Client angeschlossen.
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $mappings = $this->getMappings();
        $rawMap = [];
        $expectedChildIdents = [];
        $expectedFinalIdents = [];

        foreach ($mappings as $mapping) {
            if (!($mapping['Active'] ?? true) || trim((string) ($mapping['Topic'] ?? '')) === '') {
                continue;
            }

            $childIdent = $this->childIdentFromTopic($mapping['Topic']);
            $expectedChildIdents[] = $childIdent;

            $rawVarID = $this->ensureChildDevice($mapping, $childIdent);
            $finalVarID = $this->ensureFinalVariable($mapping);

            $ident = $mapping['Ident'] !== '' ? $mapping['Ident'] : $this->topicToIdent($mapping['Topic']);
            $expectedFinalIdents[] = $ident;

            if ($rawVarID > 0 && $finalVarID > 0) {
                $rawMap[(string) $rawVarID] = $mapping;
            }
        }

        $this->WriteAttributeString('RawVarMap', json_encode($rawMap));
        $this->cleanupOrphanedChildren($expectedChildIdents);
        $this->cleanupOrphanedVariables($expectedFinalIdents);
    }

    /**
     * Wird von IP-Symcon automatisch aufgerufen, wenn sich eine Variable ändert,
     * für die wir uns per RegisterMessage(..., VM_UPDATE) angemeldet haben.
     * Das ist der öffentlich dokumentierte Ersatz für das alte, fragile ReceiveData().
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message !== VM_UPDATE) {
            return;
        }

        $rawMap = json_decode($this->ReadAttributeString('RawVarMap'), true);
        if (!is_array($rawMap) || !isset($rawMap[(string) $SenderID])) {
            return;
        }

        $mapping = $rawMap[(string) $SenderID];
        $ident = $mapping['Ident'] !== '' ? $mapping['Ident'] : $this->topicToIdent($mapping['Topic']);
        $finalVarID = @$this->GetIDForIdent($ident);
        if ($finalVarID === false) {
            return;
        }

        $payload = @GetValueString($SenderID);
        $this->applyConvertedPayload($finalVarID, $mapping, (string) $payload);
    }

    // ---------------------------------------------------------------
    // Öffentliche Funktionen, die vom Konfigurationsformular aufgerufen werden
    // ---------------------------------------------------------------

    public function RefreshDiscoveredTopics()
    {
        $serverID = $this->ReadPropertyInteger('MQTTServerID');

        if ($serverID <= 0 || !IPS_InstanceExists($serverID)) {
            $this->WriteAttributeString('DiscoveredTopicsCache', '[]');
            $this->ReloadForm();
            echo 'Bitte zuerst oben unter "MQTT-Server / -Client" die passende Instanz auswählen.';
            return;
        }

        $rows = [];
        $formJson = @IPS_GetConfigurationForm($serverID);
        if ($formJson !== false) {
            $form = json_decode($formJson, true);
            if (is_array($form)) {
                $rows = $this->extractTopicRows($form);
            }
        }

        if (empty($rows)) {
            echo 'Es konnten keine Themen aus dem Konfigurator der gewählten Instanz gelesen werden (Heuristik hat nichts gefunden, oder es wurden dort noch keine Themen empfangen). ' .
                 'Alternativ könnt ihr Themen weiterhin direkt in der Tabelle unten manuell eintragen, oder sie aus dem nativen MQTT-Konfigurator per Copy&Paste übernehmen.';
        }

        $this->WriteAttributeString('DiscoveredTopicsCache', json_encode($rows));
        $this->ReloadForm();
    }

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
            $this->UpdateFormField('ImportBox', 'value', 'FEHLER: Kein gültiges JSON – Import abgebrochen. Ursprünglicher Inhalt wurde nicht verändert.');
            return;
        }

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
        usort($discovered, fn ($a, $b) => strcmp($b['LastSeen'] ?? '', $a['LastSeen'] ?? ''));

        $this->injectListValues($form['elements'], 'DiscoveredList', $discovered);

        return json_encode($form);
    }

    // ---------------------------------------------------------------
    // Native MQTT-Geräte-Instanzen anlegen/pflegen (statt Splitter-Kindschaft)
    // ---------------------------------------------------------------

    private function childIdentFromTopic(string $topic): string
    {
        return 'RAWDEV_' . substr(md5($topic), 0, 20);
    }

    /**
     * Legt für ein Mapping eine ganz normale native "MQTT Server Gerät"-Instanz an
     * (oder aktualisiert eine bestehende), verbindet sie mit dem gewählten
     * MQTT-Server/-Client und liefert die ID von deren "Value"-Variable zurück.
     * Das entspricht exakt dem, was der Symcon-Konfigurator im Hintergrund auch tut
     * (siehe IPS_CreateInstance / IPS_ConnectInstance / IPS_SetConfiguration).
     */
    private function ensureChildDevice(array $mapping, string $childIdent): int
    {
        $childID = @IPS_GetObjectIDByIdent($childIdent, $this->InstanceID);

        if ($childID === false) {
            $childID = IPS_CreateInstance(self::MODULE_MQTT_DEVICE);
            IPS_SetParent($childID, $this->InstanceID);
            IPS_SetIdent($childID, $childIdent);
        }

        IPS_SetName($childID, 'RAW: ' . $mapping['Topic']);
        @IPS_SetHidden($childID, true); // Rohdaten-Gerät im Baum ausblenden; sichtbar bleibt nur die Ziel-Variable

        $serverID = $this->ReadPropertyInteger('MQTTServerID');
        if ($serverID > 0 && IPS_InstanceExists($serverID)) {
            $instConfig = IPS_GetInstance($childID);
            if (($instConfig['ConnectionID'] ?? 0) !== $serverID) {
                @IPS_DisconnectInstance($childID);
                if (!@IPS_ConnectInstance($childID, $serverID)) {
                    $this->LogMessage(
                        'Konnte natives MQTT-Gerät für Topic "' . $mapping['Topic'] . '" nicht mit Instanz ' .
                        $serverID . ' verbinden. Bitte prüfen, ob dort ein MQTT-Server oder MQTT-Client ausgewählt ist.',
                        KL_WARNING
                    );
                }
            }
        }

        // Wir empfangen den Payload hier IMMER als String (Type 3) und rein passiv (nicht Retain),
        // damit wir selbst entscheiden können, wie er konvertiert/interpretiert wird.
        IPS_SetConfiguration($childID, json_encode([
            'Topic'  => $mapping['Topic'],
            'Type'   => 3,
            'Retain' => false
        ]));
        @IPS_ApplyChanges($childID);

        $rawVarID = @IPS_GetObjectIDByIdent('Value', $childID);
        if ($rawVarID !== false) {
            $this->RegisterMessage($rawVarID, VM_UPDATE);
            return $rawVarID;
        }

        return 0;
    }

    private function cleanupOrphanedChildren(array $expectedIdents): void
    {
        $previous = json_decode($this->ReadAttributeString('ManagedChildIdents'), true) ?: [];
        $removed = array_diff($previous, $expectedIdents);

        foreach ($removed as $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($id !== false && IPS_InstanceExists($id)) {
                @IPS_DeleteInstance($id);
            }
        }

        $this->WriteAttributeString('ManagedChildIdents', json_encode(array_values($expectedIdents)));
    }

    private function cleanupOrphanedVariables(array $expectedIdents): void
    {
        $previous = json_decode($this->ReadAttributeString('ManagedFinalIdents'), true) ?: [];
        $removed = array_diff($previous, $expectedIdents);

        foreach ($removed as $ident) {
            if (@$this->GetIDForIdent($ident) !== false) {
                try {
                    $this->UnregisterVariable($ident);
                } catch (Exception $e) {
                    // Variable existierte schon nicht mehr - ignorieren
                }
            }
        }

        $this->WriteAttributeString('ManagedFinalIdents', json_encode(array_values($expectedIdents)));
    }

    private function ensureFinalVariable(array $mapping): int
    {
        $ident    = $mapping['Ident'] !== '' ? $mapping['Ident'] : $this->topicToIdent($mapping['Topic']);
        $name     = $mapping['Name'] !== '' ? $mapping['Name'] : $ident;
        $position = (int) ($mapping['Position'] ?? 0);
        $convert  = $mapping['Convert'] ?? 'none';
        $varType  = (int) ($mapping['VarType'] ?? 3);
        $profile  = $mapping['Profile'] ?? '';

        // Konvertierungen liefern immer einen Integer-Wert, unabhängig vom in der Tabelle
        // gewählten "Typ" (der dann nur noch als Hinweis/Fallback für Convert=Keine dient).
        if ($convert === 'unixts') {
            $varType = 1;
            if ($profile === '') {
                $profile = '~UnixTimestamp';
            }
        } elseif ($convert === 'enum') {
            $varType = 1;
        }

        switch ($varType) {
            case 0:
                return $this->RegisterVariableBoolean($ident, $name, $profile, $position);
            case 1:
                return $this->RegisterVariableInteger($ident, $name, $profile, $position);
            case 2:
                return $this->RegisterVariableFloat($ident, $name, $profile, $position);
            default:
                return $this->RegisterVariableString($ident, $name, $profile, $position);
        }
    }

    // ---------------------------------------------------------------
    // Wertkonvertierung
    // ---------------------------------------------------------------

    private function applyConvertedPayload(int $varID, array $mapping, string $payload): void
    {
        switch ($mapping['Convert'] ?? 'none') {
            case 'unixts':
                SetValueInteger($varID, $this->parseTimestamp($payload, $mapping['DateFormat'] ?? ''));
                return;

            case 'enum':
                SetValueInteger($varID, $this->lookupEnum($payload, $mapping['EnumMap'] ?? ''));
                return;
        }

        switch ((int) ($mapping['VarType'] ?? 3)) {
            case 0: // Boolean
                SetValueBoolean($varID, in_array(strtolower(trim($payload)), ['true', '1', 'on', 'yes'], true));
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

    /**
     * Wandelt einen String-Zeitstempel in einen Unix-Timestamp (Integer) um.
     * Wenn ein explizites PHP-Datumsformat (DateFormat) angegeben ist, wird das
     * zuerst versucht (z.B. 'Y-m-d\TH:i:sP' für ISO 8601 mit Zeitzone). Ohne
     * Format oder wenn das Format nicht passt, versucht strtotime() eine
     * automatische Erkennung (deckt die meisten gängigen Formate ab, z.B. auch
     * die von Victron/GX-Geräten üblichen ISO-8601-Strings).
     */
    private function parseTimestamp(string $payload, string $format): int
    {
        $payload = trim($payload);
        if ($payload === '') {
            return 0;
        }

        if ($format !== '') {
            $dt = DateTime::createFromFormat($format, $payload);
            if ($dt !== false) {
                return $dt->getTimestamp();
            }
        }

        $ts = strtotime($payload);
        return $ts !== false ? $ts : 0;
    }

    /**
     * Wandelt einen String-Statuswert über eine einfache Zuordnungstabelle
     * ("Text1=0;Text2=1;Text3=2") in einen Integer um. Passend zu einem
     * Integer-Variablenprofil mit Assoziationen kann so z.B. ein Victron-Status
     * wie "Bulk"/"Absorption"/"Float" in 0/1/2 verwandelt werden.
     */
    private function lookupEnum(string $payload, string $enumMap): int
    {
        $payload = trim($payload);
        $table = [];

        foreach (explode(';', $enumMap) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }
            [$key, $val] = explode('=', $pair, 2);
            $table[trim($key)] = (int) trim($val);
        }

        return $table[$payload] ?? -1;
    }

    // ---------------------------------------------------------------
    // Themen-Erkennung (best-effort, über die native Konfigurator-Form)
    // ---------------------------------------------------------------

    /**
     * Durchsucht das JSON der Konfigurationsform des gewählten MQTT-Server/-Client
     * rekursiv nach der Liste bereits empfangener Themen. Der interne Feldname
     * dieser Liste ist von Symcon nicht offiziell dokumentiert, daher heuristisch:
     * gesucht wird die erste Liste, deren Zeilen ein Feld mit Schrägstrichen
     * enthalten (typisch für MQTT-Topics). Liefert die Liste nichts, kann man
     * Themen weiterhin manuell eintragen oder aus dem nativen Konfigurator
     * per Copy & Paste übernehmen - das ist immer verfügbar.
     */
    private function extractTopicRows(array $node): array
    {
        if (isset($node['values']) && is_array($node['values'])) {
            foreach ($node['values'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (['Topic', 'topic', 'Name', 'Address'] as $key) {
                    if (isset($row[$key]) && is_string($row[$key]) && strpos($row[$key], '/') !== false) {
                        $result = [];
                        foreach ($node['values'] as $r) {
                            $topic = $r[$key] ?? null;
                            if (is_string($topic) && $topic !== '') {
                                $result[$topic] = [
                                    'Topic'    => $topic,
                                    'Payload'  => (string) ($r['Value'] ?? $r['Payload'] ?? ''),
                                    'LastSeen' => date('Y-m-d H:i:s')
                                ];
                            }
                        }
                        return array_values($result);
                    }
                }
            }
        }

        foreach (['elements', 'items', 'actions'] as $childKey) {
            if (isset($node[$childKey]) && is_array($node[$childKey])) {
                foreach ($node[$childKey] as $child) {
                    if (is_array($child)) {
                        $found = $this->extractTopicRows($child);
                        if (!empty($found)) {
                            return $found;
                        }
                    }
                }
            }
        }

        return [];
    }

    // ---------------------------------------------------------------
    // Allgemeine Hilfsfunktionen
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
        $segments = explode('/', trim($topic, '/'));
        $lastSegment = end($segments) ?: $topic;

        return [
            'Active'     => true,
            'Topic'      => $topic,
            'Ident'      => $this->topicToIdent($topic),
            'Name'       => str_replace(['_', '-'], ' ', $lastSegment),
            'VarType'    => 3, // String als sicherer Default, danach in der Tabelle anpassbar
            'Profile'    => '',
            'Position'   => 0,
            'Convert'    => 'none',
            'DateFormat' => '',
            'EnumMap'    => ''
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
}
