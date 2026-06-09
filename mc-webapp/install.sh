#!/bin/bash
# ============================================================
# mc-webapp — Vollständiges Installations-Skript
# Führe als root oder mit sudo aus
# ============================================================
set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

info()    { echo -e "${GREEN}[✓]${NC} $1"; }
warn()    { echo -e "${YELLOW}[!]${NC} $1"; }
error()   { echo -e "${RED}[✗]${NC} $1"; exit 1; }
step()    { echo -e "\n${BLUE}──── $1 ────${NC}"; }

# Muss als root laufen
if [[ $EUID -ne 0 ]]; then
  echo -e "${RED}Bitte als root ausführen: sudo bash install.sh${NC}"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo ""
echo "  ⛏️  mc-webapp Installer"
echo "  ========================"
echo "  Installationsverzeichnis: $SCRIPT_DIR"
echo ""

# ── 1. Node.js installieren / prüfen ─────────────────────────
step "Node.js"

NODE_OK=false
if command -v node &>/dev/null; then
  NODE_MAJOR=$(node -e "process.stdout.write(String(process.version.match(/^v(\d+)/)[1]))")
  if [[ "$NODE_MAJOR" -ge 18 ]]; then
    info "Node.js $(node --version) bereits installiert"
    NODE_OK=true
  else
    warn "Node.js $(node --version) zu alt — installiere 20.x"
  fi
fi

if [[ "$NODE_OK" == false ]]; then
  if ! command -v curl &>/dev/null; then
    apt-get install -y curl > /dev/null
  fi
  info "Füge NodeSource-Repository hinzu..."
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash - > /dev/null 2>&1
  apt-get install -y nodejs > /dev/null 2>&1
  info "Node.js $(node --version) installiert"
fi

# ── 2. Backend-Abhängigkeiten ─────────────────────────────────
step "Backend (npm install)"
cd "$SCRIPT_DIR/backend"
npm install --omit=dev --silent
info "Backend-Pakete installiert"

# ── 3. Frontend bauen ─────────────────────────────────────────
step "Frontend (npm install + build)"
cd "$SCRIPT_DIR/frontend"
npm install --silent
npm run build
info "Frontend gebaut → frontend/dist/"

# ── 4. Upload-Verzeichnis ─────────────────────────────────────
step "Verzeichnisse"
mkdir -p /tmp/mc-webapp-uploads
info "/tmp/mc-webapp-uploads erstellt"

# ── 5. JWT-Secret generieren (falls config.js noch default) ───
step "Konfiguration"
CONFIG="$SCRIPT_DIR/backend/config.js"
if grep -q "mc-webapp-secret-bitte-aendern" "$CONFIG"; then
  NEW_SECRET=$(node -e "require('crypto').randomBytes(32).toString('hex').substring(0,32)" 2>/dev/null || openssl rand -hex 32 | cut -c1-32)
  sed -i "s/mc-webapp-secret-bitte-aendern/$NEW_SECRET/" "$CONFIG"
  info "JWT-Secret automatisch generiert"
else
  info "JWT-Secret bereits gesetzt"
fi

# ── 6. Sudoers einrichten ─────────────────────────────────────
step "Sudo-Berechtigungen"
SUDOERS_DEST="/etc/sudoers.d/mc-webapp"
cp "$SCRIPT_DIR/sudoers.example" "$SUDOERS_DEST"
chmod 440 "$SUDOERS_DEST"
# Syntax prüfen
if visudo -cf "$SUDOERS_DEST" > /dev/null 2>&1; then
  info "Sudoers eingerichtet: $SUDOERS_DEST"
else
  rm -f "$SUDOERS_DEST"
  error "Sudoers-Syntax-Fehler — manuell prüfen: $SCRIPT_DIR/sudoers.example"
fi

# ── 7. Systemd-Service installieren ──────────────────────────
step "Systemd-Service"
SERVICE_DEST="/etc/systemd/system/mc-webapp.service"

# Pfad und Benutzer in Service-Datei setzen
cp "$SCRIPT_DIR/mc-webapp.service" "$SERVICE_DEST"
sed -i "s|WorkingDirectory=.*|WorkingDirectory=$SCRIPT_DIR/backend|" "$SERVICE_DEST"
sed -i "s|ExecStart=.*|ExecStart=$(command -v node) $SCRIPT_DIR/backend/server.js|" "$SERVICE_DEST"

systemctl daemon-reload
systemctl enable mc-webapp > /dev/null 2>&1
info "Service installiert und aktiviert"

# ── 8. Service starten / neu starten ─────────────────────────
step "Service starten"
if systemctl is-active --quiet mc-webapp; then
  systemctl restart mc-webapp
  info "mc-webapp neu gestartet"
else
  systemctl start mc-webapp
  info "mc-webapp gestartet"
fi

sleep 2

if systemctl is-active --quiet mc-webapp; then
  info "mc-webapp läuft erfolgreich"
else
  warn "mc-webapp konnte nicht gestartet werden — Logs prüfen:"
  warn "  journalctl -u mc-webapp -n 30"
fi

# ── 9. Fertig ─────────────────────────────────────────────────
echo ""
echo "  ✅  Installation abgeschlossen!"
echo ""
echo "  Zugriff:"
echo "    http://$(hostname -I | awk '{print $1}'):3001"
echo ""
echo "  Login: admin / admin  (beim ersten Start ohne mcadmin-Settings)"
echo "         Gleiche Zugangsdaten wie mcadmin, falls bereits konfiguriert."
echo ""
echo "  Wichtig:"
echo "    • PHP-Panel (mcadmin) muss parallel auf Port 80 laufen"
echo "    • config.js prüfen: $CONFIG"
echo ""
echo "  Befehle:"
echo "    Status:  systemctl status mc-webapp"
echo "    Logs:    journalctl -u mc-webapp -f"
echo "    Neustart: systemctl restart mc-webapp"
echo ""
