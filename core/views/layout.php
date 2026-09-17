<?php
/**
 * Rahmen der Adminoberflaeche.
 *
 * @var \TwitchController\Core\App $app
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var string $content
 * @var string $title
 * @var string $active
 */

// Defensiv: das Layout wird auch bei Fehlerseiten gerendert und darf
// nicht selbst an einer wegbrechenden Datenbank scheitern.
//
// Drei getrennte Absicherungen, nicht eine gemeinsame: sonst wuerde ein
// Fehler beim Kanalnamen auch die Navigation verschwinden lassen.
try {
    $user = $app->auth->user();
} catch (\Throwable) {
    $user = null;
}

try {
    $nav = (new \TwitchController\Core\Admin\Nav($app))->build();
} catch (\Throwable) {
    $nav = [];
}

try {
    $channel = $app->settings->string('twitch_broadcaster_login');
} catch (\Throwable) {
    $channel = '';
}
?>
<!doctype html>
<html lang="<?= $e($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title !== '' ? $title . ' · ' . \TwitchController\Core\App::NAME : \TwitchController\Core\App::NAME) ?></title>
    <link rel="stylesheet" href="<?= $e($asset('/assets/admin.css')) ?>">
    <?php $pluginAssets = $view->adminAssets(); ?>
    <?php foreach ($pluginAssets['css'] as $css): ?>
        <link rel="stylesheet" href="<?= $e($css) ?>">
    <?php endforeach ?>
</head>
<body>
<?php /*
    Der Streifen, der beim Abschicken oben durchlaeuft. Er steht immer
    im Dokument und ist nur unsichtbar - ein Element, das erst
    entsteht, kann nicht ueberblenden. Siehe admin.js.
*/ ?>
<div id="ov-busy"></div>

<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-dot"></span>
            <div>
                <strong><?= $e(\TwitchController\Core\App::NAME) ?></strong>
                <?php if ($channel !== ''): ?>
                    <small><?= $e($channel) ?></small>
                <?php endif; ?>
            </div>
        </div>

        <nav>
            <?php foreach ($nav as $key => $group): ?>
                <?php
                // Die Gruppe mit der aktuellen Seite ist immer offen -
                // man will sehen, wo man steht. Alle anderen behalten
                // den Zustand, den der Benutzer gesetzt hat.
                $enthaeltAktive = false;
                foreach ($group['items'] as $item) {
                    if ($active === $item['key']) {
                        $enthaeltAktive = true;
                    }
                }
                ?>
                <details class="nav-group" data-group="<?= $e((string) $key) ?>"
                    <?= $enthaeltAktive ? 'open data-current' : 'open' ?>>
                    <summary class="nav-label"><?= $e($group['label']) ?></summary>
                    <div class="nav-links">
                        <?php foreach ($group['items'] as $item): ?>
                            <?php if (($item['toggle'] ?? null) === null): ?>
                                <a class="nav-item<?= $active === $item['key'] ? ' is-active' : '' ?>"
                                   href="<?= $e($url($item['href'])) ?>"><?= $e($item['label']) ?></a>
                            <?php else: ?>
                                <?php /*
                                    Menuepunkt mit Schnellschalter: damit
                                    laesst sich ein Plugin von jeder Seite
                                    aus abschalten, ohne erst dorthin zu
                                    navigieren.
                                */ ?>
                                <div class="nav-row">
                                    <a class="nav-item grow<?= $active === $item['key'] ? ' is-active' : '' ?>"
                                       href="<?= $e($url($item['href'])) ?>"><?= $e($item['label']) ?></a>
                                    <form method="post" action="<?= $e($url($item['toggle']['action'])) ?>">
                                        <input type="hidden" name="csrf" value="<?= $e($app->auth->csrfToken()) ?>">
                                        <input type="hidden" name="action" value="<?= $e($item['toggle']['value']) ?>">
                                        <button class="switch switch-small<?= $item['toggle']['on'] ? ' is-on' : '' ?>"
                                                type="submit"
                                                title="<?= $e($item['toggle']['title']) ?>"
                                                aria-label="<?= $e($item['toggle']['title']) ?>">
                                            <span class="switch-track"><span class="switch-knob"></span></span>
                                        </button>
                                    </form>
                                </div>
                            <?php endif ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>

        <?php if ($user !== null): ?>
            <form class="sidebar-foot" method="post" action="<?= $e($url('/logout')) ?>">
                <input type="hidden" name="csrf" value="<?= $e($app->auth->csrfToken()) ?>">
                <div class="who">
                    <strong><?= $e($user['display_name']) ?></strong>
                    <small><?= $e($user['role'] === 'superadmin' ? translate('nav.owner') : translate('nav.team')) ?></small>
                </div>
                <button class="btn btn-ghost" type="submit"><?= $e(translate('nav.sign_out')) ?></button>
            </form>
        <?php endif; ?>
    </aside>

    <main class="content">
        <?php /*
            Fehlende Twitch-Freigaben: oben auf JEDER Seite.

            Fehlt eine, tut ein Teil der Anwendung stillschweigend
            nichts - kein Fehler, keine Meldung, es passiert einfach
            nicht. Der ausfuehrliche Hinweis stand nur unter
            Konto > Einstellungen > Kanal, also genau dort, wo man
            nicht nachsieht, solange man den Zusammenhang nicht kennt.

            Gemeldet wird nur, was eine EINGESCHALTETE Funktion
            braucht - siehe View::missingScopes().

            Nicht auf der Einstellungsseite selbst: dort steht die
            Warnung samt Liste und Knopf, und zweimal dasselbe ist
            schlechter als einmal.
        */ ?>
        <?php if (($missingScopes ?? []) !== [] && ($active ?? '') !== 'account/settings'): ?>
            <div class="note note-warn">
                <strong><?= $e(translate('scopes.missing_banner')) ?></strong>
                <?= $e(translate('scopes.missing_banner_hint', [
                    'count' => (string) count($missingScopes),
                ])) ?>
                <?php if (permission('Account.Settings.View')): ?>
                    <a href="<?= $e($url('/account/settings/channel')) ?>">
                        <?= $e(translate('scopes.missing_banner_link')) ?>
                    </a>
                <?php endif ?>
            </div>
        <?php endif ?>

        <?= $content ?>
    </main>
