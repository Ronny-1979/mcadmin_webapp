#!/bin/bash
# ============================================================
#  Minecraft Bedrock Admin Panel + mc-webapp
#  github.com/Ronny-1979/mcadmin_webapp
#
#  Installation (PHP-Panel + React/Node.js-App):
#    curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin_webapp/main/install.sh | sudo bash
#
#  Update (Panel + App, Minecraft-Server läuft weiter):
#    curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin_webapp/main/install.sh | sudo bash -s -- --update
#
#  Deinstallation:
#    curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin_webapp/main/install.sh | sudo bash -s -- --uninstall
# ============================================================

set -euo pipefail

# ── Konfiguration ─────────────────────────────────────────────
GITHUB_USER="Ronny-1979"
GITHUB_REPO="mcadmin_webapp"
GITHUB_BRANCH="main"
GITHUB_ZIP="https://github.com/${GITHUB_USER}/${GITHUB_REPO}/archive/refs/heads/${GITHUB_BRANCH}.zip"

PANEL_DIR="/var/www/html/mcadmin"
WEBAPP_DIR="/opt/mc-webapp"
MC_DIR="/opt/minecraft-bedrock"
SERVICE_NAME="minecraft-bedrock"
WEBAPP_SERVICE="mc-webapp"
WEB_PORT=80
HTTPS_PORT=443
WEBAPP_PORT=3001
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

[[ $EUID -ne 0 ]] && err "Bitte als root ausführen:\ncurl -fsSL https://raw.githubusercontent.com/${GITHUB_USER}/${GITHUB_REPO}/main/install.sh | sudo bash"

# ── Modus ─────────────────────────────────────────────────────
MODE="install"
for arg in "$@"; do
    case "$arg" in
        --update)    MODE="update" ;;
        --uninstall) MODE="uninstall" ;;
        --help|-h)
            echo "Verwendung:"
            echo "  sudo bash install.sh              # Vollinstallation (PHP-Panel + Node.js-App)"
            echo "  sudo bash install.sh --update     # Beide Komponenten aktualisieren"
            echo "  sudo bash install.sh --uninstall  # Alles deinstallieren"
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

JOURNAL_ACCESS_CHANGED=false
ensure_web_user_journal_access() {
    JOURNAL_ACCESS_CHANGED=false
    ! command -v journalctl >/dev/null 2>&1 && { warn "journalctl nicht gefunden"; return 0; }
    ! id "${WEB_USER}" >/dev/null 2>&1 && { warn "Web-User ${WEB_USER} existiert noch nicht"; return 0; }
    local grp
    for grp in systemd-journal adm; do
        if getent group "$grp" >/dev/null 2>&1; then
            if id -nG "${WEB_USER}" 2>/dev/null | tr ' ' '\n' | grep -qx "$grp"; then
                ok "Journal-Zugriff für ${WEB_USER} bereits vorhanden (Gruppe: ${grp})"; return 0
            fi
            if usermod -aG "$grp" "${WEB_USER}" >/dev/null 2>&1; then
                JOURNAL_ACCESS_CHANGED=true; ok "Journal-Zugriff für ${WEB_USER} eingerichtet (Gruppe: ${grp})"; return 0
            fi
        fi
    done
    warn "Konnte ${WEB_USER} keiner Journal-Gruppe hinzufügen"
}

# Node.js installieren/prüfen (>= 18 erforderlich)
install_nodejs() {
    local NODE_OK=false
    if command -v node &>/dev/null; then
        local MAJOR; MAJOR=$(node -e "process.stdout.write(String(process.version.match(/^v(\d+)/)[1]))")
        [[ "$MAJOR" -ge 18 ]] && { ok "Node.js $(node --version) bereits installiert"; NODE_OK=true; }
    fi
    if [[ "$NODE_OK" == false ]]; then
        info "Installiere Node.js 20.x..."
        command -v curl &>/dev/null || $PKG_INSTALL curl >>"$LOG_FILE" 2>&1
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash - >>"$LOG_FILE" 2>&1 || {
            # Fallback für nicht-Debian
            command -v node &>/dev/null || $PKG_INSTALL nodejs npm >>"$LOG_FILE" 2>&1 || true
        }
        $PKG_INSTALL nodejs >>"$LOG_FILE" 2>&1 || true
        command -v node &>/dev/null && ok "Node.js $(node --version) installiert" || warn "Node.js konnte nicht installiert werden — mc-webapp übersprungen"
    fi
}

# mc-webapp installieren/aktualisieren
install_webapp() {
    local EXTRACTED="$1"
    local WEBAPP_SRC="${EXTRACTED}/mc-webapp"
    [ -d "$WEBAPP_SRC" ] || { warn "mc-webapp Verzeichnis nicht im ZIP gefunden — übersprungen"; return 0; }
    ! command -v node &>/dev/null && { warn "Node.js nicht verfügbar — mc-webapp übersprungen"; return 0; }

    mkdir -p "${WEBAPP_DIR}"
    cp -r "${WEBAPP_SRC}/." "${WEBAPP_DIR}/"

    # JWT-Secret generieren falls noch Default
    local CONFIG="${WEBAPP_DIR}/backend/config.js"
    if grep -q "mc-webapp-secret-bitte-aendern" "$CONFIG" 2>/dev/null; then
        local NEW_SECRET; NEW_SECRET=$(node -e "require('crypto').randomBytes(32).toString('hex')" 2>/dev/null || openssl rand -hex 64 | cut -c1-64)
        sed -i "s/mc-webapp-secret-bitte-aendern/${NEW_SECRET}/" "$CONFIG"
        ok "JWT-Secret generiert"
    fi

    info "Installiere Backend-Pakete..."
    # Als root ausführen, Ownership danach setzen — vermeidet sudo-Berechtigungs-Konflikte
    (cd "${WEBAPP_DIR}/backend"  && npm install --omit=dev --silent) \
        || { warn "Backend npm install fehlgeschlagen — Details: $LOG_FILE"; }

    info "Baue Frontend..."
    (cd "${WEBAPP_DIR}/frontend" && npm install --silent) \
        || { warn "Frontend npm install fehlgeschlagen — Details: $LOG_FILE"; }
    (cd "${WEBAPP_DIR}/frontend" && npm run build) \
        || { warn "Frontend build fehlgeschlagen — Details: $LOG_FILE"; }

    # Build-Ergebnis prüfen
    if [[ ! -f "${WEBAPP_DIR}/frontend/dist/index.html" ]]; then
        warn "ACHTUNG: Frontend-Build fehlgeschlagen! dist/index.html nicht gefunden."
        warn "Die Webapp wird eine weiße Seite zeigen. Bitte manuell prüfen:"
        warn "  cd ${WEBAPP_DIR}/frontend && npm run build"
    else
        ok "Frontend-Build erfolgreich (dist/index.html vorhanden)"
    fi

    # Ownership erst nach npm-Operationen setzen
    chown -R "${WEB_USER}:${WEB_USER}" "${WEBAPP_DIR}" 2>/dev/null || true
    mkdir -p /tmp/mc-webapp-uploads
    ok "mc-webapp installiert und Frontend gebaut"
}

