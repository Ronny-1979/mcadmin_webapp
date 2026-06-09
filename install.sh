#!/bin/bash
# ============================================================
#  Minecraft Bedrock Admin Panel
#  github.com/Ronny-1979/mcadmin
#
#  Installation:
#    curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin/main/install.sh | sudo bash
#
#  Update (nur Panel-Dateien, Server läuft weiter):
#    curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin/main/install.sh | sudo bash -s -- --update
#
#  Deinstallation:
#    curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin/main/install.sh | sudo bash -s -- --uninstall
# ============================================================

set -euo pipefail

# ── Konfiguration ─────────────────────────────────────────────
GITHUB_USER="Ronny-1979"
GITHUB_REPO="mcadmin"
GITHUB_BRANCH="main"
GITHUB_ZIP="https://github.com/${GITHUB_USER}/${GITHUB_REPO}/archive/refs/heads/${GITHUB_BRANCH}.zip"

PANEL_DIR="/var/www/html/mcadmin"
MC_DIR="/opt/minecraft-bedrock"
SERVICE_NAME="minecraft-bedrock"
WEB_PORT=80
HTTPS_PORT=443
MC_PORT_UDP=19132
MC_PORT_UDP6=19133
LOG_FILE="/var/log/mcadmin-install.log"
VERSION_FILE="${PANEL_DIR}/.mcadmin_version"

# ── Farben ────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
info() { echo -e "  ${CYAN}→${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC} $1"; }
err()  { echo -e "\n  ${RED}✗ FEHLER:${NC} $1\n"; exit 1; }
hdr()  { echo -e "\n${BOLD}${BLUE}[$1]${NC} $2"; }
ask()  { local ans; printf "  ${BOLD}%s${NC} [j/N] " "$1" >/dev/tty; read -r ans </dev/tty 2>/dev/null || true; [[ "${ans,,}" =~ ^(j|ja|y|yes)$ ]]; }

# ── Root-Check ────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && err "Bitte als root ausführen:\ncurl -fsSL https://raw.githubusercontent.com/${GITHUB_USER}/${GITHUB_REPO}/main/install.sh | sudo bash"

# ── Modus ─────────────────────────────────────────────────────
MODE="install"
for arg in "$@"; do
    case "$arg" in
        --update)    MODE="update" ;;
        --uninstall) MODE="uninstall" ;;
        --help|-h)
            echo "Verwendung:"
            echo "  sudo bash install.sh              # Installation"
            echo "  sudo bash install.sh --update     # Panel aktualisieren"
            echo "  sudo bash install.sh --uninstall  # Deinstallieren"
            exit 0 ;;
    esac
done

# ── OS erkennen ───────────────────────────────────────────────
detect_os() {
    if [ -f /etc/os-release ]; then . /etc/os-release; OS_ID="${ID,,}"; OS_FAMILY="${ID_LIKE:-}"; OS_FAMILY="${OS_FAMILY,,}"; else OS_ID="unknown"; OS_FAMILY=""; fi
    if [[ "$OS_ID" =~ ^(ubuntu|debian|raspbian|linuxmint|pop)$ ]] || [[ "$OS_FAMILY" =~ debian ]]; then
        OS_TYPE="debian"; PKG_MGR="apt-get"
        PKG_INSTALL="apt-get install -y -q"; PKG_REMOVE="apt-get remove -y"; PKG_UPDATE="apt-get update -q"
        WEB_USER="www-data"; APACHE_SERVICE="apache2"
        APACHE_CONF_DIR="/etc/apache2/sites-available"; USE_A2ENSITE=true
        APACHE_ERROR_LOG="\${APACHE_LOG_DIR}/mcadmin-error.log"
        APACHE_ACCESS_LOG="\${APACHE_LOG_DIR}/mcadmin-access.log"
        APACHE_SSL_ERROR_LOG="\${APACHE_LOG_DIR}/mcadmin-ssl-error.log"
        APACHE_SSL_ACCESS_LOG="\${APACHE_LOG_DIR}/mcadmin-ssl-access.log"
    elif [[ "$OS_ID" =~ ^(centos|rhel|almalinux|rocky|fedora|ol)$ ]] || [[ "$OS_FAMILY" =~ rhel|fedora ]]; then
        OS_TYPE="rhel"; command -v dnf &>/dev/null && PKG_MGR="dnf" || PKG_MGR="yum"
        PKG_INSTALL="$PKG_MGR install -y -q"; PKG_REMOVE="$PKG_MGR remove -y"; PKG_UPDATE="$PKG_MGR makecache -q"
        WEB_USER="apache"; APACHE_SERVICE="httpd"
        APACHE_CONF_DIR="/etc/httpd/conf.d"; USE_A2ENSITE=false
        APACHE_ERROR_LOG="/var/log/httpd/mcadmin-error.log"
        APACHE_ACCESS_LOG="/var/log/httpd/mcadmin-access.log"
        APACHE_SSL_ERROR_LOG="/var/log/httpd/mcadmin-ssl-error.log"
        APACHE_SSL_ACCESS_LOG="/var/log/httpd/mcadmin-ssl-access.log"
    elif [[ "$OS_ID" == "arch" ]] || [[ "$OS_FAMILY" =~ arch ]]; then
        OS_TYPE="arch"; PKG_MGR="pacman"
        PKG_INSTALL="pacman -S --noconfirm --needed"; PKG_REMOVE="pacman -R --noconfirm"; PKG_UPDATE="pacman -Sy"
        WEB_USER="http"; APACHE_SERVICE="httpd"
        APACHE_CONF_DIR="/etc/httpd/conf.d"; USE_A2ENSITE=false
        APACHE_ERROR_LOG="/var/log/httpd/mcadmin-error.log"
        APACHE_ACCESS_LOG="/var/log/httpd/mcadmin-access.log"
        APACHE_SSL_ERROR_LOG="/var/log/httpd/mcadmin-ssl-error.log"
        APACHE_SSL_ACCESS_LOG="/var/log/httpd/mcadmin-ssl-access.log"
    else
        warn "Unbekanntes OS — verwende Debian-Modus"
        OS_TYPE="debian"; PKG_MGR="apt-get"
        PKG_INSTALL="apt-get install -y -q"; PKG_REMOVE="apt-get remove -y"; PKG_UPDATE="apt-get update -q"
        WEB_USER="www-data"; APACHE_SERVICE="apache2"
        APACHE_CONF_DIR="/etc/apache2/sites-available"; USE_A2ENSITE=true
        APACHE_ERROR_LOG="\${APACHE_LOG_DIR}/mcadmin-error.log"
        APACHE_ACCESS_LOG="\${APACHE_LOG_DIR}/mcadmin-access.log"
        APACHE_SSL_ERROR_LOG="\${APACHE_LOG_DIR}/mcadmin-ssl-error.log"
        APACHE_SSL_ACCESS_LOG="\${APACHE_LOG_DIR}/mcadmin-ssl-access.log"
    fi
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.2")
}

# Gibt dem Webserver-User Leserechte auf systemd-journal.
# Wichtig für das Live-Log im WebIF, besonders auf Debian 13 / systemd-Systemen.
# Apache/httpd muss danach neu gestartet werden, damit neue Gruppenrechte greifen.
JOURNAL_ACCESS_CHANGED=false
ensure_web_user_journal_access() {
    JOURNAL_ACCESS_CHANGED=false

    if ! command -v journalctl >/dev/null 2>&1; then
        warn "journalctl nicht gefunden — Live-Log nutzt nur vorhandene Logdateien"
        return 0
    fi

    if ! id "${WEB_USER}" >/dev/null 2>&1; then
        warn "Web-User ${WEB_USER} existiert noch nicht — Journal-Rechte werden übersprungen"
        return 0
    fi

    local groups_to_try=(systemd-journal adm)
    local grp

    for grp in "${groups_to_try[@]}"; do
        if getent group "$grp" >/dev/null 2>&1; then
            if id -nG "${WEB_USER}" 2>/dev/null | tr ' ' '\n' | grep -qx "$grp"; then
                ok "Journal-Zugriff für ${WEB_USER} bereits vorhanden (Gruppe: ${grp})"
                return 0
            fi

            if usermod -aG "$grp" "${WEB_USER}" >/dev/null 2>&1; then
                JOURNAL_ACCESS_CHANGED=true
                ok "Journal-Zugriff für ${WEB_USER} eingerichtet (Gruppe: ${grp})"
                return 0
            fi
        fi
    done

    warn "Konnte ${WEB_USER} keiner Journal-Gruppe hinzufügen — Live-Log kann ggf. leer bleiben"
}

