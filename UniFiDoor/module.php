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

        // --- Gegensprechen (experimentell, unverifiziert) ---
        $this->RegisterPropertyString('ProtectUsername', '');
        $this->RegisterPropertyString('ProtectPassword', '');
        $this->RegisterPropertyString('CameraID', '');
        $this->RegisterPropertyString('TalkbackTestFile', '');

        // --- Variablen ---
        $this->RegisterVariableBoolean('Ring', 'Es klingelt', '~Alert', 10);
        $this->RegisterVariableInteger('LastRing', 'Letztes Klingeln', '~UnixTimestamp', 20);
        $this->RegisterVariableBoolean('Unlock', 'Tür öffnen', '~Switch', 30);
        $this->EnableAction('Unlock');

        $this->RegisterMediaSnapshot();

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

        $liveInterval = $this->ReadPropertyInteger('LiveImageIntervalSeconds');
        $this->SetTimerInterval('RefreshLiveImage', $liveInterval > 0 ? $liveInterval * 1000 : 0);

        if ($this->ReadPropertyString('Host') === '' || $this->ReadPropertyString('AccessToken') === '') {
            $this->SetStatus(104); // Instanz inaktiv – Konfiguration unvollständig
            return;
        }

        $this->SetStatus(102);
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
     * Listet die verfügbaren Türen auf — Hilfsfunktion zum Ermitteln der Door-ID.
     */
    public function ListDoors(): string
    {
        $result = $this->apiRequest('GET', '/api/v1/developer/doors');
        if ($result === false) {
            return '';
        }
        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
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
        $target = $this->ReadPropertyInteger('NotifyTargetID');
        if ($visu > 0 && IPS_InstanceExists($visu)) {
            @WFC_PushNotification(
                $visu,
                'Es klingelt',
                IPS_GetName($this->InstanceID),
                'alarm',
                $target > 0 ? $target : $this->InstanceID
            );
        }

        $this->SendDebug('Ring', 'Klingel-Ereignis verarbeitet', 0);
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
    //  Gegensprechen (Talkback) — EXPERIMENTELL, UNVERIFIZIERT
    //
    //  Nutzt einen von der offiziellen UniFi-App verwendeten, aber von
    //  Ubiquiti nicht dokumentierten WebSocket-Kanal der Protect-API
    //  (wss://<Host>/proxy/protect/ws/talkback?speaker=<CameraID>).
    //  Community-Projekte (z. B. homebridge-unifi-protect, go2rtc) nutzen
    //  ihn erfolgreich für reine Protect-Doorbells (G4 Doorbell Pro/Lite).
    //  Ob die G6 Entry als UniFi-Access-Gerät denselben Kanal anbietet, ist
    //  NICHT bestätigt — das muss am echten Gerät geprüft werden
    //  (CheckTalkbackSupport). Ubiquiti kann diesen Weg jederzeit ändern
    //  oder abschalten, ohne Vorwarnung.
    // =====================================================================

    /**
     * Meldet sich mit dem lokalen Protect-Benutzer an und liefert das
     * Session-Token (Cookie „TOKEN") zurück. Getrennt vom Access-API-Token,
     * weil der Talkback-Kanal über die Protect-API läuft, nicht über Access.
     */
    private function ProtectLogin(): ?string
    {
        $host = $this->ReadPropertyString('Host');
        $user = $this->ReadPropertyString('ProtectUsername');
        $pass = $this->ReadPropertyString('ProtectPassword');

        if ($host === '' || $user === '' || $pass === '') {
            $this->LogMessage('Protect-Zugangsdaten unvollständig (für Gegensprechen benötigt)', KL_ERROR);
            return null;
        }

        $ch = curl_init("https://{$host}/api/auth/login");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['username' => $user, 'password' => $pass]),
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code !== 200) {
            $this->LogMessage("Protect-Login fehlgeschlagen (HTTP {$code})", KL_ERROR);
            return null;
        }

        if (!preg_match('/Set-Cookie:\s*TOKEN=([^;]+)/i', (string) $response, $matches)) {
            $this->LogMessage('Protect-Login: Kein Session-Token in der Antwort gefunden', KL_ERROR);
            return null;
        }

        return $matches[1];
    }

    /**
     * Lädt das Protect-Bootstrap-Dokument (alle Kameras inkl. Fähigkeiten).
     *
     * @return array|null
     */
    private function ProtectBootstrap(string $token)
    {
        $host = $this->ReadPropertyString('Host');

        $ch = curl_init("https://{$host}/proxy/protect/api/bootstrap");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => ["Cookie: TOKEN={$token}"],
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $code !== 200) {
            $this->LogMessage("Protect-Bootstrap-Abruf fehlgeschlagen (HTTP {$code})", KL_ERROR);
            return null;
        }

        $bootstrap = json_decode((string) $response, true);
        return is_array($bootstrap) ? $bootstrap : null;
    }

    /**
     * Listet alle Protect-Kameras mit ihrer ID und ob laut Bootstrap ein
     * Lautsprecher vorhanden ist — Hilfsfunktion zum Ermitteln der Camera-ID
     * für das Gegensprechen-Feld.
     */
    public function ListProtectCameras(): string
    {
        $token = $this->ProtectLogin();
        if ($token === null) {
            return '';
        }

        $bootstrap = $this->ProtectBootstrap($token);
        if ($bootstrap === null) {
            return '';
        }

        $cameras = [];
        foreach (($bootstrap['cameras'] ?? []) as $camera) {
            $cameras[] = [
                'id'         => $camera['id'] ?? '',
                'name'       => $camera['name'] ?? '',
                'hasSpeaker' => $camera['featureFlags']['hasSpeaker'] ?? false,
            ];
        }

        return json_encode($cameras, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Prüft, ob die konfigurierte Camera-ID laut Protect-Bootstrap einen
     * Lautsprecher besitzt. Das ist die Voraussetzung für Gegensprechen —
     * aber keine Garantie, dass der Talkback-Kanal auf diesem Gerät auch
     * tatsächlich funktioniert.
     */
    public function CheckTalkbackSupport(): bool
    {
        $cameraID = $this->ReadPropertyString('CameraID');
        if ($cameraID === '') {
            $this->LogMessage('Keine Protect-Camera-ID konfiguriert', KL_ERROR);
            return false;
        }

        $token = $this->ProtectLogin();
        if ($token === null) {
            return false;
        }

        $bootstrap = $this->ProtectBootstrap($token);
        if ($bootstrap === null) {
            return false;
        }

        foreach (($bootstrap['cameras'] ?? []) as $camera) {
            if (($camera['id'] ?? '') === $cameraID) {
                $hasSpeaker = (bool) ($camera['featureFlags']['hasSpeaker'] ?? false);
                $this->SendDebug(
                    'Talkback',
                    $hasSpeaker ? 'Kamera meldet Lautsprecher-Unterstützung' : 'Kamera meldet KEINE Lautsprecher-Unterstützung',
                    0
                );
                return $hasSpeaker;
            }
        }

        $this->LogMessage("Camera-ID {$cameraID} nicht im Protect-Bootstrap gefunden", KL_ERROR);
        return false;
    }

    /**
     * Sendet eine vorbereitete AAC-ADTS-Testdatei über den Talkback-Kanal —
     * zum Nachweis, dass der Weg technisch funktioniert, bevor eine
     * Live-Mikrofon-Übertragung gebaut wird. Erzeugen einer passenden
     * Testdatei z. B. mit:
     *   ffmpeg -f lavfi -i "sine=frequency=1000:duration=1" \
     *          -ar 24000 -ac 1 -c:a aac -profile:a aac_low -f adts test.aac
     */
    public function SendTalkbackTestTone(): bool
    {
        $cameraID = $this->ReadPropertyString('CameraID');
        $file     = $this->ReadPropertyString('TalkbackTestFile');

        if ($cameraID === '' || $file === '') {
            $this->LogMessage('Camera-ID oder Testdatei für Gegensprech-Test fehlt', KL_ERROR);
            return false;
        }
        if (!is_readable($file)) {
            $this->LogMessage("Testdatei nicht lesbar: {$file}", KL_ERROR);
            return false;
        }

        $token = $this->ProtectLogin();
        if ($token === null) {
            return false;
        }

        $frames = $this->SplitAdtsFrames((string) file_get_contents($file));
        if (count($frames) === 0) {
            $this->LogMessage('Testdatei enthält keine gültigen ADTS-Frames (AAC-LC, 24 kHz, mono erwartet)', KL_ERROR);
            return false;
        }

        $socket = $this->OpenTalkbackSocket($cameraID, $token);
        if ($socket === null) {
            return false;
        }

        // ~900 ms Stille vor dem eigentlichen Ton, damit der Lautsprecher
        // beim Aufwecken nichts abschneidet.
        usleep(900000);

        foreach ($frames as $frame) {
            $this->WebsocketSendBinary($socket, $frame);
            usleep(42700); // 1024 Samples / 24000 Hz ≈ 42,7 ms pro AAC-Frame
        }

        fclose($socket);
        $this->SendDebug('Talkback', 'Testton gesendet (' . count($frames) . ' Frames)', 0);
        return true;
    }

    /**
     * Baut die rohe WebSocket-Verbindung zum Talkback-Endpunkt auf
     * (RFC-6455-Handshake von Hand, da IP-Symcon keinen WebSocket-Client
     * mitbringt).
     *
     * @return resource|null
     */
    private function OpenTalkbackSocket(string $cameraID, string $token)
    {
        $host = $this->ReadPropertyString('Host');

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:443",
            $errno,
            $errstr,
            8,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            $this->LogMessage("Talkback: Verbindung fehlgeschlagen ({$errstr})", KL_ERROR);
            return null;
        }

        $key  = base64_encode(random_bytes(16));
        $path = "/proxy/protect/ws/talkback?speaker={$cameraID}";
        $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Key: {$key}\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Origin: https://{$host}\r\n"
            . "Cookie: TOKEN={$token}\r\n"
            . "\r\n";

        fwrite($socket, $request);
        $response = fread($socket, 2048);

        if ($response === false || strpos($response, ' 101 ') === false) {
            $firstLine = strtok((string) $response, "\r\n");
            $this->LogMessage('Talkback: WebSocket-Handshake abgelehnt — ' . ($firstLine !== false ? $firstLine : 'keine Antwort'), KL_ERROR);
            fclose($socket);
            return null;
        }

        return $socket;
    }

    /**
     * Verschickt einen Binär-Frame über eine offene WebSocket-Verbindung.
     * Client→Server-Frames MÜSSEN laut RFC 6455 maskiert sein.
     *
     * @param resource $socket
     */
    private function WebsocketSendBinary($socket, string $payload): void
    {
        $length = strlen($payload);
        $mask   = random_bytes(4);

        $frame = chr(0x82); // FIN-Bit + Opcode 0x2 (Binary)

        if ($length <= 125) {
            $frame .= chr($length | 0x80);
        } elseif ($length <= 65535) {
            $frame .= chr(126 | 0x80) . pack('n', $length);
        } else {
            $frame .= chr(127 | 0x80) . pack('J', $length);
        }

        $frame .= $mask;
        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask[$i % 4];
        }

        fwrite($socket, $frame);
    }

    /**
     * Zerlegt einen rohen ADTS-Bytestrom (AAC-LC) in einzelne Frames —
     * jeder Frame wird als eigene WebSocket-Nachricht verschickt.
     */
    private function SplitAdtsFrames(string $data): array
    {
        $frames = [];
        $length = strlen($data);
        $pos    = 0;

        while ($pos + 7 <= $length) {
            if (ord($data[$pos]) !== 0xFF || (ord($data[$pos + 1]) & 0xF0) !== 0xF0) {
                break; // kein gültiger ADTS-Sync an dieser Stelle
            }

            $frameLength = ((ord($data[$pos + 3]) & 0x03) << 11)
                | (ord($data[$pos + 4]) << 3)
                | (ord($data[$pos + 5]) >> 5);

            if ($frameLength <= 0 || $pos + $frameLength > $length) {
                break;
            }

            $frames[] = substr($data, $pos, $frameLength);
            $pos += $frameLength;
        }

        return $frames;
    }
}
