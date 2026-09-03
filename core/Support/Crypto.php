<?php

declare(strict_types=1);

namespace TwitchController\Core\Support;

use TwitchController\Core\Config\Env;
use RuntimeException;

/**
 * Symmetrische Verschluesselung fuer Geheimnisse in der Datenbank
 * (Twitch-Client-Secret, Access- und Refresh-Tokens, Webhook-Secret).
 *
 * Schluessel ist APP_KEY aus der .env. Damit liegt das Geheimnis zum
 * Entschluesseln nicht in derselben Datenbank wie die Geheimnisse - ein
 * geklauter Datenbank-Dump allein reicht also nicht.
 *
 * Erzeugen:  openssl rand -hex 32
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    private ?string $key = null;

    public function __construct(private readonly Env $env)
    {
    }

    /**
     * Schluessellaenge von crypto_secretbox. Als eigene Konstante, damit
     * diese Klasse auch dann ladbar ist, wenn die sodium-Erweiterung
     * fehlt - der Systemcheck soll das melden, nicht daran sterben.
     */
    private const KEY_BYTES = 32;
    private const NONCE_BYTES = 24;

    public function isConfigured(): bool
    {
        if (!extension_loaded('sodium')) {
            return false;
        }

        $raw = (string) $this->env->get('APP_KEY', '');

        return strlen($this->normalize($raw) ?? '') === self::KEY_BYTES;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key());

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $value): string
    {
        if (!self::looksEncrypted($value)) {
            // Klartext aus einer aelteren Installation - unveraendert
            // zurueckgeben, damit ein Upgrade nichts kaputt macht.
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::NONCE_BYTES) {
            throw new RuntimeException('Verschluesselter Wert ist unbrauchbar.');
        }

        $nonce = substr($raw, 0, self::NONCE_BYTES);
        $cipher = substr($raw, self::NONCE_BYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key());
        if ($plain === false) {
            throw new RuntimeException(
                'Geheimnis konnte nicht entschluesselt werden. Wurde APP_KEY geaendert?'
            );
        }

        return $plain;
    }

    public static function looksEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    private function key(): string
    {
        if ($this->key !== null) {
            return $this->key;
        }

        if (!extension_loaded('sodium')) {
            throw new RuntimeException('Die PHP-Erweiterung "sodium" fehlt - ohne sie koennen Geheimnisse nicht verschluesselt werden.');
        }

        $key = $this->normalize((string) $this->env->require('APP_KEY'));
        if ($key === null || strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException(
                'APP_KEY muss 32 Byte lang sein (64 Hex-Zeichen). Erzeugen: openssl rand -hex 32'
            );
        }

        return $this->key = $key;
    }

    /**
     * Akzeptiert Hex (64 Zeichen), base64 oder 32 Rohbytes.
     */
    private function normalize(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (strlen($raw) === 64 && ctype_xdigit($raw)) {
            $bin = hex2bin($raw);
            return $bin === false ? null : $bin;
        }

        if (strlen($raw) === self::KEY_BYTES) {
            return $raw;
        }

        $decoded = base64_decode($raw, true);

        return $decoded === false ? null : $decoded;
    }
}
