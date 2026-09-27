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
| Gegensprechen (experimentell) | Testton über den inoffiziellen Protect-Talkback-Kanal — siehe unten |

### Gegensprechen — experimentell, unverifiziert

Die G6 ist kein SIP-Gerät, und Ubiquiti dokumentiert keinen offiziellen Weg, von
außen eine Audio-Session aufzubauen. Es gibt aber einen von der Protect-App selbst
genutzten, undokumentierten WebSocket-Kanal
(`wss://<Host>/proxy/protect/ws/talkback?speaker=<CameraID>`), den auch
Community-Projekte wie `homebridge-unifi-protect` oder `go2rtc` nutzen. Bestätigt ist
das bisher nur für reine Protect-Doorbells (G4 Doorbell Pro/Lite) — **ob die G6 Entry
als UniFi-Access-Gerät denselben Kanal anbietet, ist offen** und muss am echten Gerät
geprüft werden (siehe Einrichtung unten). Ubiquiti kann diesen Weg jederzeit ohne
Vorwarnung ändern oder abschalten.

Aktuell implementiert ist die Diagnose (Lautsprecher-Erkennung, Testton) — eine
Live-Mikrofon-Übertragung für ein echtes Gespräch ist ein separater, größerer
Ausbauschritt und noch nicht Teil des Moduls.

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

### 5. Gegensprechen testen (optional, experimentell)

1. Im Bereich *Gegensprechen* den lokalen Protect-Benutzernamen und -Passwort
   eintragen (derselbe Kontotyp wie für das „Unifi Protect"-Modul)
2. *Protect-Kameras auflisten* klicken, die G6 anhand des Namens identifizieren
   und ihre ID unter *Protect-Camera-ID der G6* eintragen
3. *Lautsprecher-Unterstützung prüfen* klicken — meldet die G6 keinen Lautsprecher,
   funktioniert der Talkback-Kanal auf diesem Gerät vermutlich nicht
4. Testdatei erzeugen: `ffmpeg -f lavfi -i "sine=frequency=1000:duration=1" -ar 24000 -ac 1 -c:a aac -profile:a aac_low -f adts test.aac`
5. Pfad zur Datei eintragen, *Testton senden* klicken und an der Tür lauschen

Kommt kein Ton an, ist entweder der Talkback-Kanal für UniFi-Access-Geräte nicht
verfügbar, oder die Camera-ID/Zugangsdaten stimmen nicht — Details stehen im
Meldungen-Log der Instanz.

## Lizenz

MIT
