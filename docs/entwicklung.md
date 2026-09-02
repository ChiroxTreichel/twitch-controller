# Entwicklung

Technische Unterlagen. Für die Installation als Streamer siehe
[README.md](../README.md).

---

## Aufbau

```
core/        Kern: Login, Benutzer, Aktivitäten, Plugin-Verwaltung, Twitch
plugins/     Funktionserweiterungen, je ein Ordner
public/      DocumentRoot, enthält nur den Front-Controller und Assets
bin/         Hintergrundprozess
docker/      Images und Serverkonfiguration
docs/        diese Unterlagen
```

Der Kern kennt bewusst nur vier Seiten: **Benutzer**, **Aktivitäten**,
**Plugins**, **Einstellungen**. Twitch ist Teil des Kerns, weil ohne
Twitch-Login niemand hineinkommt. Alles andere — Overlay, Alerts, Ziele,
Spenden, Throne — ist Plugin.

Alle Requests laufen über `public/index.php`. Grund: Plugins liegen
außerhalb des DocumentRoots und können keine Dateien darin ablegen, sie
registrieren stattdessen Routen im Router.

### Container

| Service | Aufgabe |
| --- | --- |
| `web` | Apache mit PHP, beantwortet alle Requests |
| `worker` | ruft im Takt den Hook `cron.tick` auf |
| `db` | Postgres, kein Host-Port nach außen |
| `caddy` | TLS und Frontdoor, im Compose-Profil `caddy` |

Der Datenbankdienst heißt `db` und nicht `postgres`, damit er sich auf
einem Server mit weiteren Stacks nicht mit einem vorhandenen Netz-Alias
`postgres` beißt.

### Konfiguration

In der `.env` steht nur, was gebraucht wird, **bevor** die Datenbank
erreichbar ist: `APP_URL`, `APP_KEY`, `DB_*` und die Compose-Schalter.
Alles Fachliche liegt in der Tabelle `settings` und ist über die
Oberfläche änderbar.

Geheimnisse (Twitch-Client-Secret, Webhook-Secret, Tokens) liegen mit
`APP_KEY` verschlüsselt in der Datenbank — siehe `core/Support/Crypto.php`.

---

## Plugins schreiben

Ein Plugin ist ein Ordner unter `plugins/`:

```
plugins/<slug>/
  plugin.json      Manifest
  plugin.php       Einstiegspunkt: registriert Hooks und Routen
  install.php      Tabellen anlegen (idempotent, läuft auch bei Updates)
  uninstall.php    Tabellen abräumen
  views/           eigene Vorlagen        (optional)
  assets/          CSS, JS, Medien        (optional)
  src/             Klassen unter Overlays\Plugin\<Slug>\  (optional)
```

`plugins/beispiel/` ist ein vollständig kommentiertes Beispiel und die
ausführbare Fassung dieser Dokumentation.

### Manifest

```json
{
  "slug": "throne",
  "name": "Throne",
  "version": "1.0.0",
  "description": "Wunschlisten-Spenden als Alert",
  "requires": { "core": ">=1.0.0" },
  "optional": { "alerts": ">=1.0.0" }
}
```

`requires` sind harte Abhängigkeiten — fehlt eine, lässt sich das Plugin
nicht aktivieren, und ein Plugin, von dem andere abhängen, lässt sich nicht
deaktivieren. `optional` sind weiche: das Plugin läuft auch ohne, kann aber
mehr, wenn das andere aktiv ist. Der Schlüssel `core` meint die
Kernversion.

Bedingungen: `*`, `1.2.3`, `>=1.2.3`, `<2.0.0`, `^1.2.3`, `~1.2.3` und
Kombinationen mit Leerzeichen (`>=1.0.0 <2.0.0`).

Der Ordnername muss dem `slug` entsprechen, und `plugin.php` muss
existieren — sonst wird das Plugin beim Einlesen übersprungen und der Grund
protokolliert.

### Lebenszyklus

| Aktion | Was passiert |
| --- | --- |
| Installieren | `install.php` mit `$fromVersion = null`, Eintrag in `plugins` |
| Aktivieren | Abhängigkeiten prüfen, `enabled = true`, Hook `plugin.activated` |
| Deaktivieren | prüft, dass kein aktives Plugin hart abhängt |
| Aktualisieren | `install.php` mit der vorher installierten Version |
| Entfernen | `uninstall.php`, dann Einstellungen des Scopes löschen |

`install.php` läuft also mehrfach und muss idempotent sein
(`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`). Für gezielte
Schritte zwischen Versionen `$fromVersion` auswerten:

```php
if ($fromVersion !== null && version_compare($fromVersion, '1.1.0', '<')) {
    $db->run('ALTER TABLE throne_ziele ADD COLUMN IF NOT EXISTS farbe TEXT');
}
```

Die Ladereihenfolge ergibt sich aus den Abhängigkeiten (harte wie weiche);
Zyklen werden erkannt und protokolliert. Ein Plugin, das beim Laden eine
Exception wirft, wird übersprungen — die Plugin-Verwaltung bleibt dadurch
immer bedienbar.

