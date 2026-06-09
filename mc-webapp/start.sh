#!/bin/bash
# mc-webapp starten
cd "$(dirname "${BASH_SOURCE[0]}")/backend"
echo "[mc-webapp] Starte auf Port 3001..."
node server.js
