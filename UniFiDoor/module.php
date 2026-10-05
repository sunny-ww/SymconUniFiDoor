<?php

declare(strict_types=1);

/**
 * UniFi Door — IP-Symcon Modul für UniFi Access / Protect Türstationen
 * (entwickelt für die G6 Entry am Door Hub Mini)
 */
class UniFiDoor extends IPSModule
{
    private const WEBHOOK_PREFIX = '/hook/unifidoor';
    private const TILE_VISUALIZATION_MODULE_ID = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    private const INTERACTIVE_POPUP_MIN_VERSION = '9.1';

    public function Create()
    {
        parent::Create();

        // --- Verbindung ---
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('AccessPort', 12445);
        $this->RegisterPropertyString('AccessToken', '');
        $this->RegisterPropertyString('DoorID', '');

        // --- Kamera / Video ---
        $this->RegisterPropertyString('SnapshotURL', '');
        $this->RegisterPropertyString('StreamURL', '');
        $this->RegisterPropertyInteger('LiveStreamMediaID', 0);
        $this->RegisterPropertyInteger('LiveImageMediaID', 0);
        $this->RegisterPropertyInteger('LiveImageIntervalSeconds', 0);

        // --- Verhalten ---
        $this->RegisterPropertyInteger('RingResetSeconds', 10);
        $this->RegisterPropertyInteger('NotifyTargetID', 0);
        $this->RegisterPropertyInteger('VisuInstanceID', 0);
        $this->RegisterPropertyBoolean('AutoOpenOnRing', false);
        // Unter 8.2 wird diese Option ausgeblendet und ignoriert. Die
        // interaktive Vollbildkachel bleibt zunächst eine bewusste Opt-in-Option.
        $this->RegisterPropertyBoolean('InteractivePopup', false);
        $this->RegisterPropertyInteger('LiveImagePopupTimeoutSeconds', 60);

        // --- Gegensprechen (offizielle Protect Integration API) ---
        $this->RegisterPropertyString('ProtectApiKey', '');
        $this->RegisterPropertyString('CameraID', '');
        $this->RegisterPropertyString('TalkbackTestFile', '');

        // --- Interner Zustand ---
        $this->RegisterAttributeString('RecentRingEventIDs', '[]');

        // --- Variablen ---
        $this->RegisterVariableBoolean('Ring', 'Es klingelt', '~Alert', 10);
        $this->RegisterVariableInteger('LastRing', 'Letztes Klingeln', '~UnixTimestamp', 20);
        $this->RegisterVariableBoolean('Unlock', 'Tür öffnen', '~Switch', 30);
        $this->EnableAction('Unlock');

        $this->RegisterMediaSnapshot();
        $this->RegisterMediaStream();

        // Timer zum Zurücksetzen des Klingel-Status
        $this->RegisterTimer('ResetRing', 0, 'UFD_ResetRing($_IPS[\'TARGET\']);');

        // Timer für das optionale, fortlaufende Livebild
        $this->RegisterTimer('RefreshLiveImage', 0, 'UFD_RefreshSnapshot($_IPS[\'TARGET\']);');

        // Timer, der den Schalter "Tür öffnen" nach dem Entriegeln zurücksetzt
        $this->RegisterTimer('ResetUnlock', 0, 'UFD_ResetUnlock($_IPS[\'TARGET\']);');

        // Timer zum automatischen Schließen des beim Klingeln geöffneten
        // Livebild-Popups
        $this->RegisterTimer('CloseLiveImagePopup', 0, 'UFD_CloseLiveImagePopup($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ConfigureVisualizationType();

        $this->RegisterHook(self::WEBHOOK_PREFIX . '/' . $this->InstanceID);
        $this->RegisterMediaSnapshot();
        $this->RegisterMediaStream();

        $liveInterval = $this->ReadPropertyInteger('LiveImageIntervalSeconds');
        $this->SetTimerInterval('RefreshLiveImage', $liveInterval > 0 ? $liveInterval * 1000 : 0);

        $this->SetStatus($this->DetermineConfigStatus());
    }

    private function IsInteractivePopupSupported(): bool
    {
        $version = IPS_GetKernelVersion();
        if (!preg_match('/^(\d+)\.(\d+)/', $version, $matches)) {
            return false;
        }

        return version_compare(
            $matches[1] . '.' . $matches[2],
            self::INTERACTIVE_POPUP_MIN_VERSION,
            '>='
        );
    }

    private function IsInteractivePopupEnabled(): bool
    {
        return $this->IsInteractivePopupSupported()
            && $this->ReadPropertyBoolean('InteractivePopup');
    }

    private function ConfigureVisualizationType(): void
    {
        if ($this->IsInteractivePopupEnabled()) {
            // Typ 2 entspricht INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN.
            // Die Konstante selbst wird im älteren Laufzeitpfad nicht benötigt.
            $this->SetVisualizationType(2);
            return;
        }

        $this->SetVisualizationType(0);
    }

    public function GetVisualizationTile(): string
    {
        if (!$this->IsInteractivePopupEnabled()) {
            return '';
        }

        $snapshotID = @$this->GetIDForIdent('Snapshot');
        $imageData = $snapshotID !== false ? IPS_GetMediaContent($snapshotID) : '';
        $image = $imageData !== ''
            ? '<img class="snapshot" src="' . $this->BuildImageDataUri($imageData) . '" alt="Türkamera">'
            : '<div class="no-image">Noch kein Kamerabild verfügbar</div>';

        $parentID = IPS_GetParent($this->InstanceID);
        $backButton = $parentID > 0
            ? '<button class="secondary" onclick="openObject(' . $parentID . ')">Zurück</button>'
            : '';

        return '<style>
html,body{margin:0;width:100%;height:100%;font-family:system-ui,sans-serif;background:#111;color:#fff}
.page{box-sizing:border-box;min-height:100%;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:18px;padding:20px}
.snapshot{display:block;max-width:100%;max-height:72vh;object-fit:contain;border-radius:8px}
.no-image{height:45vh;display:grid;place-items:center;color:#bbb}
.controls{display:flex;gap:12px;flex-wrap:wrap;justify-content:center}
button{font:inherit;font-size:1.1rem;padding:14px 24px;border:0;border-radius:8px;cursor:pointer}
.unlock{background:#c62828;color:#fff}.secondary{background:#444;color:#fff}
#status{min-height:1.4em;color:#ddd;text-align:center}
</style>
<main class="page">' . $image . '
<div class="controls">
<button class="unlock" onclick="unlockDoor()">Tür öffnen</button>' . $backButton . '
</div>
<div id="status" role="status" aria-live="polite"></div>
</main>
<script>
function unlockDoor(){document.getElementById("status").textContent="Entriegelung wird angefragt…";requestAction("Unlock",true);}
function handleMessage(message){if(typeof message==="string"){try{message=JSON.parse(message);}catch(e){return;}}if(!message){return;}if(message.action==="close"&&message.target>0){openObject(message.target);}if(message.action==="unlock-result"){document.getElementById("status").textContent=message.success?"Türöffnungsbefehl erfolgreich gesendet":"Tür konnte nicht geöffnet werden — Meldungen-Log prüfen";}}
</script>
';
    }

    private function BuildImageDataUri(string $base64Data): string
    {
        $mimeType = 'image/jpeg';
        if (strncmp($base64Data, 'iVBORw0KGgo', 11) === 0) {
            $mimeType = 'image/png';
        } elseif (strncmp($base64Data, 'R0lGOD', 6) === 0) {
            $mimeType = 'image/gif';
        } elseif (strncmp($base64Data, 'UklGR', 5) === 0) {
            $mimeType = 'image/webp';
        }

        return 'data:' . $mimeType . ';base64,' . trim($base64Data);
    }

    /**
     * Prüft die Konfiguration (ohne Netzwerkzugriff) und liefert den passenden
     * Instanzstatus. Die Captions stehen im "status"-Block der form.json.
     */
    private function DetermineConfigStatus(): int
    {
        if ($this->ReadPropertyString('Host') === '' || $this->ReadPropertyString('AccessToken') === '') {
            return 104; // Konfiguration unvollständig
        }

        $port = $this->ReadPropertyInteger('AccessPort');
        if ($port < 1 || $port > 65535) {
            return 201;
        }

        if ($this->ReadPropertyString('DoorID') === '') {
            return 202;
        }

        if ($this->ReadPropertyString('CameraID') !== '' && $this->ReadPropertyString('ProtectApiKey') === '') {
            return 203;
        }

        $visu = $this->ReadPropertyInteger('VisuInstanceID');
        if ($visu > 0) {
            if (!IPS_InstanceExists($visu) || IPS_GetInstance($visu)['ModuleInfo']['ModuleID'] !== self::TILE_VISUALIZATION_MODULE_ID) {
                return 204;
            }
        }

        $target = $this->ReadPropertyInteger('NotifyTargetID');
        if ($target > 0 && !IPS_MediaExists($target)) {
            return 205;
        }

        return 102;
    }

    /**
     * Liefert das Konfigurationsformular. Sind bereits eine Door-ID und/oder
     * eine Camera-ID gespeichert, wird ihr Klarname live über die jeweilige
     * API nachgeschlagen und als vorbelegte Option eingesetzt — nicht nur
     * beim ersten Öffnen, sondern bei jedem Neuaufbau des Formulars (z. B.
     * direkt nach „Übernehmen"), damit der Name nicht wieder verschwindet.
     * Schlägt der Abruf fehl, greift ein generischer Platzhalter, die ID
     * selbst bleibt in jedem Fall erhalten.
     */
    public function GetConfigurationForm()
    {
        $raw = file_get_contents(__DIR__ . '/form.json');

        $form = json_decode($raw, true);
        if (!is_array($form)) {
            return $raw;
        }

        $doorID   = $this->ReadPropertyString('DoorID');
        $cameraID = $this->ReadPropertyString('CameraID');

        $door   = $doorID !== '' ? $this->ResolveDoorOption($doorID) : null;
        $camera = $cameraID !== '' ? $this->ResolveCameraOption($cameraID) : null;

        foreach ($form['elements'] as &$panel) {
            if (!isset($panel['items'])) {
                continue;
            }
            foreach ($panel['items'] as $itemIndex => &$item) {
                $name = $item['name'] ?? '';
                if ($name === 'InteractivePopup') {
                    if (!$this->IsInteractivePopupSupported()) {
                        unset($panel['items'][$itemIndex]);
                        continue;
                    }
                }
                if ($name === 'InteractivePopupInfo') {
                    $item['caption'] = $this->IsInteractivePopupSupported()
                        ? 'Ab Symcon 9.1 öffnet das Klingel-Popup die interaktive Vollbildansicht mit Türöffnen-Schaltfläche. Das Bild wird beim Klingeln aktualisiert.'
                        : 'Die interaktive Vollbildansicht mit Türöffnen-Schaltfläche erfordert Symcon 9.1 oder neuer. Unter dieser Version bleibt das Kamera-Popup unverändert.';
                }
                if ($name === 'DoorID' && $door !== null) {
                    $item['options'] = $door['options'];
                }
                if ($name === 'DoorListStatus' && $door !== null) {
                    $item['caption'] = $door['status'];
                }
                if ($name === 'CameraID' && $camera !== null) {
                    $item['options'] = $camera['options'];
                }
                if ($name === 'CameraListStatus' && $camera !== null) {
                    $item['caption'] = $camera['status'];
                }
            }
            unset($item);
            $panel['items'] = array_values($panel['items']);
        }
        unset($panel);

        return json_encode($form);
    }

    /**
     * @return array{options: array{0: array{caption: string, value: string}}, status: string}
     */
    private function ResolveDoorOption(string $doorID): array
    {
        $result = $this->apiRequest('GET', '/api/v1/developer/doors');
        if ($result !== false) {
            foreach (($result['data'] ?? []) as $door) {
                if (($door['id'] ?? '') === $doorID) {
                    return [
                        'options' => [[
                            'caption' => sprintf('%s (%s)', $door['name'] ?? '?', $door['full_name'] ?? '?'),
                            'value'   => $doorID,
                        ]],
                        'status' => 'Gespeicherte Tür erkannt: ' . ($door['name'] ?? '?'),
                    ];
                }
            }
        }

        return [
            'options' => [[
                'caption' => 'Gespeicherte Door-ID (Name unbekannt — „Verfügbare Türen auflisten\" klicken zum Prüfen)',
                'value'   => $doorID,
            ]],
            'status' => 'Gespeicherte Door-ID konnte gerade nicht aufgelöst werden (Verbindung prüfen).',
        ];
    }

    /**
     * @return array{options: array{0: array{caption: string, value: string}}, status: string}
     */
    private function ResolveCameraOption(string $cameraID): array
    {
        $camera = $this->ProtectApiRequest('GET', "/v1/cameras/{$cameraID}");
        if ($camera !== false) {
            $hasSpeaker = $camera['featureFlags']['hasSpeaker'] ?? false;
            return [
                'options' => [[
                    'caption' => sprintf('%s%s', $camera['name'] ?? '?', $hasSpeaker ? ' (Lautsprecher)' : ''),
                    'value'   => $cameraID,
                ]],
                'status' => 'Gespeicherte Kamera erkannt: ' . ($camera['name'] ?? '?'),
            ];
        }

        return [
            'options' => [[
                'caption' => 'Gespeicherte Camera-ID (Name unbekannt — „Protect-Kameras auflisten\" klicken zum Prüfen)',
                'value'   => $cameraID,
            ]],
            'status' => 'Gespeicherte Camera-ID konnte gerade nicht aufgelöst werden (API-Key/Verbindung prüfen).',
        ];
    }

    // =====================================================================
    //  Aktionen
    // =====================================================================

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Unlock':
                // Nur beim Einschalten entriegeln — ein aktives Zurücksetzen
                // auf false (z. B. durch eine Visualisierung) darf nichts auslösen.
                if ($Value) {
                    $success = $this->Unlock();
                    $this->SetValue('Unlock', $success);
                    if ($success) {
                        $this->SetTimerInterval('ResetUnlock', 5000);
                    }
                    if ($this->IsInteractivePopupEnabled()) {
                        // Nur Strings werden akzeptiert (Arrays: "Type is not supported").
                        $this->UpdateVisualizationValue(json_encode([
                            'action'  => 'unlock-result',
                            'success' => $success,
                        ]));
                    }
                } else {
                    $this->SetValue('Unlock', false);
                    $this->SetTimerInterval('ResetUnlock', 0);
                }
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    /**
     * Entriegelt die Tür über die UniFi Access Developer API.
     * Die Entriegelungsdauer wird in UniFi Access konfiguriert.
     */
    public function Unlock(): bool
    {
        $doorID = $this->ReadPropertyString('DoorID');
        if ($doorID === '') {
            $this->LogMessage('Keine Door-ID konfiguriert', KL_ERROR);
            return false;
        }

        $result = $this->apiRequest('PUT', "/api/v1/developer/doors/{$doorID}/unlock");

        if ($result === false) {
            return false;
        }

        $this->SendDebug('Unlock', 'Tür entriegelt', 0);
        return true;
    }

    /**
     * Listet die verfügbaren Türen mit Klartext-Namen auf — Hilfsfunktion zum
     * Ermitteln der Door-ID, ohne das rohe API-JSON lesen zu müssen.
     */
    public function ListDoors(): string
    {
        $result = $this->apiRequest('GET', '/api/v1/developer/doors');
        if ($result === false) {
            return 'Abruf fehlgeschlagen, siehe Meldungen-Log.';
        }

        $doors = $result['data'] ?? [];
        $lines = [];
        foreach ($doors as $door) {
            if (($door['type'] ?? '') !== 'door') {
                continue;
            }
            $lines[] = sprintf(
                '%s  —  %s (%s)',
                $door['id'] ?? '?',
                $door['name'] ?? '?',
                $door['full_name'] ?? '?'
            );
        }

        if (count($lines) === 0) {
            return 'Keine Türen gefunden. Ist am Hub in UniFi Access eine Tür angelegt?';
        }

        return implode("\n", $lines);
    }

    /**
     * Lädt die verfügbaren Türen und befüllt die Radio-Button-Auswahl im
     * offenen Konfigurationsformular live nach. Bei genau einer gefundenen
     * Tür wird sie automatisch ausgewählt; ist die zuvor gespeicherte
     * Door-ID noch unter den Treffern, bleibt sie ausgewählt.
     */
    public function RefreshDoorList(): void
    {
        $result = $this->apiRequest('GET', '/api/v1/developer/doors');
        if ($result === false) {
            $this->UpdateFormField('DoorListStatus', 'caption', 'Abruf fehlgeschlagen, siehe Meldungen-Log.');
            return;
        }

        $doors = $result['data'] ?? [];
        $options = [];
        foreach ($doors as $door) {
            if (($door['type'] ?? '') !== 'door') {
                continue;
            }
            $options[] = [
                'caption' => sprintf('%s (%s)', $door['name'] ?? '?', $door['full_name'] ?? '?'),
                'value'   => $door['id'] ?? '',
            ];
        }

        if (count($options) === 0) {
            $this->UpdateFormField('DoorListStatus', 'caption', 'Keine Türen gefunden. Ist am Hub in UniFi Access eine Tür angelegt?');
            return;
        }

        $this->UpdateFormField('DoorID', 'options', json_encode($options));

        $current = $this->ReadPropertyString('DoorID');
        $stillValid = false;
        foreach ($options as $option) {
            if ($option['value'] === $current) {
                $stillValid = true;
                break;
            }
        }

        if (count($options) === 1) {
            $this->UpdateFormField('DoorID', 'value', $options[0]['value']);
            $this->UpdateFormField('DoorListStatus', 'caption', 'Eine Tür gefunden und automatisch ausgewählt.');
        } elseif ($stillValid) {
            $this->UpdateFormField('DoorID', 'value', $current);
            $this->UpdateFormField('DoorListStatus', 'caption', count($options) . ' Türen gefunden.');
        } else {
            $this->UpdateFormField('DoorListStatus', 'caption', count($options) . ' Türen gefunden — bitte auswählen.');
        }
    }

    /**
     * Setzt den Schalter "Tür öffnen" kurz nach dem Entriegeln zurück.
     */
    public function ResetUnlock(): void
    {
        $this->SetValue('Unlock', false);
        $this->SetTimerInterval('ResetUnlock', 0);
    }

    /**
     * Wird vom Timer aufgerufen und setzt den Klingel-Status zurück.
     */
    public function ResetRing(): void
    {
        $this->SetValue('Ring', false);
        $this->SetTimerInterval('ResetRing', 0);
    }

    // =====================================================================
    //  Webhook — wird von UniFi Protect / Access aufgerufen
    // =====================================================================

    protected function ProcessHookData()
    {
        $raw = file_get_contents('php://input');
        $this->SendDebug('Webhook', $raw, 0);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo 'invalid payload';
            return;
        }

        // Echte Struktur des Protect-Alarm-Manager-Webhooks (verifiziert per
        // Test Alarm):
        // {"alarm":{"triggers":[{"key":"ring","device":"...","eventId":"...",
        // "timestamp":...}], ...}, "timestamp":..., "alarm_id":"..."}
        // Ein Alarm kann mehrere Trigger enthalten — wir reagieren nur, wenn
        // einer davon den Key "ring" trägt.
        $isRing = false;
        $eventID = '';
        $triggers = $payload['alarm']['triggers'] ?? [];
        if (is_array($triggers)) {
            foreach ($triggers as $trigger) {
                if (($trigger['key'] ?? '') === 'ring') {
                    $isRing = true;
                    $eventID = (string) ($trigger['eventId'] ?? '');
                    break;
                }
            }
        }

        if ($isRing) {
            // Wiederholte Zustellung desselben Ereignisses nicht erneut
            // verarbeiten (sonst doppelte Push-Meldungen und Popups). Fehlt
            // die eventId im Payload, wird wie bisher jedes Ereignis verarbeitet.
            $recent = json_decode($this->ReadAttributeString('RecentRingEventIDs'), true);
            if (!is_array($recent)) {
                $recent = [];
            }

            if ($eventID !== '' && in_array($eventID, $recent, true)) {
                $this->SendDebug('Ring', "Ereignis {$eventID} bereits verarbeitet — ignoriert", 0);
            } else {
                if ($eventID !== '') {
                    $recent[] = $eventID;
                    $this->WriteAttributeString('RecentRingEventIDs', json_encode(array_slice($recent, -20)));
                }
                $this->HandleRing();
            }
        }

        http_response_code(200);
        echo 'ok';
    }

    private function HandleRing(): void
    {
        $this->RefreshSnapshot();

        $this->SetValue('Ring', true);
        $this->SetValue('LastRing', time());

        $reset = $this->ReadPropertyInteger('RingResetSeconds');
        if ($reset > 0) {
            $this->SetTimerInterval('ResetRing', $reset * 1000);
        }

        $visu = $this->ReadPropertyInteger('VisuInstanceID');
        if ($visu > 0 && IPS_InstanceExists($visu)) {
            $targetID = $this->DetermineRingTargetID();

            // Ausschließlich die neue Kachel Visualisierung; das Modul setzt
            // Symcon >= 8.2 voraus (VISU_OpenObject; VISU_PostNotification gäbe
            // es schon ab 7.0). Die alte WebFront Visualisierung (WFC_*) wird
            // bewusst nicht mehr unterstützt.
            @VISU_PostNotification($visu, 'Es klingelt', IPS_GetName($this->InstanceID), 'Alarm', $targetID);

            // Für ein fest montiertes Wandpanel (Kiosk-Modus): öffnet auf
            // Symcon 9.1+ die interaktive Vollbildkachel, andernfalls wie
            // bisher das gewählte Kamera-Medienobjekt.
            if ($this->ReadPropertyBoolean('AutoOpenOnRing')) {
                $openTargetID = $this->IsInteractivePopupEnabled()
                    ? $this->InstanceID
                    : $targetID;
                @VISU_OpenObject($visu, $openTargetID, '');

                $timeout = $this->ReadPropertyInteger('LiveImagePopupTimeoutSeconds');
                $this->SetTimerInterval('CloseLiveImagePopup', $timeout > 0 ? $timeout * 1000 : 0);
            }
        }

        $this->SendDebug('Ring', 'Klingel-Ereignis verarbeitet', 0);
    }

    /**
     * Schließt das beim Klingeln automatisch geöffnete Livebild-Popup wieder
     * — läuft über den Timer CloseLiveImagePopup, Intervall über
     * LiveImagePopupTimeoutSeconds einstellbar (0 = nie automatisch
     * schließen, nur manuell per Antippen).
     *
     * Im interaktiven HTML-SDK-Popup wird eine Nachricht an die Darstellung
     * gesendet, die lokal zur Elternkategorie navigiert. Beim bisherigen
     * Medien-Popup bleibt VISU_Reload() der Fallback und lädt die Visualisierung
     * auf allen verbundenen Geräten neu.
     */
    public function CloseLiveImagePopup(): void
    {
        $this->SetTimerInterval('CloseLiveImagePopup', 0);

        $visu = $this->ReadPropertyInteger('VisuInstanceID');
        if ($visu <= 0 || !IPS_InstanceExists($visu)) {
            return;
        }

        if ($this->IsInteractivePopupEnabled()) {
            $parentID = IPS_GetParent($this->InstanceID);
            if ($parentID > 0 && $this->UpdateVisualizationValue(json_encode([
                'action' => 'close',
                'target' => $parentID,
            ]))) {
                return;
            }
        }

        @VISU_Reload($visu);
    }

    /**
     * Bestimmt, wohin die Push-Meldung beim Antippen springt. Ist explizit
     * ein Kamera-Medienobjekt gesetzt, hat das Vorrang. Ohne Angabe springt
     * es direkt zum eigenen Snapshot-Medienobjekt — das wurde in
     * HandleRing() kurz zuvor aktualisiert, zeigt also sofort, wer an der
     * Tür steht, egal aus welcher der drei möglichen Quellen
     * (Livebild-Medienobjekt, offizielle Protect-API, G6-Snapshot-URL) das
     * Bild stammt.
     *
     * Einschränkung der Symcon-App (nicht dieses Moduls): Ein Bild direkt im
     * Benachrichtigungs-Banner ohne Antippen ist nicht möglich, und das
     * Zielobjekt muss innerhalb der unter VisuInstanceID hinterlegten Kachel
     * Visualisierung verfügbar sein (siehe VISU_PostNotification-Doku).
     */
    private function DetermineRingTargetID(): int
    {
        $target = $this->ReadPropertyInteger('NotifyTargetID');
        if ($target > 0) {
            if (IPS_ObjectExists($target)) {
                return $target;
            }
            $this->LogMessage("Push-Ziel {$target} existiert nicht mehr — es wird der eigene Snapshot verwendet", KL_WARNING);
        }

        $snapshotID = @$this->GetIDForIdent('Snapshot');
        return $snapshotID !== false ? $snapshotID : $this->InstanceID;
    }

    // =====================================================================
    //  Snapshot
    // =====================================================================

    /**
     * Aktualisiert das Livebild. Ist eine frei wählbare Kamera (Medienobjekt)
     * hinterlegt, wird deren aktueller Inhalt übernommen — das ist der
     * Standard-Weg, über den IP-Symcon Kamerabilder bereits verteilt
     * (Image Grabber, UniFi-Protect-Modul, eigene Snapshot-URL, …).
     * Ohne Auswahl greift der Fallback auf die G6-Snapshot-URL.
     */
    public function RefreshSnapshot(): bool
    {
        $liveMediaID = $this->ReadPropertyInteger('LiveImageMediaID');
        if ($liveMediaID > 0 && $this->CopyLiveImageMedia($liveMediaID)) {
            return true;
        }
        // Bei Fehlschlag (z. B. falscher Medientyp) auf die nächste Quelle
        // ausweichen, statt den Snapshot komplett scheitern zu lassen.

        $apiKey   = $this->ReadPropertyString('ProtectApiKey');
        $cameraID = $this->ReadPropertyString('CameraID');
        if ($apiKey !== '' && $cameraID !== '') {
            $host  = $this->ReadPropertyString('Host');
            $image = $this->FetchImage(
                "https://{$host}/proxy/protect/integration/v1/cameras/{$cameraID}/snapshot?highQuality=true",
                ['X-API-Key: ' . $apiKey],
                8,
                'Offizielle API'
            );
            if ($image !== null) {
                $this->StoreSnapshot($image);
                return true;
            }
            // Bei Fehlschlag auf die Snapshot-URL zurückfallen, statt ganz zu scheitern.
        }

        $url = $this->ReadPropertyString('SnapshotURL');
        if ($url === '') {
            return false;
        }

        $image = $this->FetchImage($url, [], 5, 'Snapshot-URL');
        if ($image === null) {
            return false;
        }

        $this->StoreSnapshot($image);
        return true;
    }

    /**
     * Lädt ein Bild per HTTP(S) (selbstsigniertes UniFi-Zertifikat erlaubt).
     *
     * @param string[] $headers
     * @return string|null Rohe Bild-Bytes oder null bei Fehlschlag.
     */
    private function FetchImage(string $url, array $headers, int $timeout, string $label): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $image = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($image === false || $code !== 200 || $image === '') {
            $this->SendDebug('Snapshot', "{$label} fehlgeschlagen (HTTP {$code})", 0);
            return null;
        }

        return $image;
    }

    private function StoreSnapshot(string $jpeg): void
    {
        $mediaID = $this->GetIDForIdent('Snapshot');
        IPS_SetMediaContent($mediaID, base64_encode($jpeg));
        IPS_SendMediaEvent($mediaID);
    }

    private function RegisterMediaSnapshot(): void
    {
        $ident = 'Snapshot';
        $mediaID = @$this->GetIDForIdent($ident);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, 'Türkamera');
            IPS_SetPosition($mediaID, 5);
            IPS_SetMediaCached($mediaID, true);
            IPS_SetMediaFile($mediaID, 'media/unifidoor_' . $this->InstanceID . '.jpg', false);
        }
    }

