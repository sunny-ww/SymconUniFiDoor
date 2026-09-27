<?php

declare(strict_types=1);

/**
 * UniFi Door — IP-Symcon Modul für UniFi Access / Protect Türstationen
 * (entwickelt für die G6 Entry am Door Hub Mini)
 */
class UniFiDoor extends IPSModule
{
    private const WEBHOOK_PREFIX = '/hook/unifidoor';

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
        $this->RegisterPropertyInteger('LiveImageMediaID', 0);
        $this->RegisterPropertyInteger('LiveImageIntervalSeconds', 0);

        // --- Verhalten ---
        $this->RegisterPropertyInteger('RingResetSeconds', 10);
        $this->RegisterPropertyInteger('NotifyTargetID', 0);
        $this->RegisterPropertyInteger('VisuInstanceID', 0);
        $this->RegisterPropertyBoolean('AutoOpenOnRing', false);

        // --- Gegensprechen (offizielle Protect Integration API) ---
        $this->RegisterPropertyString('ProtectApiKey', '');
        $this->RegisterPropertyString('CameraID', '');
        $this->RegisterPropertyString('TalkbackTestFile', '');

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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterHook(self::WEBHOOK_PREFIX . '/' . $this->InstanceID);
        $this->RegisterMediaSnapshot();
        $this->RegisterMediaStream();

        $liveInterval = $this->ReadPropertyInteger('LiveImageIntervalSeconds');
        $this->SetTimerInterval('RefreshLiveImage', $liveInterval > 0 ? $liveInterval * 1000 : 0);

        if ($this->ReadPropertyString('Host') === '' || $this->ReadPropertyString('AccessToken') === '') {
            $this->SetStatus(104); // Instanz inaktiv – Konfiguration unvollständig
            return;
        }