# mc-webapp systemd-Service einrichten
setup_webapp_service() {
    local SYSTEMCTL_PATH; SYSTEMCTL_PATH=$(which systemctl)
    cat > "/etc/systemd/system/${WEBAPP_SERVICE}.service" << SVCEOF
[Unit]
Description=MC Admin WebApp (React + Node.js)
Documentation=https://github.com/${GITHUB_USER}/${GITHUB_REPO}
After=network.target ${APACHE_SERVICE}.service ${SERVICE_NAME}.service
Wants=${SERVICE_NAME}.service

[Service]
Type=simple
User=${WEB_USER}
Group=${WEB_USER}
WorkingDirectory=${WEBAPP_DIR}/backend
ExecStart=${SYSTEMCTL_PATH%systemctl}/../bin/node ${WEBAPP_DIR}/backend/server.js
Restart=on-failure
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=${WEBAPP_SERVICE}
Environment=NODE_ENV=production

[Install]
WantedBy=multi-user.target
SVCEOF

    # ExecStart: node-Pfad korrekt setzen
    local NODE_PATH; NODE_PATH=$(command -v node 2>/dev/null || echo "/usr/bin/node")
    sed -i "s|ExecStart=.*|ExecStart=${NODE_PATH} ${WEBAPP_DIR}/backend/server.js|" "/etc/systemd/system/${WEBAPP_SERVICE}.service"

    systemctl daemon-reload
    systemctl enable "${WEBAPP_SERVICE}" >/dev/null 2>&1
    ok "mc-webapp systemd-Service eingerichtet"
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
    ask "Wirklich deinstallieren?" || { echo "  Abgebrochen."; exit 0; }
    echo ""
    detect_os

    hdr "1/7" "mc-webapp stoppen und entfernen"
    systemctl stop    "${WEBAPP_SERVICE}" 2>/dev/null || true
    systemctl disable "${WEBAPP_SERVICE}" 2>/dev/null || true
    rm -f "/etc/systemd/system/${WEBAPP_SERVICE}.service"
    rm -f "/etc/sudoers.d/mc-webapp"
    ok "mc-webapp gestoppt"
    if [ -d "${WEBAPP_DIR}" ]; then
        if ask "mc-webapp Verzeichnis ${WEBAPP_DIR} löschen?"; then
            rm -rf "${WEBAPP_DIR}" && ok "${WEBAPP_DIR} entfernt"
        else
            ok "${WEBAPP_DIR} behalten"
        fi
    fi

    hdr "2/7" "Minecraft Server + alle Prozesse stoppen"
    systemctl stop    "${SERVICE_NAME}" 2>/dev/null || true
    systemctl disable "${SERVICE_NAME}" 2>/dev/null || true
    pkill -f bedrock_server 2>/dev/null || true
    screen -S minecraft -X quit 2>/dev/null || true
    tmux kill-session -t minecraft 2>/dev/null || true
    command -v fuser &>/dev/null && { fuser -k 19132/udp 2>/dev/null || true; fuser -k 19133/udp 2>/dev/null || true; }
    sleep 1; ok "Minecraft Server gestoppt"
    rm -f "/etc/systemd/system/${SERVICE_NAME}.service"
    systemctl daemon-reload
    ok "Service-Dateien entfernt"
    rm -f /etc/cron.d/mcadmin-backup /var/log/mcadmin-cron.log

    hdr "3/7" "sudo-Regeln entfernen"
    rm -f "/etc/sudoers.d/minecraft-admin" && ok "sudo-Regeln entfernt" || ok "Keine sudo-Regeln gefunden"

    hdr "4/7" "Apache-Konfiguration & SSL entfernen"
    $USE_A2ENSITE && { a2dissite mcadmin.conf >/dev/null 2>&1 || true; a2dissite mcadmin-ssl.conf >/dev/null 2>&1 || true; }
    rm -f "${APACHE_CONF_DIR}/mcadmin.conf" "${APACHE_CONF_DIR}/mcadmin-ssl.conf"
    ok "Apache VirtualHost entfernt"

    hdr "5/7" "Firewall-Regeln entfernen"
    if command -v ufw &>/dev/null && ufw status 2>/dev/null | grep -q "active"; then
        ufw delete allow "${MC_PORT_UDP}/udp" >/dev/null 2>&1 || true
        ufw delete allow "${WEBAPP_PORT}/tcp" >/dev/null 2>&1 || true
        ufw delete allow "${WEB_PORT}/tcp"    >/dev/null 2>&1 || true
        ufw delete allow "${HTTPS_PORT}/tcp"  >/dev/null 2>&1 || true
        ok "UFW-Regeln entfernt"
    elif command -v firewall-cmd &>/dev/null && systemctl is-active --quiet firewalld 2>/dev/null; then
        firewall-cmd --remove-port=${MC_PORT_UDP}/udp --permanent >/dev/null 2>&1 || true
        firewall-cmd --remove-service=http --permanent >/dev/null 2>&1 || true
        firewall-cmd --remove-service=https --permanent >/dev/null 2>&1 || true
        firewall-cmd --reload >/dev/null 2>&1 || true
        ok "firewalld-Regeln entfernt"
    fi
    rm -f /tmp/mcadmin* /tmp/bedrock-server-*.zip 2>/dev/null || true

    hdr "6/7" "Verzeichnisse & Abhängigkeiten"
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
    command -v certbot &>/dev/null && ask "Let's Encrypt Zertifikat widerrufen?" && { certbot delete --non-interactive 2>/dev/null || true; $PKG_REMOVE certbot python3-certbot-apache >/dev/null 2>&1 || true; ok "Certbot entfernt"; }
    if $USE_A2ENSITE && ask "Apache & PHP deinstallieren?"; then
        systemctl stop "${APACHE_SERVICE}" 2>/dev/null || true
        $PKG_REMOVE apache2 "php${PHP_VER}" "libapache2-mod-php${PHP_VER}" "php${PHP_VER}-zip" "php${PHP_VER}-json" "php${PHP_VER}-curl" "php${PHP_VER}-mbstring" >/dev/null 2>&1 || true
        ok "Apache & PHP entfernt"
    fi

    hdr "7/7" "Node.js"
    ask "Node.js deinstallieren?" && { $PKG_REMOVE nodejs npm >/dev/null 2>&1 || true; ok "Node.js entfernt"; } || ok "Node.js behalten"

    systemctl daemon-reload
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
    echo "  ║      ⛏  mcadmin + mc-webapp — Update                ║"
    echo "  ╚══════════════════════════════════════════════════════╝"
    echo -e "${NC}"
    exec > >(tee -a "$LOG_FILE") 2>&1
    detect_os

    CURRENT_VER="unbekannt"; [ -f "$VERSION_FILE" ] && CURRENT_VER=$(cat "$VERSION_FILE")
    info "Prüfe neueste Version auf GitHub..."
    LATEST_VER=$(curl -fsSL "https://api.github.com/repos/${GITHUB_USER}/${GITHUB_REPO}/commits/${GITHUB_BRANCH}" 2>/dev/null \
        | grep '"sha"' | head -1 | cut -d'"' -f4 | cut -c1-7 || echo "unbekannt")
    echo ""
    echo -e "  Installiert: ${YELLOW}${CURRENT_VER}${NC}   GitHub: ${GREEN}${LATEST_VER}${NC}"
    echo ""
    if [ "$CURRENT_VER" = "$LATEST_VER" ] && [ "$CURRENT_VER" != "unbekannt" ]; then
        echo -e "  ${GREEN}✓ Bereits aktuell!${NC}"
        [ ! -f /tmp/mcadmin_yes ] && ask "Trotzdem neu installieren?" || exit 0
    fi

    hdr "1/5" "Dateien von GitHub laden"
    TMP_ZIP="/tmp/mcadmin_update.zip"; TMP_DIR="/tmp/mcadmin_update_extract"
    rm -rf "$TMP_DIR" "$TMP_ZIP"
    command -v wget &>/dev/null && wget -q -O "$TMP_ZIP" "$GITHUB_ZIP" || curl -fsSL -o "$TMP_ZIP" "$GITHUB_ZIP"
    [ -s "$TMP_ZIP" ] || err "Download fehlgeschlagen"
    mkdir -p "$TMP_DIR"; unzip -q "$TMP_ZIP" -d "$TMP_DIR"
    EXTRACTED=$(find "$TMP_DIR" -maxdepth 1 -type d -name "${GITHUB_REPO}-*" | head -1)
    [ -d "$EXTRACTED" ] || err "Entpacktes Verzeichnis nicht gefunden"
    ok "Download abgeschlossen"

    hdr "2/5" "PHP-Panel aktualisieren"
    CONFIG_BACKUP="/tmp/mcadmin_config_backup.php"
    [ -f "${PANEL_DIR}/config.php" ] && cp "${PANEL_DIR}/config.php" "$CONFIG_BACKUP"
    SETTINGS_BACKUP="/tmp/mcadmin_settings_backup.json"
    [ -f "${PANEL_DIR}/mcadmin_settings.json" ] && cp "${PANEL_DIR}/mcadmin_settings.json" "$SETTINGS_BACKUP"
    cp -r "${EXTRACTED}/mcadmin/"* "${PANEL_DIR}/" 2>/dev/null || true
    rm -f "${PANEL_DIR}/install.sh"
    [ -f "$CONFIG_BACKUP" ] && {
        OLD_DIR=$(grep -o "'/opt/[^']*'" "$CONFIG_BACKUP" 2>/dev/null | head -1 | tr -d "'" || true)
        if [ "$OLD_DIR" = "/opt/minecraft-bedrock" ] || [ -z "$OLD_DIR" ]; then
            rm -f "$CONFIG_BACKUP"
            ok "config.php aktualisiert (Standard-Pfad)"
        else
            cp "$CONFIG_BACKUP" "${PANEL_DIR}/config.php"; rm -f "$CONFIG_BACKUP"
            warn "config.php wiederhergestellt (Benutzerpfad: ${OLD_DIR})"
        fi
    }
    [ -f "$SETTINGS_BACKUP" ] && { cp "$SETTINGS_BACKUP" "${PANEL_DIR}/mcadmin_settings.json"; rm -f "$SETTINGS_BACKUP"; ok "Einstellungen wiederhergestellt"; }
    chown -R ${WEB_USER}:${WEB_USER} "${PANEL_DIR}"
    chmod -R 750 "${PANEL_DIR}"; chmod 770 "${PANEL_DIR}/backups" "${PANEL_DIR}/uploads" 2>/dev/null || true
    echo "$LATEST_VER" > "$VERSION_FILE"
    ok "PHP-Panel aktualisiert"

    hdr "3/5" "mc-webapp aktualisieren"
    if command -v node &>/dev/null && [ -d "${EXTRACTED}/mc-webapp" ]; then
        # Settings backup
        CONFIG_JS_BAK="/tmp/mcadmin_config_js_bak.js"
        [ -f "${WEBAPP_DIR}/backend/config.js" ] && cp "${WEBAPP_DIR}/backend/config.js" "$CONFIG_JS_BAK"
        cp -r "${EXTRACTED}/mc-webapp/." "${WEBAPP_DIR}/"
        [ -f "$CONFIG_JS_BAK" ] && cp "$CONFIG_JS_BAK" "${WEBAPP_DIR}/backend/config.js" && rm -f "$CONFIG_JS_BAK"
        (cd "${WEBAPP_DIR}/backend"  && npm install --omit=dev --silent) || warn "Backend npm install fehlgeschlagen"
        (cd "${WEBAPP_DIR}/frontend" && npm install --silent && npm run build)  || warn "Frontend build fehlgeschlagen"
        if [[ ! -f "${WEBAPP_DIR}/frontend/dist/index.html" ]]; then
            warn "ACHTUNG: Frontend-Build fehlgeschlagen! dist/index.html nicht gefunden."
        else
            ok "Frontend-Build erfolgreich"
        fi
        chown -R "${WEB_USER}:${WEB_USER}" "${WEBAPP_DIR}" 2>/dev/null || true
        systemctl restart "${WEBAPP_SERVICE}" 2>/dev/null || true
        ok "mc-webapp aktualisiert und neu gestartet"
    else
        warn "Node.js nicht verfügbar oder mc-webapp nicht im ZIP — übersprungen"
    fi

    hdr "4/5" "plyvel + Service + Cron"
    if command -v python3 &>/dev/null && ! python3 -c "import plyvel" 2>/dev/null; then
        command -v pip3 &>/dev/null && pip3 install --quiet plyvel >>"$LOG_FILE" 2>&1 && ok "plyvel aktualisiert" || true
    fi
    SVC_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
    if [ -f "$SVC_FILE" ] && grep -q 'KillMode=mixed' "$SVC_FILE"; then
        sed -i 's/KillMode=mixed/KillMode=control-group/' "$SVC_FILE"
        systemctl daemon-reload; ok "Service-Datei migriert"
    fi
    echo "* * * * * ${WEB_USER} /usr/bin/php ${PANEL_DIR}/cron.php >> /var/log/mcadmin-cron.log 2>&1" > /etc/cron.d/mcadmin-backup
    chmod 644 /etc/cron.d/mcadmin-backup
    touch /var/log/mcadmin-cron.log; chown "${WEB_USER}:${WEB_USER}" /var/log/mcadmin-cron.log; chmod 640 /var/log/mcadmin-cron.log

    hdr "5/5" "Apache neu laden"
    ensure_web_user_journal_access
    if $JOURNAL_ACCESS_CHANGED; then
        systemctl restart "${APACHE_SERVICE}" 2>/dev/null || true; ok "Apache neu gestartet"
    else
        systemctl reload "${APACHE_SERVICE}" 2>/dev/null || systemctl restart "${APACHE_SERVICE}" 2>/dev/null; ok "Apache neu geladen"
    fi

    rm -rf "$TMP_DIR" "$TMP_ZIP"
    SERVER_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
    echo ""
    echo -e "${GREEN}${BOLD}  ✅ Update abgeschlossen!${NC}  (${CURRENT_VER} → ${LATEST_VER})"
    echo -e "  PHP-Panel: ${CYAN}http://${SERVER_IP}/mcadmin/${NC}"
    echo -e "  Web-App:   ${CYAN}http://${SERVER_IP}:${WEBAPP_PORT}${NC}"
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
echo "  ║         + mc-webapp (React + Node.js)                ║"
echo "  ║         Vollautomatische Installation                ║"
echo "  ║         github.com/Ronny-1979/mcadmin_webapp        ║"
echo "  ╚══════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo "  Log: $LOG_FILE"
echo ""
exec > >(tee -a "$LOG_FILE") 2>&1

# ── 1. OS erkennen ────────────────────────────────────────────
hdr "1/14" "Betriebssystem erkennen"
detect_os
ok "OS: ${PRETTY_NAME:-$OS_ID} (${OS_TYPE}, Web-User: ${WEB_USER})"

# ── 2. Pakete aktualisieren ───────────────────────────────────
hdr "2/14" "Paketlisten aktualisieren"
$PKG_UPDATE >/dev/null 2>&1 && ok "Paketlisten aktuell" || warn "Update schlug fehl"

# ── 3. Apache + PHP installieren ──────────────────────────────
hdr "3/14" "Apache & PHP installieren"

install_debian_packages() {
    export DEBIAN_FRONTEND=noninteractive
    DEBIAN_PACKAGES=(apache2 php php-cli libapache2-mod-php php-zip php-curl php-mbstring php-xml unzip wget curl tar screen jq cron sudo python3)
    DEBIAN_FRONTEND=noninteractive $PKG_INSTALL "${DEBIAN_PACKAGES[@]}" >>"$LOG_FILE" 2>&1 || err "Apache/PHP-Installation fehlgeschlagen — Details: $LOG_FILE"
    ! command -v php >/dev/null 2>&1 && err "PHP wurde nicht installiert"
    _PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
    [ "${_PHP_MAJOR:-0}" -lt 8 ] && err "PHP-Version zu alt — mindestens PHP 8 erforderlich"
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    ok "Apache2 + PHP $(php -r 'echo PHP_VERSION;') bereit"
    if ! python3 -c "import plyvel" 2>/dev/null; then
        info "Installiere plyvel (LevelDB für Spawn-Erkennung)..."
        apt-cache show python3-plyvel >/dev/null 2>&1 && DEBIAN_FRONTEND=noninteractive $PKG_INSTALL python3-plyvel >>"$LOG_FILE" 2>&1 || true
        if ! python3 -c "import plyvel" 2>/dev/null; then
            DEBIAN_FRONTEND=noninteractive $PKG_INSTALL python3-pip libleveldb-dev python3-dev build-essential >>"$LOG_FILE" 2>&1 || true
            pip3 install --quiet plyvel >>"$LOG_FILE" 2>&1 || pip3 install --quiet --break-system-packages plyvel >>"$LOG_FILE" 2>&1 && ok "plyvel installiert" || warn "plyvel nicht verfügbar — Spawn-Erkennung nutzt Fallback"
        else
            ok "plyvel installiert (Paket)"
        fi
    else
        ok "Python3 + plyvel bereits vorhanden"
    fi
}
install_rhel_packages() {
    RHEL_BASE_PACKAGES=(httpd php php-cli php-zip php-curl php-mbstring php-xml php-json unzip wget curl tar screen jq sudo python3)
    $PKG_INSTALL "${RHEL_BASE_PACKAGES[@]}" >>"$LOG_FILE" 2>&1 || true
    _PHP_MAJOR=$(php -r 'echo PHP_MAJOR_VERSION;' 2>/dev/null || echo "0")
    if [ "${_PHP_MAJOR:-0}" -lt 8 ] && command -v dnf >/dev/null 2>&1; then
        dnf module reset php -y >>"$LOG_FILE" 2>&1 || true
        for stream in 8.4 8.3 8.2 8.1 8.0; do
            dnf module list php 2>/dev/null | grep -Eq "php[[:space:]]+${stream}" && { dnf module enable "php:${stream}" -y >>"$LOG_FILE" 2>&1 || true; $PKG_INSTALL php php-cli php-zip php-curl php-mbstring php-xml php-json >>"$LOG_FILE" 2>&1 || true; break; }
        done
    fi
    ! command -v php >/dev/null 2>&1 && err "PHP wurde nicht installiert"
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    ok "Apache + PHP $(php -r 'echo PHP_VERSION;') bereit"
    ! python3 -c "import plyvel" 2>/dev/null && { $PKG_INSTALL python3-pip leveldb-devel python3-devel gcc gcc-c++ make >>"$LOG_FILE" 2>&1 || true; pip3 install --quiet plyvel >>"$LOG_FILE" 2>&1 && ok "plyvel installiert" || warn "plyvel nicht verfügbar"; }
}
install_arch_packages() {
    ARCH_PACKAGES=(apache php php-apache php-zip unzip wget curl tar screen jq sudo python)
    $PKG_INSTALL "${ARCH_PACKAGES[@]}" >>"$LOG_FILE" 2>&1 || err "Apache/PHP-Installation fehlgeschlagen"
    PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
    sed -i 's/;extension=zip/extension=zip/' /etc/php/php.ini 2>/dev/null || true
    [ -f /etc/httpd/conf/httpd.conf ] && ! grep -q 'php_module' /etc/httpd/conf/httpd.conf && cat >> /etc/httpd/conf/httpd.conf << 'ARCH_PHP_EOF'

LoadModule php_module modules/libphp.so
AddHandler php-script .php
Include conf/extra/php_module.conf
ARCH_PHP_EOF
    ok "Apache + PHP $(php -r 'echo PHP_VERSION;') bereit"
}
case $OS_TYPE in debian) install_debian_packages ;; rhel) install_rhel_packages ;; arch) install_arch_packages ;; esac
_PHP_CLI="php${PHP_VER}"; command -v "$_PHP_CLI" &>/dev/null || _PHP_CLI="php"

