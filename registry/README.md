# Katalogserver

Drei Endpunkte für den Plugin-Marktplatz. **Wer nur den
Twitch-Controller betreibt, braucht diesen Ordner nicht** — er ist für
den, der einen Katalog anbietet.

## Einrichten

Die vier Dateien in den DocumentRoot legen, das Plugin-Repository
daneben:

```
/srv/raid/plugins.talutah.de/
    plugins/              git clone …/twitch-controller-plugins.git plugins
    web/html/             <- DocumentRoot
        _lib.php
        index.php
        download.php
        readme.php
```

Passt der Pfad nicht, ist `PLUGINS_DIR` oben in `_lib.php` die einzige
Stelle zum Ändern.

Das Repository liegt **außerhalb** des DocumentRoots. Das ist keine
Kosmetik: darin stehen `plugin.php` und `install.php` jedes Plugins, und
Apache würde sie ausführen, wären sie erreichbar. Der einzige Weg an ein
Paket ist `download.php`.

## Endpunkte

| Adresse | Antwort |
| --- | --- |
| `/index.php` | der Katalog als JSON — die Adresse, die der Client abfragt |
| `/download.php?name=<slug>` | das ZIP |
| `/readme.php?name=<slug>` | die README als Markdown |

Kein Bauschritt: der Katalog wird bei jedem Abruf aus dem Repository
gelesen. Nach einem `git -C plugins pull` ist er aktuell.

## Der Katalog

```json
{
  "format": 1,
  "generated_at": "2026-09-03T09:45:28+00:00",
  "plugins": [
    {
      "slug": "example",
      "name": "Beispiel",
      "version": "1.0.0",
      "description": "…",
      "author": "Twitch-Controller",
      "tags": ["beispiel"],
      "requires": { "core": ">=1.0.0" },
      "download": "https://plugins.talutah.de/download.php?name=example",
      "readme": "https://plugins.talutah.de/readme.php?name=example",
      "sha256": "7b8ef82d…",
      "size": 6466,
      "updated_at": "2026-09-03T09:37:09+00:00"
    }
  ]
}
```

Pflicht sind `slug`, `version` und `download` — ohne die verwirft der
Client den Eintrag. `sha256` braucht er zum Installieren: er lädt das
Paket, vergleicht die Prüfsumme mit dem Katalog und entpackt erst dann.

Geladen wird ausschließlich von demselben Host, der im Client als
Katalogadresse eingestellt ist. Ein manipulierter Katalog kann also
nicht auf einen fremden Server umleiten.

Ein Plugin erscheint nur, wenn Ordnername und `slug` übereinstimmen,
die Version dreiteilig ist und `<slug>.zip` daneben liegt.