        $this->SetStatus(102);
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
            foreach ($panel['items'] as &$item) {
                $name = $item['name'] ?? '';
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
                $this->Unlock();
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

    protected function ProcessHookData(): void
    {
        $raw = file_get_contents('php://input');
        $this->SendDebug('Webhook', $raw, 0);

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo 'invalid payload';
            return;
        }

        // Protect Alarm Manager und Access senden unterschiedliche Strukturen.
        // Wir werten pragmatisch auf ein Klingel-Ereignis aus.
        $isRing = false;
        foreach (['trigger', 'event', 'type', 'alarm'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key])
                && stripos($payload[$key], 'ring') !== false) {
                $isRing = true;
            }
        }

        if ($isRing) {
            $this->HandleRing();
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

            // Ausschließlich die neue Kachel Visualisierung (Symcon >= 7.0
            // für Push, >= 8.2 für automatisches Öffnen). Die alte WebFront
            // Visualisierung (WFC_*) wird bewusst nicht mehr unterstützt.
            @VISU_PostNotification($visu, 'Es klingelt', IPS_GetName($this->InstanceID), 'Alarm', $targetID);

            // Für ein fest montiertes Wandpanel (Kiosk-Modus): öffnet das
            // Zielobjekt live auf allen offenen Geräten dieser Visualisierung
            // — als Vollbild-Kachel, ganz ohne Antippen.
            if ($this->ReadPropertyBoolean('AutoOpenOnRing')) {
                @VISU_OpenObject($visu, $targetID, '');
            }
        }

        $this->SendDebug('Ring', 'Klingel-Ereignis verarbeitet', 0);
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
            return $target;
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
        if ($liveMediaID > 0) {
            return $this->CopyLiveImageMedia($liveMediaID);
        }

        $apiKey   = $this->ReadPropertyString('ProtectApiKey');
        $cameraID = $this->ReadPropertyString('CameraID');
        if ($apiKey !== '' && $cameraID !== '') {
            $image = $this->FetchOfficialSnapshot($cameraID);
            if ($image !== null) {
                $mediaID = $this->GetIDForIdent('Snapshot');
                IPS_SetMediaContent($mediaID, base64_encode($image));
                IPS_SendMediaEvent($mediaID);
                return true;
            }
            // Bei Fehlschlag auf die Snapshot-URL zurückfallen, statt ganz zu scheitern.
        }

        $url = $this->ReadPropertyString('SnapshotURL');
        if ($url === '') {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $image = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($image === false || $code !== 200 || $image === '') {
            $this->SendDebug('Snapshot', "Fehlgeschlagen (HTTP {$code})", 0);
            return false;
        }

        $mediaID = $this->GetIDForIdent('Snapshot');
        IPS_SetMediaContent($mediaID, base64_encode($image));
        IPS_SendMediaEvent($mediaID);
        return true;
    }

    /**
     * Holt einen Snapshot über die offizielle Protect Integration API —
     * bevorzugt gegenüber dem anonymen G6-Snapshot, da authentifiziert und
     * ohne Extra-Konfiguration an der Kamera selbst.
     *
     * @return string|null Rohe JPEG-Bytes oder null bei Fehlschlag.
     */
    private function FetchOfficialSnapshot(string $cameraID): ?string
    {
        $host = $this->ReadPropertyString('Host');
        $key  = $this->ReadPropertyString('ProtectApiKey');

        $ch = curl_init("https://{$host}/proxy/protect/integration/v1/cameras/{$cameraID}/snapshot?highQuality=true");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ['X-API-Key: ' . $key],
        ]);
        $image = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($image === false || $code !== 200 || $image === '') {
            $this->SendDebug('Snapshot', "Offizielle API fehlgeschlagen (HTTP {$code})", 0);
            return null;
        }

        return $image;
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
     * Bindet die RTSP(S)-Stream-URL der G6 als natives Symcon-Stream-
     * Medienobjekt ein (IPS_CreateMedia(MEDIATYPE_STREAM)). IP-Symcon spielt
     * RTSP-Streams damit direkt im Browser (WebFront) und in den Apps ab und
     * agiert dabei selbst als Verteiler — kein Restreamer wie go2rtc nötig.
     * Voraussetzung laut Symcon-Dokumentation: H.264-Kodierung (die G6 läuft
     * standardmäßig genau darauf).
     */
    private function RegisterMediaStream(): void
    {
        $url = $this->ReadPropertyString('StreamURL');
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
            $this->LogMessage("Als Livebild ausgewähltes Medienobjekt {$sourceMediaID} ist kein Bild", KL_ERROR);
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

        $url = sprintf('https://%s:%d%s', $host, $port, $path);

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            // UniFi-Konsolen nutzen ein selbstsigniertes Zertifikat
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        $this->SendDebug('API ' . $method, $path . ' → HTTP ' . $code, 0);

        if ($response === false) {
            $this->LogMessage("API-Fehler: {$error}", KL_ERROR);
            return false;
        }

        if ($code === 401 || $code === 403) {
            $this->LogMessage("API-Token abgelehnt (HTTP {$code}). Berechtigungen prüfen.", KL_ERROR);
            return false;
        }

        if ($code < 200 || $code >= 300) {
            $this->LogMessage("API antwortete mit HTTP {$code}: {$response}", KL_ERROR);
            return false;
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : [];
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

        $url = "https://{$host}/proxy/protect/integration{$path}";

        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $key,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->SendDebug('Protect-API ' . $method, $path . ' → HTTP ' . $code, 0);

        if ($response === false) {
            $this->LogMessage("Protect-API-Fehler bei {$path}", KL_ERROR);
            return false;
        }

        if ($code === 401 || $code === 403) {
            $this->LogMessage("Protect-API-Key abgelehnt (HTTP {$code}). Key auf der UniFi-Konsole unter Einstellungen → Integrations prüfen.", KL_ERROR);
            return false;
        }

        if ($code < 200 || $code >= 300) {
            $this->LogMessage("Protect-API antwortete mit HTTP {$code}: {$response}", KL_ERROR);
            return false;
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : [];
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
            $this->LogMessage('Talkback-Session lieferte unerwartetes Format: ' . json_encode($session), KL_ERROR);
            return false;
        }

        $this->SendDebug('Talkback', "Session erhalten: {$url} ({$codec}, {$samplingRate} Hz)", 0);

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

        $this->SendDebug('Talkback', "Starte: {$command}", 0);
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->LogMessage(
                'ffmpeg-Übertragung fehlgeschlagen (ist ffmpeg auf dem Symcon-Host installiert?): '
                . implode(' | ', array_slice($output, -5)),
                KL_ERROR
            );
            return false;
        }

        $this->SendDebug('Talkback', 'Testton über die offizielle Protect-API gesendet', 0);
        return true;
    }
}