# ── 4. Node.js installieren ───────────────────────────────────
hdr "4/14" "Node.js installieren"
install_nodejs

# ── 5. Apache Basis-Konfiguration ─────────────────────────────
hdr "5/14" "Apache konfigurieren"
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
printf 'Require all denied\n' > "${PANEL_DIR}/backups/.htaccess"
printf 'Require all denied\n' > "${PANEL_DIR}/uploads/.htaccess"
ok "Apache VirtualHost konfiguriert"

# ── 6. Dateien von GitHub laden ───────────────────────────────
hdr "6/14" "Dateien von GitHub laden (PHP-Panel + mc-webapp)"
TMP_ZIP="/tmp/mcadmin_download.zip"; TMP_DIR="/tmp/mcadmin_extract"
rm -rf "$TMP_DIR" "$TMP_ZIP"
info "Lade ${GITHUB_USER}/${GITHUB_REPO}@${GITHUB_BRANCH}..."
command -v wget &>/dev/null && wget -q -O "$TMP_ZIP" "$GITHUB_ZIP" || curl -fsSL -o "$TMP_ZIP" "$GITHUB_ZIP"
[ -s "$TMP_ZIP" ] || err "Download fehlgeschlagen — Internetverbindung prüfen"
ok "Download abgeschlossen ($(du -sh $TMP_ZIP | cut -f1))"
mkdir -p "$TMP_DIR"; unzip -q "$TMP_ZIP" -d "$TMP_DIR"
EXTRACTED=$(find "$TMP_DIR" -maxdepth 1 -type d -name "${GITHUB_REPO}-*" | head -1)
[ -d "$EXTRACTED" ] || err "Entpacktes Verzeichnis nicht gefunden"

