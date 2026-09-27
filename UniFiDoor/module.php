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
            IPS_SetMediaFile($mediaID, 'media/unifidoor_' . $this->InstanceID . '.jpg', true);
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
}
