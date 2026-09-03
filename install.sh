#!/usr/bin/env bash
#
# Twitch-Controller - Installation
#
#   ./install.sh
#
# Stellt ein paar einfache Fragen, erzeugt Schlüssel und Passwörter
# selbst, baut die Container und startet sie. Ein zweiter Aufruf ist
# gefahrlos: vorhandene Werte bleiben stehen, insbesondere APP_KEY.
#
# Ohne Rückfragen (z.B. für ein Skript):
#   ./install.sh --domain twitch.example.com --proxy npm --yes
#
set -euo pipefail

# Woher das Projekt geholt wird, wenn das Skript ohne Repository laeuft
# (also beim Aufruf per curl ... | bash).
REPO_URL="${TC_REPO:-https://github.com/ChiroxTreichel/twitch-controller.git}"
REPO_REF="${TC_REF:-main}"
DEFAULT_DIR="${TC_DIR:-/opt/twitch-controller}"

# Aufrufparameter merken, um sie nach dem Klonen weiterzugeben.
ORIGINAL_ARGS=("$@")

# Beim Aufruf per Pipe gibt es keine Skriptdatei - dann bleibt ROOT leer
# und wird nach dem Klonen gesetzt.
SCRIPT_SOURCE="${BASH_SOURCE[0]:-}"
if [ -n "$SCRIPT_SOURCE" ] && [ -f "$SCRIPT_SOURCE" ]; then
    ROOT="$(cd "$(dirname "$SCRIPT_SOURCE")" && pwd)"
else
    ROOT=''
fi

ENV_FILE=''
ENV_EXAMPLE=''

set_root() {
    ROOT="$1"
    ENV_FILE="$ROOT/.env"
    ENV_EXAMPLE="$ROOT/.env.example"
}

# Liegt hier wirklich das Projekt? core/App.php gibt es nur dort.
is_project_dir() {
    [ -n "${1:-}" ] && [ -f "$1/core/App.php" ] && [ -f "$1/docker-compose.yaml" ]
}

# Bewusst als if: mit "set -e" wuerde ein fehlgeschlagenes && als letzter
# Befehl das Skript beenden - genau im Pipe-Fall, in dem ROOT leer ist.
if [ -n "$ROOT" ]; then
    set_root "$ROOT"
fi

# --- Ausgabe ---------------------------------------------------------------

if [ -t 1 ] && [ "$(tput colors 2>/dev/null || echo 0)" -ge 8 ]; then
    C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_DIM=$'\033[2m'
    C_OK=$'\033[32m'; C_WARN=$'\033[33m'; C_ERR=$'\033[31m'; C_INFO=$'\033[36m'
else
    C_RESET=''; C_BOLD=''; C_DIM=''; C_OK=''; C_WARN=''; C_ERR=''; C_INFO=''
fi

step()  { printf '\n%s==>%s %s%s%s\n' "$C_INFO" "$C_RESET" "$C_BOLD" "$1" "$C_RESET"; }
ok()    { printf '    %s✓%s %s\n' "$C_OK" "$C_RESET" "$1"; }
info()  { printf '    %s\n' "$1"; }
dim()   { printf '    %s%s%s\n' "$C_DIM" "$1" "$C_RESET"; }
warn()  { printf '    %s!%s %s\n' "$C_WARN" "$C_RESET" "$1"; }
die()   { printf '\n%sAbbruch:%s %s\n\n' "$C_ERR" "$C_RESET" "$1" >&2; exit 1; }

# --- Argumente -------------------------------------------------------------

DOMAIN=''
EMAIL=''
PROXY_MODE=''
PROXY_NET=''
ASSUME_YES=0
DO_START=1
AUTO_INSTALL=0
NO_PULL=0
REPO_UPDATED=0

TARGET_DIR=''

usage() {
    cat <<USAGE
Twitch-Controller - Installation

  Direkt aus dem Netz (installiert und aktualisiert):
    curl -fsSL https://raw.githubusercontent.com/ChiroxTreichel/twitch-controller/main/install.sh | sudo bash

  Oder im schon geklonten Ordner:
    sudo ./install.sh [Optionen]

Ohne Optionen führt das Skript durch die Einrichtung. Alle Optionen sind
nur dafür da, die Fragen vorab zu beantworten:

  --dir <pfad>         Wohin installiert wird (Standard: $DEFAULT_DIR)
  --ref <name>         Branch, Tag oder Commit, der geholt wird
                       (Standard: $REPO_REF)
  --repo <url>         Anderes Repository verwenden
  --domain <domain>    Domain, unter der alles läuft
  --proxy <auto|npm>   auto = HTTPS selbst einrichten (Standard)
                       npm  = eigener Reverse Proxy ist schon da
  --network <name>     Docker-Netz des eigenen Proxys (wird sonst erkannt)
  --email <adresse>    Kontakt für Let's Encrypt (optional)
  --no-pull            Nicht nach Updates sehen
  --no-start           Nur vorbereiten, nicht starten
  --yes                Keine Rückfragen, Vorgaben verwenden
  --install-deps       Fehlende Pakete (git, Docker, Compose) ohne
                       Rückfrage nachinstallieren. Nur zusammen mit
                       --yes sinnvoll; ohne dieses Flag wird bei
                       --yes nichts installiert.
  --help               Diese Hilfe

Ein zweiter Aufruf ist gefahrlos: vorhandene Werte in der .env bleiben
stehen, ein vorhandener Klon wird nur aktualisiert.
USAGE
}

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)     TARGET_DIR="${2:-}"; shift 2 ;;
        --ref)     REPO_REF="${2:-}"; shift 2 ;;
        --repo)    REPO_URL="${2:-}"; shift 2 ;;
        --domain)  DOMAIN="${2:-}"; shift 2 ;;
        --email)   EMAIL="${2:-}"; shift 2 ;;
        --proxy)   PROXY_MODE="${2:-}"; shift 2 ;;
        --network) PROXY_NET="${2:-}"; shift 2 ;;
        --no-pull)  NO_PULL=1; shift ;;
        --no-start) DO_START=0; shift ;;
        --install-deps) AUTO_INSTALL=1; shift ;;
        --yes|-y)  ASSUME_YES=1; shift ;;
        --help|-h) usage; exit 0 ;;
        *) die "Unbekannte Option: $1  (--help zeigt die Übersicht)" ;;
    esac
