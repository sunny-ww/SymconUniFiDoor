# SymconUniFiDoor

IP-Symcon-Modul für UniFi-Türstationen (entwickelt mit der **G6 Entry** am **Door Hub Mini**).

Bindet Klingel-Ereignis, Kamerabild und Türöffner der UniFi-Welt in IP-Symcon ein —
als Ersatz für das SIP-basierte DoorIP, das mit UniFi-Klingeln nicht funktioniert.

## Funktionsumfang

| | |
|---|---|
| Klingel-Ereignis | Boolean-Variable, gesetzt per Webhook aus dem Protect Alarm Manager |
| Letztes Klingeln | Zeitstempel |
| Kamerabild | Medien-Objekt, bei jedem Klingeln aktualisiert. Quelle mit Priorität: 1) frei wählbares Livebild-Medienobjekt, 2) offizielle Protect-API (`GET /v1/cameras/{id}/snapshot`), 3) G6-Snapshot-URL als Fallback |
| Livebild (optional) | Beliebiges bestehendes Bild-Medienobjekt (z. B. UniFi-Protect-Modul, Image Grabber) wird laufend übernommen |
| Live-Video (optional) | RTSP(S)-Stream der G6 als natives Symcon-Stream-Medienobjekt — kein Restreamer nötig |
| Tür öffnen | Aktion über die UniFi-Access-Developer-API — **verifiziert am echten Gerät** |
| Tür-/Kamera-Auswahl | Per Radio-Button bzw. Dropdown, live aus der API befüllt — kein manuelles Eintippen von IDs nötig |
| Push | Benachrichtigung mit Sprungziel in die Türansicht (siehe Einschränkungen unten) — läuft über die neue **Kachel Visualisierung** (`VISU_PostNotification`), mit Fallback auf die alte **WebFront Visualisierung** (`WFC_PushNotification`) |
| Wandpanel-Autoöffnen (optional) | Fest montiertes Panel mit Kachel Visualisierung zeigt beim Klingeln automatisch, ganz ohne Antippen, das Kamerabild als Vollbild-Kachel — via `VISU_OpenObject()` |
| Interaktives Klingel-Popup (ab Symcon 9.1, optional) | Eigene HTML-SDK-Vollbildkachel mit Kamerabild und direkter Türöffnen-Schaltfläche. Unter Symcon 8.2 bis 9.0 bleibt das bisherige Medien-Popup verfügbar. |
| Gegensprechen | Testton über die offizielle UniFi Protect Integration API — siehe unten |

### Push-Benachrichtigung — Einschränkungen

Zwei Dinge, die an der Symcon-App liegen, nicht am Modul:

- **Kein Livebild im Benachrichtigungs-Banner selbst.** Titel und Text sind alles, was
  vor dem Antippen sichtbar ist — ein Bild lässt sich laut Symcon-Entwickler technisch
  nicht einbetten.
- **Bei der alten WebFront Visualisierung** muss das Sprungziel ein Medienobjekt sein,
  das in einer WebFront-Kachel liegt. Ist es nur ein Kind-Objekt der Instanz, aber auf
  keiner Visualisierungsseite platziert, scheitert der Sprung beim Antippen lautlos mit
  „TargetID is too deep". Bei der neuen Kachel Visualisierung tritt dieses Problem nicht auf.
- **Push-Benachrichtigungen benötigen ein gültiges Symcon-App-Abo** sowie ein in
  IP-Symcon registriertes Gerät — ohne das kommt gar nichts an.

Wer eine echte "Livebild, bevor man reagiert"-Erfahrung will (wie früher bei DoorIP per
SIP-Early-Media), erreicht das über ein **fest montiertes Wandpanel mit der neuen Kachel
Visualisierung**: Mit *Bei Klingeln automatisch öffnen* zeigt das Panel beim Klingeln
automatisch das Kamerabild — ganz ohne SIP, Antippen oder manuelle Reitersuche.

Ab Symcon 9.1 kann zusätzlich *Interaktives Popup mit Türöffnen-Schaltfläche verwenden*
aktiviert werden. Die Option ist standardmäßig ausgeschaltet und muss in den
Instanzeinstellungen bewusst aktiviert werden. Dann öffnet das Klingelereignis die
HTML-SDK-Vollbildansicht der Modulinstanz mit dem aktuellen Kamerabild, einer direkten
*Tür öffnen*-Schaltfläche und einer Zurücknavigation zur Kategorie der Instanz. Die
Ansicht ist bisher nicht auf einem Wandpanel verifiziert. Bei aktivierter Option verwendet
die Modulinstanz auch außerhalb des Popups diesen Visualisierungstyp; die normale
Instanzkachel kann daher anders aussehen. Der automatische Timeout sendet eine Navigation
an die verbundenen Ansichten dieser Visualisierung. Unter Symcon 8.2 bis 9.0 wird die
Option ausgeblendet und das bisherige Medien-Popup verwendet; dort schließt der Timeout
weiterhin über `VISU_Reload()`, das alle verbundenen Geräte neu lädt.
`VISU_OpenObject()` öffnet die Ansicht auf allen verbundenen Geräten der gewählten
Visualisierung.

