<?php

declare(strict_types=1);

/**
 * Schema des Kerns. Wird bei der Erstinstallation und bei jedem
 * Versionswechsel ausgefuehrt und muss deshalb idempotent sein.
 *
 * Verfuegbar:
 *   $db          Overlays\Core\Database\Db
 *   $fromVersion vorher installierte Version, null bei Erstinstallation
 *
 * Dieselbe Signatur gilt fuer plugins/<slug>/install.php.
 */

/** @var \Overlays\Core\Database\Db $db */
/** @var string|null $fromVersion */

// --- Einstellungen: Kern und Plugins, JSON-Werte -----------------------
$db->run('
    CREATE TABLE IF NOT EXISTS settings (
        scope      TEXT        NOT NULL,
        key        TEXT        NOT NULL,
        value      JSONB,
        updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
        PRIMARY KEY (scope, key)
    )
');

// --- Benutzer ----------------------------------------------------------
// Identitaet kommt immer von Twitch, es gibt keine eigenen Passwoerter.
// Der erste Login wird automatisch superadmin.
$db->run("
    CREATE TABLE IF NOT EXISTS users (
        twitch_id    TEXT        PRIMARY KEY,
        login        TEXT        NOT NULL,
        display_name TEXT        NOT NULL,
        role         TEXT        NOT NULL DEFAULT 'member',
        permissions  JSONB       NOT NULL DEFAULT '[]'::jsonb,
        created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
        last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )
");

// --- Sessions ----------------------------------------------------------
// Im Cookie steht ein Zufallstoken, in der Datenbank nur sein Hash.
$db->run('
    CREATE TABLE IF NOT EXISTS sessions (
        token_hash   TEXT        PRIMARY KEY,
        twitch_id    TEXT        NOT NULL REFERENCES users (twitch_id) ON DELETE CASCADE,
        created_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
        last_seen_at TIMESTAMPTZ NOT NULL DEFAULT now(),
        ip           TEXT,
        user_agent   TEXT
    )
');
$db->run('CREATE INDEX IF NOT EXISTS sessions_twitch_id_idx ON sessions (twitch_id)');

// --- Einladungen -------------------------------------------------------
// Wer nicht der erste Login ist, braucht einen Einladungscode.
$db->run('
    CREATE TABLE IF NOT EXISTS invites (
        code       TEXT        PRIMARY KEY,
        created_by TEXT        REFERENCES users (twitch_id) ON DELETE SET NULL,
        created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
        expires_at TIMESTAMPTZ,
        used_by    TEXT,
        used_at    TIMESTAMPTZ
    )
');

// --- Plugin-Register ---------------------------------------------------
$db->run('
    CREATE TABLE IF NOT EXISTS plugins (
        slug         TEXT        PRIMARY KEY,
        version      TEXT        NOT NULL,
        enabled      BOOLEAN     NOT NULL DEFAULT false,
        installed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
        updated_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
        source       TEXT,
        manifest     JSONB
    )
');

// --- Twitch-Tokens -----------------------------------------------------
// purpose trennt die Zwecke: "broadcaster" (Kanal-Scopes), "bot" (Chat).
// Tokens liegen verschluesselt, siehe core/Support/Crypto.php.
$db->run('
    CREATE TABLE IF NOT EXISTS twitch_tokens (
        purpose       TEXT        PRIMARY KEY,
        twitch_id     TEXT,
        login         TEXT,
        access_token  TEXT        NOT NULL,
        refresh_token TEXT        NOT NULL,
        expires_at    TIMESTAMPTZ,
        scopes        JSONB       NOT NULL DEFAULT \'[]\'::jsonb,
        updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
    )
');

// --- Aktivitaeten ------------------------------------------------------
// Der zentrale Event-Eingang: EventSub schreibt hier hinein, Plugins
// lesen daraus (Alerts, Ziele, Statistik). UNIQUE(source, external_id)
// macht doppelte Zustellung durch Twitch harmlos.
$db->run('
    CREATE TABLE IF NOT EXISTS events (
        id                BIGSERIAL   PRIMARY KEY,
        source            VARCHAR(32) NOT NULL,
        event_type        VARCHAR(128) NOT NULL,
        external_id       VARCHAR(128) NOT NULL,
        occurred_at       TIMESTAMPTZ NOT NULL,
        received_at       TIMESTAMPTZ NOT NULL DEFAULT now(),
        actor_name        VARCHAR(255),
        actor_external_id VARCHAR(255),
        message           TEXT,
        amount            NUMERIC(12,2),
        currency          VARCHAR(16),
        payload           JSONB       NOT NULL,
        raw_payload       TEXT        NOT NULL,
        CONSTRAINT events_source_external_id_unique UNIQUE (source, external_id)
    )
');
$db->run('CREATE INDEX IF NOT EXISTS events_received_at_idx ON events (received_at DESC)');
$db->run('CREATE INDEX IF NOT EXISTS events_source_type_idx ON events (source, event_type)');
$db->run('CREATE INDEX IF NOT EXISTS events_actor_idx       ON events (source, actor_external_id)');