done

# "caddy" wird weiter akzeptiert, heißt nach außen aber "auto".
[ "$PROXY_MODE" = "caddy" ] && PROXY_MODE='auto'

# --- Eingabe-Helfer --------------------------------------------------------

interactive() {
    [ "$ASSUME_YES" = "0" ] && [ -r /dev/tty ]
}

ask() {
    # ask <Frage> <Vorgabe>
    local question="$1" default="${2:-}" answer=''

    if ! interactive; then
        printf '%s\n' "$default"
        return
    fi

    if [ -n "$default" ]; then
        printf '    %s%s%s [%s]: ' "$C_BOLD" "$question" "$C_RESET" "$default" >/dev/tty
    else
        printf '    %s%s%s: ' "$C_BOLD" "$question" "$C_RESET" >/dev/tty
    fi

    read -r answer </dev/tty || true
    printf '%s\n' "${answer:-$default}"
}

confirm() {
    # confirm <Frage>  -> 0 = ja
    local answer=''

    interactive || return 0

    printf '    %s%s%s [J/n]: ' "$C_BOLD" "$1" "$C_RESET" >/dev/tty
    read -r answer </dev/tty || true
    case "${answer:-j}" in [jJyY]*) return 0 ;; *) return 1 ;; esac
}

offer_install() {
    # Zustimmung zum Nachinstallieren von Software.
    #
    # Bewusst nicht ueber confirm(): dort gilt "keine Rueckfrage" als Ja,
    # und ein --yes darf nicht dazu fuehren, dass ungefragt ein Skript aus
    # dem Netz mit Administratorrechten laeuft. Ohne Terminal wird also
    # nur installiert, wenn --install-deps ausdruecklich dabei steht.
    if ! interactive; then
        [ "$AUTO_INSTALL" = "1" ]
        return
    fi

    confirm "$1"
}

confirm_destructive() {
    # Zustimmung zu etwas, das Daten verwirft. Ohne Terminal immer nein -
    # ein unbeaufsichtigter Lauf darf nichts loeschen.
    interactive || return 1

    confirm "$1"
}

choose() {
    # choose <Vorgabe-Nummer> <Text1> <Text2> ...  -> gewählte Nummer
    local default="$1"; shift
    local count=$# index=1 answer=''

    if ! interactive; then
        printf '%s\n' "$default"
        return
    fi

    for option in "$@"; do
        if [ "$index" = "$default" ]; then
            printf '      %s%s)%s %s %s(empfohlen)%s\n' \
                "$C_BOLD" "$index" "$C_RESET" "$option" "$C_DIM" "$C_RESET" >/dev/tty
        else
            printf '      %s%s)%s %s\n' "$C_BOLD" "$index" "$C_RESET" "$option" >/dev/tty
        fi
        index=$((index + 1))
    done

    while true; do
        printf '    %sAuswahl%s [%s]: ' "$C_BOLD" "$C_RESET" "$default" >/dev/tty
        read -r answer </dev/tty || true
        answer="${answer:-$default}"

        case "$answer" in
            ''|*[!0-9]*) : ;;
            *) if [ "$answer" -ge 1 ] && [ "$answer" -le "$count" ]; then
                   printf '%s\n' "$answer"
                   return
               fi ;;
        esac

        printf '    %sBitte eine Zahl zwischen 1 und %s eingeben.%s\n' "$C_WARN" "$count" "$C_RESET" >/dev/tty
    done
}

# --- Sonstige Helfer -------------------------------------------------------

random_hex() {
    local bytes="$1"

    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex "$bytes"
    elif [ -r /dev/urandom ]; then
        od -An -tx1 -N "$bytes" /dev/urandom | tr -d ' \n'
    else
        die "Weder openssl noch /dev/urandom vorhanden - kann keine Schlüssel erzeugen."
    fi
}

env_get() {
    local key="$1" file="${2:-$ENV_FILE}"
    [ -f "$file" ] || return 0
    sed -n "s/^${key}=//p" "$file" | head -n1
}

env_set() {
    local key="$1" value="$2" tmp

    tmp="$(mktemp)"
    if grep -q "^${key}=" "$ENV_FILE" 2>/dev/null; then
        KEY="$key" VALUE="$value" awk '
            BEGIN { key = ENVIRON["KEY"]; value = ENVIRON["VALUE"] }
            index($0, key "=") == 1 { print key "=" value; next }
            { print }
        ' "$ENV_FILE" > "$tmp"
    else
        cat "$ENV_FILE" > "$tmp" 2>/dev/null || true
        printf '%s=%s\n' "$key" "$value" >> "$tmp"
    fi
    mv "$tmp" "$ENV_FILE"
    chmod 600 "$ENV_FILE" 2>/dev/null || true
}

port_in_use() {
    local port="$1"

    if command -v ss >/dev/null 2>&1; then
        ss -ltn 2>/dev/null | grep -qE "[:.]${port}[[:space:]]"
    elif command -v netstat >/dev/null 2>&1; then
        netstat -ltn 2>/dev/null | grep -qE "[:.]${port}[[:space:]]"
    else
        return 1
    fi
}

version_of() {
    printf '%s' "$1" | grep -oE '[0-9]+(\.[0-9]+)+' | head -n1
}

