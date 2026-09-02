# Overlays

Stream-Werkzeug für Twitch: ein schlanker Kern, alles Weitere als Plugin.
Jeder hostet seine eigene Installation.

## Installation

Voraussetzungen: ein Server mit Docker, eine Domain, deren A-Record darauf zeigt.

```bash
git clone <repo> overlays && cd overlays
cp .env.example .env
```

In der `.env` müssen fünf Dinge stehen — mehr nicht:

| Wert | Bedeutung |
| --- | --- |
| `APP_DOMAIN` | die Domain, z.B. `overlays.example.com` |
| `APP_URL` | `https://` plus diese Domain |
| `APP_KEY` | `openssl rand -hex 32` |
| `DB_PASS` | `openssl rand -hex 24` |
| `ACME_EMAIL` | für Let's-Encrypt-Hinweise (darf leer bleiben) |

Dann:

```bash
docker compose up -d
```

Danach `https://<deine-domain>` im Browser aufrufen. Der Rest — Twitch-App,
Kanal, Event-Abos — läuft über den Einrichtungsassistenten.

### Eigener Reverse Proxy statt Caddy

Wer schon einen Proxy betreibt (z.B. Nginx Proxy Manager), schaltet den
mitgelieferten Caddy in der `.env` ab:

```
COMPOSE_PROFILES=
COMPOSE_FILE=docker-compose.yaml:docker-compose.npm.yaml
```

Einmalig `docker network create proxy`, dann im Proxy auf Host `overlays`,
Port 80 zeigen.

## Aufbau

```
core/        Kern: Login, Benutzer, Aktivitäten, Plugin-Verwaltung, Twitch
plugins/     Funktionserweiterungen, je ein Ordner
public/      DocumentRoot, enthält nur den Front-Controller und Assets
bin/         Hintergrundprozess
docker/      Images und Serverkonfiguration
legacy/      alter Code als Nachschlagewerk (nicht im Repository)
```

Der Kern kennt bewusst nur vier Seiten: **Benutzer**, **Aktivitäten**,
**Plugins**, **Einstellungen**. Twitch ist Teil des Kerns, weil ohne
Twitch-Login niemand hineinkommt. Alles andere — Overlay, Alerts, Ziele,
Spenden, Throne — ist Plugin.

### Container

| Service | Aufgabe |
| --- | --- |
| `web` | Apache mit PHP, beantwortet alle Requests |
| `worker` | ruft im Takt den Hook `cron.tick` auf |
| `db` | Postgres, kein Host-Port |
| `caddy` | TLS und Frontdoor, abschaltbar |

## Plugins schreiben

Ein Plugin ist ein Ordner unter `plugins/` mit diesen Dateien:

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

`plugins/beispiel/` ist ein vollständig kommentiertes Beispiel und kann
gelöscht werden.

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
mehr, wenn das andere aktiv ist. Der Schlüssel `core` meint die Kernversion.

Bedingungen: `*`, `1.2.3`, `>=1.2.3`, `<2.0.0`, `^1.2.3`, `~1.2.3` und
Kombinationen mit Leerzeichen (`>=1.0.0 <2.0.0`).

### Hooks

`dispatch` meldet ein Ereignis, `filter` reicht einen Wert durch alle
Zuhörer und gibt das Ergebnis zurück. Kleinere Priorität läuft früher.

| Hook | Art | Zweck |
| --- | --- | --- |
| `admin.nav` | filter | Menüpunkte anhängen |
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
| `plugin.activated` / `.deactivated` / `.installed` / `.uninstalled` / `.upgraded` | dispatch | Lebenszyklus |
| `plugins.booted` | dispatch | alle Plugins geladen |
| `user.login` / `.created` / `.removed` / `.permissions_changed` | dispatch | Benutzerverwaltung |

### Einstellungen und Daten

Jedes Plugin hat einen eigenen Einstellungs-Scope:

```php
$scope = Settings::pluginScope($plugin->slug);   // "plugin:throne"
$app->settings->set('ziel', 250, $scope);
$app->settings->setSecret('api_key', $key, $scope);   // verschlüsselt
```

Der Scope wird beim Entfernen des Plugins mitgelöscht. Für echte Tabellen
`install.php` benutzen und die Namen mit dem Slug präfixen.

### Routen

```php
$router->get('/throne', $handler, [
    'auth' => true,
    'permission' => 'Throne.Seite.View',
]);
```

Muster kennen `{name}` für ein Segment und `{name*}` für den Rest des
Pfades. Statische Plugin-Dateien liegen unter
`/plugin/<slug>/assets/<pfad>`.

## Twitch

Es gibt genau **eine** Redirect-URI für alles: `https://<domain>/auth/callback`.
Der Zweck des Logins steckt im signierten `state`-Parameter, deshalb muss in
der Twitch-Konsole nur diese eine Adresse eingetragen werden.

EventSub-Events kommen auf `https://<domain>/hooks/twitch` an und werden per
HMAC gegen das Webhook-Secret geprüft. Welche Abos gebraucht werden, ergibt
sich aus Kern plus aktiven Plugins — nach jedem Aktivieren einmal
*Einstellungen → Abos abgleichen*.

## Sicherheit

- Geheimnisse in der Datenbank sind mit `APP_KEY` verschlüsselt (libsodium).
  Ein Datenbank-Dump allein reicht nicht, um sie zu lesen.
- Ändert sich `APP_KEY`, sind alle Geheimnisse unlesbar und müssen neu
  eingegeben werden. Der Schlüssel gehört ins Backup.
- Im Session-Cookie steht ein Zufallstoken, in der Datenbank nur sein Hash.
- Postgres hat keinen Host-Port. `web` lauscht standardmäßig nur auf
  `127.0.0.1`.
- Der erste Twitch-Login wird Kanalinhaber. Danach ist die Einrichtung für
  alle anderen gesperrt, und neue Benutzer brauchen einen Einladungslink.

## Entwicklung

```bash
docker compose logs -f web worker     # Logs
docker compose exec web php -v        # in den Container
docker compose restart worker         # nach Änderungen an Plugin-Hooks
```

Änderungen am PHP-Code wirken sofort — der Code ist ins Image gemountet, es
wird nichts kompiliert. Nur der `worker` lädt Plugins einmal beim Start und
braucht deshalb einen Neustart.
