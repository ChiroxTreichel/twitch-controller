# Plugin-Katalog

Die Gegenseite zu *Konto → Plugins → Plugins finden*. Läuft eigenständig,
gehört nicht zur Installation auf dem Streamer-Server.

## Aufstellen

`registry/public/` ist das DocumentRoot. Alles darüber (`bin/`) gehört
**nicht** ins Web.

```
plugins.example.com  ->  /pfad/zu/registry/public
```

Für Apache liegt eine `.htaccess` bei; sie braucht `AllowOverride All`
oder die Regeln direkt im vHost. Nginx braucht stattdessen:

```nginx
root /pfad/zu/registry/public;
index index.php;
location / { try_files $uri $uri/ /index.php?$query_string; }
```

> Wenn die Domain mit **403** antwortet, zeigt das DocumentRoot meist noch
> aufs falsche Verzeichnis oder es fehlt eine Startdatei. Prüfen: liegt
> `public/index.php` wirklich unter dem eingestellten Pfad?

PHP 8.2 oder neuer mit der Erweiterung `zip`. Mehr wird nicht gebraucht —
keine Datenbank, kein Composer.

## Ein Plugin veröffentlichen

```bash
# 1. Plugin-Ordner zu einem Paket packen
php bin/pack.php /pfad/zum/plugin

# 2. Katalog neu erzeugen
php bin/build.php --base-url https://plugins.example.com
```

`pack.php` liest Slug und Version aus `plugin.json` und schreibt
`public/pkg/<slug>-<version>.zip`. `build.php` geht alle Pakete durch,
berechnet Prüfsummen und schreibt `public/index.json`.

Liegen mehrere Versionen eines Plugins im Ordner, kommt die höchste in den
Katalog. Alte Pakete kann man liegen lassen — sie bleiben abrufbar, werden
aber nicht mehr angeboten.

Statt `--base-url` geht auch `REGISTRY_BASE_URL` als Umgebungsvariable.

## Zusätzliche Angaben

Was nicht ins Plugin selbst gehört — Langtext, Bilder, Schlagworte —
kommt nach `public/meta/<slug>.json`:

```json
{
  "summary": "Eine Zeile, erscheint in der Liste",
  "description": "Langtext für die Detailseite.\n\nUnterstützt **fett**, *kursiv*, `Code`, Aufzählungen mit \"- \" und [Links](https://example.com).",
  "tags": ["alerts", "overlay"],
  "homepage": "https://github.com/…",
  "icon": "img/alerts.png",
  "screenshots": ["img/alerts-1.png", "img/alerts-2.png"]
}
```

Alles darin ist optional und überschreibt die Werte aus `plugin.json`.
Relative Bildpfade werden beim Bauen zu vollständigen Adressen ergänzt;
Bilder gehören nach `public/img/`.

Der Langtext wird beim Streamer **nicht** als HTML eingebettet, sondern
serverseitig durch einen kleinen Markdown-Ersatz geschickt, der vorher
alles escaped. HTML im Text erscheint deshalb als Text.

## Schnittstelle

| Adresse | Zweck |
| --- | --- |
| `GET /index.json` | der ganze Katalog — **das ist die eigentliche Schnittstelle** |
| `GET /api/plugins` | dasselbe, mit `?q=` und `?tag=` filterbar |
| `GET /api/plugins/<slug>` | ein Plugin |
| `GET /pkg/<datei>.zip` | das Paket |
| `GET /` | Übersicht im Browser |

Der Client holt ausschließlich `/index.json` und sucht danach lokal. Die
`/api/`-Endpunkte sind Bequemlichkeit für andere Werkzeuge — wer mag, kann
`public/` auch komplett statisch ausliefern und `index.php` weglassen.

### Format von index.json

```json
{
  "format": 1,
  "generated_at": "2026-09-02T13:55:49+00:00",
  "plugins": [
    {
      "slug": "alerts",
      "name": "Alerts",
      "version": "1.0.0",
      "summary": "…",
      "description": "…",
      "author": "…",
      "homepage": "https://…",
      "tags": ["alerts"],
      "icon": "https://…/img/alerts.png",
      "screenshots": ["https://…"],
      "requires": { "core": ">=1.0.0" },
      "optional": { "overlay": ">=1.0.0" },
      "download": "https://…/pkg/alerts-1.0.0.zip",
      "sha256": "…",
      "size": 12345,
      "updated_at": "2026-09-02T13:55:49+00:00"
    }
  ]
}
```

`format` muss `1` sein, sonst lehnt der Client den Katalog ab. Einträge
ohne gültigen `slug`, ohne `version` im Format `X.Y.Z` oder ohne
`download` werden übersprungen.

## Was der Client prüft, bevor er installiert

Wissenswert, weil es festlegt, was ein Paket erfüllen muss:

- **Gleicher Host.** Die `download`-Adresse muss auf demselben Host liegen
  wie der Katalog. Ein übernommener Katalog kann so nicht auf fremde
  Pakete umleiten. Ein CDN unter anderem Namen funktioniert daher nicht.
- **Prüfsumme.** `sha256` ist Pflicht. Fehlt sie, wird nicht installiert.
- **Größe.** Download höchstens 32 MB, entpackt höchstens 128 MB,
  höchstens 3000 Dateien.
- **Pfade.** Kein Eintrag im Archiv darf aus seinem Verzeichnis
  herausführen.
- **Inhalt.** `plugin.json` und `plugin.php` müssen im Wurzelverzeichnis
  des Archivs liegen (ein einzelner Unterordner wird toleriert), und der
  `slug` im Manifest muss der angeforderte sein.

Die Prüfsumme schützt gegen kaputte Übertragung, **nicht** gegen einen
übernommenen Katalogserver — wer den Index kontrolliert, kontrolliert auch
den Hash. Wer das absichern will, kann Signaturen einschalten: dazu ein
Ed25519-Schlüsselpaar erzeugen, jedes Paket signieren, die Signatur als
`signature` (base64) in den Index legen und beim Streamer den öffentlichen
Schlüssel in der Einstellung `registry_public_key` hinterlegen. Ist dort
ein Schlüssel gesetzt, wird die Signatur verbindlich geprüft.