# ── 7. PHP-Panel installieren ─────────────────────────────────
hdr "7/14" "PHP-Panel installieren"
cp -r "${EXTRACTED}/mcadmin/"* "${PANEL_DIR}/" 2>/dev/null || err "Kopieren fehlgeschlagen"
rm -f "${PANEL_DIR}/install.sh"
INSTALL_VER=$(curl -fsSL "https://api.github.com/repos/${GITHUB_USER}/${GITHUB_REPO}/commits/${GITHUB_BRANCH}" 2>/dev/null \
    | grep '"sha"' | head -1 | cut -d'"' -f4 | cut -c1-7 || echo "unknown")
echo "$INSTALL_VER" > "$VERSION_FILE"
ok "PHP-Panel installiert (Version: ${INSTALL_VER})"

# ── 8. mc-webapp installieren ─────────────────────────────────
hdr "8/14" "mc-webapp installieren (React + Node.js)"
install_webapp "$EXTRACTED"

# ── 9. Minecraft Verzeichnis ──────────────────────────────────
hdr "9/14" "Minecraft Server Verzeichnis"
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
ok "Verzeichnisse & Berechtigungen gesetzt"

# ── 10. Minecraft Bedrock Server herunterladen ────────────────
hdr "10/14" "Minecraft Bedrock Server"
MC_VER_INSTALLED=false; MC_VERSION=""
if [ -f "${MC_DIR}/bedrock_server" ]; then
    MC_VERSION=$(cat "${MC_DIR}/version.txt" 2>/dev/null || echo "unbekannt")
    ok "Minecraft Server bereits installiert (v${MC_VERSION}) — wird übersprungen"
    MC_VER_INSTALLED=true