# ════════════════════════════════════════════════════════════════
#  MODUS: DEINSTALLATION
# ════════════════════════════════════════════════════════════════
if [ "$MODE" = "uninstall" ]; then
    clear
    echo -e "${BOLD}"
    echo "  ╔══════════════════════════════════════════════════════╗"
    echo "  ║      ⛏  Minecraft Bedrock Admin Panel               ║"
    echo "  ║         Deinstallation                               ║"
    echo "  ╚══════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    warn "Diese Aktion entfernt das Admin-Panel und alle zugehörigen Dateien."
    echo ""
    ask "Wirklich deinstallieren?" || { echo "  Abgebrochen."; exit 0; }
    echo ""
    detect_os

    hdr "1/6" "Minecraft Server + alle Prozesse stoppen"
    systemctl stop    "${SERVICE_NAME}" 2>/dev/null || true
    systemctl disable "${SERVICE_NAME}" 2>/dev/null || true
    pkill -f bedrock_server 2>/dev/null || true
    screen -S minecraft -X quit 2>/dev/null || true
    tmux kill-session -t minecraft 2>/dev/null || true
    if command -v fuser &>/dev/null; then
        fuser -k 19132/udp 2>/dev/null || true
        fuser -k 19133/udp 2>/dev/null || true
    fi
    sleep 1
    ok "Minecraft Server gestoppt und Ports freigegeben"

    hdr "2/6" "systemd Service entfernen"
    rm -f "/etc/systemd/system/${SERVICE_NAME}.service"
    systemctl daemon-reload
    ok "Service-Datei entfernt"

    rm -f /etc/cron.d/mcadmin-backup
    rm -f /var/log/mcadmin-cron.log
    ok "Backup-Cron entfernt"

    hdr "3/6" "sudo-Regeln entfernen"
    rm -f "/etc/sudoers.d/minecraft-admin" && ok "sudo-Regeln entfernt" || ok "Keine sudo-Regeln gefunden"

    hdr "4/6" "Apache-Konfiguration & SSL entfernen"
    $USE_A2ENSITE && { a2dissite mcadmin.conf >/dev/null 2>&1 || true; a2dissite mcadmin-ssl.conf >/dev/null 2>&1 || true; }
    rm -f "${APACHE_CONF_DIR}/mcadmin.conf" "${APACHE_CONF_DIR}/mcadmin-ssl.conf"
    ok "Apache VirtualHost entfernt"

    hdr "5/6" "Firewall-Regeln entfernen"
    if command -v ufw &>/dev/null && ufw status 2>/dev/null | grep -q "active"; then
        ufw delete allow "${MC_PORT_UDP}/udp" >/dev/null 2>&1 && ok "UFW MC-Port entfernt" || true
        ufw delete allow "${WEB_PORT}/tcp"    >/dev/null 2>&1 || true
        ufw delete allow "${HTTPS_PORT}/tcp"  >/dev/null 2>&1 && ok "UFW HTTPS-Port entfernt" || true
    elif command -v firewall-cmd &>/dev/null && systemctl is-active --quiet firewalld 2>/dev/null; then
        firewall-cmd --remove-port=${MC_PORT_UDP}/udp --permanent >/dev/null 2>&1 || true
        firewall-cmd --remove-service=http --permanent >/dev/null 2>&1 || true
        firewall-cmd --remove-service=https --permanent >/dev/null 2>&1 || true
        firewall-cmd --reload >/dev/null 2>&1 && ok "firewalld-Regeln entfernt" || true
    fi
    rm -f /tmp/mcadmin* /tmp/bedrock-server-*.zip 2>/dev/null || true
    ok "Temporäre Dateien entfernt"

    hdr "6/6" "Verzeichnisse & Abhängigkeiten"
    echo ""
    if [ -d "${PANEL_DIR}" ]; then
        warn "Panel-Verzeichnis enthält möglicherweise Backups!"
        if ask "Panel-Verzeichnis ${PANEL_DIR} löschen (inkl. Backups)?"; then
            rm -rf "${PANEL_DIR}" && ok "${PANEL_DIR} entfernt"
        else
            ok "${PANEL_DIR} behalten"
        fi
    fi
    if [ -d "${MC_DIR}" ]; then
        warn "Minecraft-Verzeichnis enthält Server-Daten und Welten!"
        if ask "Minecraft-Verzeichnis ${MC_DIR} löschen (ALLE Welten gehen verloren!)?"; then
            rm -rf "${MC_DIR}" && ok "${MC_DIR} entfernt"
        else
            ok "${MC_DIR} behalten"
        fi
    fi
    if command -v certbot &>/dev/null; then
        if ask "Let's Encrypt Zertifikat widerrufen und Certbot entfernen?"; then
            certbot delete --non-interactive 2>/dev/null || true
            $PKG_REMOVE certbot python3-certbot-apache >/dev/null 2>&1 || true
            ok "Certbot entfernt"
        fi
    fi
    echo ""
    if $USE_A2ENSITE; then
        echo -e "    apache2, php${PHP_VER}, libapache2-mod-php${PHP_VER}, php-extensions, unzip, wget, screen"
        if ask "Apache & PHP deinstallieren?"; then
            systemctl stop "${APACHE_SERVICE}" 2>/dev/null || true
            systemctl disable "${APACHE_SERVICE}" 2>/dev/null || true
            $PKG_REMOVE apache2 "php${PHP_VER}" "libapache2-mod-php${PHP_VER}" \
                "php${PHP_VER}-zip" "php${PHP_VER}-json" "php${PHP_VER}-curl" \
                "php${PHP_VER}-mbstring" >/dev/null 2>&1 && ok "Apache & PHP entfernt" || warn "Teilweise entfernt"
        fi
        if ask "Hilfsprogramme (screen, wget) entfernen?"; then
            $PKG_REMOVE screen wget >/dev/null 2>&1 && ok "Hilfsprogramme entfernt" || true
        fi
    else
        if ask "Apache (httpd) & PHP deinstallieren?"; then
            systemctl stop "${APACHE_SERVICE}" 2>/dev/null || true
            $PKG_REMOVE httpd php php-zip php-json php-curl php-mbstring >/dev/null 2>&1 && ok "Apache & PHP entfernt" || true
        fi
    fi
    systemctl restart "${APACHE_SERVICE}" 2>/dev/null || true
    ask "Installations-Log ${LOG_FILE} löschen?" && rm -f "$LOG_FILE" && ok "Log gelöscht" || true

    echo ""
    echo -e "  ${BOLD}Prüfe verbleibende Prozesse und Ports...${NC}"
    LEFTOVER=false
    if pgrep -f bedrock_server >/dev/null 2>&1; then
        warn "Noch laufend: bedrock_server — manuell beenden mit: pkill -f bedrock_server"
        LEFTOVER=true
    fi
    if command -v fuser &>/dev/null; then
        for _PORT in 19132 19133; do
            _PROCS=$(fuser ${_PORT}/udp 2>/dev/null || true)
            [ -n "$_PROCS" ] && warn "Port ${_PORT}/udp noch belegt durch PID: ${_PROCS}" && LEFTOVER=true
        done
    fi
    $LEFTOVER || ok "Alle Ports und Prozesse sauber"

    echo ""
    echo -e "${GREEN}${BOLD}  ✅ Deinstallation abgeschlossen!${NC}"
    echo ""
    exit 0
fi

