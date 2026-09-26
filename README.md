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
| Tür öffnen | Aktion über die UniFi-Access-Developer-API |
| Push | Benachrichtigung mit Sprungziel in die Türansicht |

### Was dieses Modul nicht kann

**Gegensprechen.** Die G6 ist kein SIP-Gerät, und UniFi Protect bietet keinen
dokumentierten Weg, von außen eine Audio-Session zur Klingel aufzubauen.
Sprechen bleibt der Protect-App vorbehalten.

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
https://github.com/<dein-account>/SymconUniFiDoor
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

### 4. Webhook für das Klingeln

Die Webhook-Adresse im Konfigurationsformular anzeigen lassen und in UniFi Protect
im **Alarm Manager** als Ziel für das Klingel-Ereignis eintragen.

## Lizenz

MIT