else
    info "Ermittle neueste Bedrock-Version..."
    MC_DL_URL=$(curl -s --max-time 15 -A "Mozilla/5.0" \
        "https://net-secondary.web.minecraft-services.net/api/v1.0/download/links" \
        | jq -r '.result.links[] | select(.downloadType=="serverBedrockLinux") | .downloadUrl' 2>/dev/null) || true
    if [ -z "$MC_DL_URL" ]; then
        warn "Bedrock-Server konnte nicht ermittelt werden — im Panel unter 'Updates' nachholen"
    else
        MC_VERSION=$(echo "$MC_DL_URL" | grep -oP 'bedrock-server-\K[0-9.]+(?=\.zip)') || true
        info "Lade Bedrock Server v${MC_VERSION}..."
        _MC_TMP="/tmp/bedrock-server-${MC_VERSION}.zip"; _MC_EXTRACT="/tmp/mc_extract_$$"
        wget -q -U "Mozilla/5.0" -O "$_MC_TMP" "$MC_DL_URL" 2>/dev/null || curl -sL -A "Mozilla/5.0" -o "$_MC_TMP" "$MC_DL_URL" 2>/dev/null || true
        if [ -f "$_MC_TMP" ] && [ -s "$_MC_TMP" ]; then
            mkdir -p "$_MC_EXTRACT"; unzip -q "$_MC_TMP" -d "$_MC_EXTRACT" || true
            for f in "$_MC_EXTRACT"/*; do
                base="$(basename "$f")"
                case "$base" in worlds|server.properties|whitelist.json|permissions.json) ;; *) cp -r "$f" "${MC_DIR}/" ;; esac
            done
            chmod +x "${MC_DIR}/bedrock_server" 2>/dev/null || true
            chown -R "${WEB_USER}:${WEB_USER}" "${MC_DIR}"
            echo "$MC_VERSION" > "${MC_DIR}/version.txt"
            rm -rf "$_MC_EXTRACT" "$_MC_TMP"
            ok "Bedrock Server v${MC_VERSION} installiert"
            MC_VER_INSTALLED=true
        else
            warn "Download fehlgeschlagen — im Panel unter 'Updates' nachholen"
            rm -f "$_MC_TMP" 2>/dev/null || true
        fi
    fi
fi

# ── 11. systemd Services ──────────────────────────────────────
hdr "11/14" "systemd Services einrichten"

# Minecraft-Service
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

# mc-webapp-Service
command -v node &>/dev/null && setup_webapp_service || warn "Node.js nicht verfügbar — mc-webapp-Service übersprungen"

pkill -f bedrock_server 2>/dev/null || true
screen -S minecraft -X quit 2>/dev/null || true
tmux kill-session -t minecraft 2>/dev/null || true
command -v fuser &>/dev/null && { fuser -k 19132/udp 2>/dev/null || true; fuser -k 19133/udp 2>/dev/null || true; }
sleep 1

# ── 12. sudo-Rechte ───────────────────────────────────────────
hdr "12/14" "sudo-Rechte"
SYSTEMCTL_PATH=$(which systemctl)
PANEL_UPDATE_SCRIPT="/usr/local/sbin/mcadmin-panel-update.sh"
cat > "$PANEL_UPDATE_SCRIPT" << 'PANEL_SCRIPT_EOF'
#!/bin/bash
exec curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin_webapp/main/install.sh | bash -s -- --update
PANEL_SCRIPT_EOF
chmod 755 "$PANEL_UPDATE_SCRIPT"

cat > "/etc/sudoers.d/minecraft-admin" << SUDOEOF
# mcadmin + mc-webapp | github.com/Ronny-1979/mcadmin_webapp
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} start ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} stop ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} restart ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} status ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: ${SYSTEMCTL_PATH} is-active ${SERVICE_NAME}
${WEB_USER} ALL=(ALL) NOPASSWD: /bin/bash ${PANEL_UPDATE_SCRIPT}
${WEB_USER} ALL=(ALL) NOPASSWD: /bin/tar -xzf ${PANEL_DIR}/backups/*.tar.gz -C ${MC_DIR}
SUDOEOF
chmod 440 "/etc/sudoers.d/minecraft-admin"
visudo -c -f "/etc/sudoers.d/minecraft-admin" >/dev/null 2>&1 && ok "sudo-Regeln gesetzt" || {
    warn "sudo Syntax-Fehler — entfernt"; rm -f "/etc/sudoers.d/minecraft-admin"
}

# ── 13. Let's Encrypt / HTTPS ─────────────────────────────────
hdr "13/14" "Let's Encrypt (HTTPS)"
echo ""
LETSENCRYPT_DONE=false; DOMAIN=""

if ask "Möchtest du HTTPS mit Let's Encrypt einrichten?"; then
    echo ""
    echo -e "  ${YELLOW}Hinweise:${NC}"
    echo -e "  • Du brauchst eine echte Domain (z.B. mc.meinserver.de)"
    echo -e "  • Die Domain muss auf diese Server-IP zeigen (DNS A-Record)"
    echo -e "  • Port 80 muss kurz für die Challenge erreichbar sein"
    echo ""
    while true; do
        printf "  ${BOLD}Domain eingeben:${NC} " >/dev/tty; read -r DOMAIN </dev/tty 2>/dev/null || true
        DOMAIN=$(echo "$DOMAIN" | tr '[:upper:]' '[:lower:]' | sed 's|https\?://||; s|/.*||')
        [ -n "$DOMAIN" ] && break; warn "Bitte eine Domain eingeben"
    done
    printf "  ${BOLD}E-Mail für Let's Encrypt:${NC} " >/dev/tty; read -r LE_EMAIL </dev/tty 2>/dev/null || true
    [ -z "$LE_EMAIL" ] && LE_EMAIL="admin@${DOMAIN}"
    echo ""
    if ! command -v certbot &>/dev/null; then
        info "Installiere Certbot..."
        if $USE_A2ENSITE; then $PKG_INSTALL certbot python3-certbot-apache >/dev/null 2>&1
        elif [ "$OS_TYPE" = "rhel" ]; then
            $PKG_INSTALL certbot python3-certbot-apache >/dev/null 2>&1 || { $PKG_INSTALL snapd >/dev/null 2>&1; snap install --classic certbot >/dev/null 2>&1; ln -sf /snap/bin/certbot /usr/bin/certbot 2>/dev/null || true; }
        else $PKG_INSTALL certbot >/dev/null 2>&1; fi
    fi
    if ! command -v certbot &>/dev/null; then
        warn "Certbot nicht verfügbar — überspringe HTTPS"
    else
        ok "Certbot bereit"
        sed -i "s|ServerName mcadmin.local|ServerName ${DOMAIN}|g" "${APACHE_CONF_DIR}/mcadmin.conf"
        systemctl start "${APACHE_SERVICE}" >/dev/null 2>&1 || true
        if certbot --apache -d "${DOMAIN}" --email "${LE_EMAIL}" --agree-tos --non-interactive --redirect 2>&1 | tee -a "$LOG_FILE"; then
            ok "SSL-Zertifikat ausgestellt!"
            LETSENCRYPT_DONE=true
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
<VirtualHost *:${WEB_PORT}>
    ServerName ${DOMAIN}
    RewriteEngine On
    RewriteRule ^(.*)$ https://${DOMAIN}\$1 [R=301,L]
</VirtualHost>
SSLEOF
            $USE_A2ENSITE && { a2enmod ssl headers rewrite >/dev/null 2>&1 || true; a2ensite mcadmin-ssl.conf >/dev/null 2>&1 || true; a2dissite mcadmin.conf >/dev/null 2>&1 || true; }
            systemctl enable certbot.timer >/dev/null 2>&1 || true
            ok "Auto-Renewal aktiviert"
        else
            warn "Zertifikat-Beantragung fehlgeschlagen — Panel läuft weiter über HTTP"
        fi
    fi
else
    ok "HTTPS übersprungen — Panel läuft über HTTP"
fi

# ── 14. PHP-Limits, Cron, Apache, Server + App starten ────────
hdr "14/14" "Abschluss: PHP, Cron, Apache + Services starten"

for phpini in "/etc/php/${PHP_VER}/apache2/php.ini" "/etc/php/8.2/apache2/php.ini" "/etc/php/8.1/apache2/php.ini" "/etc/php/php.ini" "/etc/php.ini"; do
    if [ -f "$phpini" ]; then
        sed -i 's/^upload_max_filesize.*/upload_max_filesize = 512M/' "$phpini"
        sed -i 's/^post_max_size.*/post_max_size = 512M/'             "$phpini"
        sed -i 's/^max_execution_time.*/max_execution_time = 300/'    "$phpini"
        sed -i 's/^memory_limit.*/memory_limit = 256M/'               "$phpini"
        ok "PHP-Limits angepasst: $phpini"; break
    fi