# ════════════════════════════════════════════════════════════════
#  MODUS: UPDATE
# ════════════════════════════════════════════════════════════════
if [ "$MODE" = "update" ]; then
    clear
    echo -e "${BOLD}"
    echo "  ╔══════════════════════════════════════════════════════╗"
    echo "  ║      ⛏  Minecraft Bedrock Admin Panel — Update      ║"
    echo "  ╚══════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    exec > >(tee -a "$LOG_FILE") 2>&1
    detect_os

    CURRENT_VER="unbekannt"; [ -f "$VERSION_FILE" ] && CURRENT_VER=$(cat "$VERSION_FILE")
    info "Prüfe neueste Version auf GitHub..."
    LATEST_VER=$(curl -fsSL "https://api.github.com/repos/${GITHUB_USER}/${GITHUB_REPO}/commits/${GITHUB_BRANCH}" 2>/dev/null \
        | grep '"sha"' | head -1 | cut -d'"' -f4 | cut -c1-7 || echo "unbekannt")
    echo ""
    echo -e "  Installiert: ${YELLOW}${CURRENT_VER}${NC}"
    echo -e "  GitHub:      ${GREEN}${LATEST_VER}${NC}"
    echo ""
    if [ "$CURRENT_VER" = "$LATEST_VER" ] && [ "$CURRENT_VER" != "unbekannt" ]; then
        echo -e "  ${GREEN}✓ Panel ist bereits aktuell!${NC}"
        # Im Web-Modus (Flag-Datei vorhanden) immer fortfahren, sonst interaktiv fragen
        if [ ! -f /tmp/mcadmin_yes ]; then
            ask "Trotzdem neu installieren?" || exit 0
        fi
    fi

    hdr "1/4" "Dateien von GitHub laden"
    TMP_ZIP="/tmp/mcadmin_update.zip"; TMP_DIR="/tmp/mcadmin_update_extract"
    rm -rf "$TMP_DIR" "$TMP_ZIP"
    info "Lade ${GITHUB_USER}/${GITHUB_REPO}@${GITHUB_BRANCH}..."
    command -v wget &>/dev/null && wget -q -O "$TMP_ZIP" "$GITHUB_ZIP" || curl -fsSL -o "$TMP_ZIP" "$GITHUB_ZIP"
    [ -s "$TMP_ZIP" ] || err "Download fehlgeschlagen"
    ok "Download abgeschlossen"

    hdr "2/4" "Entpacken"
    mkdir -p "$TMP_DIR"; unzip -q "$TMP_ZIP" -d "$TMP_DIR"
    EXTRACTED=$(find "$TMP_DIR" -maxdepth 1 -type d -name "${GITHUB_REPO}-*" | head -1)
    [ -d "$EXTRACTED" ] || err "Entpacktes Verzeichnis nicht gefunden"

    hdr "3/4" "Panel-Dateien aktualisieren"
    CONFIG_BACKUP="/tmp/mcadmin_config_backup.php"
    [ -f "${PANEL_DIR}/config.php" ] && cp "${PANEL_DIR}/config.php" "$CONFIG_BACKUP"
    SETTINGS_BACKUP="/tmp/mcadmin_settings_backup.json"
    [ -f "${PANEL_DIR}/mcadmin_settings.json" ] && cp "${PANEL_DIR}/mcadmin_settings.json" "$SETTINGS_BACKUP"

    cp -r "${EXTRACTED}/mcadmin/"* "${PANEL_DIR}/" 2>/dev/null || err "Kopieren fehlgeschlagen"
    rm -f "${PANEL_DIR}/install.sh"

    [ -f "$CONFIG_BACKUP" ] && {
        OLD_DIR=$(grep -o "'/opt/[^']*'" "$CONFIG_BACKUP" 2>/dev/null | head -1 | tr -d "'")
        if [ "$OLD_DIR" = "/opt/minecraft-bedrock" ] || [ -z "$OLD_DIR" ]; then
            # Standard-Serverpfad → neue config.php von GitHub behalten (enthält alle Fixes)
            rm -f "$CONFIG_BACKUP"
            ok "config.php aktualisiert (Standard-Pfad — alle Code-Fixes aktiv)"
        else
            # Benutzerdefinierter Pfad → alte config.php wiederherstellen
            cp "$CONFIG_BACKUP" "${PANEL_DIR}/config.php"; rm -f "$CONFIG_BACKUP"
            warn "config.php wiederhergestellt (Benutzerpfad: ${OLD_DIR})"
        fi
    }
    [ -f "$SETTINGS_BACKUP" ] && { cp "$SETTINGS_BACKUP" "${PANEL_DIR}/mcadmin_settings.json"; rm -f "$SETTINGS_BACKUP"; ok "mcadmin_settings.json (Passwort/Discord) wiederhergestellt"; }

    chown -R ${WEB_USER}:${WEB_USER} "${PANEL_DIR}"
    chmod -R 750 "${PANEL_DIR}"
    chmod 770 "${PANEL_DIR}/backups" "${PANEL_DIR}/uploads" 2>/dev/null || true
    echo "$LATEST_VER" > "$VERSION_FILE"
    rm -rf "$TMP_DIR" "$TMP_ZIP"

    hdr "3b/4" "Python3 plyvel prüfen (LevelDB für Spawn-Erkennung)"
    if command -v python3 &>/dev/null; then
        if python3 -c "import plyvel" 2>/dev/null; then
            ok "plyvel bereits installiert"
        else
            info "Installiere plyvel..."
            if command -v pip3 &>/dev/null; then
                pip3 install --quiet plyvel >/dev/null 2>&1 \
                    && ok "plyvel installiert" \
                    || warn "plyvel nicht verfügbar — Spawn-Erkennung nutzt Fallback"
            elif command -v pip &>/dev/null; then
                pip install --quiet plyvel >/dev/null 2>&1 \
                    && ok "plyvel installiert" \
                    || warn "plyvel nicht verfügbar — Spawn-Erkennung nutzt Fallback"
            else
                warn "pip nicht gefunden — plyvel übersprungen"
            fi
        fi
    else
        warn "Python3 nicht gefunden — Spawn-Erkennung nutzt Fallback"
    fi

    hdr "4/4" "Service-Datei migrieren + Update-Skript + Apache neu laden"
    PANEL_UPDATE_SCRIPT="/usr/local/sbin/mcadmin-panel-update.sh"
    cat > "$PANEL_UPDATE_SCRIPT" << 'PANEL_SCRIPT_EOF'
#!/bin/bash
exec curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin/main/install.sh | bash -s -- --update
PANEL_SCRIPT_EOF
    chmod 755 "$PANEL_UPDATE_SCRIPT"
    SVC_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
    if [ -f "$SVC_FILE" ] && grep -q 'KillMode=mixed' "$SVC_FILE"; then
        sed -i 's/KillMode=mixed/KillMode=control-group/' "$SVC_FILE"
        systemctl daemon-reload
        ok "Service-Datei migriert: KillMode=control-group (Port-Fix)"
    fi
    ensure_web_user_journal_access
    if $JOURNAL_ACCESS_CHANGED; then
        systemctl restart "${APACHE_SERVICE}" 2>/dev/null || true
        ok "Apache neu gestartet — neue Journal-Rechte aktiv"
    else
        systemctl reload "${APACHE_SERVICE}" 2>/dev/null || systemctl restart "${APACHE_SERVICE}" 2>/dev/null
        ok "Apache neu geladen — Minecraft-Server läuft weiter"
    fi

    # Cron-Eintrag und Log-Datei sicherstellen (auch nach Panel-Updates)
    echo "* * * * * ${WEB_USER} /usr/bin/php ${PANEL_DIR}/cron.php >> /var/log/mcadmin-cron.log 2>&1" \
        > /etc/cron.d/mcadmin-backup
    chmod 644 /etc/cron.d/mcadmin-backup
    touch /var/log/mcadmin-cron.log
    chown "${WEB_USER}:${WEB_USER}" /var/log/mcadmin-cron.log
    chmod 640 /var/log/mcadmin-cron.log
    ok "Backup-Cron sichergestellt (Cron-Eintrag + Log-Datei)"

    SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
    echo ""
    echo -e "${GREEN}${BOLD}  ✅ Panel aktualisiert!${NC}"
    echo -e "  Version: ${CURRENT_VER} → ${LATEST_VER}"
    echo -e "  Panel: ${CYAN}http://${SERVER_IP}/mcadmin/${NC}"
    echo ""
    exit 0