</div>

<script>
// Zugeklappte Gruppen merken. Die Gruppe der aktuellen Seite bleibt
// offen, damit man nach dem Navigieren nicht ins Leere schaut.
(function () {
    'use strict';

    var schluessel = 'ov_nav_zu';
    var zu = [];

    try {
        zu = JSON.parse(localStorage.getItem(schluessel) || '[]');
    } catch (e) {
        zu = [];
    }

    function anwenden() {
        document.querySelectorAll('.nav-group').forEach(function (gruppe) {
            if (!gruppe.hasAttribute('data-current') && zu.indexOf(gruppe.dataset.group) !== -1) {
                gruppe.open = false;
            }
        });
    }

    /*
     * Ein Zuhoerer am Dokument statt je Gruppe: nach einem Formular
     * ohne Seitenwechsel ist das Menue neu eingesetzt, und Zuhoerer an
     * den alten Elementen waeren mit ihnen weg. toggle steigt nicht
     * auf, darum true als dritter Wert.
     */
    document.addEventListener('toggle', function (ereignis) {
        var gruppe = ereignis.target;

        if (!(gruppe instanceof Element) || !gruppe.matches('.nav-group')) {
            return;
        }

        var name = gruppe.dataset.group;
        var index = zu.indexOf(name);

        if (gruppe.open && index !== -1) {
            zu.splice(index, 1);
        } else if (!gruppe.open && index === -1) {
            zu.push(name);
        }

        try {
            localStorage.setItem(schluessel, JSON.stringify(zu));
        } catch (e) {
            // Kein Speicher verfuegbar - dann eben nur fuer diese Sitzung.
        }
    }, true);

    anwenden();
    document.addEventListener('overlay:swapped', anwenden);
}());
</script>
<script>
// Rueckfragen: Escape, Klick daneben und "Abbrechen" schliessen den
// Kasten. Alles davon ist eine Zugabe - ohne JavaScript schliesst ein
// zweiter Klick auf den Knopf, weil es ein <details> ist.
(function () {
    'use strict';

    function alleZu(ausser) {
        document.querySelectorAll('details.confirm[open]').forEach(function (kasten) {
            if (kasten !== ausser) {
                kasten.open = false;
            }
        });
    }

    document.addEventListener('click', function (ereignis) {
        if (ereignis.target.closest('[data-confirm-cancel]')) {
            var eigener = ereignis.target.closest('details.confirm');
            if (eigener) {
                eigener.open = false;
            }
            return;
        }

        // Immer nur eine Rueckfrage offen - zwei uebereinander sind
        // nicht zu lesen.
        alleZu(ereignis.target.closest('details.confirm'));
    });

    document.addEventListener('keydown', function (ereignis) {
        if (ereignis.key === 'Escape') {
            alleZu(null);
        }
    });

    /*
     * Beim Oeffnen nachsehen, ob der Kasten ins Fenster passt.
     *
     * Ohne das steht er halb neben der Seite, sobald der Knopf weit
     * rechts sitzt - in der Plugin-Liste steht "Entfernen" ganz aussen,
     * und die Frage war nicht mehr zu lesen.
     *
     * Die Vorlage darf das weiterhin selbst festlegen (confirm-right).
     * Gemessen wird nur, wenn sie es NICHT getan hat: eine ausdrueckliche
     * Angabe ist eine Entscheidung, und die soll nicht von einer
     * Fensterbreite ueberstimmt werden.
     *
     * Gemessen wird beim Oeffnen und nicht einmal am Anfang: der Kasten
     * hat zugeklappt keine Groesse, und die Seite kann sich bis dahin
     * verschoben haben.
     */
    function ausrichten(kasten) {
        if (kasten.classList.contains('confirm-right')) {
            return;
        }

        var feld = kasten.querySelector('.confirm-panel');
        if (!feld) {
            return;
        }

        // Erst zuruecksetzen: beim zweiten Oeffnen koennte die
        // Entscheidung von vorhin noch stehen, und das Fenster ist
        // vielleicht ein anderes.
        kasten.classList.remove('confirm-flip');

        var platz = feld.getBoundingClientRect();
        if (platz.right <= window.innerWidth - 8) {
            return;
        }

        kasten.classList.add('confirm-flip');

        // Passt es nach links auch nicht, war die erste Lage die
        // bessere - ein Kasten, der links heraussteht, ist nicht besser
        // als einer, der rechts heraussteht.
        if (feld.getBoundingClientRect().left < 8) {
            kasten.classList.remove('confirm-flip');
        }
    }

    /*
     * <details> kennt kein "wird gleich geoeffnet", nur das toggle
     * DANACH - und genau dann steht der Kasten schon falsch. Sichtbar
     * wird das trotzdem nicht: das Ausrichten passiert im selben
     * Bilddurchlauf, bevor der Browser zeichnet.
     *
     * toggle steigt nicht auf, darum true als dritter Wert: so faengt
     * ein einziger Zuhoerer alle Kaesten, auch spaeter dazugekommene.
     */
    document.addEventListener('toggle', function (ereignis) {
        var kasten = ereignis.target;

        if (kasten instanceof Element && kasten.matches('details.confirm') && kasten.open) {
            ausrichten(kasten);
        }
    }, true);
}());
</script>