done

CONFIG="${PANEL_DIR}/config.php"
sed -i "s|__DIR__ . '/mcadmin_state.json'|'${PANEL_DIR}/mcadmin_state.json'|g"   "$CONFIG" 2>/dev/null || true
sed -i "s|__DIR__ . '/backups'|'${PANEL_DIR}/backups'|g"                          "$CONFIG" 2>/dev/null || true
sed -i "s|__DIR__ . '/uploads'|'${PANEL_DIR}/uploads'|g"                          "$CONFIG" 2>/dev/null || true
sed -i "s|__DIR__ . '/version_cache.json'|'${PANEL_DIR}/version_cache.json'|g"    "$CONFIG" 2>/dev/null || true
sed -i "s|__DIR__ . '/mcadmin_settings.json'|'${PANEL_DIR}/mcadmin_settings.json'|g" "$CONFIG" 2>/dev/null || true
ok "config.php Pfade angepasst"

echo "* * * * * ${WEB_USER} /usr/bin/php ${PANEL_DIR}/cron.php >> /var/log/mcadmin-cron.log 2>&1" > /etc/cron.d/mcadmin-backup
chmod 644 /etc/cron.d/mcadmin-backup
touch /var/log/mcadmin-cron.log
chown "${WEB_USER}:${WEB_USER}" /var/log/mcadmin-cron.log
chmod 640 /var/log/mcadmin-cron.log
case "$OS_TYPE" in
    debian) command -v cron &>/dev/null || DEBIAN_FRONTEND=noninteractive $PKG_INSTALL cron >>"$LOG_FILE" 2>&1; systemctl enable cron 2>/dev/null || true; systemctl start cron 2>/dev/null || true ;;
    rhel)   command -v crond &>/dev/null || $PKG_INSTALL cronie >>"$LOG_FILE" 2>&1; systemctl enable --now crond 2>/dev/null || true ;;
    arch)   command -v crond &>/dev/null || $PKG_INSTALL cronie >>"$LOG_FILE" 2>&1; systemctl enable --now cronie 2>/dev/null || true ;;
