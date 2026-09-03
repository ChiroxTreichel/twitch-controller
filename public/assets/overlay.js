/**
 * ===================================================================
 *  Overlay - Leitung und Buehne
 * ===================================================================
 *
 * Diese Datei macht drei Dinge und sonst nichts:
 *
 *   1. sie skaliert die Buehne auf das Fenster
 *   2. sie haelt die SSE-Verbindung offen
 *   3. sie verteilt eintreffende Nachrichten an die Plaetze
 *
 * Was in einem Platz zu sehen ist, bringt das jeweilige Plugin mit.
 * Es meldet sich so an:
 *
 *   Overlay.on('goals', function (data) {
 *       Overlay.slot('goals').textContent = data.text;
 *   });
 *
 * Fuer alles, was hintereinander ablaufen muss - Alerts mit Video und
 * Ton - gibt es die Warteschlange. Der Handler bekommt ein "fertig"
 * und die naechste Nachricht wartet, bis er es aufruft:
 *
 *   Overlay.queue('alerts', function (data, fertig) {
 *       spieleVideo(data.video, fertig);
 *   });
 */
(function () {
    'use strict';

    var koerper = document.body;
    var buehne = document.getElementById('stage');

    var breite = parseInt(koerper.dataset.overlayWidth, 10) || 1920;
    var hoehe = parseInt(koerper.dataset.overlayHeight, 10) || 1080;
    var quelle = koerper.dataset.overlayStream || '';
    var start = parseInt(koerper.dataset.overlayStart, 10) || 0;

    var hoerer = {};      // Platz -> Liste von Funktionen
    var schlangen = {};   // Platz -> { handler, wartend: [], laeuft: bool }
    var verbindung = null;

    // ---------------------------------------------------------------
    //  Buehne auf das Fenster bringen
    // ---------------------------------------------------------------
    // Die Buehne ist immer so gross wie eingestellt (z.B. 1920x1080)
    // und wird skaliert. So sieht ein Platz gleich aus, egal wie gross
    // die Quelle in OBS gezogen wurde - Plugins koennen also in
    // festen Pixeln rechnen.
    function skalieren() {
        var faktor = Math.min(
            window.innerWidth / breite,
            window.innerHeight / hoehe
        );

        buehne.style.transform = 'scale(' + faktor + ')';

        // Uebriggebliebenen Rand ausgleichen, damit die Buehne mittig
        // sitzt und nicht oben links klebt.
        buehne.style.marginLeft = Math.max(0, (window.innerWidth - breite * faktor) / 2) + 'px';
        buehne.style.marginTop = Math.max(0, (window.innerHeight - hoehe * faktor) / 2) + 'px';
    }

    window.addEventListener('resize', skalieren);
    skalieren();

    // ---------------------------------------------------------------
    //  Zustand anzeigen
    // ---------------------------------------------------------------
    function zustand(wert, text) {
        document.documentElement.dataset.connection = wert;

        var anzeige = document.getElementById('ov-state');
        if (anzeige) {
            anzeige.textContent = text;
        }
    }

    // ---------------------------------------------------------------
    //  Nachrichten verteilen
    // ---------------------------------------------------------------
    function verteilen(platz, daten) {
        (hoerer[platz] || []).forEach(function (fn) {
            // Ein Fehler in einem Plugin darf die Leitung nicht
            // mitnehmen - sonst steht das ganze Overlay still, weil
            // eines von fuenf Plugins sich verschluckt hat.
            try {
                fn(daten);
            } catch (fehler) {
                console.error('[overlay] Hoerer fuer "' + platz + '" ist gescheitert:', fehler);
            }
        });

        var schlange = schlangen[platz];
        if (schlange) {
            schlange.wartend.push(daten);
            weiter(platz);
        }

        // Testnachrichten zeigt das Overlay selbst. Damit ist "Test
        // senden" auch dann zu sehen, wenn fuer diesen Platz noch kein
        // Plugin etwas anzeigt.
        if (daten && daten.test) {
            zeigeTest(platz, daten);
        }
    }

    function weiter(platz) {
        var schlange = schlangen[platz];
        if (!schlange || schlange.laeuft || schlange.wartend.length === 0) {
            return;
        }

        schlange.laeuft = true;
        var naechste = schlange.wartend.shift();
        var schonFertig = false;

        var fertig = function () {
            if (schonFertig) {
                return;
            }
            schonFertig = true;
            schlange.laeuft = false;
            weiter(platz);
        };

        try {
            schlange.handler(naechste, fertig);
        } catch (fehler) {
            console.error('[overlay] Warteschlange "' + platz + '" ist gescheitert:', fehler);
            fertig();
        }
    }

    function zeigeTest(platz, daten) {
        var kasten = Overlay.slot(platz);
        if (!kasten) {
            return;
        }

        var hinweis = document.createElement('div');
        hinweis.style.cssText = 'font:600 20px/1.4 system-ui,sans-serif;color:#fff;'
            + 'background:rgba(124,58,237,.92);padding:12px 18px;border-radius:10px;'
            + 'box-shadow:0 6px 24px rgba(0,0,0,.35);white-space:nowrap;';
        hinweis.textContent = (daten.message || 'Test') + ' - ' + platz;

        kasten.appendChild(hinweis);
        window.setTimeout(function () {
            hinweis.remove();
        }, 4000);
    }

    // ---------------------------------------------------------------
    //  Verbinden
    // ---------------------------------------------------------------
    function verbinden() {
        if (!quelle || typeof window.EventSource !== 'function') {
            zustand('closed', 'kein EventSource');
            return;
        }

        zustand('opening', 'verbinde …');

        // since nur beim ersten Mal: danach kennt der Browser die
        // letzte Nummer selbst und schickt sie als Last-Event-ID.
        var adresse = quelle + (start > 0 ? '?since=' + encodeURIComponent(start) : '');
        verbindung = new EventSource(adresse);

        verbindung.onopen = function () {
            zustand('open', 'verbunden');
        };

        verbindung.onerror = function () {
            // Die Antwort endet planmaessig nach knapp einer Minute,
            // damit kein PHP-Prozess dauerhaft belegt bleibt. Der
            // Browser verbindet dann von selbst neu - das hier ist
            // also der Normalfall, kein Ausfall.
            zustand('closed', 'neu verbinden …');
        };

        // Fuer jeden bekannten Platz einen Hoerer anmelden. Die
        // Nachricht kommt als Ereignis mit dem Namen des Platzes.
        Array.prototype.forEach.call(
            document.querySelectorAll('.ov-slot'),
            function (kasten) {
                var platz = kasten.dataset.slot;

                verbindung.addEventListener(platz, function (ereignis) {
                    var daten = {};
                    try {
                        daten = JSON.parse(ereignis.data);
                    } catch (fehler) {
                        console.error('[overlay] Nachricht fuer "' + platz + '" ist kein JSON:', ereignis.data);
                        return;
                    }

                    verteilen(platz, daten);
                });
            }
        );
    }

    // ---------------------------------------------------------------
    //  Was Plugins benutzen
    // ---------------------------------------------------------------
    var Overlay = {
        /** Der Kasten eines Platzes, oder null. */
        slot: function (platz) {
            return document.getElementById('ov-slot-' + platz);
        },

        /** Auf Nachrichten eines Platzes hoeren. */
        on: function (platz, fn) {
            if (typeof fn !== 'function') {
                return;
            }
            (hoerer[platz] = hoerer[platz] || []).push(fn);
        },

        /**
         * Nachrichten eines Platzes hintereinander abarbeiten. Der
         * Handler bekommt (daten, fertig) und ruft fertig(), wenn er
         * durch ist. Nur ein Handler je Platz.
         */
        queue: function (platz, handler) {
            if (typeof handler !== 'function') {
                return;
            }
            schlangen[platz] = { handler: handler, wartend: [], laeuft: false };
        },

        /** Groesse der Buehne, fuer Plugins die rechnen muessen. */
        size: function () {
            return { width: breite, height: hoehe };
        }
    };

    window.Overlay = Overlay;

    // Erst verbinden, wenn die Plugin-Dateien geladen sind und ihre
    // Hoerer angemeldet haben. Sonst kaeme die erste Nachricht an,
    // bevor jemand zuhoert.
    window.addEventListener('load', verbinden);
}());
