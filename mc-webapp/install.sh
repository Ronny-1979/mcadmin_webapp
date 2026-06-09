#!/bin/bash
# ============================================================
# mc-webapp — Installations-Skript
# ============================================================
set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

info()  { echo -e "${GREEN}[✓]${NC} $1"; }
warn()  { echo -e "${YELLOW}[!]${NC} $1"; }
error() { echo -e "${RED}[✗]${NC} $1"; exit 1; }

echo ""
echo "  ⛏️  mc-webapp Installer"
echo "  ========================"
echo ""

# Node.js prüfen
if ! command -v node &>/dev/null; then
  error "Node.js nicht gefunden. Bitte installieren: https://nodejs.org (v18+)"
fi
NODE_VER=$(node -e "process.stdout.write(process.version)")
info "Node.js gefunden: $NODE_VER"

# npm prüfen
if ! command -v npm &>/dev/null; then
  error "npm nicht gefunden."
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Backend-Abhängigkeiten
info "Installiere Backend-Abhängigkeiten..."
cd "$SCRIPT_DIR/backend"
npm install --omit=dev

# Frontend-Abhängigkeiten + Build
info "Installiere Frontend-Abhängigkeiten..."
cd "$SCRIPT_DIR/frontend"
npm install

info "Baue Frontend..."
npm run build

# Upload-Verzeichnis anlegen
mkdir -p /tmp/mc-webapp-uploads

info "Installation abgeschlossen!"
echo ""
echo "  Nächste Schritte:"
echo "  1. backend/config.js anpassen (Pfade prüfen)"
echo "  2. sudo ./start.sh  (oder systemd-Service einrichten)"
echo ""
warn "Sudo-Rechte für systemctl werden benötigt (sudoers-Eintrag beachten)"
echo ""
