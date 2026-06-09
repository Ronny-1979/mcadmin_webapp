# ⛏️ mc-webapp — Minecraft Bedrock Admin Panel

Moderne React + Node.js Web-App als vollständige Ergänzung zum mcadmin PHP-Panel.  
Läuft **parallel** zu mcadmin auf Port **3001** und teilt dieselbe Konfiguration.

**Hauptvorteil:** Echtzeit-Konsole via WebSocket (kein Polling), vollständiges Feature-Set.

---

## 🌟 Features

| Bereich | Features |
|---------|---------|
| **Dashboard** | Server-Status, Uptime, Version · Start / Stop / Neustart · Online-Spieler mit OP / DeOP / Kick / Whitelist direkt im Dashboard |
| **Konsole** | Echtzeit-Log via WebSocket (sofort, kein Polling) · Befehle senden · Schnellbefehle · Befehlsverlauf (↑ / ↓) |
| **Welten** | Liste mit Pack-Status und Größe · Welt aktivieren / löschen · **Neue Welt erstellen** (Spielmodus, Schwierigkeit, Seed) · **Umbenennen** · **Properties-Editor** pro Welt · Upload (.mcworld per Drag & Drop) mit Ergebnis-Anzeige |
| **Packs** | Global: Resource & Behavior Packs installieren, ersetzen, löschen · **Pro-Welt:** Pack per Toggle aktivieren / deaktivieren · fehlende Packs nachliefern |
| **Spieler** | Whitelist anzeigen, hinzufügen, entfernen · OP-Berechtigungen verwalten · Online-Spieler mit Aktionen |
| **Statistiken** | Spielzeit-Tabelle pro Spieler · Sessions · Kicks · CSS-Balkendiagramm · aus Log-Analyse |
| **Backups** | Erstellen (mit Bezeichnung) · Herunterladen · **Importieren** (.tar.gz) · **Wiederherstellen** · Löschen · max. 20 (automatische Rotation) |
| **Updates** | MC Bedrock-Version prüfen & aktualisieren mit Live-Log · mcadmin Panel-Update prüfen & installieren |
| **Zeitpläne** | Auto-Backup · Auto-Neustart · Auto-Update-Prüfung mit optionaler automatischer Installation |
| **Willkommensnachrichten** | Pro-Welt-Nachricht mit Platzhaltern `{player}` `{world}` `{server}` und Vorschau |
| **Einstellungen** | server.properties · Passwort & Benutzername ändern · Discord-Webhook |

---

## 📋 Voraussetzungen

| Software | Mindestversion | Hinweis |
|----------|---------------|---------|
| **mcadmin** | beliebig | Muss installiert und auf Port 80 erreichbar sein |
| **Apache + PHP** | PHP 8.x | Für mcadmin und PHP-Bridge |
| **Node.js** | 18.x (empfohlen: 20.x) | Wird von `install.sh` automatisch installiert |
| **npm** | 9.x | Kommt mit Node.js |
| **Minecraft Bedrock Server** | beliebig | Als systemd-Service `minecraft-bedrock` |

---

## 🚀 Installation

### Einzeiler (empfohlen)

Aus dem `mcadmin_webapp`-Verzeichnis des Repositories:

```bash
sudo bash mc-webapp/install.sh
```

Das Skript erledigt **alles automatisch:**
1. Node.js 20.x installieren (falls nicht vorhanden oder zu alt)
2. npm-Abhängigkeiten installieren (Backend + Frontend)
3. React-Frontend bauen (`frontend/dist/`)
4. JWT-Secret automatisch generieren
5. Sudo-Berechtigungen einrichten (`/etc/sudoers.d/mc-webapp`)
6. Systemd-Service installieren, aktivieren und starten

### Nach der Installation

Öffne im Browser:
```
http://DEINE-SERVER-IP:3001
```

**Login:** Gleiche Zugangsdaten wie mcadmin.  
Beim ersten Start ohne mcadmin-Settings: `admin` / `admin` → sofort ändern!

---

## ⚙️ Konfiguration

Die Konfigurations-Datei liegt im Repository:

