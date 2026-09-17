<?php

declare(strict_types=1);

namespace TwitchController\Core\Upload;

use TwitchController\Core\App;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;

/**
 * Nimmt eine Alert-Datei entgegen.
 *
 * Antwortet als JSON und nicht mit einer Seite: aufgerufen wird das
 * vom Dateifeld im Rahmen (layout.php), waehrend der Benutzer ein
 * Formular ausfuellt. Eine Weiterleitung waere hier falsch - sie
 * verwuerfe alles, was schon eingetippt ist.
 *
 * Ohne JavaScript passiert nichts davon. Dann bleibt das Textfeld, was
 * es war: eine Zeile, in die man den Pfad einer Datei schreibt, die
 * schon auf dem Server liegt.
 */
final class UploadController
{
    public function __construct(private readonly App $app)
    {
    }

    public function store(Request $request): Response
    {
        if (!$this->app->auth->checkCsrf($request->input('csrf'))) {
            return Response::json(['ok' => false, 'error' => translate('common.error.form_expired')], 419);
        }

        $datei = $_FILES['file'] ?? null;

        if (!is_array($datei) || !isset($datei['error'])) {
            return Response::json(['ok' => false, 'error' => translate('upload.error.none')], 400);
        }

        $fehler = (int) $datei['error'];

        if ($fehler !== UPLOAD_ERR_OK) {
            return Response::json(['ok' => false, 'error' => Media::errorText($fehler)], 400);
        }

        $name = (string) ($datei['name'] ?? '');
        $temp = (string) ($datei['tmp_name'] ?? '');

        /*
         * is_uploaded_file und nicht nur "die Datei ist da": ohne die
         * Pruefung liesse sich ueber tmp_name eine beliebige Datei des
         * Servers benennen und damit nach public/ kopieren.
         */
        if ($temp === '' || !is_uploaded_file($temp)) {
            return Response::json(['ok' => false, 'error' => translate('upload.error.server')], 400);
        }

        if (!Media::isAllowed($name)) {
            return Response::json(['ok' => false, 'error' => translate('upload.error.type', [
                'types' => implode(', ', Media::allowed()),
            ])], 415);
        }

        if (filesize($temp) > Media::MAX_BYTES) {
            return Response::json(['ok' => false, 'error' => Media::errorText(UPLOAD_ERR_INI_SIZE)], 413);
        }

        $ordner = Media::directory($this->app);

        /*
         * Den Ordner hier anlegen und nicht im Installationsskript.
         *
         * public/uploads gibt es seit der Einrichtung und es gehoert
         * dem Webserver - der Unterordner fehlte nur. Wer schon
         * installiert hat, soll dafuer nicht wieder auf die Konsole:
         * das Update bringt diese Zeile mit, und beim ersten Hochladen
         * ist der Ordner da.
         */
        if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
            $this->app->log('Upload: Ordner laesst sich nicht anlegen: ' . $ordner);

            return Response::json(['ok' => false, 'error' => translate('upload.error.directory')], 500);
        }

        if (!is_writable($ordner)) {
            $this->app->log('Upload: Ordner ist nicht beschreibbar: ' . $ordner);

            return Response::json(['ok' => false, 'error' => translate('upload.error.directory')], 500);
        }

        $ziel = Media::freeName($ordner, Media::safeName($name));

        if (!@move_uploaded_file($temp, $ordner . '/' . $ziel)) {
            $this->app->log('Upload: Datei laesst sich nicht ablegen: ' . $ordner . '/' . $ziel);

            return Response::json(['ok' => false, 'error' => translate('upload.error.server')], 500);
        }

        // Lesbar fuer den Webserver, der sie gleich ausliefert.
        @chmod($ordner . '/' . $ziel, 0664);

        return Response::json([
            'ok'   => true,
            'path' => Media::URL_PREFIX . $ziel,
            // Der Name kann sich geaendert haben - bereinigt, oder mit
            // einer Nummer, weil es ihn schon gab. Das Feld zeigt
            // hinterher den echten Pfad, nicht den gewuenschten.
            'name' => $ziel,
        ]);
    }
}