### In plugin.php verfügbar

| Variable | Typ |
| --- | --- |
| `$app` | `Overlays\Core\App` |
| `$plugin` | `Overlays\Core\Plugin\Manifest` |
| `$hooks` | `Overlays\Core\Hook\Hooks` |
| `$router` | `Overlays\Core\Http\Router` |
| `$settings` | `Overlays\Core\Config\Settings` |
| `$db` | `Overlays\Core\Database\Db` |

`plugin.php` soll nur registrieren, nicht arbeiten — sie wird bei jedem
Request geladen.

### Hooks

`dispatch` meldet ein Ereignis, `filter` reicht einen Wert durch alle
Zuhörer und gibt das Ergebnis zurück. Kleinere Priorität läuft früher.

| Hook | Art | Zweck |
| --- | --- | --- |
| `admin.nav` | filter | Menüpunkte anhängen |
| `plugin.settings` | filter | Einstellungsseite in der Plugin-Liste verlinken |
| `permissions.catalog` | filter | eigene Rechte anmelden |
| `core.event.stored` | dispatch | auf ein eingegangenes Event reagieren |
| `core.events.normalize` | filter | eigene Event-Quellen vereinheitlichen |
| `core.eventsub.subscriptions` | filter | zusätzliche Twitch-Abos anfordern |
| `core.eventsub.revoked` | dispatch | Twitch hat ein Abo entzogen |
| `core.twitch.broadcaster_scopes` | filter | zusätzliche Twitch-Rechte anfordern |
| `core.twitch.bot_scopes` | filter | Twitch-Rechte für den Chat-Account |
| `core.oauth.callback` | filter | eigenen Login-Flow abschließen |
| `core.landing` | filter | Startseite übernehmen |
| `cron.tick` | dispatch | wiederkehrende Aufgaben |
| `plugin.installed` / `.activated` / `.deactivated` / `.upgraded` / `.uninstalled` | dispatch | Lebenszyklus |
| `plugins.booted` | dispatch | alle Plugins geladen |
| `user.login` / `.created` / `.removed` / `.permissions_changed` | dispatch | Benutzerverwaltung |

`core.event.stored` läuft im Webhook-Request. Twitch erwartet eine schnelle
Antwort, also dort nur notieren und die Arbeit in `cron.tick` erledigen.

### Einstellungen und Daten

Jedes Plugin hat einen eigenen Scope:

```php
$scope = Settings::pluginScope($plugin->slug);   // "plugin:throne"

$app->settings->set('ziel', 250, $scope);
$app->settings->int('ziel', 0, $scope);
$app->settings->setSecret('api_key', $key, $scope);   // verschlüsselt
$app->settings->secret('api_key', '', $scope);
```

Der Scope wird beim Entfernen des Plugins mitgelöscht. Für echte Tabellen
`install.php` benutzen und die Namen mit dem Slug präfixen.

### Routen und Rechte

```php
$router->get('/throne', $handler, [
    'auth'       => true,
    'permission' => 'Throne.Seite.View',
]);
```

Muster kennen `{name}` für ein Segment und `{name*}` für den Rest des
Pfades. Handler-Signatur: `fn(Request $request, array $params): Response`.

Rechte folgen dem Schema `Bereich.Funktion.Recht`. Rechte auf `.View`
bekommen neu eingeladene Benutzer automatisch. Superadmin umgeht jede
Prüfung.

Statische Plugin-Dateien liegen unter `/plugin/<slug>/assets/<pfad>` und
werden vom Kern ausgeliefert (nur bekannte Dateitypen, kein
Verzeichniswechsel).

### Eigene Vorlagen

```php
$app->view->from($plugin->directory . '/views')->render('seite', [
    'title'  => 'Throne',
    'active' => 'throne',
]);
```

In der Vorlage stehen `$e` (Escaping), `$url`, `$app` und die übergebenen
Daten bereit. Ohne dritten Parameter wird das Layout des Kerns benutzt.

---

## Marktplatz

*Konto → Plugins* hat zwei Reiter: **Installierte Plugins** und **Plugins
finden**. Der zweite holt einen Katalog von `plugins.talutah.de`
(einstellbar über `registry_url`), sucht darin und zeigt eine eigene
Detailseite - kein iframe, die Daten werden bei uns gerendert.

Beteiligte Klassen:

| Klasse | Aufgabe |
| --- | --- |
| `core/Registry/Client.php` | Katalog holen, zwischenspeichern, durchsuchen |
| `core/Registry/Installer.php` | Paket herunterladen, prüfen, entpacken, einsetzen |
| `core/Admin/PluginsController.php` | die beiden Reiter und die Detailseite |
| `core/Support/Markdown.php` | Beschreibungstexte, escapt vor dem Umwandeln |

Der Katalog wird eine Stunde lang als frisch betrachtet und liegt in der
Einstellung `registry_cache`. Die Liste der installierten Plugins liest
bewusst nur den Zwischenspeicher, damit sie nicht auf einen fremden Server
wartet.

