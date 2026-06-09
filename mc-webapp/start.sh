#!/bin/bash
# mc-webapp manuell starten (zum Testen, nicht als root nötig)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "[mc-webapp] Starte auf Port 3001..."
echo "[mc-webapp] Verzeichnis: $SCRIPT_DIR/backend"
echo "[mc-webapp] Zum Beenden: Strg+C"
echo ""
cd "$SCRIPT_DIR/backend"
node server.js