fi

# ════════════════════════════════════════════════════════════════
#  MODUS: INSTALLATION
# ════════════════════════════════════════════════════════════════
clear
echo -e "${BOLD}"
echo "  ╔══════════════════════════════════════════════════════╗"
echo "  ║      ⛏  Minecraft Bedrock Admin Panel               ║"
echo "  ║         Vollautomatische Installation                ║"
echo "  ║         github.com/Ronny-1979/mcadmin               ║"
echo "  ╚══════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo "  Log: $LOG_FILE"
echo ""
exec > >(tee -a "$LOG_FILE") 2>&1

# ── 1. OS erkennen ────────────────────────────────────────────
hdr "1/12" "Betriebssystem erkennen"
detect_os
ok "OS: ${PRETTY_NAME:-$OS_ID} (${OS_TYPE}, Web-User: ${WEB_USER})"

# ── 2. Pakete aktualisieren ───────────────────────────────────
hdr "2/12" "Paketlisten aktualisieren"
$PKG_UPDATE >/dev/null 2>&1 && ok "Paketlisten aktuell" || warn "Update schlug fehl"

# ── 3. Apache + PHP installieren ──────────────────────────────
hdr "3/12" "Apache & PHP installieren"

install_debian_packages() {
    export DEBIAN_FRONTEND=noninteractive

    info "Installiere/aktualisiere Apache, PHP und Hilfsprogramme aus den offiziellen Paketquellen..."

    # Wichtig:
    # Keine harte PHP-Version erzwingen.
    # Debian/Ubuntu/Raspberry Pi OS liefern über diese Meta-Pakete automatisch
    # die aktuelle Standard-PHP-Version der Distribution:
    # Debian 12 → PHP 8.2, Debian 13 → PHP 8.4, Ubuntu → Ubuntu-Standard.
    DEBIAN_PACKAGES=(
        apache2
        php
        php-cli
        libapache2-mod-php
        php-zip
        php-curl
        php-mbstring
        php-xml
        unzip
        wget
        curl
        tar
        screen
        jq
        cron
        sudo
        python3
    )

    if ! DEBIAN_FRONTEND=noninteractive $PKG_INSTALL "${DEBIAN_PACKAGES[@]}" >>"$LOG_FILE" 2>&1; then
        err "Apache/PHP-Installation fehlgeschlagen — Details: $LOG_FILE"
    fi

    if ! command -v php >/dev/null 2>&1; then
        err "PHP wurde nicht installiert — Details: $LOG_FILE"
    fi

    _PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
    if [ "${_PHP_MAJOR:-0}" -lt 8 ]; then
        err "Installierte PHP-Version ist zu alt: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo '?') — mindestens PHP 8 erforderlich"
    fi

    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "")
    [ -n "$PHP_VER" ] || err "PHP-Version konnte nicht ermittelt werden"

    ok "Apache2 + PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "$PHP_VER") bereit"

    # Python3 + plyvel für LevelDB-Spawn-Erkennung beim Welt-Import.
    # Erst Distribution-Paket nutzen, danach pip als Fallback.
    if ! python3 -c "import plyvel" 2>/dev/null; then
        info "Installiere plyvel (LevelDB-Zugriff für Spawn-Erkennung)..."
        if apt-cache show python3-plyvel >/dev/null 2>&1; then
            DEBIAN_FRONTEND=noninteractive $PKG_INSTALL python3-plyvel >>"$LOG_FILE" 2>&1 || true
        fi

        if ! python3 -c "import plyvel" 2>/dev/null; then
            DEBIAN_FRONTEND=noninteractive $PKG_INSTALL python3-pip libleveldb-dev python3-dev build-essential >>"$LOG_FILE" 2>&1 || true
            pip3 install --quiet plyvel >>"$LOG_FILE" 2>&1 \
                || pip3 install --quiet --break-system-packages plyvel >>"$LOG_FILE" 2>&1 \
                && ok "plyvel installiert (pip)" \
                || warn "plyvel nicht verfügbar — Spawn-Erkennung nutzt Fallback"
        else
            ok "plyvel installiert (Paket)"
        fi
    else
        ok "Python3 + plyvel bereits vorhanden"
    fi
}
install_rhel_packages() {
    info "Installiere/aktualisiere Apache, PHP und Hilfsprogramme aus den offiziellen Paketquellen..."

    # Auf RHEL/Fedora zuerst die vorhandenen Distributionspakete nutzen.
    # Falls RHEL 8/9 nur einen zu alten PHP-Stream aktiv hat, wird automatisch
    # der höchste verfügbare 8.x-Modulstream aktiviert.
    RHEL_BASE_PACKAGES=(
        httpd
        php
        php-cli
        php-zip
        php-curl
        php-mbstring
        php-xml
        php-json
        unzip
        wget
        curl
        tar
        screen
        jq
        sudo
        python3
    )

    $PKG_INSTALL "${RHEL_BASE_PACKAGES[@]}" >>"$LOG_FILE" 2>&1 || true

    _PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
    if [ "${_PHP_MAJOR:-0}" -lt 8 ] && command -v dnf >/dev/null 2>&1; then
        info "Aktiviere neuesten verfügbaren PHP-8-Modulstream..."
        dnf module reset php -y >>"$LOG_FILE" 2>&1 || true

        _PHP_STREAM=""
        for stream in 8.4 8.3 8.2 8.1 8.0; do
            if dnf module list php 2>/dev/null | grep -Eq "php[[:space:]]+${stream}([[:space:]]|$)"; then
                _PHP_STREAM="$stream"
                break
            fi
        done

        if [ -n "$_PHP_STREAM" ]; then
            dnf module enable "php:${_PHP_STREAM}" -y >>"$LOG_FILE" 2>&1 || true
            $PKG_INSTALL php php-cli php-zip php-curl php-mbstring php-xml php-json >>"$LOG_FILE" 2>&1 || true
        fi
    fi

    if ! command -v php >/dev/null 2>&1; then
        err "PHP wurde nicht installiert — Details: $LOG_FILE"
    fi

    _PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
    if [ "${_PHP_MAJOR:-0}" -lt 8 ]; then
        err "Installierte PHP-Version ist zu alt: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo '?') — mindestens PHP 8 erforderlich"
    fi

    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "")
    ok "Apache + PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "$PHP_VER") bereit"

    # Python3 + plyvel (optional)
    if ! python3 -c "import plyvel" 2>/dev/null; then
        info "Installiere plyvel (LevelDB-Zugriff für Spawn-Erkennung)..."
        $PKG_INSTALL python3-pip leveldb-devel python3-devel gcc gcc-c++ make >>"$LOG_FILE" 2>&1 || true
        (pip3 install --quiet plyvel >>"$LOG_FILE" 2>&1 \
            || pip3 install --quiet --break-system-packages plyvel >>"$LOG_FILE" 2>&1) \
            && ok "plyvel installiert (pip)" \
            || warn "plyvel nicht verfügbar — Spawn-Erkennung nutzt Fallback"
    else
        ok "Python3 + plyvel bereits vorhanden"
    fi
}
install_arch_packages() {
    info "Installiere/aktualisiere Apache, PHP und Hilfsprogramme aus den offiziellen Arch-Paketquellen..."

    ARCH_PACKAGES=(
        apache
        php
        php-apache
        php-zip
        unzip
        wget
        curl
        tar
        screen
        jq
        sudo
        python
    )

    $PKG_INSTALL "${ARCH_PACKAGES[@]}" >>"$LOG_FILE" 2>&1 \
        || err "Apache/PHP-Installation fehlgeschlagen — Details: $LOG_FILE"

    if ! command -v php >/dev/null 2>&1; then
        err "PHP wurde nicht installiert — Details: $LOG_FILE"
    fi

    _PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
    if [ "${_PHP_MAJOR:-0}" -lt 8 ]; then
        err "Installierte PHP-Version ist zu alt: $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo '?') — mindestens PHP 8 erforderlich"
    fi

    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "")
    sed -i 's/;extension=zip/extension=zip/' /etc/php/php.ini 2>/dev/null || true

    # Apache-PHP-Modul sicherstellen, ohne bestehende httpd.conf hart zu überschreiben.
    if [ -f /etc/httpd/conf/httpd.conf ] && ! grep -q 'php_module' /etc/httpd/conf/httpd.conf; then
        cat >> /etc/httpd/conf/httpd.conf <<'ARCH_PHP_EOF'