Beim Installieren prüft `Installer` in dieser Reihenfolge: gleicher Host
wie der Katalog, Größenbegrenzungen, `sha256`, optionale Signatur, Pfade
im Archiv, Vorhandensein von `plugin.json` und `plugin.php`, und ob der
Slug im Paket der angeforderte ist. Erst danach werden die Dateien aus
einem Nebenordner an ihren Platz geschoben; scheitert etwas, kommt der
alte Stand zurück.

Weil der Webserver als `www-data` läuft, muss `plugins/` ihm gehören -
`install.sh` setzt das. Ist es nicht beschreibbar, sagt der Reiter das und
bietet nichts zum Installieren an.

Die Gegenseite samt Format liegt in [registry/README.md](../registry/README.md).

Eigene Einstellungen eines Plugins - etwa PayPal-Zugangsdaten - werden
über den Hook `plugin.settings` in der Plugin-Liste verlinkt:

```php
$hooks->on('plugin.settings', function (array $pages) use ($plugin) {
    $pages[$plugin->slug] = [
        'label' => 'PayPal einrichten',
        'href'  => '/spenden/einstellungen',
    ];
    return $pages;
});
```

---

## Twitch

Es gibt genau **eine** Redirect-URI: `https://<domain>/auth/callback`. Der
Zweck des Logins steckt im HMAC-signierten `state`-Parameter, das Nonce in
einem kurzlebigen Cookie. Plugins hängen eigene Login-Flows an
`core.oauth.callback` und brauchen keine zweite URI.

EventSub-Events kommen auf `https://<domain>/hooks/twitch` an und werden per
HMAC gegen das Webhook-Secret geprüft, dazu gegen ihr Alter (Replay-Schutz).
Anschließend werden sie normalisiert in `events` geschrieben;
`UNIQUE(source, external_id)` macht Doppelzustellungen harmlos.

Follows bekommen einen berechneten Schlüssel `follow:<user>:<zeit>` statt
der zufälligen Nachrichten-ID. So kann ein Plugin, das von Twitch
unterdrückte Follows über die Follower-Liste nachträgt, denselben
Schlüssel verwenden — die Datenbank dedupliziert dann von selbst.

Welche Abos gebraucht werden, ergibt sich aus der Basisliste des Kerns plus
allem, was Plugins über `core.eventsub.subscriptions` melden. *Konto →
Einstellungen → Abos abgleichen* legt Fehlendes an und entfernt, was auf
unsere Callback-URL zeigt, aber nicht mehr gebraucht wird.

---

## Updates

`core/Update/Updater.php`, sichtbar unter *Konto → Einstellungen → System*.

Der Ablauf ist absichtlich zweistufig:

1. Die Oberfläche schreibt `update_requested_at` in die Einstellungen.
2. Der `worker` sieht das beim nächsten Takt, spult den Checkout per
   `git merge --ff-only` vor, ruft `installCore()` sowie
   `upgradeIfNeeded()` für jedes installierte Plugin auf und beendet sich.
   Docker startet ihn wegen `restart: always` neu — nötig, damit er die
   neuen Plugin-Dateien lädt.
3. Das Ergebnis landet in `update_last_result` und wird angezeigt.

Grund für die Zweistufigkeit: Apache antwortet als `www-data` und darf im
Projektordner (gehört root) nicht schreiben. Der Worker läuft als root.

Der Webcontainer bekommt **keinen** Zugriff auf den Docker-Socket. Damit
kann er weder Images bauen noch Container neu starten — das ist Absicht,
denn sonst wäre ein übernommener Admin-Zugang gleichbedeutend mit dem
ganzen Server. Ändert ein Update etwas an

```
docker/  docker-compose.yaml  docker-compose.npm.yaml  install.sh
```

setzt `check()` das Kennzeichen `update_needs_shell`, und die Oberfläche
verlangt stattdessen `sudo ./install.sh` auf dem Server. Die Liste steht
in `Updater::SHELL_PATHS`.

`git` läuft mit `-c safe.directory=<root>`, weil der Ordner root gehört
und git sonst „dubious ownership" meldet.

Nur vorspulen, nie `reset --hard`: liegen im Ordner eigene Änderungen,
schlägt das Update mit einer Meldung fehl statt sie zu überschreiben.

---

## Betrieb und Entwicklung

```bash
docker compose logs -f web worker     # Logs mitlesen
docker compose ps                     # Was läuft
docker compose restart worker         # nach Änderungen an Plugin-Hooks
docker compose exec web bash          # in den Container
```

Änderungen am PHP-Code wirken sofort — der Code ist in die Container
gemountet. Nur der `worker` lädt Plugins einmal beim Start und braucht
deshalb einen Neustart.

Es gibt bewusst kein Composer und keinen Build-Schritt: ein kleiner
PSR-4-Autoloader in `core/Support/Autoloader.php` reicht, damit sich das
Projekt klonen und starten lässt.

### Datenbank

```bash
docker compose exec -T db psql -U overlays overlays
docker compose exec -T db pg_dump -U overlays overlays > backup.sql
```

Das Schema legt die Anwendung selbst an: `core/install.php` für den Kern,
`plugins/<slug>/install.php` je Plugin.
