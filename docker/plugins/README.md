# Der Katalogserver

Liefert das Plugin-Repository so aus, dass der Twitch-Controller es als
Katalog lesen kann. Läuft unter `plugins.talutah.de`.

Eigener Stack mit eigenem Lebenslauf — mit dem Controller hat er nur
das Proxy-Netz gemeinsam.

## Aufbau

```
docker/plugins/
  docker-compose.yaml
  default.conf            nginx
  php-fpm.conf            damit CATALOG_BASE_URL ankommt
  src/
    public/index.php      das Docroot: eine Datei, der Katalog
    plugins/              das geklonte Plugin-Repository
```

Die Pakete liegen **neben** dem Docroot, nicht darin. Damit ist der
Quelltext der Plugins gar nicht erst erreichbar, statt ihn im
Auslieferer wieder aussperren zu müssen — eine Sperrliste bliebe früher
oder später unvollständig, ein Verzeichnis außerhalb des Docroots nicht.

## Dynamisch, nicht erzeugt

`src/public/index.php` **ist** der Katalog. Er liest bei jedem Aufruf
`src/plugins/` und baut daraus die Antwort — es gibt keine erzeugte
`index.json`, die jemand nach einem Paket vergessen könnte nachzuziehen.

Veröffentlichen:

```bash
php bin/pack.php <slug>     # im Arbeitsverzeichnis, dann commit + push
```

```bash
git -C src/plugins pull     # auf dem Katalogserver
```

Kein Neustart, kein Erzeugungsschritt. Auch die Prüfsumme wird bei
jedem Aufruf gerechnet — eine abgelegte Prüfsumme, die niemand
nachzieht, ist schlimmer als keine: nach einem erneuten Packen schlüge
jede Installation fehl.

## Erreichbar ist

| Adresse | |
| --- | --- |
| `/` | der Katalog, mit `?search=` |
| `/<slug>/<slug>.zip` | die Pakete |
| `/<slug>/README.md` | die Beschreibungen |

Alles andere ist 404.

## Gesucht wird hier

Der Controller hängt `?search=` an und erwartet eine gefilterte Liste —
so geht nur über die Leitung, was gebraucht wird. Gesucht wird über
Slug, Name, Beschreibung und Schlagworte; mehrere Wörter müssen alle
vorkommen.

## Einrichten

```bash
git clone git@github.com:ChiroxTreichel/twitch-controller-plugins.git src/plugins
```

```bash
docker compose up -d
```

Das Proxy-Netz wird **nicht** angelegt (`external: true`) — es muss
dasselbe sein, in dem auch der Twitch-Controller hängt. Welches das
ist, steht in dessen `.env` unter `PROXY_NETWORK`. Stimmt es nicht,
bricht Compose ab mit *network … declared as external, but could not be
found*. Das ist die freundliche Variante: ein stillschweigend
angelegtes eigenes Netz wäre schlimmer — der Alias existierte, und der
Proxy fände ihn trotzdem nicht.

Im Nginx Proxy Manager dann:

| | |
| --- | --- |
| Domain Names | `plugins.talutah.de` |
| Scheme | `http` |
| Forward Hostname | `plugins` |
| Forward Port | `80` |

## Die Adressen im Katalog

`download` muss eine vollständige Adresse sein, sonst wirft der
Controller den Eintrag weg, und `readme` muss auf demselben Host
liegen, sonst holt er sie gar nicht erst. Beide baut `index.php` aus
der Anfrage — hinter dem Proxy zählt dabei `X-Forwarded-Proto`, denn
dort kommt sie als `http` an, während sie nach draußen `https` ist.

Reicht der Proxy die Kopfzeile nicht weiter, hilft eine `.env` neben
der `docker-compose.yaml`:

```
CATALOG_BASE_URL=https://plugins.talutah.de
PROXY_NETWORK=<das Netz des Proxys>
```

## Was nicht im Katalog landet

Ein Ordner ohne `<slug>.zip`, ein Manifest, dessen `slug` nicht zum
Ordner passt, und eine Version, die keine ist. Alle drei ergäben einen
Eintrag, der vollständig aussieht und dessen Installation erst beim
Klick scheitert.

Ist noch nichts geklont, ist der Katalog leer — und ein leerer Katalog
ist ein gültiger Katalog, keine Fehlermeldung.
