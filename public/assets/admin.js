/*
 * ===================================================================
 *  Formulare ohne Seitenwechsel
 * ===================================================================
 *
 * Jedes Formular im Verwaltungsbereich ging bisher denselben Weg:
 * abschicken, der Server antwortet mit einer Weiterleitung, der
 * Browser laedt die ganze Seite neu. Richtig, aber bei einem
 * Schnellschalter im Menue oder einem "Speichern" mitten in einer
 * langen Liste merkt man es: das Bild blinkt, die Seite springt nach
 * oben, aufgeklappte Kaesten fallen zu.
 *
 * Hier passiert dasselbe, nur ohne Seitenwechsel: das Formular geht
 * per fetch() zum Server, der antwortet wie immer mit seiner
 * Weiterleitung, wir holen die Seite dahinter und tauschen daraus nur
 * <main> und das Menue aus.
 *
 * Wichtig ist, was NICHT passiert: kein Controller aendert sich, kein
 * Formular braucht ein Attribut, und es gibt keine zweite Antwortform
 * neben HTML. Der Server weiss von alldem nichts - er schickt seine
 * Seite, und ob sie im Browser eingesetzt oder neu geladen wird, ist
 * seine Sache nicht. Genau darum gilt es fuer alle Seiten auf einmal,
 * auch fuer die, die es noch gar nicht gibt.
 *
 * Und ohne dieses Skript bleibt alles beim Alten. Es ist eine Zugabe,
 * keine Voraussetzung: wer JavaScript abschaltet, bekommt wieder die
 * ganze Seite - dieselbe Seite.
 *
 *   data-no-ajax  am <form> nimmt ein einzelnes wieder heraus.
 *
 * Plugins, die beim Laden etwas an Elemente haengen, hoeren auf
 * "overlay:swapped" - siehe docs/entwicklung.md.
 */
