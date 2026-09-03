# Twitch-Controller

Alerts, Ziele und Overlays für deinen Stream — auf deinem eigenen Server,
ohne Fremdplattform dazwischen.

Du bekommst eine Weboberfläche, in der du deine Follow-, Abo-, Bit- und
Raid-Alerts einstellst und als Browserquelle in OBS einbindest. Deine
Moderatoren kannst du einladen und ihnen genau die Rechte geben, die sie
brauchen. Was du nicht brauchst, installierst du einfach nicht.

---

## Was du dafür brauchst

**Einen kleinen Server mit Linux.** Bei Anbietern wie Hetzner, Netcup oder
Contabo kostet einer ab etwa 4 € im Monat. Es geht auch ein Raspberry Pi
zu Hause, wenn du dich damit auskennst. Nimm Ubuntu oder Debian, wenn du
die Wahl hast.

**Eine Domain.** Also eine Adresse wie `twitch.deinname.de`. Wenn du
schon eine Domain hast, reicht eine Unteradresse davon. Sie muss auf deinen
Server zeigen — bei deinem Domain-Anbieter heißt der passende Eintrag
**A-Record**, dort trägst du die IP-Adresse deines Servers ein.

**Zehn Minuten Zeit** und die Bereitschaft, einen Befehl in ein schwarzes
Fenster zu kopieren. Mehr ist es nicht.

> Warum überhaupt ein eigener Server? Weil damit deine Zuschauerdaten,
> deine Spenden und deine Einstellungen dir gehören und nicht einem
> Anbieter, der morgen die Preise ändert oder zumacht.

---

## Installation