### Gegensprechen

Die G6 ist kein SIP-Gerät, aber UniFi Protect bietet seit einiger Zeit eine
**offizielle, dokumentierte** Integration API (developer.ui.com/protect) mit einem
Talkback-Endpunkt:

```
POST /v1/cameras/{id}/talkback-session
```

Antwort: eine RTP-Zieladresse plus Audio-Vorgabe (Opus, 24 kHz, 16 Bit). Die G6 Entry
meldet laut `featureFlags.hasSpeaker` grundsätzlich Lautsprecher-Unterstützung —
bestätigt am echten Gerät (siehe Einrichtung unten). Authentifiziert wird per
API-Key (`X-API-Key`-Header), erzeugt direkt auf der **UniFi-Konsole** unter
**Einstellungen → Integrations** (nicht auf unifi.ui.com).

Aktuell implementiert ist die Diagnose (Kameras auflisten, Lautsprecher-Prüfung,
Testton) — die eigentliche Kodierung/Übertragung übernimmt `ffmpeg`, das auf dem
Symcon-Server installiert sein muss. Eine Live-Mikrofon-Übertragung für ein echtes
Gespräch ist ein separater, größerer Ausbauschritt und noch nicht Teil des Moduls.

**Live-Video** läuft über IP-Symcons eigenen Stream-Medientyp: Einfach die
`rtsps://`-Adresse der G6 (UniFi Protect → Kamera → RTSP-Freigabe) unter
*Kamera → RTSP(S)-Stream-URL* eintragen. IP-Symcon spielt RTSP/RTSPS nativ im
WebFront und in den Apps ab, sofern H.264-kodiert — bei der G6 im
Standard-Kodierungsmodus der Fall. Kein Restreamer wie go2rtc oder MediaMTX nötig.

## Voraussetzungen

- IP-Symcon 8.2 oder neuer (nutzt ausschließlich die neue Kachel Visualisierung
  für Push/Wandpanel-Funktionen, `VISU_OpenObject` braucht mindestens 8.2)
- UniFi-Konsole mit installierter **Access**-Applikation
- UniFi Access Door Hub (getestet: Door Hub Mini) mit angelegter Tür
- Eine UniFi-Türstation mit Klingeltaster (getestet: G6 Entry)

## Installation

Im Symcon Module Store unter *Modul über Git-Repository hinzufügen*:

```
https://github.com/sunny-ww/SymconUniFiDoor
```

## Einrichtung

### 1. API-Token erzeugen

In der **Access-Applikation** unter *Einstellungen → Allgemein → API-Token → Neu erstellen*.

> Nicht über die Integrations-Seite der Konsole — die dort erzeugten Token gehören zu
> Protect und werden von der Access-API abgelehnt.

Empfohlene Berechtigungen (Minimalprinzip — der Token liegt in der Symcon-Konfiguration):

| Berechtigung | Wert |
|---|---|
| Standorte | Bearbeiten |
| Gerät | Anzeigen |
| Systemprotokoll | Anzeigen |
| alle übrigen | Keinen |

Meldet das Modul **HTTP 403**, eine Stufe erweitern und erneut testen.

### 2. Instanz anlegen

Host, Port (Standard 12445) und Token eintragen, übernehmen.
Dann im Bereich *Tür* auf *Verfügbare Türen auflisten* klicken — die gefundene(n)
Tür(en) erscheinen als Radio-Buttons; bei genau einer Tür wird sie automatisch
ausgewählt. Der Klarname bleibt auch nach *Übernehmen* stehen (wird bei jedem
Öffnen des Formulars live nachgeschlagen).

Mit *Tür öffnen (Test)* prüfen, ob der Öffner anspricht.

### 3. Kamerabild

Drei mögliche Quellen, das Modul nutzt automatisch die erste verfügbare:

**1) Livebild-Medienobjekt (höchste Priorität)** — unter *Kamera für Livebild* ein
bestehendes Bild-Medienobjekt auswählen, z. B. das Snapshot-Medienobjekt einer
bereits eingerichteten **UniFi-Protect-Modul**-Instanz. Übernimmt laufend dessen
aktuelles Bild über den normalen Medienobjekt-Mechanismus von IP-Symcon.

**2) Offizielle Protect-API (empfohlen, falls 1 nicht genutzt wird)** — siehe
Schritt 5 unten: Sobald dort ein API-Key und eine Camera-ID hinterlegt sind, nutzt
das Modul automatisch `GET /v1/cameras/{id}/snapshot`. Kein Extra-Setup an der
Kamera nötig.

