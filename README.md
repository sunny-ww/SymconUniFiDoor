# SymconUniFiDoor

IP-Symcon-Modul für UniFi-Türstationen (entwickelt mit der **G6 Entry** am **Door Hub Mini**).

Bindet Klingel-Ereignis, Kamerabild und Türöffner der UniFi-Welt in IP-Symcon ein —
als Ersatz für das SIP-basierte DoorIP, das mit UniFi-Klingeln nicht funktioniert.

## Funktionsumfang

| | |
|---|---|
| Klingel-Ereignis | Boolean-Variable, gesetzt per Webhook aus dem Protect Alarm Manager |
| Letztes Klingeln | Zeitstempel |
| Kamerabild | Medien-Objekt, bei jedem Klingeln aktualisiert |
| Livebild (optional) | Beliebiges bestehendes Bild-Medienobjekt (z. B. UniFi-Protect-Modul, Image Grabber) wird laufend übernommen — Fallback ist die G6-Snapshot-URL |
| Tür öffnen | Aktion über die UniFi-Access-Developer-API |
| Push | Benachrichtigung mit Sprungziel in die Türansicht |
| Gegensprechen | Testton über die offizielle UniFi Protect Integration API — siehe unten |

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

**Live-Video im Browser** kommt nicht direkt aus Protect: RTSP spielt kein Browser ab.
Wer Bewegtbild in der Visualisierung will, stellt go2rtc oder MediaMTX daneben und
trägt hier die davon erzeugte HLS- oder WebRTC-Adresse ein.

## Voraussetzungen

- IP-Symcon 8.0 oder neuer
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
Dann *Verfügbare Türen auflisten* klicken und die Door-ID übernehmen.

Mit *Tür öffnen (Test)* prüfen, ob der Öffner anspricht.

### 3. Kamerabild

Am einfachsten über den anonymen Snapshot: In der Weboberfläche der Kamera
(Benutzer `ubnt`, Gerätepasswort aus der Konsole) *Anonymous Snapshot* aktivieren,
dann als Snapshot-URL eintragen:

```
http://<IP-der-Kamera>/snap.jpeg
```

Funktioniert der anonyme Snapshot nicht zuverlässig (z. B. wegen Zertifikats- oder
Netzwerkproblemen), unter *Kamera für Livebild* stattdessen ein bestehendes
Bild-Medienobjekt auswählen — etwa das Snapshot-Medienobjekt einer bereits
eingerichteten **UniFi-Protect-Modul**-Instanz. Diese Instanz übernimmt dann
laufend dessen aktuelles Bild über den normalen Medienobjekt-Mechanismus von
IP-Symcon; die G6-Snapshot-URL wird in diesem Fall nicht mehr benötigt.

### 4. Webhook für das Klingeln

Die Webhook-Adresse im Konfigurationsformular anzeigen lassen und in UniFi Protect
im **Alarm Manager** als Ziel für das Klingel-Ereignis eintragen.

### 5. Gegensprechen testen (optional)

1. `ffmpeg` auf dem Symcon-Server installieren, falls noch nicht vorhanden
   (z. B. `apt install ffmpeg`)
2. API-Key erzeugen: auf der **UniFi-Konsole** (nicht unifi.ui.com) unter
   **Einstellungen → Integrations → Create New API Key** (wird nur einmal
   angezeigt) und im Bereich *Gegensprechen* eintragen
3. *Protect-Kameras auflisten* klicken und die G6 im Dropdown auswählen
   (Kameras mit Lautsprecher sind entsprechend markiert)
4. *Lautsprecher-Unterstützung prüfen* klicken — sollte Lautsprecher-Unterstützung
   melden
5. *Testton senden* klicken (ohne Testdatei-Pfad genügt das) und an der Tür lauschen

Kommt kein Ton an, steht der genaue Fehler (z. B. abgelehnter API-Key, fehlendes
ffmpeg, unerwartetes Session-Format) im Meldungen-/Debug-Log der Instanz.

## Lizenz

MIT