esac
ok "Backup-Cron eingerichtet"

ok "Standard-Zugangsdaten: ${BOLD}admin / admin${NC} — bitte sofort ändern!"

systemctl enable "${APACHE_SERVICE}" >/dev/null 2>&1
systemctl restart "${APACHE_SERVICE}" 2>&1 && ok "Apache gestartet" || warn "Apache-Fehler"
sleep 1
systemctl is-active --quiet "${APACHE_SERVICE}" && ok "Apache läuft ✓" || warn "Apache läuft möglicherweise nicht"

# Firewall-Hinweis für mc-webapp Port
if command -v ufw &>/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
    if ask "mc-webapp Port ${WEBAPP_PORT}/TCP in Firewall öffnen?"; then
        ufw allow ${WEBAPP_PORT}/tcp >/dev/null 2>&1; ok "UFW: Port ${WEBAPP_PORT}/TCP geöffnet"
    fi
    if ask "Minecraft Port ${MC_PORT_UDP}/UDP für Spieler öffnen?"; then
        ufw allow ${MC_PORT_UDP}/udp >/dev/null 2>&1; ufw allow ${MC_PORT_UDP6}/udp >/dev/null 2>&1; ok "UFW: Minecraft Ports geöffnet"
    fi
    if $LETSENCRYPT_DONE; then ufw allow ${HTTPS_PORT}/tcp >/dev/null 2>&1; ok "UFW: Port ${HTTPS_PORT}/TCP geöffnet"
    elif ask "Web-Interface Port ${WEB_PORT}/TCP extern öffnen?"; then ufw allow ${WEB_PORT}/tcp >/dev/null 2>&1; ok "UFW: Port ${WEB_PORT}/TCP geöffnet"; fi