**3) Anonymer G6-Snapshot (Fallback)** — In der Weboberfläche der Kamera
(Benutzer `ubnt`, Gerätepasswort aus der Konsole) *Anonymous Snapshot* aktivieren,
dann als Snapshot-URL eintragen:

```
http://<IP-der-Kamera>/snap.jpeg
```

### 4. Webhook für das Klingeln

UniFi Protect muss Symcon aktiv mitteilen, wenn es klingelt — das passiert über einen
**Alarm-Manager-Webhook**. Ohne den erfährt das Modul nie von einem Klingel-Ereignis.

**a) URL zusammensetzen**

Im Modul-Formular auf *Webhook-Pfad anzeigen* klicken — liefert nur den Pfad
(z. B. `/hook/unifidoor/12345`), da das Modul eure von außen erreichbare Server-Adresse
nicht kennen kann. Davor die Basis-Adresse ergänzen: dieselbe Adresse, mit der ihr
Symcon im Browser öffnet, plus Port `:3777` (Standard-Webhook-Port, siehe ggf. eure
WebHook-Control-Instanz, falls abweichend konfiguriert). Beispiel:

```
http://192.168.178.4:3777/hook/unifidoor/12345
```

**b) In UniFi Protect anlegen**

In der **Protect-App** (nicht Access) → **Alarm Manager** → *New Alarm*:

| Feld | Wert |
|---|---|
| Name | frei wählbar, z. B. „Symcon Webhook-Klingel" |
| Trigger | **Doors** → Haken bei **Ring** (nicht Count/Door Status/Scan) |
| Applies To | die G6-Tür anhaken |
| Action | **Webhook** → *Custom Webhook* |
| Delivery URL | die oben zusammengesetzte URL |
| Delivery method | **POST** |
| Authentication | **None** |
| Content | Default Content (Use Thumbnails kann deaktiviert bleiben, das Modul holt Snapshots unabhängig davon) |

Danach *Create* klicken.

**c) Testen**

Die Regel erneut öffnen und auf **„Test Alarm"** klicken — das simuliert ein
Klingel-Ereignis, ohne dass jemand am Taster drücken muss. Direkt danach im
Meldungen-/Debug-Log der Symcon-Instanz nachsehen: Unter `Webhook` sollte der rohe
Payload auftauchen, und `Ring` kurz auf `true` springen. Kommt nichts an, zuerst
prüfen, ob überhaupt eine **WebHook-Control**-Instanz in Symcon existiert (Pflicht,
siehe Meldungen-Log — ohne die schlägt die Registrierung lautlos fehl).

Optional, nur bei einem fest montierten Wandpanel mit der neuen Kachel Visualisierung:
Unter *Bei Klingeln automatisch öffnen* den Haken setzen. Ab Symcon 9.1 kann zusätzlich
*Interaktives Popup mit Türöffnen-Schaltfläche verwenden* aktiviert werden (standardmäßig
aus). Dann öffnet sich die eigene Türkamera-Ansicht mit der Entriegelungs-Schaltfläche.
Bei deaktivierter Option oder Symcon 8.2 bis 9.0 öffnet sich stattdessen das unter *Kamera-Medienobjekt für Push-Ziel*
gewählte Medienobjekt (oder ohne Auswahl der eigene Snapshot) als Vollbild-Kachel. Die
Funktion benötigt mindestens Symcon 8.2 und wirkt nicht in der alten WebFront Visualisierung.

### 5. Protect-API-Key einrichten (für Gegensprechen und/oder Kamerabild)

1. `ffmpeg` auf dem Symcon-Server installieren, falls noch nicht vorhanden
   (z. B. `apt install ffmpeg`) — nur für Gegensprechen nötig, nicht für den Snapshot
2. API-Key erzeugen: auf der **UniFi-Konsole** (nicht unifi.ui.com) unter
   **Einstellungen → Integrations → Create New API Key** (wird nur einmal
   angezeigt) und im Bereich *Gegensprechen* eintragen
3. *Protect-Kameras auflisten* klicken und die G6 im Dropdown auswählen
   (Kameras mit Lautsprecher sind entsprechend markiert); damit ist automatisch
   auch der Kamerabild-Snapshot aus Schritt 3, Quelle 2 aktiv
4. *Lautsprecher-Unterstützung prüfen* klicken — sollte Lautsprecher-Unterstützung
   melden
5. *Testton senden* klicken (ohne Testdatei-Pfad genügt das) und an der Tür lauschen

Kommt kein Ton an, steht der genaue Fehler (z. B. abgelehnter API-Key, fehlendes
ffmpeg, unerwartetes Session-Format) im Meldungen-/Debug-Log der Instanz.

## Lizenz

MIT