Verbinde dich mit deinem Server. Bei Windows nimmst du dazu
[PuTTY](https://www.putty.org/) oder gibst in der Eingabeaufforderung
`ssh benutzer@server-ip` ein; bei Mac und Linux dasselbe im Terminal.

Dann kopierst du diese eine Zeile hinein und drückst Enter:

```bash
curl -fsSL https://raw.githubusercontent.com/ChiroxTreichel/twitch-controller/main/install.sh | sudo bash
```

Jetzt wirst du zwei Dinge gefragt:

1. **Deine Domain** — also `twitch.deinname.de`, ohne `https://`
2. **Wie HTTPS laufen soll** — wenn du nicht weißt, was das bedeutet:
   nimm die vorgeschlagene Antwort mit Enter. Sie ist richtig.

Danach läuft alles von selbst. Beim ersten Mal dauert es ein paar Minuten,
weil einiges heruntergeladen wird. Am Ende steht auf dem Bildschirm, was
noch zu tun ist.

Falls etwas fehlt — zum Beispiel Docker, das Programm, in dem alles läuft —
fragt das Skript, ob es das nachinstallieren soll. Sag ruhig ja.

---

## Twitch-Verbindung anlegen

Das ist der einzige Schritt, der etwas Klickarbeit ist. Twitch verlangt,
dass jede Installation sich einmal offiziell anmeldet. Das musst nur du
machen, nicht deine Zuschauer.

1. Öffne [dev.twitch.tv/console/apps/create](https://dev.twitch.tv/console/apps/create)
   und melde dich mit deinem Twitch-Account an.
2. **Name**: irgendwas, zum Beispiel `Meine Overlays`. Sieht niemand außer
   dir.
3. **OAuth Redirect URLs**: hier trägst du genau das ein, mit deiner
   eigenen Domain:

   ```
   https://overlays.deinname.de/auth/callback
   ```

   Achte darauf, dass kein Leerzeichen und kein Schrägstrich am Ende
   dazukommt. Das ist die häufigste Stolperstelle.
4. **Category**: `Website Integration`
5. Auf **Create** klicken.
6. Bei der neuen Anwendung auf **Manage** und dann auf **New Secret**.
   Du siehst jetzt zwei lange Zeichenfolgen: **Client ID** und
   **Client Secret**. Lass die Seite offen.

Das Client Secret bekommst du nur einmal zu sehen. Falls du es verlierst,
klickst du einfach erneut auf *New Secret* — das alte wird dann ungültig.

---

## Einrichtung im Browser

Jetzt öffne deine Adresse im Browser:

```
https://overlays.deinname.de
```

Du landest automatisch in der Einrichtung und wirst durch vier Schritte
geführt:

**1. Prüfung** — Es wird kontrolliert, ob auf dem Server alles vorhanden
ist. Steht überall „in Ordnung", klickst du weiter.

**2. Twitch-Anwendung** — Hier setzt du die Client ID und das Client
Secret aus dem vorigen Abschnitt ein. Das dritte Feld ist schon
ausgefüllt, das lässt du so.

**3. Kanal verbinden** — Ein Klick, dann fragt Twitch, ob du erlauben
willst. **Wichtig:** melde dich hier mit deinem Kanal-Account an, nicht
mit einem Bot- oder Zweitaccount. Dieser Account wird der Besitzer deiner
Installation.

**4. Events** — Hier wird bei Twitch angemeldet, worüber du informiert
werden willst: Follows, Abos, Bits, Raids. Ein Klick, fertig.

Danach bist du drin.

---

## Was du jetzt hast

Links im Menü findest du **Konto** mit vier Punkten:

| Punkt | Wofür |
| --- | --- |
| **Benutzer** | Moderatoren einladen und festlegen, was sie dürfen |
| **Aktivitäten** | Alles, was im Kanal passiert ist — Follows, Abos, Bits, Raids |
| **Overlay** | Die Fläche, die du in OBS einbaust |
| **Plugins** | Zusatzfunktionen an- und abschalten |
| **Einstellungen** | Deine Twitch-Verbindung |

Der Kern selbst macht absichtlich wenig. Alles Weitere — Alerts,
Spendenziele, Chat-Befehle — kommt als **Plugin** dazu, und du
installierst nur, was du wirklich willst. Unter *Konto → Plugins* siehst
du, was verfügbar ist.

### Das Overlay in OBS einbauen

Das Overlay ist die durchsichtige Fläche, auf der später deine Alerts und
Ziele erscheinen. Sie zeigt von sich aus nichts — das machen die Plugins.
Einbauen kannst du sie aber schon jetzt, dann ist sie fertig, wenn das
erste Plugin dazukommt.

1. Unter *Konto → Overlay* steht die Adresse. Kopiere sie.
2. In OBS: **Quelle hinzufügen → Browser**.
3. Die Adresse einsetzen, Breite **1920**, Höhe **1080**.
4. Auf **OK** klicken.

Jetzt kommt der Schritt, der leicht übersehen wird: **einmal anmelden.**
Eine Browserquelle in OBS ist ein eigener kleiner Browser und kennt deine
Anmeldung nicht.

5. Rechtsklick auf die Quelle → **Interagieren**
6. Im Fenster, das aufgeht, mit Twitch anmelden
7. Fenster schließen

Ab jetzt merkt sich OBS das. Ob es geklappt hat, prüfst du so: unter
*Konto → Overlay* auf **Test senden** klicken — in OBS muss oben in der
Quelle kurz „Die Verbindung steht" erscheinen. Kommt nichts, wiederhole
Schritt 5 bis 7.

> **Tipp:** Aktiviere bei der Quelle *nicht* die Option „Quelle beim
> Ausblenden herunterfahren". Damit vergisst OBS die Anmeldung immer
> wieder.

### Moderatoren einladen

Unter *Konto → Benutzer* klickst du auf **Link erstellen** und schickst
den Link an die Person. Sie meldet sich damit über Twitch an und ist
drin — ohne Passwort, ohne dass du ihr Zugangsdaten geben musst.

Standardmäßig darf sie erst mal nur zuschauen. Über **Rechte** legst du
einzeln fest, was sie ändern darf. Jedes Recht ist in normalem Deutsch
erklärt.

Ohne so einen Link kann sich niemand anmelden — auch nicht, wenn er deine
Adresse kennt.

---

## Auf dem neuesten Stand bleiben

Das geht aus der Oberfläche heraus: unter *Konto → Einstellungen* findest
du oben die Karte **System**. Dort steht deine Version, und ein Klick auf
**Nach Updates sehen** prüft, ob es etwas Neueres gibt. Wenn ja, erscheint
**Jetzt aktualisieren** — das läuft im Hintergrund und ist meist in unter
einer Minute durch. Danach die Seite neu laden.

Deine Einstellungen, Benutzer, Daten und installierten Plugins bleiben
dabei erhalten.

Dieselben Knöpfe liegen zur Sicherheit noch ein zweites Mal unter
`https://deine-domain/rescue` — für den Fall, dass die Einstellungsseite
selbst einmal nicht mehr lädt.

**Manche Updates brauchen doch die Konsole.** Wenn sich am Server selbst
etwas ändert, sagt dir die Oberfläche das und zeigt den passenden Befehl
an. Er sieht so aus:

```bash
cd /opt/overlays && sudo ./install.sh
```

Denselben Befehl kannst du auch sonst jederzeit benutzen — er holt den
neuen Stand und startet alles einmal durch.

---

## Sicherheitskopie

Zwei Dinge solltest du regelmäßig wegsichern — beides liegt im
Installationsordner, standardmäßig `/opt/overlays`:

- die Datei **`.env`** — darin steht der Schlüssel, mit dem deine
  Twitch-Zugangsdaten verschlüsselt sind
- den Ordner **`pgdata`** — darin liegen deine Einstellungen, Benutzer
  und Aktivitäten

Ohne die `.env` ist eine Kopie der Daten wertlos, weil sie sich nicht mehr
entschlüsseln lässt. Am besten sicherst du beides zusammen.

---

## Wenn etwas nicht klappt

**Die Seite lädt nicht.** Zeigt deine Domain wirklich auf den Server? Das
Installationsskript sagt es dir, wenn nicht. Nach einer Änderung am
DNS-Eintrag kann es bis zu einer Stunde dauern, bis sie überall ankommt.

**Twitch sagt „redirect URI does not match".** Dann stimmt die Adresse in
der Twitch-Konsole nicht genau. Sie muss lauten
`https://deine-domain/auth/callback` — mit `https`, ohne Schrägstrich am
Ende, ohne Leerzeichen.

**Die Einrichtung meckert bei Schritt 4.** Twitch ruft deine Adresse
sofort selbst auf, um sie zu prüfen. Das klappt nur, wenn die Seite von
außen über HTTPS erreichbar ist. Öffne deine Adresse einmal von deinem
Handy im Mobilfunknetz — wenn es dort nicht geht, geht es für Twitch auch
nicht.

**Es kommen keine Alerts / keine Aktivitäten.** Schau unter *Konto →
Einstellungen* und klicke auf **Abos abgleichen**. Das ist auch nach jedem
neu aktivierten Plugin nötig.

**Eine Seite in der Verwaltung lädt nicht mehr.** Dafür gibt es den
Notausgang. Rufe ihn direkt auf:

```
https://deine-domain/rescue
```

Diese Seite ist absichtlich fast leer — kein Menü, keine Plugins — und
läuft deshalb auch dann noch, wenn eine andere Seite streikt. Dort kannst
du das System aktualisieren und die Sprache umstellen. Meistens ist damit
alles wieder in Ordnung, ohne dass du auf die Konsole musst.

**Irgendwas anderes.** Dieser Befehl zeigt dir, was der Server gerade
macht:

```bash
cd /opt/overlays && sudo docker compose logs -f web
```

Mit `Strg + C` beendest du die Anzeige wieder. Wenn du damit nicht
weiterkommst, kopiere die letzten Zeilen und frag nach — daran ist
meistens direkt zu erkennen, was fehlt.

---

## Für Entwickler

Wie der Kern aufgebaut ist und wie man eigene Plugins schreibt, steht in
[docs/entwicklung.md](docs/entwicklung.md).
