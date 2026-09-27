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
| Tür öffnen | Aktion über die UniFi-Access-Developer-API — **verifiziert am echten Gerät** |
| Tür-/Kamera-Auswahl | Per Radio-Button bzw. Dropdown, live aus der API befüllt — kein manuelles Eintippen von IDs nötig |
| Push | Benachrichtigung mit Sprungziel in die Türansicht (siehe Einschränkungen unten) |
| Wandpanel-Sprung (optional) | Fest montiertes WebFront-Panel wechselt beim Klingeln automatisch, ganz ohne Antippen, auf den Kamera-Reiter — via `WFC_SwitchPage()` |
| Gegensprechen | Testton über die offizielle UniFi Protect Integration API — siehe unten |

### Push-Benachrichtigung — Einschränkungen

Zwei Dinge, die an der Symcon-App liegen, nicht am Modul:

- **Kein Livebild im Benachrichtigungs-Banner selbst.** Titel und Text sind alles, was
  vor dem Antippen sichtbar ist — ein Bild lässt sich laut Symcon-Entwickler technisch
  nicht einbetten.
- **Das Sprungziel muss ein Medienobjekt sein, das in einer WebFront-Kachel liegt.**
  Ist es nur ein Kind-Objekt der Instanz, aber auf keiner Visualisierungsseite platziert,
  scheitert der Sprung beim Antippen lautlos mit „TargetID is too deep".
- **Push-Benachrichtigungen benötigen ein gültiges Symcon-App-Abo** sowie ein in
  IP-Symcon registriertes Gerät — ohne das kommt gar nichts an.

Wer eine echte "Livebild, bevor man reagiert"-Erfahrung will (wie früher bei DoorIP per
SIP-Early-Media), erreicht das nur über ein **fest montiertes WebFront-Wandpanel**: Mit
dem *WebFront-Reiter*-Feld unten schaltet das Panel beim Klingeln automatisch auf den
Kamera-Reiter um und weckt sich dabei aus dem Ruhezustand — ganz ohne SIP.

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

Die Webhook-Adresse im Konfigurationsformular anzeigen lassen und in UniFi Protect
im **Alarm Manager** als Ziel für das Klingel-Ereignis eintragen.

Optional, nur bei einem fest montierten WebFront-Wandpanel: Im WebFront-Editor den
Reiter mit dem Kamerabild öffnen, den dort in Klammern angezeigten Namen (z. B.
„item1234") unter *WebFront-Reiter für automatischen Sprung* eintragen — das Panel
schaltet beim Klingeln dann von selbst dorthin um.

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