(function () {
    'use strict';

    // Kann der Browser das? Wenn nicht, passiert hier gar nichts, und
    // jedes Formular geht wie eh und je zum Server.
    if (!window.fetch || !window.FormData || !window.DOMParser || !window.history) {
        return;
    }

    var HAUPT = 'main.content';
    var MENUE = '.sidebar nav';

    var laeuft = null;

    // -----------------------------------------------------------------
    //  Was der Browser weiss und die Antwort nicht
    // -----------------------------------------------------------------

    /*
     * Welche Kaesten offen sind, weiss nur der Browser.
     *
     * Der Server rendert sie nach seinen eigenen Regeln zu - ein
     * aufgeklappter Timer waere nach dem Speichern wieder zu, und man
     * sucht seine Stelle. Der Schluessel ist die Beschriftung und
     * nicht die Reihenfolge: loescht man einen Eintrag, rutschen die
     * anderen, und ploetzlich stuende ein fremder Kasten offen.
     *
     * Rueckfragen sind ausgenommen. Die sollen nach der Antwort zu
     * sein - dafuer hat man ja gerade geantwortet.
     */
    function schluessel(kasten) {
        var titel = kasten.querySelector('summary');

        return (kasten.className || '') + '|' + (titel ? titel.textContent.trim() : '');
    }

    function kaestenMerken() {
        var stand = {};

        document.querySelectorAll(HAUPT + ' details').forEach(function (kasten) {
            if (!kasten.classList.contains('confirm')) {
                stand[schluessel(kasten)] = kasten.open;
            }
        });

        return stand;
    }

    function kaestenSetzen(stand) {
        document.querySelectorAll(HAUPT + ' details').forEach(function (kasten) {
            var name = schluessel(kasten);

            // Nur was vorher da war. Einen Kasten, den der Server
            // gerade neu aufgemacht hat, laesst man in Ruhe.
            if (Object.prototype.hasOwnProperty.call(stand, name)) {
                kasten.open = stand[name];
            }
        });
    }

    /*
     * Wo der Schreibkursor stand.
     *
     * Nach dem Tauschen ist das Feld ein anderes Element mit demselben
     * Namen. Ohne das hier springt der Kursor ans Ende der Seite, und
     * wer gerade tippt, tippt ins Leere.
     */
    function kursorMerken() {
        var feld = document.activeElement;

        if (!feld || !feld.name || !feld.form) {
            return null;
        }

        if (feld.tagName !== 'INPUT' && feld.tagName !== 'TEXTAREA') {
            return null;
        }

        var gleiche = document.querySelectorAll('[name="' + feld.name + '"]');

        return {
            name: feld.name,
            wievielter: Array.prototype.indexOf.call(gleiche, feld),
            von: feld.selectionStart,
            bis: feld.selectionEnd
        };
    }

    function kursorSetzen(stand) {
        if (!stand) {
            return;
        }

        var gleiche = document.querySelectorAll('[name="' + stand.name + '"]');
        var feld = gleiche[stand.wievielter];

        if (!feld) {
            return;
        }

        feld.focus();

        try {
            feld.setSelectionRange(stand.von, stand.bis);
        } catch (e) {
            // Ein Feld ohne Textkursor (Zahl, Farbe) kann das nicht.
        }
    }

    // -----------------------------------------------------------------
    //  Einsetzen
    // -----------------------------------------------------------------

    function einsetzen(html, adresse) {
        var neu = new DOMParser().parseFromString(html, 'text/html');
        var quelle = neu.querySelector(HAUPT);
        var ziel = document.querySelector(HAUPT);

        // Keine Seite dieser Art - etwa eine Fehlerseite ohne Rahmen.
        // Dann ist Einsetzen falsch, und der Aufrufer geht den langen
        // Weg.
        if (!quelle || !ziel) {
            return false;
        }

        var kaesten = kaestenMerken();
        var kursor = kursorMerken();
        var hoehe = window.scrollY;

        ziel.replaceWith(quelle);

        // Das Menue traegt Schnellschalter und Zaehler - es aendert
        // sich beim Speichern mit.
        var menueNeu = neu.querySelector(MENUE);
        var menueAlt = document.querySelector(MENUE);

        if (menueNeu && menueAlt) {
            menueAlt.replaceWith(menueNeu);
        }

        var titel = neu.querySelector('title');
        if (titel) {
            document.title = titel.textContent;
        }

        kaestenSetzen(kaesten);
        kursorSetzen(kursor);
        window.scrollTo(0, hoehe);

        /*
         * Die Adresse mitziehen, als waere die Seite geladen worden.
         * Der Server haengt "?notice=..." an, und nach einem normalen
         * Abschicken stuende das auch im Adressfeld - wer neu laedt,
         * soll dasselbe sehen wie eben.
         */
        if (adresse) {
            try {
                history.replaceState(null, '', adresse);
            } catch (e) {
                // Eine fremde Adresse laesst der Browser nicht zu -
                // dann bleibt eben die alte stehen.
            }
        }

        // Fuer Skripte, die beim Laden etwas an Elemente gehaengt
        // haben: ihre Elemente sind jetzt weg.
        document.dispatchEvent(new CustomEvent('overlay:swapped'));

        return true;
    }

    // -----------------------------------------------------------------
    //  Abschicken
    // -----------------------------------------------------------------

    /*
     * Die Adresse eines Formulars - ueber das ATTRIBUT, niemals ueber
     * formular.action.
     *
     * Ein Formular stellt seine Felder als Eigenschaften bereit, und
     * die verdecken dabei die eigenen. Jedes Formular hier hat ein
     * <input name="action"> - "formular.action" ist damit dieses FELD
     * und nicht die Adresse. Geschickt wurde an
     * "[object HTMLInputElement]", und das Aktualisieren von Plugins
     * ging ins Leere.
     *
     * Dasselbe gilt fuer method und target, und es faellt nicht auf:
     * ohne ein Feld dieses Namens stimmt alles.
     */
    function attribut(formular, name) {
        return (formular.getAttribute(name) || '').trim();
    }

    /*
     * Der Streifen oben.
     *
     * Frueher drehte der Browser beim Laden sein Rad - das faellt mit
     * dem Seitenwechsel weg. "Alle aktualisieren" holt Dateien von
     * einem anderen Server und dauert; ohne Zeichen sieht der Klick
     * aus, als waere er ins Leere gegangen, und man klickt noch
     * einmal.
     */
    function beschaeftigt(ja) {
        document.documentElement.dataset.busy = ja ? '1' : '0';
    }

    function zieladresse(formular) {
        return attribut(formular, 'action') || location.href;
    }

    function zustaendig(formular, knopf) {
        if (formular.hasAttribute('data-no-ajax') || attribut(formular, 'target') !== '') {
            return false;
        }

        // Nur POST. Ein GET-Formular ist eine Suche und wechselt die
        // Seite - das ist ein Seitenwechsel und soll einer bleiben.
        if ((attribut(formular, 'method') || 'get').toLowerCase() !== 'post') {
            return false;
        }

        /*
         * Ohne den Knopf fehlt sein Name im Paket. Genau daran haengen
         * "Loeschen" und "Hinzufuegen" in den Listen - ein Formular
         * ohne diesen Namen taete etwas anderes, als der Benutzer
         * angeklickt hat. Kennt der Browser den Absender nicht, bleibt
         * es beim normalen Weg.
         */
        if (knopf === undefined) {
            return false;
        }

        var ziel = new URL(zieladresse(formular), location.href);

        return ziel.origin === location.origin;
    }

    function abschicken(formular, knopf) {
        var daten = new FormData(formular);

        // Der angeklickte Knopf gehoert dazu, wenn er einen Namen hat.
        if (knopf && knopf.name) {
            daten.append(knopf.name, knopf.value);
        }

        if (knopf) {
            knopf.disabled = true;
        }

        laeuft = formular;
        beschaeftigt(true);

        fetch(zieladresse(formular), {
            method: 'POST',
            body: daten,
            credentials: 'same-origin',
            redirect: 'follow',
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (antwort) {
            /*
             * Wohin es gegangen waere, wenn der Browser die
             * Weiterleitung selbst befolgt haette. Nur wenn das
             * dieselbe Seite ist, ist Einsetzen richtig - sonst war es
             * ein Seitenwechsel und soll einer bleiben (Abmelden).
             */
            var umgeleitet = antwort.redirected;
            var ziel = new URL(antwort.url, location.href);

            if (umgeleitet && ziel.pathname !== location.pathname) {
                location.href = antwort.url;

                return null;
            }

            return antwort.text().then(function (html) {
                if (einsetzen(html, umgeleitet ? antwort.url : null)) {
                    return;
                }

                /*
                 * Keine Seite dieser Art - eine Fehlerseite ohne
                 * Rahmen zum Beispiel. Dann hinsehen, aber NICHT noch
                 * einmal abschicken: die Buchung ist ja schon
                 * gelaufen.
                 */
                if (umgeleitet) {
                    location.href = antwort.url;
                } else {
                    location.reload();
                }
            });
        }).catch(function () {
            /*
             * Gar nichts angekommen - kein Netz, Server nicht da, oder
             * die Weiterleitung ging zu einem fremden Server.
             *
             * Hier NICHT von selbst noch einmal abschicken. Ob der
             * Server den ersten Versuch schon verarbeitet hat, ist von
             * hier aus nicht zu sehen, und ein zweiter legte im
             * Zweifel denselben Eintrag ein zweites Mal an.
             *
             * Stattdessen geht dieses Formular ab jetzt den normalen
             * Weg. Der naechste Klick ist dann ein gewoehnliches
             * Abschicken mit allem, was eingetippt ist - und die
             * Entscheidung trifft der Benutzer, nicht dieses Skript.
             */
            formular.setAttribute('data-no-ajax', '');
        }).then(function () {
            laeuft = null;
            beschaeftigt(false);

            if (knopf) {
                knopf.disabled = false;
            }
        });
    }

    document.addEventListener('submit', function (ereignis) {
        if (ereignis.defaultPrevented) {
            return;
        }

        var formular = ereignis.target;

        if (!(formular instanceof HTMLFormElement)) {
            return;
        }

        if (!zustaendig(formular, ereignis.submitter)) {
            return;
        }

        // Zweimal dasselbe Formular waere zweimal dieselbe Buchung.
        if (laeuft === formular) {
            ereignis.preventDefault();

            return;
        }

        ereignis.preventDefault();
        abschicken(formular, ereignis.submitter);
    });
}());