```bash
nano mc-webapp/backend/config.js
```

Nach Änderungen Service neu starten:
```bash
sudo systemctl restart mc-webapp
```

### Wichtige Einstellungen

```js
export const config = {
  port: 3001,              // Port der Web-App (mcadmin = 80)
  jwtSecret: '...',        // Automatisch generiert — nicht ändern nötig

  mc: {
    serverDir:   '/opt/minecraft-bedrock',  // Pfad zum MC-Server
    worldsDir:   '/opt/minecraft-bedrock/worlds',
    serviceName: 'minecraft-bedrock',       // systemd-Service-Name
    fifo:        '/opt/minecraft-bedrock/server.stdin',  // FIFO für Befehle
  },

  panel: {
    settingsFile: '/var/www/html/mcadmin/mcadmin_settings.json',  // Gemeinsam mit mcadmin
    backupDir:    '/var/www/html/mcadmin/backups',
    phpApiUrl:    'http://127.0.0.1/mcadmin/api/handler.php',     // PHP-Bridge
  },
};
```

---

## 🔧 Service verwalten

```bash
# Status
sudo systemctl status mc-webapp

# Logs live verfolgen
sudo journalctl -u mc-webapp -f

# Neustart
sudo systemctl restart mc-webapp

# Stoppen
sudo systemctl stop mc-webapp

# Manuell starten (zum Testen, ohne systemd)
bash mc-webapp/start.sh
```

---

## 🏗️ Architektur

```
Browser
  │  HTTP (Port 3001)   WebSocket (Port 3001/ws)
  ▼
Node.js / Express (mc-webapp)
  │
  ├── Direkte Datei-Ops:  worlds/, server.properties, mcadmin_settings.json
  ├── systemctl:          Start / Stop / Restart via sudo
  ├── FIFO:               Befehle → /opt/minecraft-bedrock/server.stdin
  ├── Log-Analyse:        getPlayerStats() aus server.log
  │
  └── PHP-Bridge (intern, Port 80):
        ↓ POST multipart + X-McAdmin-Secret Header
      Apache / PHP (mcadmin) — handler.php
        ↓
      Komplexe Ops: World-Upload (NBT-Parsing), Pack-Management,
                    MC-Updates, Panel-Updates
```

**PHP-Bridge:** Node.js leitet komplexe Operationen intern an das PHP-Panel weiter.  
Das PHP-Panel muss dafür auf `http://127.0.0.1/mcadmin/` erreichbar sein.  
Das Shared-Secret wird beim ersten Start automatisch in `mcadmin_settings.json` gespeichert.

---

## 🔄 Vergleich mit mcadmin (PHP-Panel)

| | mcadmin (PHP) | mc-webapp (Node.js + React) |
|---|---|---|
| Konsole | Polling alle 2,5 s | WebSocket, sofort |
| Frontend | Server-seitiges PHP | React SPA |
| Port | 80 / 443 | 3001 |
| Welt erstellen | ✅ | ✅ |
| Welt umbenennen | ✅ | ✅ |
| Welt-Properties | ✅ | ✅ |
| Pack pro Welt togglen | ✅ | ✅ |
| Spieler-Statistiken | ✅ | ✅ |
| Backup Import/Restore | ✅ | ✅ |
| Updates (MC + Panel) | ✅ | ✅ |
| Zeitpläne | ✅ (via cron.php) | ✅ (Einstellungen, cron.php führt aus) |
| Willkommensnachrichten | ✅ | ✅ |

Beide laufen **parallel**. Einstellungen (Login, Discord, Zeitpläne, Backups) sind gemeinsam über `mcadmin_settings.json` geteilt.

---

## 🔒 Sicherheit

- JWT-Token (24 h Gültigkeit), httpOnly-Cookie — kein localStorage
- Passwörter als **bcrypt**-Hash (identisch mit mcadmin)
- Alle API-Endpunkte erfordern Authentifizierung
- WebSocket-Verbindungen per Token validiert
- PHP-Bridge nur intern (`127.0.0.1`) mit Shared-Secret
- Settings-API gibt niemals `admin_pass_hash` oder `php_bridge_secret` zurück