    /**
     * Bindet einen RTSP(S)-Stream als natives Symcon-Stream-Medienobjekt ein
     * (IPS_CreateMedia(MEDIATYPE_STREAM)). IP-Symcon spielt RTSP-Streams
     * damit direkt im Browser (WebFront) und in den Apps ab und agiert dabei
     * selbst als Verteiler — kein Restreamer wie go2rtc nötig. Voraussetzung
     * laut Symcon-Dokumentation: H.264-Kodierung (die G6 läuft standardmäßig
     * genau darauf).
     *
     * Quelle mit Priorität: 1) frei wählbares externes Stream-Medienobjekt
     * (z. B. „Stream_High" einer UniFi-Protect-Modul-Instanz) — dessen
     * URL wird übernommen, 2) manuell eingetragene Stream-URL.
     */
    private function RegisterMediaStream(): void
    {
        $url = $this->ResolveStreamURL();
        if ($url === '') {
            return;
        }

        $ident = 'Stream';
        $mediaID = @$this->GetIDForIdent($ident);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(MEDIATYPE_STREAM);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, 'Live-Stream');
            IPS_SetPosition($mediaID, 6);
        }

        IPS_SetMediaFile($mediaID, $url, false);
    }

    /**
     * Liefert die zu verwendende Stream-URL: bevorzugt aus einem frei
     * wählbaren externen Stream-Medienobjekt übernommen, sonst die manuell
     * eingetragene StreamURL.
     */
    private function ResolveStreamURL(): string
    {
        $sourceMediaID = $this->ReadPropertyInteger('LiveStreamMediaID');
        if ($sourceMediaID > 0 && IPS_MediaExists($sourceMediaID)) {
            $sourceMedia = IPS_GetMedia($sourceMediaID);
            if ($sourceMedia['MediaType'] === MEDIATYPE_STREAM) {
                return $sourceMedia['MediaFile'];
            }
            $this->LogMessage("Als Live-Stream ausgewähltes Medienobjekt {$sourceMediaID} ist kein Stream", KL_ERROR);
        }

        return $this->ReadPropertyString('StreamURL');
    }

    /**
     * Übernimmt den Inhalt eines fremden Bild-Medienobjekts (z. B. das
     * Snapshot-Medienobjekt einer UniFi-Protect-Modul-Instanz) in das
     * eigene Medienobjekt, damit die Instanz-Kachel unabhängig von der
     * Quelle immer das aktuelle Bild zeigt.
     */
    private function CopyLiveImageMedia(int $sourceMediaID): bool
    {
        if (!IPS_MediaExists($sourceMediaID)) {
            $this->SendDebug('Livebild', "Medienobjekt {$sourceMediaID} existiert nicht mehr", 0);
            return false;
        }

        $sourceMedia = IPS_GetMedia($sourceMediaID);
        if ($sourceMedia['MediaType'] !== MEDIATYPE_IMAGE) {
            $hint = $sourceMedia['MediaType'] === MEDIATYPE_STREAM
                ? ' — das ist ein Video-Stream, dafür bitte das Feld „Bestehendes Stream-Medienobjekt übernehmen\" im Bereich Kamera verwenden'
                : '';
            $this->LogMessage("Als Livebild ausgewähltes Medienobjekt {$sourceMediaID} ist kein Bild{$hint}", KL_ERROR);
            return false;
        }

        $content = IPS_GetMediaContent($sourceMediaID);
        if ($content === '') {
            $this->SendDebug('Livebild', "Medienobjekt {$sourceMediaID} liefert kein Bild", 0);
            return false;
        }

        $mediaID = $this->GetIDForIdent('Snapshot');
        IPS_SetMediaContent($mediaID, $content);
        IPS_SendMediaEvent($mediaID);
        return true;
    }

    // =====================================================================
    //  HTTP-Helfer
    // =====================================================================

    /**
     * @return array|false
     */
    private function apiRequest(string $method, string $path, ?array $body = null)
    {
        $host  = $this->ReadPropertyString('Host');
        $port  = $this->ReadPropertyInteger('AccessPort');
        $token = $this->ReadPropertyString('AccessToken');

        if ($host === '' || $token === '') {
            $this->LogMessage('Konfiguration unvollständig', KL_ERROR);
            return false;
        }

        return $this->jsonRequest(
            sprintf('https://%s:%d%s', $host, $port, $path),
            $method,
            ['Authorization: Bearer ' . $token],
            $body,
            'API',
            'API-Token abgelehnt (HTTP %d). Berechtigungen prüfen.'
        );
    }

    /**
     * Gemeinsamer JSON-Request für die Access- und die Protect-API
     * (selbstsigniertes UniFi-Zertifikat erlaubt).
     *
     * @param string[] $authHeaders
     * @return array|false
     */
    private function jsonRequest(string $url, string $method, array $authHeaders, ?array $body, string $label, string $authError)
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => array_merge($authHeaders, [
                'Accept: application/json',
                'Content-Type: application/json',
            ]),
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $this->SendDebug($label . ' ' . $method, $path . ' → HTTP ' . $code, 0);

        if ($response === false) {
            $this->LogMessage("{$label}-Fehler bei {$path}: {$error}", KL_ERROR);
            return false;
        }

        if ($code === 401 || $code === 403) {
            $this->LogMessage(sprintf($authError, $code), KL_ERROR);
            return false;
        }

        if ($code < 200 || $code >= 300) {
            $this->LogMessage("{$label} antwortete mit HTTP {$code}: {$response}", KL_ERROR);
            return false;
        }

        // Leerer Body bei Erfolg (z. B. manche PUT-Aufrufe) ist in Ordnung,
        // nicht-leerer Body ohne gültiges JSON dagegen ein Fehler — sonst
        // sähe er in Listenfunktionen wie „keine Einträge gefunden" aus.
        if (trim((string) $response) === '') {
            return [];
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            $this->LogMessage("{$label}: Antwort von {$path} ist kein gültiges JSON", KL_ERROR);
            return false;
        }
        return $decoded;
    }

    // =====================================================================
    //  WebHook-Registrierung
    // =====================================================================

    private function RegisterHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            $this->LogMessage('Keine WebHook-Control-Instanz gefunden — Klingel-Webhook kann nicht registriert werden. Bitte einmalig eine WebHook-Control-Instanz anlegen.', KL_WARNING);
            return;
        }

        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }

        foreach ($hooks as $index => $entry) {
            if ($entry['Hook'] === $hook) {
                if ($entry['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$index]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }

        $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    /**
     * Gibt die vollständige Webhook-URL zurück — zum Eintragen im Protect Alarm Manager.
     */
    public function GetWebhookURL(): string
    {
        return self::WEBHOOK_PREFIX . '/' . $this->InstanceID;
    }

    // =====================================================================
    //  Gegensprechen (Talkback) — über die offizielle UniFi Protect
    //  Integration API (developer.ui.com/protect), nicht mehr über einen
    //  Reverse-Engineering-Weg. Authentifizierung per API-Key (Header
    //  X-API-Key), erzeugt direkt auf der UniFi-Konsole unter
    //  Einstellungen → Integrations (nicht auf unifi.ui.com).
    //  Bestätigt: die G6 Entry meldet featureFlags.hasSpeaker = true.
    // =====================================================================

    /**
     * Führt einen Request gegen die Protect Integration API aus
     * (https://<Host>/proxy/protect/integration/...).
     *
     * @return array|false
     */
    private function ProtectApiRequest(string $method, string $path, ?array $body = null)
    {
        $host = $this->ReadPropertyString('Host');
        $key  = $this->ReadPropertyString('ProtectApiKey');

        if ($host === '' || $key === '') {
            $this->LogMessage('Protect-API-Key fehlt (für Gegensprechen/Kamera-Details benötigt)', KL_ERROR);
            return false;
        }

        return $this->jsonRequest(
            "https://{$host}/proxy/protect/integration{$path}",
            $method,
            ['X-API-Key: ' . $key],
            $body,
            'Protect-API',
            'Protect-API-Key abgelehnt (HTTP %d). Key auf der UniFi-Konsole unter Einstellungen → Integrations prüfen.'
        );
    }

    /**
     * Listet alle Protect-Kameras mit ihrer ID und Lautsprecher-Fähigkeit —
     * Hilfsfunktion zum Ermitteln der Camera-ID für das Gegensprechen-Feld.
     */
    public function ListProtectCameras(): string
    {
        $cameras = $this->ProtectApiRequest('GET', '/v1/cameras');
        if ($cameras === false) {
            return '';
        }

        $result = [];
        foreach ($cameras as $camera) {
            $result[] = [
                'id'         => $camera['id'] ?? '',
                'name'       => $camera['name'] ?? '',
                'hasSpeaker' => $camera['featureFlags']['hasSpeaker'] ?? false,
            ];
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Lädt die verfügbaren Protect-Kameras und befüllt das Kamera-Dropdown
     * im offenen Konfigurationsformular live nach. Bleibt die zuvor
     * gespeicherte Camera-ID unter den Treffern, bleibt sie ausgewählt.
     */
    public function RefreshCameraList(): void
    {
        $cameras = $this->ProtectApiRequest('GET', '/v1/cameras');
        if ($cameras === false) {
            $this->UpdateFormField('CameraListStatus', 'caption', 'Abruf fehlgeschlagen, siehe Meldungen-Log.');
            return;
        }

        $options = [];
        foreach ($cameras as $camera) {
            $hasSpeaker = $camera['featureFlags']['hasSpeaker'] ?? false;
            $options[] = [
                'caption' => sprintf('%s%s', $camera['name'] ?? '?', $hasSpeaker ? ' (Lautsprecher)' : ''),
                'value'   => $camera['id'] ?? '',
            ];
        }

        if (count($options) === 0) {
            $this->UpdateFormField('CameraListStatus', 'caption', 'Keine Kameras gefunden.');
            return;
        }

        $this->UpdateFormField('CameraID', 'options', json_encode($options));

        $current = $this->ReadPropertyString('CameraID');
        foreach ($options as $option) {
            if ($option['value'] === $current) {
                $this->UpdateFormField('CameraID', 'value', $current);
                break;
            }
        }

        $this->UpdateFormField('CameraListStatus', 'caption', count($options) . ' Kamera(s) gefunden.');
    }

    /**
     * Prüft laut offizieller API, ob die konfigurierte Camera-ID einen
     * Lautsprecher besitzt — Voraussetzung für Gegensprechen.
     */
    public function CheckTalkbackSupport(): bool
    {
        $cameraID = $this->ReadPropertyString('CameraID');
        if ($cameraID === '') {
            $this->LogMessage('Keine Protect-Camera-ID konfiguriert', KL_ERROR);
            return false;
        }

        $camera = $this->ProtectApiRequest('GET', "/v1/cameras/{$cameraID}");
        if ($camera === false) {
            return false;
        }

        $hasSpeaker = (bool) ($camera['featureFlags']['hasSpeaker'] ?? false);
        $this->SendDebug(
            'Talkback',
            $hasSpeaker ? 'Kamera meldet Lautsprecher-Unterstützung' : 'Kamera meldet KEINE Lautsprecher-Unterstützung',
            0
        );
        return $hasSpeaker;
    }

    /**
     * Baut eine Talkback-Session über die offizielle API auf und sendet
     * einen Testton (oder eine konfigurierte Audiodatei) hindurch. Die API
     * liefert eine RTP-Zieladresse plus Codec-Vorgabe (Opus); die eigentliche
     * Kodierung und RTP-Übertragung übernimmt ffmpeg, das auf dem
     * Symcon-Host installiert sein muss.
     */
    public function SendTalkbackTestTone(): bool
    {
        $cameraID = $this->ReadPropertyString('CameraID');
        if ($cameraID === '') {
            $this->LogMessage('Keine Protect-Camera-ID konfiguriert', KL_ERROR);
            return false;
        }

        $session = $this->ProtectApiRequest('POST', "/v1/cameras/{$cameraID}/talkback-session");
        if ($session === false) {
            return false;
        }

        $url          = $session['url'] ?? '';
        $codec        = $session['codec'] ?? '';
        $samplingRate = (int) ($session['samplingRate'] ?? 0);

        if ($url === '' || $codec !== 'opus' || $samplingRate <= 0) {
            $this->LogMessage('Talkback-Session lieferte unerwartetes Format (Codec: ' . ($codec !== '' ? $codec : '?') . ', Samplingrate: ' . $samplingRate . ', URL ' . ($url !== '' ? 'vorhanden' : 'fehlt') . ')', KL_ERROR);
            return false;
        }

        // Die Session-URL kann temporäre Zugangsdaten enthalten — nur Ziel-
        // Host und Port ins Debug-Log, nie die komplette URL.
        $target = (parse_url($url, PHP_URL_HOST) ?: '?') . ':' . (parse_url($url, PHP_URL_PORT) ?: '?');
        $this->SendDebug('Talkback', "Session erhalten: {$target} ({$codec}, {$samplingRate} Hz)", 0);

        $file = $this->ReadPropertyString('TalkbackTestFile');
        $input = ($file !== '' && is_readable($file))
            ? '-i ' . escapeshellarg($file)
            : '-f lavfi -i ' . escapeshellarg('sine=frequency=1000:duration=2');

        $command = sprintf(
            'ffmpeg -y %s -ar %d -ac 1 -c:a libopus -b:a 32k -f rtp %s 2>&1',
            $input,
            $samplingRate,
            escapeshellarg($url)
        );

        $this->SendDebug('Talkback', 'Starte ffmpeg (Opus, ' . $samplingRate . ' Hz) → ' . $target, 0);
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->LogMessage(
                'ffmpeg-Übertragung fehlgeschlagen (ist ffmpeg auf dem Symcon-Host installiert?): '
                . str_replace($url, '<Session-URL>', implode(' | ', array_slice($output, -5))),
                KL_ERROR
            );
            return false;
        }

        $this->SendDebug('Talkback', 'Testton über die offizielle Protect-API gesendet', 0);
        return true;
    }
}