sync_repo() {
    # sync_repo <ordner>
    #
    # Holt den Stand von REPO_REF in einen vorhandenen Klon. Wird sowohl
    # beim Aktualisieren einer bestehenden Installation benutzt als auch
    # direkt nach dem Klonen.
    #
    # Rueckgabe: 0 = auf dem neuesten Stand (auch wenn nichts zu tun war),
    #            1 = uebersprungen oder nicht moeglich
    local dir="$1"

    [ -d "$dir/.git" ] || return 1

    if ! git -C "$dir" remote get-url origin >/dev/null 2>&1; then
        dim "Kein Repository hinterlegt - Aktualisieren übersprungen."
        return 1
    fi

    if ! git -C "$dir" fetch --quiet origin "$REPO_REF" 2>/dev/null; then
        warn "Konnte nicht nach Updates sehen (keine Verbindung zu GitHub?)."
        dim "Die Einrichtung läuft mit dem vorhandenen Stand weiter."
        return 1
    fi

    local here there
    here="$(git -C "$dir" rev-parse HEAD 2>/dev/null || true)"
    there="$(git -C "$dir" rev-parse FETCH_HEAD 2>/dev/null || true)"

    if [ -z "$there" ]; then
        warn "Konnte $REPO_REF nicht lesen."
        return 1
    fi

    if [ "$here" = "$there" ]; then
        ok "Schon auf dem neuesten Stand"
        return 0
    fi

    # Was kommt dazu? Kurz zeigen, damit man weiss, was passiert.
    local anzahl
    anzahl="$(git -C "$dir" rev-list --count HEAD..FETCH_HEAD 2>/dev/null || echo '?')"
    info "Es gibt eine neuere Version (${anzahl} Änderungen)."
    git -C "$dir" log --oneline --no-decorate HEAD..FETCH_HEAD 2>/dev/null \
        | head -n 5 | while read -r zeile; do dim "  $zeile"; done

    # Eigene Änderungen nicht stillschweigend wegwerfen.
    if ! git -C "$dir" diff --quiet HEAD 2>/dev/null; then
        warn "Im Ordner liegen geänderte Dateien."
        if confirm_destructive "Änderungen verwerfen und aktualisieren?"; then
            git -C "$dir" reset --hard FETCH_HEAD >/dev/null 2>&1 \
                || { warn "Aktualisieren fehlgeschlagen."; return 1; }
            REPO_UPDATED=1
            ok "Aktualisiert auf $REPO_REF"
            return 0
        fi

        warn "Nicht aktualisiert - deine Änderungen bleiben erhalten."
        return 1
    fi

    if git -C "$dir" merge --ff-only FETCH_HEAD >/dev/null 2>&1 \
       || git -C "$dir" checkout --quiet --detach FETCH_HEAD >/dev/null 2>&1; then
        REPO_UPDATED=1
        ok "Aktualisiert auf $REPO_REF"
        return 0
    fi

    warn "Aktualisieren fehlgeschlagen. Von Hand:  git -C $dir pull"
    return 1
}

version_at_least() {
    [ -n "$1" ] || return 1
    [ "$(printf '%s\n%s\n' "$2" "$1" | sort -V | head -n1)" = "$2" ]
}

# --- Begrüßung -------------------------------------------------------------

printf '\n%s========================================================%s\n' "$C_INFO" "$C_RESET"
printf '%s Twitch-Controller - Einrichtung%s\n' "$C_BOLD" "$C_RESET"
printf '%s========================================================%s\n' "$C_INFO" "$C_RESET"
cat <<'INTRO'

  Dieses Skript richtet alles ein, was auf dem Server nötig ist.
  Es fragt zwei Dinge: deine Domain und wie HTTPS laufen soll.
  Alles andere - Passwörter, Schlüssel, Datenbank - macht es selbst.

  Was du danach noch brauchst: eine Twitch-Anwendung. Wie das geht,
  steht am Ende in einer Schritt-für-Schritt-Anleitung.

INTRO

# --- 1. Voraussetzungen ----------------------------------------------------

step "Ist alles da, was gebraucht wird?"

# Wie bekommen wir Root-Rechte fuers Nachinstallieren?
SUDO=''
if [ "$(id -u)" != "0" ]; then
    if command -v sudo >/dev/null 2>&1; then
        SUDO='sudo'
    fi
fi

# Docker-Aufrufe brauchen unter Umstaenden sudo, solange der Benutzer
# noch nicht in der Gruppe "docker" ist (greift erst nach Neuanmeldung).
DOCKER_SUDO=''

# Paketmanager der Distribution erkennen.
PKG_MANAGER=''
for candidate in apt-get dnf yum zypper pacman apk; do
    if command -v "$candidate" >/dev/null 2>&1; then
        PKG_MANAGER="$candidate"
        break
    fi
done

pkg_install() {
    # pkg_install <paket> [paket...]
    [ -n "$PKG_MANAGER" ] || return 1

    case "$PKG_MANAGER" in
        apt-get) $SUDO apt-get update -qq && $SUDO apt-get install -y -qq "$@" ;;
        dnf)     $SUDO dnf install -y -q "$@" ;;
        yum)     $SUDO yum install -y -q "$@" ;;
        zypper)  $SUDO zypper --non-interactive install "$@" ;;
        pacman)  $SUDO pacman -Sy --noconfirm "$@" ;;
        apk)     $SUDO apk add --quiet "$@" ;;
        *)       return 1 ;;
    esac
}

can_elevate() {
    [ "$(id -u)" = "0" ] || [ -n "$SUDO" ]
}