---

## 🛠️ Entwicklung (Hot-Reload)

```bash
# Backend (Terminal 1)
cd mc-webapp/backend
npm run dev          # node --watch server.js

# Frontend (Terminal 2)
cd mc-webapp/frontend
npm run dev          # Vite Dev-Server → http://localhost:5173
                     # Proxy auf Backend :3001 bereits konfiguriert
```

---

## ❓ Häufige Probleme

**„Konsole zeigt nichts an"**  
→ `config.js` → `mc.logFiles` auf richtige Datei prüfen.  
→ Leserechte: `ls -la /opt/minecraft-bedrock/logs/latest.log`

**„Befehl senden funktioniert nicht"**  
→ FIFO prüfen: `ls -la /opt/minecraft-bedrock/server.stdin`  
→ Schreibrechte für `www-data` auf die FIFO prüfen.

**„Server starten/stoppen schlägt fehl"**  
→ Sudoers-Eintrag testen: `sudo -u www-data systemctl status minecraft-bedrock`  
→ Falls Fehler: `sudo visudo -f /etc/sudoers.d/mc-webapp` prüfen.

**„Welt-Upload / Pack-Upload schlägt fehl"**  
→ mcadmin PHP-Panel muss auf `http://127.0.0.1/mcadmin/` erreichbar sein.  
→ Logs: `journalctl -u mc-webapp -f` und Apache-Log prüfen.

**„Login funktioniert nicht"**  
→ `panel.settingsFile` in `config.js` auf richtige `mcadmin_settings.json` prüfen.  
→ Ohne Settings-Datei: Login mit `admin` / `admin`.

**„PHP-Bridge antwortet nicht"**  
→ Apache läuft? `systemctl status apache2`  
→ Shared-Secret in `mcadmin_settings.json` vorhanden? (`php_bridge_secret`-Feld)  
→ mc-webapp-Service neu starten: `sudo systemctl restart mc-webapp`

---

## 📁 Dateistruktur

```
mc-webapp/
├── backend/
│   ├── server.js          ← Express + WebSocket + alle API-Routen
│   ├── minecraft.js       ← MC-Interaktionen (FIFO, Log, systemctl, Datei-Ops)
│   ├── php-bridge.js      ← Proxy für komplexe PHP-Ops (World-Upload, Updates…)
│   ├── auth.js            ← JWT + bcrypt Login
│   ├── config.js          ← ⚙️ Konfiguration (hier anpassen!)
│   └── package.json
├── frontend/
│   ├── src/
│   │   ├── App.jsx                    ← Router + Sidebar-Navigation
│   │   └── components/
│   │       ├── Login.jsx
│   │       ├── Dashboard.jsx          ← Status + Spieler-Aktionen
│   │       ├── Console.jsx            ← WebSocket-Echtzeit-Konsole
│   │       ├── Worlds.jsx             ← Welten verwalten (erstellen, rename, props)
│   │       ├── Packs.jsx              ← Global + Pro-Welt Pack-Management
│   │       ├── Players.jsx            ← Whitelist + OP-Verwaltung
│   │       ├── Stats.jsx              ← Spieler-Statistiken aus Logs
│   │       ├── Backups.jsx            ← Backup erstellen, import, restore
│   │       ├── Updates.jsx            ← MC + Panel Updates
│   │       ├── Schedules.jsx          ← Auto-Backup/Restart/Update Zeitpläne
│   │       ├── WelcomeMessages.jsx    ← Willkommensnachrichten pro Welt
│   │       └── SettingsPage.jsx       ← server.properties + Passwort + Discord
│   ├── dist/                          ← Gebaut von "npm run build"
│   └── package.json
├── install.sh          ← Vollautomatisches Installations-Skript (als root)
├── start.sh            ← Manueller Start zum Testen
├── mc-webapp.service   ← systemd-Unit (von install.sh angepasst)
├── sudoers.example     ← sudo-Berechtigungen (von install.sh installiert)
└── README.md           ← Diese Datei
```
