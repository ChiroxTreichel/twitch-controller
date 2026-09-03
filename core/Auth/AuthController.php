<?php

declare(strict_types=1);

namespace TwitchController\Core\Auth;

use TwitchController\Core\Admin\Nav;
use TwitchController\Core\App;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;
use TwitchController\Core\Setup\SetupController;
use TwitchController\Core\Twitch\OAuth;
use Throwable;

/**
 * Anmeldung ueber Twitch.
 *
 * Alle OAuth-Rueckwege landen auf /auth/callback. Der Zweck steckt im
 * signierten state; unbekannte Zwecke gehen an den Hook
 * 'core.oauth.callback', damit Plugins eigene Login-Flows anhaengen
 * koennen (Spendenseite, Raid-Anfrage) ohne eine zweite Redirect-URI.
 */
final class AuthController
{
    public function __construct(private readonly App $app)
    {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->app->auth->isLoggedIn()) {
            return Response::redirect($this->app->url((new Nav($this->app))->firstAllowedHref()));
        }

        return Response::html($this->app->view->render('login', [
            'title'  => translate('login.title'),
            'invite' => $request->get('invite'),
            'error'  => $request->get('error'),
        ], null));
    }

    public function startLogin(Request $request): Response
    {
        $invite = trim($request->get('invite'));

        return Response::redirect($this->app->twitch->oauth()->authorizeUrl(
            'login',
            [],
            false,
            $invite === '' ? [] : ['invite' => $invite]
        ));
    }

    public function callback(Request $request): Response
    {
        $error = $request->get('error_description') ?: $request->get('error');
        if ($error !== '') {
            return $this->fail(translate('auth.login_cancelled', ['reason' => $error]));
        }

        $code = $request->get('code');
        if ($code === '') {
            return $this->fail(translate('auth.no_code'));
        }

        try {
            $state = $this->app->twitch->oauth()->consumeState(
                $request->get('state'),
                (string) ($_COOKIE[OAuth::STATE_COOKIE] ?? '')
            );

            $token = $this->app->twitch->oauth()->exchangeCode($code);
            $twitchUser = $this->app->twitch->api()->userForToken($token['access_token']);
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        $purpose = $state['purpose'];
        $extra = $state['extra'];

        try {
            if ($purpose === 'setup_channel') {
                return (new SetupController($this->app))->completeChannelConnect($token, $twitchUser);
            }

            if ($purpose === 'login') {
                $this->app->auth->completeLogin($twitchUser, (string) ($extra['invite'] ?? ''));

                return Response::redirect($this->app->url((new Nav($this->app))->firstAllowedHref()));
            }

            // Plugins und spaetere Kern-Flows (Bot verbinden, Kanal neu
            // verbinden) haengen sich hier ein.
            $handled = $this->app->hooks->filter(
                'core.oauth.callback',
                null,
                $purpose,
                $token,
                $twitchUser,
                $extra
            );

            if ($handled instanceof Response) {
                return $handled;
            }
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }

        return $this->fail(translate('auth.unknown_purpose', ['purpose' => $purpose]));
    }

    public function logout(Request $request): Response
    {
        if (!$this->app->auth->checkCsrf($request->input('csrf'))) {
            return Response::text(translate('auth.bad_csrf'), 400);
        }

        $this->app->auth->logout();

        return Response::redirect($this->app->url('/login'));
    }

    private function fail(string $message): Response
    {
        $this->app->log('Login fehlgeschlagen: ' . $message);

        if (!$this->app->settings->bool('installed', false)) {
            return Response::redirect($this->app->url('/setup?error=' . rawurlencode($message)));
        }

        return Response::redirect($this->app->url('/login?error=' . rawurlencode($message)));
    }
}