MISSING=''
note_missing() { MISSING="${MISSING}
  - $1"; }

# --- curl -----------------------------------------------------------------
# Wird gleich fuers Nachinstallieren gebraucht, deshalb zuerst.
if ! command -v curl >/dev/null 2>&1; then
    if can_elevate && [ -n "$PKG_MANAGER" ] && offer_install "curl fehlt. Soll ich es installieren?"; then
        pkg_install curl >/dev/null 2>&1 || true
    fi
fi
command -v curl >/dev/null 2>&1 && ok "curl vorhanden" \
    || warn "curl fehlt - ein paar Kontrollen entfallen."

# --- git ------------------------------------------------------------------
if command -v git >/dev/null 2>&1; then
    ok "git $(version_of "$(git --version 2>/dev/null)")"
else
    info "git fehlt. Wird gebraucht, um später Updates zu holen."
    if can_elevate && [ -n "$PKG_MANAGER" ] && offer_install "Soll ich git jetzt installieren?"; then
        if pkg_install git; then
            ok "git $(version_of "$(git --version 2>/dev/null)") installiert"
        else
            note_missing "git konnte nicht installiert werden. Bitte von Hand:
      $PKG_MANAGER install git"
        fi
    else
        note_missing "git fehlt.
      Debian/Ubuntu:  sudo apt install git
      Fedora:         sudo dnf install git"
    fi
fi

# --- docker ---------------------------------------------------------------
install_docker() {
    command -v curl >/dev/null 2>&1 || {
        warn "Ohne curl kann ich Docker nicht holen."
        return 1
    }

    local script='/tmp/get-docker.sh'

    info "Lade das offizielle Installationsskript von Docker..."
    if ! curl -fsSL https://get.docker.com -o "$script"; then
        warn "Download fehlgeschlagen."
        return 1
    fi

    info "Installiere Docker. Das dauert ein paar Minuten..."
    if ! $SUDO sh "$script"; then
        rm -f "$script"
        warn "Die Installation ist fehlgeschlagen."
        return 1
    fi
    rm -f "$script"

    # Dienst starten und beim Systemstart mitnehmen.
    if command -v systemctl >/dev/null 2>&1; then
        $SUDO systemctl enable --now docker >/dev/null 2>&1 || true
    fi

    # Damit docker künftig ohne sudo geht.
    if [ "$(id -u)" != "0" ]; then
        $SUDO usermod -aG docker "$(id -un)" >/dev/null 2>&1 || true
    fi

    return 0
}

DOCKER_OK=0
if command -v docker >/dev/null 2>&1; then
    DOCKER_VERSION="$(version_of "$(docker --version 2>/dev/null)")"
    if version_at_least "$DOCKER_VERSION" "20.10"; then
        ok "Docker $DOCKER_VERSION"
        DOCKER_OK=1
    else
        note_missing "Docker $DOCKER_VERSION ist zu alt, gebraucht wird mindestens 20.10.
      Anleitung: https://docs.docker.com/engine/install/"
    fi
else
    info "Docker ist nicht installiert - ohne Docker läuft hier nichts."
    printf '\n'
    dim "Ich kann es für dich installieren. Dazu wird das offizielle"
    dim "Skript von Docker geladen (https://get.docker.com) und mit"
    dim "Administratorrechten ausgeführt - so steht es auch in der"
    dim "Anleitung von Docker selbst."
    printf '\n'

    if ! can_elevate; then
        note_missing "Docker fehlt, und mir fehlen die Rechte zum Installieren.
      Bitte einmal:  sudo ./install.sh
      Oder von Hand: https://docs.docker.com/engine/install/"
    elif offer_install "Docker jetzt installieren?"; then
        if install_docker && command -v docker >/dev/null 2>&1; then
            ok "Docker $(version_of "$(docker --version 2>/dev/null)") installiert"
            DOCKER_OK=1
        else
            note_missing "Docker konnte nicht installiert werden.
      Anleitung: https://docs.docker.com/engine/install/"
        fi
    else
        note_missing "Ohne Docker geht es nicht weiter.
      Anleitung: https://docs.docker.com/engine/install/"
    fi
fi

# --- docker compose v2 ----------------------------------------------------
# Version 1 (das alte Python-Skript "docker-compose") reicht nicht: dieses
# Projekt nutzt Profile, COMPOSE_FILE-Verkettung und
# "depends_on: condition: service_healthy" - alles erst ab v2 vorhanden.
compose_version() {
    docker compose version --short 2>/dev/null || docker compose version 2>/dev/null || true
}

DC=''
if [ "$DOCKER_OK" = "1" ]; then
    COMPOSE_VERSION="$(version_of "$(compose_version)")"

    if [ -z "$COMPOSE_VERSION" ] || ! version_at_least "$COMPOSE_VERSION" "2.0"; then
        # Vielleicht liegt v2 als eigenständiges Binary vor.
        if command -v docker-compose >/dev/null 2>&1; then
            LEGACY_VERSION="$(version_of "$(docker-compose version --short 2>/dev/null || docker-compose --version 2>/dev/null)")"
            if version_at_least "$LEGACY_VERSION" "2.0"; then
                DC="docker-compose"
                COMPOSE_VERSION="$LEGACY_VERSION"
                ok "Docker Compose $COMPOSE_VERSION"
            fi
        fi

        if [ -z "$DC" ]; then
            if [ -n "$COMPOSE_VERSION" ]; then
                info "Gefunden: Docker Compose $COMPOSE_VERSION - gebraucht wird Version 2."
            else
                info "Docker Compose Version 2 fehlt."
            fi

            if can_elevate && [ -n "$PKG_MANAGER" ] \
               && offer_install "Soll ich Docker Compose Version 2 installieren?"; then
                pkg_install docker-compose-plugin >/dev/null 2>&1 \
                    || pkg_install docker-compose >/dev/null 2>&1 \
                    || true

                COMPOSE_VERSION="$(version_of "$(compose_version)")"
                if version_at_least "$COMPOSE_VERSION" "2.0"; then
                    DC="docker compose"
                    ok "Docker Compose $COMPOSE_VERSION installiert"
                else
                    note_missing "Docker Compose Version 2 konnte nicht installiert werden.
      Debian/Ubuntu:  sudo apt install docker-compose-plugin
      Prüfen mit:     docker compose version"
                fi
            else
                note_missing "Docker Compose Version 2 fehlt.
      Debian/Ubuntu:  sudo apt install docker-compose-plugin
      Prüfen mit:     docker compose version"
            fi
        fi
    else
        DC="docker compose"
        ok "Docker Compose $COMPOSE_VERSION"
    fi
fi

if [ -n "$MISSING" ]; then
    die "Es fehlt noch etwas:
${MISSING}

  Bitte erledigen und install.sh erneut aufrufen."
fi

# --- Läuft der Dienst, und darf ich ihn steuern? --------------------------
if ! docker info >/dev/null 2>&1; then
    # Frisch installiert? Dann greift die Gruppenmitgliedschaft erst nach
    # einer Neuanmeldung - fuer diesen Durchlauf hilft sudo.
    if [ -n "$SUDO" ] && $SUDO docker info >/dev/null 2>&1; then
        DOCKER_SUDO="$SUDO"
        DC="$SUDO $DC"
        ok "Docker läuft"
        dim "Für diesen Durchlauf mit sudo. Nach der nächsten Anmeldung"
        dim "geht docker auch ohne."
    else
        if command -v systemctl >/dev/null 2>&1 && [ -n "$SUDO" ] \
           && confirm "Docker antwortet nicht. Soll ich den Dienst starten?"; then
            $SUDO systemctl enable --now docker >/dev/null 2>&1 || true
        fi

        if docker info >/dev/null 2>&1; then
            ok "Docker läuft"
        elif [ -n "$SUDO" ] && $SUDO docker info >/dev/null 2>&1; then
            DOCKER_SUDO="$SUDO"
            DC="$SUDO $DC"
            ok "Docker läuft (mit sudo)"
        else
            die "Docker ist installiert, antwortet aber nicht.

  Dienst starten:  sudo systemctl start docker
  Rechte fehlen?   sudo usermod -aG docker \"\$(id -un)\"   (danach neu anmelden)
  Oder einfach:    sudo ./install.sh"
        fi
    fi
else
    ok "Docker läuft"
fi

# --- 1b. Projekt holen, falls es noch nicht da ist -------------------------
#
# Beim Aufruf per "curl ... | bash" gibt es hier noch keine Dateien. Dann
# wird das Repository geklont (oder ein vorhandener Klon aktualisiert) und
# das Skript von dort neu gestartet.

if ! is_project_dir "$ROOT"; then
    if [ "${TC_BOOTSTRAPPED:-0}" = "1" ]; then
        die "Das Projekt wurde geholt, ist aber nicht auffindbar. Bitte melden."
    fi

    step "Projekt herunterladen"

    if [ -z "$TARGET_DIR" ]; then
        dim "Hierhin wird installiert. Der Ordner darf noch nicht existieren"
        dim "oder muss ein früherer Klon dieses Projekts sein."
        TARGET_DIR="$(ask 'Zielordner' "$DEFAULT_DIR")"
    fi

    case "$TARGET_DIR" in
        /*) : ;;
        *) TARGET_DIR="$(pwd)/$TARGET_DIR" ;;
    esac

    if [ -d "$TARGET_DIR/.git" ]; then
        # Schon vorhanden: aktualisieren statt neu klonen.
        info "Vorhandene Installation in $TARGET_DIR gefunden."
        sync_repo "$TARGET_DIR" || true
    elif [ -e "$TARGET_DIR" ] && [ -n "$(ls -A "$TARGET_DIR" 2>/dev/null)" ]; then
        die "Der Ordner $TARGET_DIR ist nicht leer und enthält kein Projekt.
  Bitte einen anderen Ordner wählen:  --dir /pfad/zum/ordner"
    else
        info "Hole $REPO_REF von $REPO_URL"
        git clone --depth 1 --branch "$REPO_REF" "$REPO_URL" "$TARGET_DIR" 2>/dev/null \
            || git clone "$REPO_URL" "$TARGET_DIR" \
            || die "Klonen fehlgeschlagen. Ist das Repository öffentlich erreichbar?
  Getestet werden kann es mit:  git clone $REPO_URL"

        # --branch nimmt keine Commit-IDs; dann nachträglich wechseln.
        if ! git -C "$TARGET_DIR" rev-parse --verify --quiet "HEAD" >/dev/null; then
            die "Der Klon ist unbrauchbar."
        fi
        git -C "$TARGET_DIR" checkout --quiet "$REPO_REF" 2>/dev/null || true

        ok "Nach $TARGET_DIR geholt"
    fi

    is_project_dir "$TARGET_DIR" \
        || die "In $TARGET_DIR fehlen Projektdateien - stimmt --ref \"$REPO_REF\"?"

    step "Einrichtung fortsetzen"
    info "Weiter geht es mit $TARGET_DIR/install.sh"

    # Die Bootstrap-Argumente sind erledigt und werden nicht weitergegeben:
    # sie waeren nicht nur ueberfluessig, sondern wuerden auch scheitern,
    # wenn ein aelterer Stand geholt wurde, der sie noch nicht kennt.
    FORWARD_ARGS=()
    skip_next=0
    for arg in ${ORIGINAL_ARGS[@]+"${ORIGINAL_ARGS[@]}"}; do
        if [ "$skip_next" = "1" ]; then
            skip_next=0
            continue
        fi
        case "$arg" in
            --dir|--ref|--repo) skip_next=1 ;;
            *) FORWARD_ARGS+=("$arg") ;;
        esac
    done

    export TC_BOOTSTRAPPED=1
    cd "$TARGET_DIR"
    exec bash "$TARGET_DIR/install.sh" ${FORWARD_ARGS[@]+"${FORWARD_ARGS[@]}"}
fi

# --- 1c. Vorhandene Installation aktualisieren -----------------------------
#
# Wir laufen hier bereits im Projektordner. Ohne diesen Schritt waere ein
# erneuter Aufruf von install.sh wirkungslos - es wuerde nur die
# Konfiguration pruefen und die Container neu starten, aber keinen neuen
# Code holen.

if [ "$NO_PULL" = "0" ] && [ -d "$ROOT/.git" ]; then
    step "Nach Updates sehen"
    sync_repo "$ROOT" || true

    # Nach dem Aktualisieren mit der neuen Fassung weiterarbeiten - sonst
    # laeuft die alte Datei weiter und spaetere Schritte passen womoeglich
    # nicht zum neuen Stand.
    if [ "$REPO_UPDATED" = "1" ] && [ "${TC_RELOADED:-0}" != "1" ]; then
        info "Starte mit der neuen Fassung neu."

        export TC_RELOADED=1
        exec bash "$ROOT/install.sh" --no-pull ${ORIGINAL_ARGS[@]+"${ORIGINAL_ARGS[@]}"}
    fi
fi

[ -f "$ENV_EXAMPLE" ] || die ".env.example fehlt - liegt install.sh im richtigen Ordner?
  Erwartet wurde: $ENV_EXAMPLE"

# --- 2. Vorhandene Installation? ------------------------------------------

FRESH=1
if [ -f "$ENV_FILE" ]; then
    FRESH=0
    info "Es gibt schon eine Einrichtung - bestehende Angaben bleiben erhalten."
else
    cp "$ENV_EXAMPLE" "$ENV_FILE"
    chmod 600 "$ENV_FILE"
fi

# --- 3. Domain -------------------------------------------------------------

step "Unter welcher Adresse soll es laufen?"

if [ -z "$DOMAIN" ]; then
    CURRENT_DOMAIN="$(env_get APP_DOMAIN)"
    case "$CURRENT_DOMAIN" in
        ''|twitch.example.com) CURRENT_DOMAIN='' ;;
    esac

    dim "Die Adresse, die du später im Browser eingibst - ohne https://"
    dim "Beispiel: twitch.deinedomain.de"
    DOMAIN="$(ask 'Domain' "$CURRENT_DOMAIN")"
fi

DOMAIN="${DOMAIN#http://}"
DOMAIN="${DOMAIN#https://}"
DOMAIN="${DOMAIN%%/*}"
DOMAIN="$(printf '%s' "$DOMAIN" | tr -d '[:space:]' | tr '[:upper:]' '[:lower:]')"

[ -n "$DOMAIN" ] || die "Ohne Domain geht es nicht: Twitch verlangt für Anmeldung und
  Events eine per HTTPS erreichbare Adresse."

case "$DOMAIN" in
    *.*) : ;;
    *) die "\"$DOMAIN\" sieht nicht wie eine Domain aus. Erwartet wird etwas wie
  twitch.deinedomain.de" ;;
esac

env_set APP_DOMAIN "$DOMAIN"
env_set APP_URL "https://$DOMAIN"
ok "https://$DOMAIN"

# Zeigt die Domain wirklich hierher? Das ist der haeufigste Grund,
# warum es hinterher nicht klappt - also lieber jetzt sagen.
if command -v curl >/dev/null 2>&1; then
    DOMAIN_IP=''
    if command -v getent >/dev/null 2>&1; then
        DOMAIN_IP="$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1; exit}')"
    fi
    if [ -z "$DOMAIN_IP" ] && command -v dig >/dev/null 2>&1; then
        DOMAIN_IP="$(dig +short A "$DOMAIN" 2>/dev/null | head -n1)"
    fi

    SERVER_IP="$(curl -s --max-time 5 https://api.ipify.org 2>/dev/null || true)"

    if [ -z "$DOMAIN_IP" ]; then
        warn "Die Domain lässt sich noch nicht auflösen."
        dim "Fehlt vielleicht der DNS-Eintrag, oder er ist noch nicht verteilt."
        dim "Das darf jetzt so sein - HTTPS klappt aber erst danach."
    elif [ -n "$SERVER_IP" ] && [ "$DOMAIN_IP" != "$SERVER_IP" ]; then
        warn "Die Domain zeigt auf $DOMAIN_IP, dieser Server hat $SERVER_IP."
        dim "Falls ein Dienst wie Cloudflare davor hängt, ist das in Ordnung."
        dim "Sonst muss der DNS-Eintrag (A-Record) auf $SERVER_IP zeigen."
    else
        ok "Die Domain zeigt auf diesen Server"
    fi
fi

# --- 4. HTTPS --------------------------------------------------------------

step "Wie soll HTTPS eingerichtet werden?"

# Laeuft hier schon ein Reverse Proxy? Dann ist Port 80/443 belegt und
# "auto" wuerde scheitern.
DETECTED_NAME=''
DETECTED_NET=''
PROXY_LINE="$($DOCKER_SUDO docker ps --format '{{.ID}}|{{.Image}}|{{.Names}}' 2>/dev/null \
    | grep -Ei 'nginx-proxy-manager|nginxproxymanager|jc21/nginx|traefik|caddy|swag' \
    | grep -v 'twitch-controller-caddy' | head -n1 || true)"

if [ -n "$PROXY_LINE" ]; then
    PROXY_ID="$(printf '%s' "$PROXY_LINE" | cut -d'|' -f1)"
    DETECTED_NAME="$(printf '%s' "$PROXY_LINE" | cut -d'|' -f3)"
    DETECTED_NET="$($DOCKER_SUDO docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$PROXY_ID" 2>/dev/null \
        | tr ' ' '\n' | grep -vE '^(bridge|host|none)?$' | head -n1 || true)"
fi

if [ -z "$PROXY_MODE" ]; then
    if [ "$FRESH" = "0" ] && [ -n "$(env_get COMPOSE_FILE)" ] \
       && [ "$(env_get COMPOSE_FILE)" != "docker-compose.yaml" ]; then
        PROXY_MODE='npm'
        info "Wie bei der letzten Einrichtung: vorhandener Reverse Proxy."
    else
        DEFAULT_CHOICE=1
        if [ -n "$DETECTED_NAME" ]; then
            info "Auf diesem Server läuft schon ein Programm, das HTTPS verteilt:"
            info "\"$DETECTED_NAME\" - vermutlich dein Nginx Proxy Manager."
            DEFAULT_CHOICE=2
        elif port_in_use 443 || port_in_use 80; then
            warn "Port 80 oder 443 ist schon belegt."
            DEFAULT_CHOICE=2
        fi
        printf '\n'

        SELECTED="$(choose "$DEFAULT_CHOICE" \
            "Selbst einrichten - Zertifikat wird automatisch geholt" \
            "Über das schon vorhandene Programm laufen lassen")"

        case "$SELECTED" in
            1) PROXY_MODE='auto' ;;
            *) PROXY_MODE='npm' ;;
        esac
    fi
fi

case "$PROXY_MODE" in
    auto)
        env_set COMPOSE_PROFILES caddy
        env_set COMPOSE_FILE docker-compose.yaml

        if [ -z "$EMAIL" ]; then
            CURRENT_EMAIL="$(env_get ACME_EMAIL)"
            printf '\n'
            dim "Optional: An diese Adresse warnt Let's Encrypt, falls die"
            dim "Verlängerung des Zertifikats mal scheitert. Leer ist auch ok."
            EMAIL="$(ask 'E-Mail (Enter = keine)' "$CURRENT_EMAIL")"
        fi
        env_set ACME_EMAIL "$EMAIL"

        ok "HTTPS wird automatisch eingerichtet"
        dim "Dafür müssen Port 80 und 443 frei sein und die Domain hierher zeigen."
        ;;
    npm)
        env_set COMPOSE_PROFILES ''
        env_set COMPOSE_FILE 'docker-compose.yaml:docker-compose.npm.yaml'

        # Netzwerknamen niemals erfragen - selbst herausfinden.
        if [ -z "$PROXY_NET" ]; then
            PROXY_NET="$(env_get PROXY_NETWORK)"
        fi

        if [ -z "$PROXY_NET" ] && [ -n "$DETECTED_NET" ]; then
            PROXY_NET="$DETECTED_NET"
            ok "Verbindung zu \"$DETECTED_NAME\" wird hergestellt"
            dim "(gemeinsames Docker-Netz: $PROXY_NET)"
        fi

        if [ -z "$PROXY_NET" ]; then
            # Nichts gefunden: Standardnetz anlegen und klar sagen, was
            # der Nutzer im Proxy tun muss.
            PROXY_NET='proxy'
            warn "Ich konnte nicht erkennen, wie dein Proxy angebunden ist."
            dim "Ich lege ein Netz namens \"proxy\" an. Dein Proxy muss diesem"
            dim "Netz beitreten, sonst findet er die Anwendung nicht."
        fi

        if ! $DOCKER_SUDO docker network inspect "$PROXY_NET" >/dev/null 2>&1; then
            $DOCKER_SUDO docker network create "$PROXY_NET" >/dev/null
            ok "Netz \"$PROXY_NET\" angelegt"
        fi

        env_set PROXY_NETWORK "$PROXY_NET"
        ;;
    *)
        die "--proxy erwartet \"auto\" oder \"npm\", bekommen: \"$PROXY_MODE\""
        ;;
esac

# --- 5. Port für den Webcontainer -----------------------------------------

WEB_BIND="$(env_get WEB_BIND)"
[ -n "$WEB_BIND" ] || WEB_BIND='127.0.0.1:2300'
WEB_PORT="${WEB_BIND##*:}"

if port_in_use "$WEB_PORT"; then
    NEW_PORT="$WEB_PORT"
    for _ in 1 2 3 4 5 6 7 8 9 10; do
        NEW_PORT=$((NEW_PORT + 1))
        port_in_use "$NEW_PORT" || break
    done
    WEB_BIND="${WEB_BIND%:*}:$NEW_PORT"
    dim "Interner Port $WEB_PORT war belegt, nehme $NEW_PORT."
fi

env_set WEB_BIND "$WEB_BIND"

# --- 6. Schlüssel und Passwörter ------------------------------------------

step "Schlüssel und Passwörter"

if [ -n "$(env_get APP_KEY)" ]; then
    ok "Vorhandener Sicherheitsschlüssel bleibt unverändert"
else
    env_set APP_KEY "$(random_hex 32)"
    ok "Sicherheitsschlüssel erzeugt"
fi

if [ -n "$(env_get DB_PASS)" ]; then
    ok "Vorhandenes Datenbank-Passwort bleibt unverändert"
else
    env_set DB_PASS "$(random_hex 24)"
    ok "Datenbank-Passwort erzeugt"
fi

[ -n "$(env_get DB_HOST)" ] || env_set DB_HOST db
# Datenbank und Benutzer heissen weiter "overlays". Das ist kein
# Ueberrest: sie heissen so in bestehenden Installationen, und ein
# Umbenennen waere keine Umbenennung, sondern eine Datenmigration.
[ -n "$(env_get DB_NAME)" ] || env_set DB_NAME overlays
[ -n "$(env_get DB_USER)" ] || env_set DB_USER overlays

chmod 600 "$ENV_FILE"
dim "Beides steht in der Datei .env und ist nur für dich lesbar."

# --- 7. Verzeichnisse ------------------------------------------------------

mkdir -p "$ROOT/plugins" "$ROOT/public/uploads" "$ROOT/pgdata"

# Apache im Container laeuft als www-data (UID 33) und muss in plugins/
# und uploads/ schreiben koennen, damit Plugins und Medien installierbar
# sind.
if [ "$(id -u)" = "0" ]; then
    chown -R 33:33 "$ROOT/plugins" "$ROOT/public/uploads" 2>/dev/null || true
else
    chmod -R a+rwX "$ROOT/plugins" "$ROOT/public/uploads" 2>/dev/null || true
fi

# --- 8. Bauen und starten --------------------------------------------------

if [ "$DO_START" = "0" ]; then
    step "Vorbereitet, aber noch nicht gestartet"
    info "Starten mit:  $DC up -d --build"
    exit 0
fi

# Das Compose-Projekt hiess einmal "overlays". Nach der Umbenennung
# gilt "twitch-controller" - Docker haelt das fuer ein voellig anderes
# Projekt und laesst die alten Container stehen. Die halten dann Port 80
# und den Netz-Alias fest, und der neue Start scheitert. Oder, schlimmer,
# beide laufen und es ist Zufall, wen der Proxy erwischt.
#
# Die Daten liegen in ./pgdata und ./public/uploads, also im
# Projektordner - beim Abraeumen der Container geht nichts verloren.
if $DC -p overlays ps -q 2>/dev/null | grep -q .; then
    step "Alten Stand abräumen"
    info "Vor der Umbenennung hiess das Projekt \"overlays\". Dessen Container"
    info "werden gestoppt und entfernt. Datenbank und Uploads bleiben liegen."
    $DC -p overlays down --remove-orphans || true
    ok "Alter Stand abgeräumt"
fi

step "Container bauen und starten"
info "Beim ersten Mal dauert das ein paar Minuten."
printf '\n'

if ! $DC up -d --build --remove-orphans; then
    die "Der Start ist fehlgeschlagen. Die Ursache steht meist direkt darüber.
  Sonst nachsehen mit:  $DC logs"
fi

step "Warten, bis alles läuft"

DB_READY=0
for _ in $(seq 1 60); do
    if $DC exec -T db pg_isready -q >/dev/null 2>&1; then
        DB_READY=1
        break
    fi
    sleep 2
done

if [ "$DB_READY" = "1" ]; then
    ok "Datenbank läuft"
else
    warn "Die Datenbank meldet sich nicht. Nachsehen mit:  $DC logs db"
fi

if command -v curl >/dev/null 2>&1; then
    APP_READY=0
    for _ in $(seq 1 15); do
        STATUS="$(curl -s -o /dev/null -w '%{http_code}' "http://${WEB_BIND}/setup" 2>/dev/null || echo 000)"
        case "$STATUS" in
            200|302) APP_READY=1; break ;;
        esac
        sleep 2
    done

    if [ "$APP_READY" = "1" ]; then
        ok "Anwendung antwortet"
    else
        warn "Die Anwendung antwortet noch nicht. Nachsehen mit:  $DC logs web"
    fi
fi

# --- 9. Anleitung für den Rest --------------------------------------------

printf '\n%s========================================================%s\n' "$C_OK" "$C_RESET"
printf '%s  Fertig. Jetzt fehlen noch zwei Schritte.%s\n' "$C_BOLD" "$C_RESET"
printf '%s========================================================%s\n' "$C_OK" "$C_RESET"

SCHRITT=1

if [ "$PROXY_MODE" = "npm" ]; then
    printf '\n%sSchritt %s: Im Nginx Proxy Manager eintragen%s\n\n' "$C_BOLD" "$SCHRITT" "$C_RESET"
    printf '  Dort "Proxy Hosts" öffnen, "Add Proxy Host", und eintragen:\n\n'
    printf '    Domain Names      %s%s%s\n' "$C_BOLD" "$DOMAIN" "$C_RESET"
    printf '    Scheme            http\n'
    printf '    Forward Hostname  %soverlays%s\n' "$C_BOLD" "$C_RESET"
    printf '    Forward Port      %s80%s\n\n' "$C_BOLD" "$C_RESET"
    printf '  Im Reiter "SSL": "Request a new SSL Certificate" anhaken,\n'
    printf '  dazu "Force SSL". Speichern.\n'
    SCHRITT=$((SCHRITT + 1))
fi

printf '\n%sSchritt %s: Twitch-Anwendung anlegen%s\n\n' "$C_BOLD" "$SCHRITT" "$C_RESET"
printf '  1. Öffne  https://dev.twitch.tv/console/apps/create\n'
printf '  2. Name        frei wählbar, z.B. "Twitch-Controller"\n'
printf '  3. OAuth Redirect URL - genau das, ohne Leerzeichen:\n\n'
printf '     %shttps://%s/auth/callback%s\n\n' "$C_BOLD" "$DOMAIN" "$C_RESET"
printf '  4. Category    "Website Integration"\n'
printf '  5. Anlegen, dann "New Secret" klicken\n'
printf '  6. Client-ID und Secret bereithalten\n'
SCHRITT=$((SCHRITT + 1))

printf '\n%sSchritt %s: Im Browser öffnen%s\n\n' "$C_BOLD" "$SCHRITT" "$C_RESET"
printf '     %shttps://%s%s\n\n' "$C_BOLD" "$DOMAIN" "$C_RESET"
printf '  Dort führt dich die Einrichtung durch den Rest. Der erste\n'
printf '  Twitch-Login wird automatisch der Besitzer dieser Installation.\n'

printf '\n%sWenn etwas nicht geht:%s\n' "$C_BOLD" "$C_RESET"
printf '    %s logs -f web       zeigt, was der Webserver macht\n' "$DC"
printf '    %s restart           startet alles neu\n' "$DC"
printf '    ./install.sh          kann jederzeit erneut laufen\n'

printf '\n%sWichtig für Backups: Die Datei .env enthält den Schlüssel, mit dem\n' "$C_DIM"
printf 'die Twitch-Zugangsdaten verschlüsselt sind. Ohne sie ist ein\n'
printf 'Backup der Datenbank nicht wiederherstellbar.%s\n\n' "$C_RESET"