<script>
// Dateiauswahl: der eigene Knopf loest den verborgenen
// <input type="file"> aus. Steht im Kern und nicht im Plugin - das
// Feld ist ein allgemeiner Baustein, und ein Plugin-Skript kann
// fehlen. Ohne JavaScript bleibt das Textfeld bedienbar; der Knopf
// ist eine Zugabe.
(function () {
    'use strict';

    document.addEventListener('click', function (ereignis) {
        var knopf = ereignis.target.closest('.file-field-button');
        if (!knopf) {
            return;
        }

        var feld = knopf.closest('.file-field');
        var auswahl = feld ? feld.querySelector('.file-field-native') : null;
        if (auswahl) {
            auswahl.click();
        }
    });

    document.addEventListener('change', function (ereignis) {
        var auswahl = ereignis.target;
        if (!auswahl.classList || !auswahl.classList.contains('file-field-native')) {
            return;
        }

        if (!auswahl.files || auswahl.files.length === 0) {
            return;
        }

        var feld = auswahl.closest('.file-field');
        var text = feld ? feld.querySelector('input[type="text"]') : null;
        if (!text) {
            return;
        }

        hochladen(feld, text, auswahl.files[0]);

        // Dieselbe Datei noch einmal waehlen soll wieder etwas tun.
        // Ohne das bleibt der Wert stehen, und "change" kommt nicht.
        auswahl.value = '';
    });

    /*
     * Die Meldung unter dem Feld.
     *
     * Vom Skript gebaut und nicht in der Vorlage: das Dateifeld steht
     * in einem halben Dutzend Plugin-Vorlagen, und keine davon soll
     * dafuer angefasst werden muessen.
     */
    function meldung(feld, text, schlecht) {
        var zeile = feld.parentNode.querySelector('.file-field-note');

        if (!zeile) {
            zeile = document.createElement('p');
            zeile.className = 'hint file-field-note';
            feld.parentNode.insertBefore(zeile, feld.nextSibling);
        }

        zeile.textContent = text;
        zeile.classList.toggle('file-field-error', !!schlecht);
        zeile.hidden = text === '';
    }

    /*
     * Wirklich hochladen.
     *
     * Vorher schrieb der Knopf nur "/uploads/alerts/" plus den Namen in
     * das Textfeld - hochgeladen wurde nichts. Wer das nicht wusste,
     * trug damit den Pfad zu einer Datei ein, die es auf dem Server nie
     * gab: der Alert lief, und es war nichts zu sehen und nichts zu
     * hoeren.
     *
     * Kann der Browser kein fetch, bleibt es beim alten Verhalten. Der
     * Pfad steht dann wieder nur da - aber wer so einen Browser
     * benutzt, kennt den Weg ueber den Server ohnehin.
     */
    function hochladen(feld, text, datei) {
        if (!window.fetch || !window.FormData) {
            text.value = '<?= $e(\TwitchController\Core\Upload\Media::URL_PREFIX) ?>' + datei.name;
            text.dispatchEvent(new Event('input', { bubbles: true }));

            return;
        }

        var daten = new FormData();
        daten.append('file', datei);
        daten.append('csrf', <?= json_encode($app->auth->csrfToken()) ?>);

        meldung(feld, <?= json_encode(translate('upload.busy')) ?>, false);
        document.documentElement.dataset.busy = '1';

        fetch(<?= json_encode($url('/account/uploads')) ?>, {
            method: 'POST',
            body: daten,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (antwort) {
            return antwort.json().catch(function () {
                return { ok: false, error: antwort.status + '' };
            });
        }).then(function (ergebnis) {
            if (!ergebnis || !ergebnis.ok) {
                meldung(feld, (ergebnis && ergebnis.error) || '', true);

                return;
            }

            // Der Server hat das letzte Wort ueber den Namen: er
            // bereinigt ihn und haengt eine Nummer an, wenn es ihn
            // schon gab. Im Feld steht darum, was WIRKLICH da liegt.
            text.value = ergebnis.path;
            text.dispatchEvent(new Event('input', { bubbles: true }));
            meldung(feld, '', false);
        }).catch(function () {
            meldung(feld, <?= json_encode(translate('upload.error.server')) ?>, true);
        }).then(function () {
            document.documentElement.dataset.busy = '0';
        });
    }
}());
</script>

<?php /*
    Formulare ohne Seitenwechsel. Vor den Plugin-Skripten, damit die
    sich auf "overlay:swapped" verlassen koennen - siehe
    docs/entwicklung.md.
*/ ?>
<script src="<?= $e($asset('/assets/admin.js')) ?>"></script>

<?php foreach ($pluginAssets['js'] as $js): ?>
    <script src="<?= $e($js) ?>"></script>
<?php endforeach ?>
</body>
</html>