# PHP für MC Bedrock Admin Panel
LoadModule php_module modules/libphp.so
AddHandler php-script .php
Include conf/extra/php_module.conf
ARCH_PHP_EOF
        ok "Apache PHP-Modul ergänzt"
    fi

    if ! python3 -c "import plyvel" 2>/dev/null; then
        info "Installiere plyvel (LevelDB-Zugriff für Spawn-Erkennung)..."
        if pacman -Ss '^python-plyvel$' >/dev/null 2>&1; then
            $PKG_INSTALL python-plyvel >>"$LOG_FILE" 2>&1 || true
        fi
        if ! python3 -c "import plyvel" 2>/dev/null; then
            $PKG_INSTALL python-pip leveldb base-devel >>"$LOG_FILE" 2>&1 || true
            pip install --quiet plyvel >>"$LOG_FILE" 2>&1 \
                && ok "plyvel installiert (pip)" \
                || warn "plyvel nicht verfügbar — Spawn-Erkennung nutzt Fallback"
        else
            ok "plyvel installiert (Paket)"
        fi
    else
        ok "Python3 + plyvel bereits vorhanden"
    fi

    ok "Apache + PHP $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo "$PHP_VER") bereit"
}
case $OS_TYPE in debian) install_debian_packages ;; rhel) install_rhel_packages ;; arch) install_arch_packages ;; esac
_PHP_CLI="php${PHP_VER}"
command -v "$_PHP_CLI" &>/dev/null || _PHP_CLI="php"
ok "PHP Version: $($_PHP_CLI -r 'echo PHP_VERSION;' 2>/dev/null || echo '?')"

# ── 4. Apache Basis-Konfiguration ─────────────────────────────
hdr "4/12" "Apache konfigurieren"

mkdir -p "${PANEL_DIR}/backups" "${PANEL_DIR}/uploads"

