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
  src/             Klassen unter TwitchController\Plugin\<Slug>\  (optional)
```

Das **Beispiel-Plugin** ist ein vollständig kommentiertes, lauffähiges
Plugin und die ausführbare Fassung dieser Dokumentation. Es liegt nicht
in diesem Repository, sondern im Plugin-Repository — genau wie jedes
andere Plugin:

```
github.com/ChiroxTreichel/twitch-controller-plugins
```

Zum Anschauen entweder im Marktplatz unter *Konto → Plugins → Plugins
finden* installieren, oder das Repository auschecken und den Ordner
`example/src/` als Vorlage kopieren.

### Trennung der Repositories

| Repository | Inhalt |
| --- | --- |
| `twitch-controller` | der Kern. `plugins/` ist darin **nicht** verfolgt |
| `twitch-controller-plugins` | die Plugins, ausgeliefert über `plugins.talutah.de` |

Der Grund: ein Plugin bekommt so einen eigenen Lebenszyklus. Es lässt
sich veröffentlichen, ohne den Kern anzufassen, und der Selbst-Update-Pull
des Kerns (`git merge --ff-only`) kann nicht an installierten Plugins
scheitern.

Aufbau eines Plugins im Plugin-Repository — ein Ordner je Slug, darin
`src/` mit dem Quellcode, daneben `plugin.json`, `README.md` (die
Beschreibungsseite im Marktplatz) und das gebaute `<slug>.zip`. Gepackt
wird mit `php bin/pack.php <slug>`; Einzelheiten stehen in der README
dieses Repositories.

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
| `$app` | `TwitchController\Core\App` |
| `$plugin` | `TwitchController\Core\Plugin\Manifest` |
| `$hooks` | `TwitchController\Core\Hook\Hooks` |
| `$router` | `TwitchController\Core\Http\Router` |
| `$settings` | `TwitchController\Core\Config\Settings` |
| `$db` | `TwitchController\Core\Database\Db` |

In Vorlagen zusaetzlich: `$e` (Escaping), `$url`, `$asset`, `$app`, `$view`,
`$language`.

Global verfuegbar, ohne `$app` durchzureichen:

```php
translate('nav.users')                        // Text aus der Sprachdatei
permission('Konto.Benutzer.Manage')           // darf der Angemeldete das?
```

`permission()` ist die Anzeige-Frage, nicht der Schutz: eine Route
schuetzt ihr `permission` im Router, eine POST-Aktion prueft zusaetzlich
selbst. Wer nur den Knopf ausblendet, hat die Aktion nicht abgesichert.

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
| `core.events.labels` | filter | Klarnamen für eigene Event-Typen |
| `core.obs.present` | filter | eigene Ereignisse im Feed darstellen (null blendet aus) |
| `core.obs.badges` | filter | eigene Badges samt Standardfarben anmelden |
| `core.obs.filters` | filter | eigene Filterknoten im Feed, auch in vorhandene Zweige |
| `overlay.slots` | filter | eigenen Platz in der Overlay-Flaeche anmelden |
| `admin.assets` | filter | eigenes CSS und JavaScript in die Verwaltungsseiten |
| `overlay.assets` | filter | eigenes CSS und JavaScript in die Overlay-Flaeche |
| `core.twitch.scope_labels` | filter | Klartext für eigene Twitch-Berechtigungen |
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

### Ein Zuhörer, der abstürzt

Wirft ein Zuhörer eine Exception, wird sie **gemeldet und
übersprungen** — bei `filter()` bleibt der Wert dann unverändert, bei
`dispatch()` laufen die übrigen Zuhörer weiter. Die Meldung nennt Hook,
Plugin und Ursache:

```
Hook "alerts.tabs": Plugin "twitch-alerts" ist gescheitert -
Class "…\Types" not found in …/plugin.php:76
```

Das ist bewusst keine stille Unterdrückung. Ohne diese Isolierung reißt
ein einziges kaputtes Plugin **jede Seite mit, auf der sein Hook
läuft** — und man kommt nicht mehr dorthin, wo man es abschalten
könnte. Der `PluginManager` fängt nur beim *Laden* ab; ein Hook läuft
später, mitten im Request einer beliebigen Seite.

### Klassen eines Plugins

Der Autoloader bildet `TwitchController\Plugin\<Namensraum>\<Klasse>`
auf `plugins/<slug>/src/<Klasse>.php` ab. Ein Slug darf Bindestriche
haben, ein PHP-Namensraum nicht — aus `twitch-alerts` wird also
`TwitchAlerts`. Diese Richtung rechnet der Autoloader zurück und
probiert beide Formen (`twitchalerts`, `twitch-alerts`), weil er nicht
wissen kann, welche gemeint war.

### Bedienelemente des Kerns

Ein Plugin soll für gewöhnliche Bausteine kein eigenes Stylesheet
brauchen — das kann fehlen, und dann sieht die Seite kaputt aus. Im
Kern liegen deshalb:

| Klasse | Zweck |
| --- | --- |
| `.switch` | Kippschalter als Absende-Knopf — wirkt sofort |
| `.switch-field` | Kippschalter als Checkbox — wirkt beim Speichern |
| `.head-row` | Titel links, Bedienelement rechts |
| `.file-field` | Adresse eintippen oder Datei wählen |
| `.case` / `.case-body` | aufklappbarer Block |
| `.tabs` / `.tab` | Reiterleiste |
| `_confirm.php` | Rückfrage als Aufklapp-Kasten (Vorlage) |

Für alles darüber hinaus gibt es `admin.assets`.

### Schnellschalter im Menü

Ein Menüpunkt darf einen Schalter tragen, mit dem sich das Plugin von
**jeder** Seite aus abschalten lässt:

```php
$nav['display'] = [
    'label' => translate('alerts.nav.display'),
    'items' => [[
        'label'      => translate('alerts.name'),
        'href'       => '/display/alerts',
        'permission' => 'Alerts.Global.View',
        'toggle'     => [
            'on'         => Alerts::enabled($app),
            'action'     => '/display/alerts',   // eigene Adresse, POST
            'value'      => 'toggle',            // Wert für "action"
            'permission' => 'Alerts.Global.Toggle',
            'title'      => translate('alerts.toggle_hint'),
        ],
    ]],
];
```

Den CSRF-Wert setzt der Kern. Fehlt das Recht, erscheint kein Schalter
— der Menüpunkt bleibt. Das Ziel muss mit `/` beginnen: der Schalter
steht auf jeder Seite, ein fremdes Ziel wäre ein Formular, das
ungefragt nach draußen schickt.

Die POST-Route sollte danach dorthin zurückführen, wo der Klick kam
(`Referer`, geprüft gegen die eigene Adresse) — sonst wirft der
Schalter einen von der Seite, auf der man gerade war.

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

**Pfade sind englisch.** `/account/settings`, nicht
`/konto/einstellungen`. Das gilt für Segmente ebenso wie für
Query-Parameter (`?notice=`, `?error=`, `?filter=`, `?range=`, `?page=`,
`?compact=`, `?refresh=`, `?welcome=`) und für den Slug eines
Plugins, weil der im Pfad landet. Ein alter Link mit `?zeitraum=7d`
bleibt gültig — der Parameter greift dann nicht mehr, und es gilt
wieder die Voreinstellung. Sichtbare Texte kommen dagegen aus der
Sprachdatei, und die Rechte-Namen bleiben deutsch (`Konto.Plugins.View`) --
sie stehen pro Benutzer in der Datenbank und lassen sich nicht umbenennen,
ohne bestehende Installationen mitzuziehen.

Rechte folgen dem Schema `Bereich.Funktion.Recht`. Rechte auf `.View`
bekommen neu eingeladene Benutzer automatisch. Superadmin umgeht jede
Prüfung.

Das Schema ist nicht nur Konvention: `Auth::permissionTree()` teilt die
Schlüssel auf und baut daraus die Gliederung der Rechteseite — Bereich
als Kasten, Funktion als Zwischentitel, Rechte nebeneinander. Ein
Plugin meldet seine Rechte weiter flach über `permissions.catalog` an
und bekommt die Gliederung geschenkt, solange es sich an das Schema
hält. Klarnamen der letzten Stufe (`View` → «Anzeigen») liefert
`Auth::rightLabels()`; unbekannte Stufen erscheinen unter ihrem
Schlüssel.

Die Rollenvorlagen (`Auth::rolePresets()`) sind **Regeln**, keine
Listen: `Read-Only` ist alles auf `.View`, `Stream-Helfer` zusätzlich
alles außerhalb von `Konto.`, `Editor` alles außer
`Konto.Benutzer.*` und `Konto.Einstellungen.*`. Eine feste Liste wäre
nach dem ersten installierten Plugin unvollständig — ohne dass es
auffällt.

Statische Plugin-Dateien liegen unter `/plugin/<slug>/assets/<pfad>` und
werden vom Kern ausgeliefert (nur bekannte Dateitypen, kein
Verzeichniswechsel).

### Uebersetzungen

Im Code stehen **englische Schluessel**, nie fertige Texte:

```php
translate('account.users.title')
translate('account.activity.kinds', ['count' => $anzahl])
translate('settings.events.hint', ['url' => $callbackUrl])
```

Vorteil gegenueber Texten als Schluessel: eine Umformulierung im
Deutschen macht nicht alle anderen Sprachen ungueltig.

In Vorlagen immer zusammen mit dem Escaping:

```php
<?= $e(translate('common.save')) ?>
```

Platzhalter sind **benannt** (`%{name}`) und werden als Array
uebergeben. Positionelle (`%s`, `%d`) funktionieren auch, sind aber
schlechter: die Wortstellung ist je Sprache anders, und bei mehreren
Werten muss ein Uebersetzer mitzaehlen.

```json
{ "settings.events.hint": "Twitch schickt Events an %{url}. …" }
```

Steckt im Platzhalter eigenes Markup, wird die Ausgabe bewusst **nicht**
escaped - dann aber nur mit selbst gebauten Werten und mit Kommentar:

```php
<?php // Ohne $e: der Platzhalter ist eigenes Markup. ?>
<?= translate('settings.app.redirect', [
    'url' => '<span class="mono">' . $e($redirectUri) . '</span>',
]) ?>
```

Geladen wird zweistufig: zuerst `de.json` als Grundlage, dann die aktive
Sprache darueber. Fehlt ein Schluessel in der Uebersetzung, erscheint
also der deutsche Text und nicht der nackte Schluessel. Fehlt er auch in
`de.json`, kommt der Schluessel selbst - dann sieht man sofort, wo etwas
nachzutragen ist.

| Ort | Inhalt |
| --- | --- |
| `lang/<code>.json` | Kern |
| `plugins/<slug>/lang/<code>.json` | je Plugin, beim Laden ergaenzt |

Ein Plugin darf Kern-Schluessel mitbenutzen (`common.save`,
`nav.settings`) - die Kerndatei ist beim Laden schon da.

Nicht von Hand pflegen, sondern pruefen lassen:

```bash
php bin/lang.php --all                 # Kern und alle Plugins
php bin/lang.php --plugin throne       # nur ein Plugin
php bin/lang.php --all --fix           # fehlende Schluessel leer anlegen
```

Gemeldet wird, was im Code benutzt wird aber in `de.json` fehlt (das
waere ein sichtbarer Schluessel in der Oberflaeche), was in `de.json`
steht aber nirgends benutzt wird, und wie viel je Uebersetzung noch
offen ist. Der Prueflauf liest die PHP-Tokens, ein `translate()` im
Kommentar zaehlt also nicht mit. Schluessel aus einer Variablen kann er
nicht sehen - im Code deshalb immer ausschreiben.

Die Sprache kommt aus der Einstellung `language`, sonst aus `APP_LANG`,
sonst Deutsch. Umgestellt wird sie unter *Konto > Einstellungen*.


Das `lang`-Attribut gehört ebenfalls dazu. `View::render()` legt
`$language` in jede Vorlage — mit Netz, damit die Fehlerseite auch bei
weggebrochener Datenbank noch rendert:

```php
<html lang="<?= $e($language) ?>">
```

In vier Vorlagen stand dort fest `lang="de"`. Das fällt niemandem auf,
weil die Seite trotzdem richtig aussieht — nur Vorleseprogramme und
Übersetzungshilfen lesen weiter Deutsch.

#### Was der Prüflauf sonst noch findet

Die Schlüsselprüfung beantwortet nur eine Frage: steht jeder benutzte
Schlüssel in der Sprachdatei? Sie kann nicht sehen, was **gar nicht
erst** durch `translate()` geht. Genau dort lagen die letzten Lücken:
eine Beschriftungstabelle aus Substantiven (`'Neues Abo'`, `'Sub Ende'`)
fällt keiner Wortsuche auf, und `<html lang="de">` stand fest in vier
Vorlagen. Ohne `--plugin` läuft darum ein zweiter Abschnitt mit, der
aus drei Richtungen fragt:

| Prüfung | Frage |
| --- | --- |
| Vorlagen | deutscher Text ausserhalb der PHP-Blöcke, also direkt als HTML |
| Anzeigestellen | Zeichenkette in `'label' => …`, `->fail(…)`, `Response::text(…)`, `new RuntimeException(…)` — nach der **Stelle** gefragt, nicht nach der Sprache, damit auch ein englischer Text auffällt |
| Zeichenketten | deutsche Wörter in beliebigen Zeichenketten, für Fälle wie `return 'Alles ist bereits aktuell.';`, die keiner Stelle entsprechen |

Der Vorlagenteil liest die PHP-Tokens und nicht die Zeichen: in
`core/views/_confirm.php` steht ein `?>` mitten in einem Kommentar, und
jeder Regex hält danach den Rest der Datei für HTML.


**Kein deutscher Text im Code.** Auch nicht in Ausnahmen: die landen
über `$e->getMessage()` in der Oberfläche. Die Ausnahmen von der
Ausnahme stehen als Liste in `bin/lang.php` und nicht nur hier:

| Datei | warum |
| --- | --- |
| `Config/Env.php` | liest die `.env`, bevor der Übersetzer gebootet ist |
| `Http/View.php` | fehlende Vorlage ist ein Programmierfehler, kein Benutzertext |
| `Plugin/Manifest.php` | der Aufrufer fängt die Ausnahme und loggt sie |
| `Hook/Hooks.php` | `melde()` baut nur eine Logzeile |
| `I18n/Translator.php` | Sprachnamen stehen in ihrer eigenen Sprache — «Türkçe» zu übersetzen wäre genau falsch, die Liste soll der lesen können, der die Oberfläche noch nicht versteht |
| `bin/*`, `plugins/bin/pack.php` | Kommandozeile, dort läuft kein Übersetzer |

Zwei weitere Gruppen bleiben absichtlich, wie sie sind:

- **Rumpf einer Maschinenantwort.** `Response::text('Not Found', 404)`
  liest kein Mensch: Twitch bekommt es beim Webhook, der Browser beim
  Nachladen einer Plugin-Datei. Sie bleiben bei der Schreibweise aus
  dem HTTP-Standard.
- **Was Twitch selbst so schreibt.** Wer im Stream «Tier 1» sagt, sagt
  es auf Deutsch auch so. Beschreibende Wörter im selben Filterbaum
  («Gesendet», «Empfangen») sind dagegen übersetzt.

Ebenso ausgenommen: Argumente von `$app->log()`. Die gehen ins Log des
Containers, nicht zum Benutzer — und dort ist eine feste Sprache
angenehmer als eine wechselnde.

Ein `translate()` in einem `<script>`-Block wäre ein Laufzeitfehler:
dort gibt es die Funktion nicht. Richtig ist
`<?= json_encode(translate('…')) ?>` — PHP setzt den Text ein,
`json_encode` kümmert sich um die Anführungszeichen.

#### Übersetzen heisst nicht umformulieren

Beim Herausziehen eines festen Textes gehört in `de.json` **genau das
Wort, das vorher im Code stand**. Beim ersten Durchgang hatte ich in
`Obs/Badges.php` aus `'Sub'` ein «Abo» und aus `'Gift erhalten'` ein
«Geschenk erhalten» gemacht — der Feed sah danach anders aus als die
Legacy, und der Vergleichstest fiel zu Recht durch. Der Auftrag ist das
Hardcoding, nicht die Formulierung. Wer die Wortwahl ändern will,
ändert `de.json` — dafür ist sie da.

### Statische Dateien

Immer ueber `$asset()` einbinden, nie ueber `$url()`:

```php
<link rel="stylesheet" href="<?= $e($asset('/plugin/throne/assets/throne.css')) ?>">
```

`$asset()` haengt den Aenderungsstempel der Datei an
(`?v=1788423165`). Damit holt der Browser eine geaenderte Datei sofort
und darf sie ansonsten ein Jahr behalten. Ohne das sehen Nutzer nach
einem Update das alte Aussehen, bis sie von Hand neu laden - und melden
das dann als Fehler.

Es funktioniert fuer `/assets/…` (aus `public/`) und
`/plugin/<slug>/assets/…` (aus dem Plugin-Ordner). Fehlt die Datei,
kommt die Adresse ohne Stempel zurueck - kein Fehler.

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

## Einstellungen

Drei Reiter, weil die Bereiche ganz verschiedene Lebensdauern haben:

| Reiter | Adresse | Inhalt |
| --- | --- | --- |
| **System** | `/account/settings` | Fassung und Update, Zeitzone, Sprache, Reihenfolge im Menü |
| **Kanal** | `/account/settings/channel` | verbundener Kanal, Twitch-Rechte, Event-Abos |
| **Secrets** | `/account/settings/secrets` | Client-ID, Client-Secret, Webhook-Secret |

Eine POST-Route für alle drei: die Aktionen unterscheiden sich im Feld
`action`, nicht in der Adresse. Die Daten holt `settingsData()` einmal
für alle Reiter — sie sind eine Aufteilung der *Anzeige*, nicht drei
verschiedene Seiten. Wer hier trennt, hat dreimal dieselbe Abfrage und
beim nächsten Feld drei Stellen zu pflegen.

### Reihenfolge im Menü

Unter *System*, mit Auf-und-ab-Knöpfen — kein Ziehen: das geht ohne
JavaScript, ist mit der Tastatur bedienbar und auf einem Telefon
zuverlässiger.

Gespeichert wird in `nav_order` als Liste von Bereichs-Schlüsseln.
Verschoben wird auf der Liste der Bereiche, die es **gerade** gibt —
sonst wandert ein Bereich an einem Schlüssel vorbei, der zu einem
entfernten Plugin gehört, und scheinbar passiert nichts. Schlüssel
entfernter Plugins bleiben hinten stehen: wird das Plugin neu
installiert, landet es wieder an seinem Platz.

Ein Bereich, der nicht in der Liste steht, sortiert sich nach seinem
`order` aus dem Plugin und landet hinten. Ein neues Plugin stört die
eingestellte Reihenfolge damit nicht.

---

## Overlay

Die Fläche, die in OBS läuft — eine Kernfähigkeit, kein Plugin. Zwei
Seiten, wie beim Aktivitäten-Feed:

| Adresse | Zweck |
| --- | --- |
| `/overlay` | die Fläche selbst, als Browserquelle in OBS |
| `/overlay/stream` | die Leitung dorthin (Server-Sent Events) |
| `/account/overlay` | Größe, Verbindungsanzeige, Liste der Plätze |

Das Overlay zeigt **nichts** von sich aus. Es stellt Plätze bereit, in
die Plugins zeichnen, und die Leitung, über die Nachrichten dorthin
kommen. Einen Platz namens `system` bringt der Kern mit — darin
erscheint *Test senden*, damit die Fläche auch ohne ein einziges Plugin
prüfbar ist.

### Warum SSE und nicht WebSocket

Server-Sent Events sind gewöhnliches HTTP. Damit laufen sie durch jeden
Reverse Proxy ohne eigene Einstellung, brauchen keinen zweiten Port und
keinen Dauerprozess. Den Wiederaufbau der Verbindung macht der Browser
selbst, und über `Last-Event-ID` holt er dabei das Verpasste nach.

Die Antwort begrenzt sich auf 50 Sekunden und endet dann von selbst —
jede offene Antwort belegt so lange einen PHP-Prozess. Der Browser
verbindet danach neu; verloren geht nichts, weil die Nachricht in der
Tabelle steht.

### Der Weg einer Nachricht

Ein Twitch-Event kommt in einem Webhook-Request an, die Browserquelle
hängt an einem anderen — und PHP hat zwischen zwei Requests kein
gemeinsames Gedächtnis. Dazwischen liegt deshalb die Tabelle
`overlay_messages`:

```
Webhook-Request          Tabelle                offene SSE-Antwort
  Bus::send(…)   ──▶  overlay_messages  ──▶  Bus::since($letzte)  ──▶  Browser
```

`Bus` räumt Zeilen weg, die älter als eine Viertelstunde sind. Lang
genug, dass eine Browserquelle einen Neustart von OBS übersteht — und
lange genug, um nachzusehen, ob ein Alert überhaupt abgeschickt wurde.

Postgres könnte das mit `LISTEN`/`NOTIFY` ohne Nachfragen erledigen. Es
bräuchte aber eine zweite, dauerhaft offene Verbindung, und eine
verpasste Benachrichtigung ist unwiederbringlich.

### Ein Plugin einhängen

Drei Schritte: Platz anmelden, Dateien anmelden, Nachrichten schicken.

```php
// 1. Platz anmelden
$hooks->on('overlay.slots', static function (array $slots): array {
    $slots['alerts'] = [
        'label'    => translate('alerts.name'),
        'position' => 'center',   // siehe Bus::positions()
        'width'    => '900px',    // leer = Vorgabe aus dem CSS
        'z'        => 20,         // kleiner liegt weiter hinten
    ];

    return $slots;
});

// 2. Eigenes CSS und JavaScript anmelden
$hooks->on('overlay.assets', static function (array $assets) use ($app): array {
    $assets['css'][] = $app->asset('/plugin/alerts/assets/alerts.css');
    $assets['js'][]  = $app->asset('/plugin/alerts/assets/alerts.js');

    return $assets;
});

// 3. Nachricht schicken, z.B. aus core.event.stored
$hooks->on('core.event.stored', static function (array $event) use ($app): void {
    if (($event['event_type'] ?? '') !== 'twitch.channel.follow') {
        return;
    }

    (new \TwitchController\Core\Overlay\Bus($app))->send('alerts', [
        'kind' => 'follow',
        'name' => (string) ($event['actor_name'] ?? '?'),
    ]);
});
```

Nur eigene Adressen werden eingebunden (`App::ownUrl()`) — ein Plugin
soll nicht ungefragt Code von einem fremden Server in eine Seite holen,
die unbeaufsichtigt im Stream läuft. Erlaubt sind beide Formen:

```
/plugin/alerts/assets/alerts.js
https://meine-domain/plugin/alerts/assets/alerts.js?v=123
```

Die zweite ist wichtig: `$app->asset()` gibt eine **vollständige**
Adresse zurück. Die Prüfung war einmal „beginnt mit `/`" — damit wurde
jedes Plugin-Stylesheet und -JavaScript stillschweigend verworfen, und
es gab keine Fehlermeldung, an der man das gesehen hätte. Wer hier
etwas ändert: `ownUrl()` benutzen, nicht selbst prüfen.

### Im Browser

`public/assets/overlay.js` skaliert die Bühne, hält die Verbindung und
verteilt die Nachrichten. Plugins benutzen davon vier Dinge:

```js
Overlay.slot('alerts')          // der Kasten des Platzes, oder null
Overlay.size()                  // { width, height } der Bühne
Overlay.on('goals', fn)         // jede Nachricht sofort
Overlay.queue('alerts', fn)     // eine nach der anderen
```

`queue` ist für alles, was nicht gleichzeitig laufen darf — ein Alert
mit Video und Ton. Der Handler bekommt `(daten, fertig)` und die
nächste Nachricht wartet, bis er `fertig()` aufruft:

```js
Overlay.queue('alerts', function (daten, fertig) {
    var video = document.createElement('video');
    video.src = daten.video;
    video.onended = fertig;
    Overlay.slot('alerts').appendChild(video);
    video.play();
});
```

Ein Fehler in einem Hörer wird protokolliert und weggesteckt — sonst
stünde das ganze Overlay still, weil eines von fünf Plugins sich
verschluckt hat.

### Die Bühne rechnet in festen Pixeln

Die Bühne ist immer so groß wie eingestellt (Vorgabe 1920×1080) und wird
auf das Fenster skaliert. Ein Platz sieht damit gleich aus, egal wie
groß die Quelle in OBS gezogen wurde — Plugins können also in festen
Pixeln rechnen.

### Anmeldung

`/overlay` verlangt eine Anmeldung wie `/obs`. Eine Browserquelle kann
sich nicht selbst anmelden: in OBS Rechtsklick auf die Quelle, dann
*Interagieren*, dort einmal einloggen. OBS behält das Cookie in seinem
eigenen Browser-Cache.

Das ist die eine Stelle, an der ein Zugang über einen geheimen Schlüssel
in der URL nachgerüstet werden müsste — die Rechteprüfung der Route in
`core/Routes.php`.

---

## Aktivitaeten-Feed

Zwei Seiten:

| Adresse | Zweck |
| --- | --- |
| `/account/activities` | Einstellungen: Badge-Farben |
| `/obs` | der Feed selbst, gedacht als Browser-Dock in OBS |

Der Feed ist angemeldet-only (`Konto.Aktivitaeten.View`). Das ist kein
Widerspruch zur Nutzung in OBS: ein **eigenes Browser-Dock** teilt die
Cookies mit dem Browser, eine Browserquelle nicht. Als Quelle im Stream
ist der Feed auch nicht gedacht.

Beteiligte Klassen:

| Klasse | Aufgabe |
| --- | --- |
| `core/Obs/Presenter.php` | Event-Zeile -> Badge, Name, Nachricht, Filterschluessel |
| `core/Obs/Badges.php` | Badge-Katalog und Farben |
| `core/Obs/Filters.php` | Filtergruppen und Zeitraeume |
| `core/Obs/Payload.php` | Lesehilfen fuer den Event-Payload (Stufe, Prime, anonym) |
| `core/Obs/FeedController.php` | die Seite und das Nachladen |

Gefiltert wird **nach** dem Aufbereiten, weil sich der Filterschluessel
erst aus dem Payload ergibt (eine Abostufe steht nicht als Spalte in der
Datenbank). Bei aktiver Auswahl liest der Controller deshalb ein
groesseres Fenster und schneidet danach zu.

Nachgeladen wird ueber `/obs/updates?since_id=…`, das nur die neuen
Ereignisse als JSON zurueckgibt; die Seite haengt sie oben an.

Ein Plugin mit eigener Ereignisart braucht drei Hooks:

```php
// 1. Wie es im Feed aussieht
$hooks->on('core.obs.present', function (?array $view, array $row, array $payload) {
    if (($row['event_type'] ?? '') !== 'paypal.send_money') {
        return $view;                    // nicht meins - unveraendert weiter
    }
    return [
        'badge'  => 'EUR ' . number_format((float) $row['amount'], 2, ',', '.'),
        'style'  => 'paypal',            // Schluessel aus core.obs.badges
        'title'  => $row['actor_name'] ?? 'Anonym',
        'filter' => 'paypal.named',      // Schluessel aus core.obs.filters
    ];
});

// 2. Welche Farbe das Badge hat
$hooks->on('core.obs.badges', function (array $badges) {
    $badges['paypal'] = ['label' => 'PayPal', 'bg' => '#0070ba', 'text' => '#ffffff'];
    return $badges;
});

// 3. Wo es im Filter auftaucht
$hooks->on('core.obs.filters', function (array $nodes) {
    $nodes[] = ['key' => 'paypal', 'label' => 'PayPal', 'order' => 35];
    $nodes[] = ['key' => 'paypal.named',     'label' => 'Mit Twitch-Name', 'parent' => 'paypal'];
    $nodes[] = ['key' => 'paypal.anonymous', 'label' => 'Anonym',          'parent' => 'paypal'];
    return $nodes;
});
```

### Der Filterbaum

Knoten werden **flach** angemeldet, mit Verweis auf den Elternknoten. Der
Kern baut daraus den Baum. Damit kann ein Plugin auch in einen
vorhandenen Zweig einhaengen und nicht nur oben etwas anfuegen - das
Follow-Plugin haengt seine Unfollows unter `follows`, ohne dass der Kern
davon wissen muss:

```php
$nodes[] = ['key' => 'follows.unfollow', 'label' => 'Unfollow',
            'parent' => 'follows', 'order' => 20];
```

Ob ein Knoten **Blatt** ist, ergibt sich daraus, ob ihn jemand als
Elternknoten nennt. Ein Plugin kann so aus `bits` nachtraeglich eine
Gruppe machen. Nur Blaetter kommen in die Adresse; Elternknoten sind
Bedienhilfe und schalten beim Anklicken alle Blaetter darunter.

Zwei Zustaende, die man nicht verwechseln darf:

| Adresse | Bedeutung |
| --- | --- |
| `/obs` | kein Filter - alles zeigen |
| `/obs?filter=bits,raids` | nur diese Blaetter |
| `/obs?filter=` | alles abgewaehlt - nichts zeigen |

Deshalb prueft `Filters::selected()` mit `array_key_exists` und nicht auf
"leer". Ist alles angehakt, laesst die Oberflaeche den Parameter weg -
dann bleibt ein gespeicherter Link auch gueltig, wenn spaeter neue
Ereignisarten dazukommen.

Ein Elternknoten in der Adresse wird zu seinen Blaettern aufgeloest,
`?filter=subs` funktioniert also von Hand geschrieben.

Die Schluessel sind bewusst dieselben wie im alten `obs.php`
(`subs.tiered.tier1`, `system.stream`, ...), damit gespeicherte
Feed-Links weiter funktionieren.

`core.obs.present` darf `null` zurueckgeben - dann erscheint das
Ereignis nicht im Feed. So blendet man Zwischenmeldungen aus, etwa den
Fortschritt eines Hype-Trains.

### Zeitzone

Zeitstempel aus Postgres kommen mit Offset, und `format()` uebernimmt
diesen Offset - `date_default_timezone_set()` allein bringt also nichts.
Deshalb laufen alle Anzeigen ueber `core/Support/Dates.php`, das
ausdruecklich in die eingestellte Zone umrechnet. Wer neue Datumsanzeigen
baut, nimmt bitte diese Klasse und nicht `substr()` auf den Rohwert.

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

Der Katalog wird bei **jedem** Aufruf des Reiters live geholt, und
gesucht wird auf dem Katalogserver (`?search=`) - er kennt seinen
Bestand. Zwischengespeichert wird nur die letzte vollständige Antwort in
der Einstellung `registry_cache`, als Rückfall für die Liste der
installierten Plugins: die wird bei jedem Seitenaufruf gebraucht und darf
nicht auf einen fremden Server warten. Ein Suchergebnis landet dort
bewusst nicht - es ist ausschnittsweise.

Beim Installieren prüft `Installer` in dieser Reihenfolge: gleicher Host
wie der Katalog, Größenbegrenzungen, `sha256`, optionale Signatur, Pfade
im Archiv, Vorhandensein von `plugin.json` und `plugin.php`, und ob der
Slug im Paket der angeforderte ist. Erst danach werden die Dateien aus
einem Nebenordner an ihren Platz geschoben; scheitert etwas, kommt der
alte Stand zurück.

Weil der Webserver als `www-data` läuft, muss `plugins/` ihm gehören -
`install.sh` setzt das. Ist es nicht beschreibbar, sagt der Reiter das und
bietet nichts zum Installieren an.

### Aktualisieren

Zwei Arten, und die Liste unterscheidet sie:

| | wann | was passiert |
| --- | --- | --- |
| **Katalog** | im Katalog liegt eine neuere Fassung | Dateien holen, danach `install.php` nachziehen |
| **Dateien** | die Dateien sind neuer als der Stand in der Datenbank | nur `install.php` nachziehen |

Der zweite Fall tritt nach einem Update von Hand auf — Dateien
hineinkopiert, aber das Schema fehlt noch.

Die Liste holt den Katalog dafür **live**. Vorher las sie nur den
Zwischenspeicher: war der Marktplatz noch nie offen, stand dort nichts,
und ein vorhandenes Update war unsichtbar. Ist der Katalogserver nicht
erreichbar, gibt es einen Hinweis und keine Fehlerseite — er darf nicht
darüber entscheiden, ob man seine Plugins verwalten kann.

**«Alle aktualisieren»** geht dieselben zwei Fälle durch, in
Abhängigkeitsreihenfolge (`resolveOrder()`): ein Plugin, dessen
Voraussetzung noch alt ist, kann bei seinem `install.php` über eine
fehlende Klasse fallen. Ein gescheitertes Plugin hält die übrigen nicht
auf — sonst bliebe nach dem ersten Fehler alles andere alt, und man
müsste erst herausfinden, welches der Übeltäter war.

### Entfernen

**Ein** Knopf, drei Schritte: ausschalten, Daten abräumen, Dateien
löschen. Vorher waren es zwei Knöpfe — erst «Entfernen» für die Daten,
dann «Dateien löschen» — und das war zweimal Klicken für eine Absicht.

Die Reihenfolge ist zwingend: `PluginManager::uninstall()` führt die
`uninstall.php` des Plugins aus, die muss also noch auf der Platte
liegen. Erst danach `Installer::remove()`.

Verweigert wird das Entfernen, wenn ein **installiertes** Plugin dieses
voraussetzt (`installedDependents()`). Nicht nur ein aktives: wäre die
Voraussetzung weg, ließe sich der Abhängige nie wieder einschalten — und
der Grund dafür wäre dann nicht mehr zu sehen.

Scheitert das Löschen der Dateien, sind die Daten schon weg. Das steht
dann auch in der Meldung: sonst versucht es jemand noch einmal und
wundert sich, dass das Plugin leer wiederkommt.

### Die Gegenseite

Der Katalogserver liegt nicht in diesem Repository. Verlangt werden drei
Endpunkte:

| Adresse | Antwort |
| --- | --- |
| `/index.php` | der Katalog als JSON, `?search=` filtert |
| `/download.php?name=<slug>` | das ZIP |
| `/readme.php?name=<slug>` | die Beschreibung als Markdown |

Ein Katalogeintrag:

```json
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
```

Pflicht sind `slug`, `version` und `download` - fehlt eines, verwirft
der Client den Eintrag. `sha256` braucht er zum Installieren. `summary`
darf fehlen; dann nimmt der Client den ersten Satz aus `description`.

Zwei Formen sind erlaubt: das Objekt mit `format` und `plugins`, oder
eine nackte Liste. Eine leere Liste ist ein gültiger Katalog, kein
Fehler.

Eigene Einstellungen eines Plugins - etwa PayPal-Zugangsdaten - werden
über den Hook `plugin.settings` in der Plugin-Liste verlinkt:

```php
$hooks->on('plugin.settings', function (array $pages) use ($plugin) {
    $pages[$plugin->slug] = [
        'label' => 'PayPal einrichten',
        'href'  => '/donations/settings',
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

## Notausgang

`/rescue` -- Update pruefen, Update einspielen, Sprache umstellen. Ohne
Navigation, ohne Plugins, ohne Twitch-Abfragen, mit dem `plain`-Layout.

Der Grund fuer die Seite: beides lag ausschliesslich auf
`/account/settings`. Reisst dort eine Zeile die Seite -- eine
Uebersetzung, deren Platzhalter nicht zum Aufruf passen, genuegt --, ist
genau der Knopf unerreichbar, der den Fehler behebt, und es bleibt nur
die Kommandozeile. Die Fehlerseite verlinkt `/rescue` deshalb, sobald
jemand angemeldet ist.

Wer hier etwas ergaenzt, haelt die Seite arm: kein Hook-Aufruf, keine
Netzanfrage, nichts, was eine Datenbanktabelle voraussetzt, die eine
Migration erst noch anlegen muss.

---

## Namen

Das Projekt hiess einmal "Overlays". Was dabei umbenannt wurde und was
absichtlich nicht:

| | jetzt | vorher |
| --- | --- | --- |
| Namensraum | `TwitchController\Core\…` | `Overlays\Core\…` |
| Plugin-Namensraum | `TwitchController\Plugin\<Slug>\…` | `Overlays\Plugin\…` |
| Compose-Projekt | `twitch-controller` | `overlays` |
| Images | `twitch-controller-php`, `twitch-controller-postgres` | `overlays-php`, `overlays-postgres` |
| Anzeigename | `App::NAME` | fest in vier Vorlagen |

**Absichtlich unveraendert:**

- Der **Netz-Alias `overlays`** in `docker-compose.npm.yaml`. Darauf
  zeigt der Proxy-Host in Nginx Proxy Manager; ein neuer Alias hiesse,
  dort ein Feld nachzutragen, und bis dahin waere die Seite offline.
- **`DB_NAME` und `DB_USER`** heissen weiter `overlays`. Sie heissen so
  in bestehenden Datenbanken, und ein Umbenennen waere keine
  Umbenennung, sondern eine Datenmigration.

### Was das fuer Plugins bedeutet

Der Namensraum ist Teil des Plugin-Vertrags: ein Plugin mit
`use Overlays\Core\Http\Response;` laedt nicht mehr. Deshalb steht die
Kernversion auf **2.0.0**, und Plugins fordern `"core": ">=2.0.0"`.

Ein Plugin mit dem alten Namensraum reisst nichts mit: `PluginManager`
faengt pro Plugin ab, protokolliert und ueberspringt es. Die
Plugin-Verwaltung bleibt bedienbar, und der Marktplatz bietet das
Update an.

### Beim Aktualisieren

`docker-compose.yaml` hat sich geaendert, also verlangt das Update die
Konsole (`SHELL_PATHS` im `Updater`). `install.sh` merkt, dass noch
Container des alten Projekts `overlays` laufen, und raeumt sie ab -
sonst halten die Port 80 und den Netz-Alias fest. Daten liegen in
`./pgdata` und `./public/uploads` und bleiben unberuehrt.

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