elif command -v firewall-cmd &>/dev/null && systemctl is-active --quiet firewalld 2>/dev/null; then
    ask "Minecraft Port ${MC_PORT_UDP}/UDP öffnen?" && { firewall-cmd --add-port=${MC_PORT_UDP}/udp --permanent >/dev/null 2>&1; firewall-cmd --add-port=${MC_PORT_UDP6}/udp --permanent >/dev/null 2>&1; firewall-cmd --reload >/dev/null 2>&1; ok "firewalld: Minecraft Ports geöffnet"; }
    ask "mc-webapp Port ${WEBAPP_PORT}/TCP öffnen?" && { firewall-cmd --add-port=${WEBAPP_PORT}/tcp --permanent >/dev/null 2>&1; firewall-cmd --reload >/dev/null 2>&1; ok "firewalld: Port ${WEBAPP_PORT}/TCP geöffnet"; }
fi

# Minecraft Server starten
MC_STARTED=false
if $MC_VER_INSTALLED; then
    info "Starte Minecraft Server..."
    if systemctl start "${SERVICE_NAME}" 2>/dev/null; then
        _LEVEL=$(grep '^level-name=' "${MC_DIR}/server.properties" 2>/dev/null | cut -d= -f2); _LEVEL="${_LEVEL:-Bedrock level}"
        _WORLD_DIR="${MC_DIR}/worlds/${_LEVEL}"
        info "Warte auf Weltgenerierung ('${_LEVEL}')..."
        _waited=0
        while [ ! -d "$_WORLD_DIR" ] && [ "$_waited" -lt 45 ]; do sleep 1; _waited=$((_waited+1)); done
        if [ -d "$_WORLD_DIR" ]; then
            ok "Welt '${_LEVEL}' erstellt ✓"; MC_STARTED=true
            printf '{"active_world":"%s","world_packs":{},"world_imported_packs":{}}\n' "${_LEVEL}" > "${PANEL_DIR}/mcadmin_state.json"
            chown "${WEB_USER}:${WEB_USER}" "${PANEL_DIR}/mcadmin_state.json"
        else
            warn "Welt nach 45s noch nicht angelegt — im Panel prüfen"; MC_STARTED=true
        fi
        systemctl is-active --quiet "${SERVICE_NAME}" && ok "Minecraft Server läuft ✓" || warn "Server evtl. nicht aktiv"
    else
        warn "Server konnte nicht gestartet werden — im Panel manuell starten"
    fi
fi

# mc-webapp starten
if command -v node &>/dev/null && [ -d "${WEBAPP_DIR}/backend" ]; then
    info "Starte mc-webapp..."
    systemctl start "${WEBAPP_SERVICE}" 2>/dev/null && ok "mc-webapp läuft auf Port ${WEBAPP_PORT} ✓" || warn "mc-webapp konnte nicht gestartet werden — Logs: journalctl -u ${WEBAPP_SERVICE}"
fi

rm -rf "$TMP_DIR" "$TMP_ZIP" 2>/dev/null || true

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
echo -e "  ${BOLD}Zugriff:${NC}"
if $LETSENCRYPT_DONE && [ -n "$DOMAIN" ]; then
    echo -e "    ${CYAN}PHP-Panel:  https://${DOMAIN}/mcadmin/${NC}"
    echo -e "    ${CYAN}React-App:  http://${SERVER_IP}:${WEBAPP_PORT}${NC}  ${YELLOW}(HTTPS empfohlen — Reverse-Proxy nötig)${NC}"
else
    echo -e "    ${CYAN}PHP-Panel:  http://${SERVER_IP}/mcadmin/${NC}  ${YELLOW}← Vollständiges Web-Interface${NC}"
    echo -e "    ${CYAN}React-App:  http://${SERVER_IP}:${WEBAPP_PORT}${NC}   ${YELLOW}← Echtzeit-Konsole + alle Features${NC}"
fi
echo ""
echo -e "  ${BOLD}Standard-Login:${NC}  Benutzer: ${YELLOW}admin${NC}  Passwort: ${YELLOW}admin${NC}"
echo -e "  ${RED}  ⚠ Bitte sofort unter Einstellungen → Benutzer & Passwort ändern!${NC}"
echo ""
$MC_VER_INSTALLED && echo -e "  ${BOLD}Minecraft:${NC} v${MC_VERSION} ✓ installiert"
echo ""
echo -e "  ${BOLD}Nächste Schritte:${NC}"
echo -e "    ${GREEN}1.${NC} PHP-Panel oder React-App aufrufen"
echo -e "    ${GREEN}2.${NC} Einstellungen → Passwort ändern"
if $MC_STARTED; then
    echo -e "    ${GREEN}3.${NC} Welt 'Bedrock level' ist bereit — Mitspieler einladen! 🎮"
else
    echo -e "    ${GREEN}3.${NC} Panel → Server starten → erste Welt wird erstellt"
fi
echo ""
echo -e "  ${BOLD}Nützliche Befehle:${NC}"
echo -e "    ${CYAN}Update:${NC}       curl -fsSL https://raw.githubusercontent.com/${GITHUB_USER}/${GITHUB_REPO}/main/install.sh | sudo bash -s -- --update"
echo -e "    ${CYAN}MC-Log:${NC}       journalctl -u ${SERVICE_NAME} -f"
echo -e "    ${CYAN}App-Log:${NC}      journalctl -u ${WEBAPP_SERVICE} -f"
echo -e "    ${CYAN}Deinstall:${NC}    curl -fsSL https://raw.githubusercontent.com/${GITHUB_USER}/${GITHUB_REPO}/main/install.sh | sudo bash -s -- --uninstall"
echo ""