cat > "${APACHE_CONF_DIR}/mcadmin.conf" << APACHEEOF
<VirtualHost *:${WEB_PORT}>
    ServerName mcadmin.local
    DocumentRoot ${PANEL_DIR}
    <Directory ${PANEL_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory ${PANEL_DIR}/backups>
        Require all denied
    </Directory>
    <Directory ${PANEL_DIR}/uploads>
        Require all denied
    </Directory>
    ErrorLog ${APACHE_ERROR_LOG}
    CustomLog ${APACHE_ACCESS_LOG} combined
</VirtualHost>
APACHEEOF

if $USE_A2ENSITE; then
    for _old_php in 5.6 7.0 7.1 7.2 7.3 7.4; do a2dismod "php${_old_php}" &>/dev/null 2>&1 || true; done
    a2enmod rewrite "php${PHP_VER}" >/dev/null 2>&1 || true
    a2ensite mcadmin.conf >/dev/null 2>&1 || true
    a2dissite 000-default.conf >/dev/null 2>&1 || true
fi
cat > "${PANEL_DIR}/backups/.htaccess" << 'EOF'
Require all denied
EOF
cat > "${PANEL_DIR}/uploads/.htaccess" << 'EOF'
Require all denied
EOF
ok "Apache VirtualHost konfiguriert"

# ── 5. Panel von GitHub laden ─────────────────────────────────
hdr "5/12" "Panel-Dateien von GitHub laden"

TMP_ZIP="/tmp/mcadmin_download.zip"; TMP_DIR="/tmp/mcadmin_extract"
rm -rf "$TMP_DIR" "$TMP_ZIP"
info "Lade ${GITHUB_USER}/${GITHUB_REPO}@${GITHUB_BRANCH}..."
command -v wget &>/dev/null && wget -q -O "$TMP_ZIP" "$GITHUB_ZIP" || curl -fsSL -o "$TMP_ZIP" "$GITHUB_ZIP"
[ -s "$TMP_ZIP" ] || err "Download fehlgeschlagen — Internetverbindung prüfen"
ok "Download abgeschlossen ($(du -sh $TMP_ZIP | cut -f1))"

mkdir -p "$TMP_DIR"; unzip -q "$TMP_ZIP" -d "$TMP_DIR"
EXTRACTED=$(find "$TMP_DIR" -maxdepth 1 -type d -name "${GITHUB_REPO}-*" | head -1)
[ -d "$EXTRACTED" ] || err "Entpacktes Verzeichnis nicht gefunden"

cp -r "${EXTRACTED}/mcadmin/"* "${PANEL_DIR}/" 2>/dev/null || err "Kopieren fehlgeschlagen"
rm -f "${PANEL_DIR}/install.sh"
rm -rf "$TMP_DIR" "$TMP_ZIP"

INSTALL_VER=$(curl -fsSL "https://api.github.com/repos/${GITHUB_USER}/${GITHUB_REPO}/commits/${GITHUB_BRANCH}" 2>/dev/null \
    | grep '"sha"' | head -1 | cut -d'"' -f4 | cut -c1-7 || echo "unknown")
echo "$INSTALL_VER" > "$VERSION_FILE"
ok "Panel-Dateien installiert (Version: ${INSTALL_VER})"

# ── 6. Minecraft Verzeichnis ──────────────────────────────────
hdr "6/12" "Minecraft Server Verzeichnis"

mkdir -p "${MC_DIR}"/{worlds,behavior_packs,resource_packs,logs}
if [ ! -f "${MC_DIR}/server.properties" ]; then
cat > "${MC_DIR}/server.properties" << 'EOF'
server-name=Mein Minecraft Server
gamemode=survival
difficulty=easy
allow-cheats=false
max-players=10
online-mode=true
white-list=false
server-port=19132
server-portv6=19133
view-distance=32
tick-distance=4
player-idle-timeout=30
max-threads=8
level-name=Bedrock level
level-seed=
default-player-permission-level=member
texturepack-required=false
content-log-file-enabled=false
compression-threshold=1
server-authoritative-movement=server-auth
server-authoritative-block-breaking=false
correct-player-movement=false
EOF
    ok "server.properties Vorlage erstellt"
fi
[ ! -f "${MC_DIR}/whitelist.json" ]   && echo "[]" > "${MC_DIR}/whitelist.json"
[ ! -f "${MC_DIR}/permissions.json" ] && echo "[]" > "${MC_DIR}/permissions.json"
chown -R ${WEB_USER}:${WEB_USER} "${MC_DIR}" "${PANEL_DIR}"
chmod -R 750 "${PANEL_DIR}"; chmod 770 "${PANEL_DIR}/backups" "${PANEL_DIR}/uploads"
chmod 664 "${MC_DIR}/whitelist.json" "${MC_DIR}/permissions.json" "${MC_DIR}/server.properties" 2>/dev/null || true
ok "Verzeichnis & Berechtigungen gesetzt"

# ── 7. Minecraft Bedrock Server herunterladen ─────────────────
hdr "7/12" "Minecraft Bedrock Server"

MC_VER_INSTALLED=false
MC_DL_URL=""
MC_VERSION=""

# Bereits installiert? → nur Version anzeigen, kein erneuter Download
if [ -f "${MC_DIR}/bedrock_server" ]; then
    MC_VERSION=$(cat "${MC_DIR}/version.txt" 2>/dev/null) || true
    [ -z "$MC_VERSION" ] && MC_VERSION="unbekannt"
    ok "Minecraft Server bereits installiert (v${MC_VERSION}) — wird übersprungen"
    MC_VER_INSTALLED=true
else
    info "Ermittle neueste Bedrock-Version..."

    # jq (in Schritt 3 installiert) parst die offizielle Mojang-API zuverlässig
    MC_DL_URL=$(curl -s --max-time 15 -A "Mozilla/5.0" \
        "https://net-secondary.web.minecraft-services.net/api/v1.0/download/links" \
        | jq -r '.result.links[] | select(.downloadType=="serverBedrockLinux") | .downloadUrl' \
        2>/dev/null) || true

    if [ -z "$MC_DL_URL" ]; then
        warn "Bedrock-Server konnte nicht ermittelt werden."
        warn "Bitte nach der Installation im Panel unter 'Updates' manuell herunterladen."
    else
        MC_VERSION=$(echo "$MC_DL_URL" | grep -oP 'bedrock-server-\K[0-9.]+(?=\.zip)') || true
        info "Lade Bedrock Server v${MC_VERSION} herunter..."

        _MC_TMP="/tmp/bedrock-server-${MC_VERSION}.zip"
        _MC_EXTRACT="/tmp/mc_extract_$$"

        # Download — gleiche Logik wie run_update_async im Panel
        wget -q -U "Mozilla/5.0" -O "$_MC_TMP" "$MC_DL_URL" 2>/dev/null \
            || curl -sL -A "Mozilla/5.0" -o "$_MC_TMP" "$MC_DL_URL" 2>/dev/null \
            || true

        if [ -f "$_MC_TMP" ] && [ -s "$_MC_TMP" ]; then
            info "Entpacke Server..."
            mkdir -p "$_MC_EXTRACT"
            unzip -q "$_MC_TMP" -d "$_MC_EXTRACT" || true

            for f in "$_MC_EXTRACT"/*; do
                base="$(basename "$f")"
                case "$base" in
                    worlds|server.properties|whitelist.json|permissions.json) ;;
                    *) cp -r "$f" "${MC_DIR}/" ;;
                esac
            done

            chmod +x "${MC_DIR}/bedrock_server" 2>/dev/null || true
            chown -R "${WEB_USER}:${WEB_USER}" "${MC_DIR}"
            echo "$MC_VERSION" > "${MC_DIR}/version.txt"

            rm -rf "$_MC_EXTRACT" "$_MC_TMP"
            ok "Bedrock Server v${MC_VERSION} installiert"
            MC_VER_INSTALLED=true
        else
            warn "Download fehlgeschlagen. Bitte im Panel unter 'Updates' manuell herunterladen."
            rm -f "$_MC_TMP" 2>/dev/null || true
        fi
    fi
fi

# ── 8. systemd Service ────────────────────────────────────────
hdr "8/12" "systemd Service"

cat > "/etc/systemd/system/${SERVICE_NAME}.service" << SVCEOF
[Unit]
Description=Minecraft Bedrock Server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${WEB_USER}
Group=${WEB_USER}
WorkingDirectory=${MC_DIR}
ExecStart=/bin/bash -c 'rm -f ${MC_DIR}/server.stdin; mkfifo ${MC_DIR}/server.stdin; exec ${MC_DIR}/bedrock_server <>${MC_DIR}/server.stdin'
Restart=on-failure
RestartSec=15
StandardOutput=journal
StandardError=journal
SyslogIdentifier=minecraft-bedrock
LimitNOFILE=65536
KillSignal=SIGTERM
KillMode=control-group
TimeoutStopSec=30
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
SVCEOF
systemctl daemon-reload
systemctl enable "${SERVICE_NAME}" >/dev/null 2>&1 || true
ok "Service '${SERVICE_NAME}' registriert"
ensure_web_user_journal_access

pkill -f bedrock_server 2>/dev/null || true
screen -S minecraft -X quit 2>/dev/null || true
tmux kill-session -t minecraft 2>/dev/null || true
if command -v fuser &>/dev/null; then
    fuser -k 19132/udp 2>/dev/null || true
    fuser -k 19133/udp 2>/dev/null || true
fi
sleep 1

# ── 8. sudo-Rechte ────────────────────────────────────────────
hdr "9/12" "sudo-Rechte"

SYSTEMCTL_PATH=$(which systemctl)
PANEL_UPDATE_SCRIPT="/usr/local/sbin/mcadmin-panel-update.sh"
cat > "$PANEL_UPDATE_SCRIPT" << 'PANEL_SCRIPT_EOF'
#!/bin/bash
exec curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin/main/install.sh | bash -s -- --update
PANEL_SCRIPT_EOF
chmod 755 "$PANEL_UPDATE_SCRIPT"
cat > "/etc/sudoers.d/minecraft-admin" << SUDOEOF
# MC Bedrock Admin Panel | github.com/Ronny-1979/mcadmin
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} start ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} stop ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} restart ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} status ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} is-active ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: /bin/bash ${PANEL_UPDATE_SCRIPT}
SUDOEOF
chmod 440 "/etc/sudoers.d/minecraft-admin"
visudo -c -f "/etc/sudoers.d/minecraft-admin" >/dev/null 2>&1 && ok "sudo-Regeln gesetzt" || {
    warn "sudo Syntax-Fehler — entfernt"; rm -f "/etc/sudoers.d/minecraft-admin"
}

# ── 9. Let's Encrypt / HTTPS ──────────────────────────────────
hdr "10/12" "Let's Encrypt (HTTPS)"
echo ""
LETSENCRYPT_DONE=false
DOMAIN=""

if ask "Möchtest du HTTPS mit Let's Encrypt einrichten?"; then
    echo ""
    echo -e "  ${YELLOW}Hinweise:${NC}"
    echo -e "  • Du brauchst eine echte Domain (z.B. mc.meinserver.de)"
    echo -e "  • Die Domain muss auf diese Server-IP zeigen (DNS A-Record)"
    echo -e "  • Port 80 muss kurz für die Challenge erreichbar sein"
    echo ""

    while true; do
        printf "  ${BOLD}Domain eingeben (z.B. mc.meinserver.de):${NC} " >/dev/tty; read -r DOMAIN </dev/tty 2>/dev/null || true
        DOMAIN=$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed 's|https\?://||; s|/.*||')
        [ -n "$DOMAIN" ] && break
        warn "Bitte eine Domain eingeben"
    done

    printf "  ${BOLD}E-Mail für Let's Encrypt (Ablauf-Benachrichtigungen):${NC} " >/dev/tty; read -r LE_EMAIL </dev/tty 2>/dev/null || true
    [ -z "$LE_EMAIL" ] && LE_EMAIL="admin@${DOMAIN}"

    echo ""
    if command -v certbot &>/dev/null; then
        ok "Certbot bereits installiert ($(certbot --version 2>&1 | head -1))"
    else
        info "Installiere Certbot..."
        if $USE_A2ENSITE; then
            $PKG_INSTALL certbot python3-certbot-apache >/dev/null 2>&1
        elif [ "$OS_TYPE" = "rhel" ]; then
            $PKG_INSTALL certbot python3-certbot-apache >/dev/null 2>&1 || \
            { $PKG_INSTALL snapd >/dev/null 2>&1; snap install --classic certbot >/dev/null 2>&1; ln -sf /snap/bin/certbot /usr/bin/certbot 2>/dev/null || true; }
        else
            $PKG_INSTALL certbot >/dev/null 2>&1
        fi
    fi

    if ! command -v certbot &>/dev/null; then
        warn "Certbot konnte nicht installiert werden — überspringe HTTPS"
    else
        ok "Certbot installiert"

        sed -i "s|ServerName mcadmin.local|ServerName ${DOMAIN}|g" "${APACHE_CONF_DIR}/mcadmin.conf"

        UFW_HAD_80=false
        if command -v ufw &>/dev/null && ufw status 2>/dev/null | grep -q "active"; then
            if ! ufw status | grep -q "80/tcp"; then
                info "Öffne Port 80 kurz für Let's Encrypt Challenge..."
                ufw allow 80/tcp >/dev/null 2>&1
                UFW_HAD_80=true
            fi
        fi
        if command -v firewall-cmd &>/dev/null && systemctl is-active --quiet firewalld 2>/dev/null; then
            firewall-cmd --add-service=http --permanent >/dev/null 2>&1
            firewall-cmd --reload >/dev/null 2>&1
        fi

        systemctl start "${APACHE_SERVICE}" >/dev/null 2>&1 || true

        info "Beantrage Let's Encrypt Zertifikat für ${DOMAIN}..."
        if certbot --apache \
            -d "${DOMAIN}" \
            --email "${LE_EMAIL}" \
            --agree-tos \
            --non-interactive \
            --redirect 2>&1 | tee -a "$LOG_FILE"; then

            ok "SSL-Zertifikat erfolgreich ausgestellt!"
            LETSENCRYPT_DONE=true

            SSL_CONF="${APACHE_CONF_DIR}/mcadmin-le-ssl.conf"
            [ ! -f "$SSL_CONF" ] && SSL_CONF=$(find /etc/apache2/sites-available/ /etc/httpd/conf.d/ -name "*${DOMAIN}*ssl*" -o -name "*ssl*${DOMAIN}*" 2>/dev/null | head -1)

            CERT_DIR="/etc/letsencrypt/live/${DOMAIN}"

            cat > "${APACHE_CONF_DIR}/mcadmin-ssl.conf" << SSLEOF
<VirtualHost *:${HTTPS_PORT}>
    ServerName ${DOMAIN}
    DocumentRoot ${PANEL_DIR}

    SSLEngine on
    SSLCertificateFile     ${CERT_DIR}/fullchain.pem
    SSLCertificateKeyFile  ${CERT_DIR}/privkey.pem

    <Directory ${PANEL_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <Directory ${PANEL_DIR}/backups>
        Require all denied
    </Directory>
    <Directory ${PANEL_DIR}/uploads>
        Require all denied
    </Directory>

    Header always set Strict-Transport-Security "max-age=31536000"

    ErrorLog ${APACHE_SSL_ERROR_LOG}
    CustomLog ${APACHE_SSL_ACCESS_LOG} combined
</VirtualHost>

# HTTP → HTTPS Weiterleitung
<VirtualHost *:${WEB_PORT}>
    ServerName ${DOMAIN}
    RewriteEngine On
    RewriteRule ^(.*)$ https://${DOMAIN}\$1 [R=301,L]
</VirtualHost>
SSLEOF

            $USE_A2ENSITE && { a2enmod ssl headers rewrite >/dev/null 2>&1 || true; a2ensite mcadmin-ssl.conf >/dev/null 2>&1 || true; a2dissite mcadmin.conf >/dev/null 2>&1 || true; }

            systemctl enable certbot.timer >/dev/null 2>&1 || true
            ok "Auto-Renewal aktiviert (90-Tage Zertifikat wird automatisch erneuert)"

        else
            warn "Zertifikat-Beantragung fehlgeschlagen"
            warn "Mögliche Ursachen:"
            warn "  • Domain zeigt nicht auf diese IP"
            warn "  • Port 80 von außen nicht erreichbar"
            warn "  • Let's Encrypt Rate-Limit erreicht"
            warn "Panel läuft weiter über HTTP"
        fi

    fi
else
    ok "HTTPS übersprungen — Panel läuft über HTTP"
fi

# ── 10. Firewall konfigurieren ────────────────────────────────
hdr "11/12" "Firewall konfigurieren"

UFW_ACTIVE=false
FIREWALLD_ACTIVE=false
command -v ufw &>/dev/null && ufw status 2>/dev/null | grep -q "Status: active" && UFW_ACTIVE=true || true
command -v ufw &>/dev/null && ! $UFW_ACTIVE && UFW_AVAILABLE=true || UFW_AVAILABLE=false
command -v firewall-cmd &>/dev/null && systemctl is-active --quiet firewalld 2>/dev/null && FIREWALLD_ACTIVE=true || true

configure_ufw() {
    echo ""
    if ask "Minecraft Port ${MC_PORT_UDP}/UDP für Spieler öffnen?"; then
        ufw allow ${MC_PORT_UDP}/udp  >/dev/null 2>&1
        ufw allow ${MC_PORT_UDP6}/udp >/dev/null 2>&1
        ok "UFW: Minecraft Ports geöffnet (${MC_PORT_UDP}/UDP, ${MC_PORT_UDP6}/UDP)"
    fi
    if $LETSENCRYPT_DONE; then
        ufw allow ${HTTPS_PORT}/tcp >/dev/null 2>&1
        ok "UFW: Port ${HTTPS_PORT}/TCP (HTTPS) geöffnet"
        ufw allow ${WEB_PORT}/tcp >/dev/null 2>&1 || true
        ufw delete allow ${WEB_PORT}/tcp >/dev/null 2>&1 && ok "UFW: Port ${WEB_PORT}/TCP geschlossen (HTTPS-Redirect bleibt aktiv)" || true
    else
        echo ""
        warn "Sicherheitshinweis: Port ${WEB_PORT} extern öffnen = Admin-Panel aus dem Internet erreichbar!"
        if ask "Web-Interface Port ${WEB_PORT}/TCP extern öffnen?"; then
            ufw allow ${WEB_PORT}/tcp >/dev/null 2>&1
            ok "UFW: Port ${WEB_PORT}/TCP geöffnet"
            warn "Empfehlung: Starkes Passwort setzen!"
        else
            ok "Port ${WEB_PORT} nicht extern geöffnet (nur LAN)"
        fi
    fi
}

if $UFW_ACTIVE; then
    ok "UFW Firewall ist aktiv"
    configure_ufw
elif $UFW_AVAILABLE; then
    info "UFW ist installiert aber nicht aktiv"
    if ask "UFW aktivieren und Ports konfigurieren?"; then
        ufw allow ssh >/dev/null 2>&1  # SSH zuerst!
        echo "y" | ufw enable >/dev/null 2>&1
        ok "UFW aktiviert"
        configure_ufw
    fi
elif $FIREWALLD_ACTIVE; then
    ok "firewalld ist aktiv"
    if ask "Minecraft Port ${MC_PORT_UDP}/UDP öffnen?"; then
        firewall-cmd --add-port=${MC_PORT_UDP}/udp  --permanent >/dev/null 2>&1
        firewall-cmd --add-port=${MC_PORT_UDP6}/udp --permanent >/dev/null 2>&1
        firewall-cmd --reload >/dev/null 2>&1
        ok "firewalld: Minecraft Ports geöffnet (${MC_PORT_UDP}/UDP, ${MC_PORT_UDP6}/UDP)"
    fi
    if $LETSENCRYPT_DONE; then
        firewall-cmd --add-service=https --permanent >/dev/null 2>&1
        firewall-cmd --remove-service=http --permanent >/dev/null 2>&1
        firewall-cmd --reload >/dev/null 2>&1
        ok "firewalld: Port ${HTTPS_PORT}/TCP (HTTPS) geöffnet, Port ${WEB_PORT}/TCP geschlossen"
    elif ask "Web-Interface Port ${WEB_PORT}/TCP öffnen?"; then
        firewall-cmd --add-service=http --permanent >/dev/null 2>&1
        firewall-cmd --reload >/dev/null 2>&1; ok "firewalld: HTTP geöffnet"
    fi
else
    info "Keine aktive Firewall — keine Port-Konfiguration nötig"
fi

# ── 11. PHP-Limits & Admin-Passwort ──────────────────────────
hdr "12/12" "PHP-Konfiguration"

for phpini in "/etc/php/${PHP_VER}/apache2/php.ini" "/etc/php/8.2/apache2/php.ini" "/etc/php/8.1/apache2/php.ini" "/etc/php/php.ini" "/etc/php.ini"; do
    if [ -f "$phpini" ]; then
        sed -i 's/^upload_max_filesize.*/upload_max_filesize = 512M/' "$phpini"
        sed -i 's/^post_max_size.*/post_max_size = 512M/' "$phpini"
        sed -i 's/^max_execution_time.*/max_execution_time = 300/' "$phpini"
        sed -i 's/^memory_limit.*/memory_limit = 256M/' "$phpini"
        ok "PHP-Limits angepasst: $phpini"; break
    fi
done

CONFIG="${PANEL_DIR}/config.php"
sed -i "s|__DIR__ . '/mcadmin_state.json'|'${PANEL_DIR}/mcadmin_state.json'|g" "$CONFIG" 2>/dev/null || true
sed -i "s|__DIR__ . '/backups'|'${PANEL_DIR}/backups'|g"                        "$CONFIG" 2>/dev/null || true
sed -i "s|__DIR__ . '/uploads'|'${PANEL_DIR}/uploads'|g"                        "$CONFIG" 2>/dev/null || true
sed -i "s|__DIR__ . '/version_cache.json'|'${PANEL_DIR}/version_cache.json'|g"  "$CONFIG" 2>/dev/null || true
sed -i "s|__DIR__ . '/mcadmin_settings.json'|'${PANEL_DIR}/mcadmin_settings.json'|g" "$CONFIG" 2>/dev/null || true
ok "config.php Pfade angepasst"

echo "* * * * * ${WEB_USER} /usr/bin/php ${PANEL_DIR}/cron.php >> /var/log/mcadmin-cron.log 2>&1" \
    > /etc/cron.d/mcadmin-backup
chmod 644 /etc/cron.d/mcadmin-backup
# Log-Datei mit korrektem Eigentümer anlegen, damit www-data hineinschreiben darf.
# Fehlt diese Datei oder gehört sie root, schlägt die Shell-Umleitung (>>) fehl
# und PHP wird gar nicht erst gestartet → kein Backup, kein Heartbeat.
touch /var/log/mcadmin-cron.log
chown "${WEB_USER}:${WEB_USER}" /var/log/mcadmin-cron.log
chmod 640 /var/log/mcadmin-cron.log
# Sicherstellen, dass der Cron-Dienst installiert und aktiv ist.
# Auf Debian 13 (Trixie) ist 'cron' kein Standard-Paket mehr; ohne laufenden
# Cron-Dienst wird /etc/cron.d/mcadmin-backup nie ausgeführt.
if [[ "$OS_TYPE" == "debian" ]]; then
    command -v cron &>/dev/null || DEBIAN_FRONTEND=noninteractive $PKG_INSTALL cron >>"$LOG_FILE" 2>&1
    systemctl enable cron  2>/dev/null || true
    systemctl start  cron  2>/dev/null || true
elif [[ "$OS_TYPE" == "rhel" ]]; then
    command -v crond &>/dev/null || $PKG_INSTALL cronie >>"$LOG_FILE" 2>&1
    systemctl enable --now crond 2>/dev/null || true
elif [[ "$OS_TYPE" == "arch" ]]; then
    command -v crond &>/dev/null || $PKG_INSTALL cronie >>"$LOG_FILE" 2>&1
    systemctl enable --now cronie 2>/dev/null || true
fi
ok "Backup-Cron eingerichtet (prüft minütlich die konfigurierten Einstellungen)"

echo ""
ok "Standard-Zugangsdaten: ${BOLD}admin / admin${NC}"
warn "Bitte direkt nach dem ersten Login unter Einstellungen ändern!"

systemctl enable "${APACHE_SERVICE}" >/dev/null 2>&1
systemctl restart "${APACHE_SERVICE}" 2>&1 && ok "Apache gestartet" || warn "Apache-Fehler"
sleep 1
systemctl is-active --quiet "${APACHE_SERVICE}" && ok "Apache läuft ✓" || warn "Apache läuft möglicherweise nicht"

# ── Server-Start (automatisch) ───────────────────────────────
MC_STARTED=false
if $MC_VER_INSTALLED; then
    info "Starte Minecraft Server..."
    if systemctl start "${SERVICE_NAME}" 2>/dev/null; then
            # Level-Name aus server.properties lesen
            _LEVEL=$(grep '^level-name=' "${MC_DIR}/server.properties" 2>/dev/null | cut -d= -f2)
            _LEVEL="${_LEVEL:-Bedrock level}"
            _WORLD_DIR="${MC_DIR}/worlds/${_LEVEL}"
            info "Warte auf Weltgenerierung ('${_LEVEL}')..."
            # Bis zu 45 Sekunden auf das Welt-Verzeichnis warten
            _waited=0
            while [ ! -d "$_WORLD_DIR" ] && [ "$_waited" -lt 45 ]; do
                sleep 1; _waited=$((_waited+1))
            done
            if [ -d "$_WORLD_DIR" ]; then
                ok "Welt '${_LEVEL}' erstellt ✓"
                MC_STARTED=true
                # mcadmin_state.json mit active_world befüllen damit das Panel sofort stimmt
                printf '{"active_world":"%s","world_packs":{},"world_imported_packs":{}}\n' "${_LEVEL}" \
                    > "${PANEL_DIR}/mcadmin_state.json"
                chown "${WEB_USER}:${WEB_USER}" "${PANEL_DIR}/mcadmin_state.json"
            else
                warn "Welt noch nicht angelegt nach 45s — im Panel prüfen"
                MC_STARTED=true
            fi
            systemctl is-active --quiet "${SERVICE_NAME}" \
                && ok "Minecraft Server läuft ✓" \
                || warn "Server evtl. nicht aktiv — im Panel prüfen"
        else
            warn "Server konnte nicht gestartet werden — im Panel manuell starten"
        fi
fi

# ── Fertig ────────────────────────────────────────────────────
SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
[ -z "$SERVER_IP" ] && SERVER_IP=$(ip route get 1 2>/dev/null | awk '{print $NF;exit}')
[ -z "$SERVER_IP" ] && SERVER_IP="DEINE-SERVER-IP"

echo ""
echo -e "${BOLD}${GREEN}"
echo "  ╔══════════════════════════════════════════════════════╗"
echo "  ║          ✅  Installation abgeschlossen!             ║"
echo "  ╚══════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo -e "  ${BOLD}Panel aufrufen:${NC}"
if $LETSENCRYPT_DONE && [ -n "$DOMAIN" ]; then
    echo -e "    ${CYAN}https://${DOMAIN}/mcadmin/${NC}"
else
    echo -e "    ${CYAN}http://${SERVER_IP}/mcadmin/${NC}"
fi
echo ""
echo -e "  ${BOLD}Standard-Login:${NC}"
echo -e "    Benutzer: ${YELLOW}admin${NC}"
echo -e "    Passwort: ${YELLOW}admin${NC}"
echo -e "    ${RED}⚠ Bitte sofort unter Einstellungen ändern!${NC}"
echo ""
if $MC_VER_INSTALLED; then
    echo -e "  ${BOLD}Minecraft Server:${NC} v${MC_VERSION} ${GREEN}✓ installiert${NC}"
    echo ""
fi
echo -e "  ${BOLD}Nächste Schritte:${NC}"
echo -e "    ${GREEN}1.${NC} Browser öffnen → Panel aufrufen"
echo -e "    ${GREEN}2.${NC} ${BOLD}Einstellungen${NC} → Benutzername & Passwort ändern"
if $MC_VER_INSTALLED; then
    if $MC_STARTED; then
        echo -e "    ${GREEN}3.${NC} Panel öffnen → Welt 'Bedrock level' ist bereit ✓"
        echo -e "    ${GREEN}4.${NC} Mitspieler einladen — fertig! 🎮"
    else
        echo -e "    ${GREEN}3.${NC} Panel → ${BOLD}Server starten${NC} → erste Welt wird automatisch erstellt"
        echo -e "    ${GREEN}4.${NC} Spielen — fertig! 🎮"
    fi
else
    echo -e "    ${GREEN}3.${NC} ${BOLD}Updates${NC} → Bedrock Server herunterladen"
    echo -e "    ${GREEN}4.${NC} Server starten — fertig! 🎮"
fi
echo ""
echo -e "  ${BOLD}Nützliche Befehle:${NC}"
echo -e "    ${CYAN}# Panel aktualisieren:${NC}"
echo -e "    curl -fsSL https://raw.githubusercontent.com/${GITHUB_USER}/${GITHUB_REPO}/main/install.sh | sudo bash -s -- --update"
echo -e "    ${CYAN}# Deinstallieren:${NC}"
echo -e "    curl -fsSL https://raw.githubusercontent.com/${GITHUB_USER}/${GITHUB_REPO}/main/install.sh | sudo bash -s -- --uninstall"
echo -e "    ${CYAN}# Server-Log live:${NC}"
echo -e "    journalctl -u ${SERVICE_NAME} -f"
echo ""
