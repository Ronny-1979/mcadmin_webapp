# ⛏️ mc-webapp — Minecraft Bedrock Admin Panel (React + Node.js)

Moderne Web-App als Ersatz / Ergänzung zu mcadmin.  
Läuft **parallel** zu mcadmin auf Port **3001**.  
Teilt dieselbe `mcadmin_settings.json` — gleicher Login, gleiche Konfiguration.

---

## 📋 Voraussetzungen

| Software | Mindestversion |
|----------|---------------|
| Node.js  | 18.x oder neuer |
| npm      | 9.x oder neuer |
| mcadmin  | muss installiert sein (teilt Einstellungen) |

Node.js installieren (falls noch nicht vorhanden):
```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
sudo apt-get install -y nodejs
```

---

## 🚀 Installation

### 1. Dateien entpacken

```bash
sudo mkdir -p /opt/mc-webapp
sudo tar -xzf mc-webapp.tar.gz -C /opt/mc-webapp --strip-components=1
# oder ZIP:
sudo unzip mc-webapp.zip -d /opt/mc-webapp
```

### 2. Konfiguration anpassen

```bash
sudo nano /opt/mc-webapp/backend/config.js
```

Die wichtigsten Einstellungen:

```js
port: 3001,                          // Port der Web App (mcadmin = 80)
jwtSecret: 'HIER_AENDERN',          // ⚠️ Bitte ändern!

mc: {
  serverDir:   '/opt/minecraft-bedrock',   // Pfad zum Minecraft-Server
  serviceName: 'minecraft-bedrock',        // systemd-Service-Name
  fifo:        '/opt/minecraft-bedrock/server.stdin',  // FIFO für Befehle
},

panel: {
  settingsFile: '/var/www/html/mcadmin/mcadmin_settings.json',  // Gleiche Datei wie mcadmin
  backupDir:    '/var/www/html/mcadmin/backups',
},
```

### 3. Installation ausführen

```bash
cd /opt/mc-webapp
sudo bash install.sh
```

Das Skript:
- Installiert Node.js-Pakete (Backend + Frontend)
- Baut das React-Frontend (`frontend/dist/`)

### 4. Sudo-Rechte einrichten

Damit die App `systemctl` aufrufen kann:

```bash
sudo cp /opt/mc-webapp/sudoers.example /etc/sudoers.d/mc-webapp
sudo chmod 440 /etc/sudoers.d/mc-webapp
```

> **Hinweis:** Falls dein Node.js-Prozess nicht als `www-data` läuft, den Benutzernamen in `sudoers.example` anpassen.

### 5. Als systemd-Service einrichten (empfohlen)

```bash
sudo cp /opt/mc-webapp/mc-webapp.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable mc-webapp
sudo systemctl start mc-webapp
```

Status prüfen:
```bash
sudo systemctl status mc-webapp
```

Logs ansehen:
```bash
journalctl -u mc-webapp -f
```

---

## 🖥️ Zugriff

Öffne im Browser:
```
http://DEINE-IP:3001
```

**Login:** Gleiche Zugangsdaten wie mcadmin (`admin` / `admin` beim ersten Start → sofort ändern!)

---

## 🔧 Manueller Start (zum Testen)

```bash
cd /opt/mc-webapp
bash start.sh
```

---

## 🌟 Features

| Feature | Beschreibung |
|---------|-------------|
| **Dashboard** | Server-Status, Uptime, Online-Spieler, Start/Stop/Restart |
| **Konsole** | Echtzeit-Log via WebSocket (sofort, kein Polling), Befehle senden, Schnellbefehle, Verlauf (↑/↓) |
| **Welten** | Liste, Aktivieren, Löschen, Upload (.mcworld per Drag & Drop) |
| **Packs** | Resource & Behavior Packs, Upload (.mcpack/.mcaddon/.zip) |
| **Spieler** | Online-Spieler, Whitelist verwalten, OP-Berechtigungen, Kick |
| **Backups** | Erstellen, Herunterladen, Löschen (max. 20, automatische Rotation) |
| **Einstellungen** | server.properties bearbeiten, Passwort ändern, Discord-Webhook |

---

## 🔄 Unterschied zu mcadmin

| | mcadmin (PHP) | mc-webapp (Node+React) |
|---|---|---|
| Konsole | Polling alle 2,5s | WebSocket, sofort |
| Frontend | Server-seitiges PHP | React SPA |
| Log-Rendering | Kompletter Rebuild | Nur neue Zeilen anhängen |
| Backend | Apache + PHP | Node.js + Express |
| Port | 80 / 443 | 3001 |
| Einstellungen | Eigene Datei | Teilt mcadmin_settings.json |

Beide können **parallel** laufen. Die Einstellungen (Login, Discord, Backups) sind gemeinsam.

---

## 🔒 Sicherheit

- JWT-Token (24h Gültigkeit), gespeichert als httpOnly-Cookie
- Passwörter als bcrypt-Hash (identisch mit mcadmin)
- Alle API-Endpunkte erfordern Authentifizierung
- WebSocket-Verbindungen werden per Token validiert
- **`jwtSecret` in `config.js` unbedingt ändern!**

---

## 🛠️ Entwicklung (mit Hot-Reload)

```bash
# Backend (Terminal 1)
cd /opt/mc-webapp/backend
npm run dev

# Frontend (Terminal 2)  
cd /opt/mc-webapp/frontend
npm run dev
# → http://localhost:5173 (Proxy auf Backend :3001)
```

---

## ❓ Häufige Probleme

**„Konsole zeigt nichts an"**  
→ Prüfe ob `mc.logFiles` in `config.js` auf die richtige Datei zeigt.  
→ Prüfe ob der Node.js-Prozess Leserechte auf die Log-Datei hat.

**„Befehl senden funktioniert nicht"**  
→ Prüfe ob die FIFO-Datei (`server.stdin`) existiert: `ls -la /opt/minecraft-bedrock/server.stdin`  
→ Prüfe ob Node.js Schreibrechte auf die FIFO hat.

**„Server starten/stoppen schlägt fehl"**  
→ Prüfe den sudoers-Eintrag: `sudo -u www-data systemctl start minecraft-bedrock`

**„Login funktioniert nicht"**  
→ Prüfe ob `panel.settingsFile` in `config.js` auf die richtige `mcadmin_settings.json` zeigt.  
→ Beim ersten Start ohne Settings-Datei: Login mit `admin` / `admin`.

---

## 📁 Dateistruktur

```
mc-webapp/
├── backend/
│   ├── server.js       ← Express + WebSocket-Server
│   ├── minecraft.js    ← Alle MC-Interaktionen (FIFO, Log, systemctl)
│   ├── auth.js         ← JWT + bcrypt Login
│   ├── config.js       ← ⚙️ Konfiguration (hier anpassen!)
│   └── package.json
├── frontend/
│   ├── src/
│   │   ├── App.jsx                    ← Router + Sidebar
│   │   ├── components/
│   │   │   ├── Login.jsx
│   │   │   ├── Dashboard.jsx
│   │   │   ├── Console.jsx            ← WebSocket-Konsole
│   │   │   ├── Worlds.jsx
│   │   │   ├── Packs.jsx
│   │   │   ├── Players.jsx
│   │   │   ├── Backups.jsx
│   │   │   └── SettingsPage.jsx
│   │   ├── utils.js                   ← API-Helfer, Formatter
│   │   ├── useToast.js                ← Toast-Benachrichtigungen
│   │   └── index.css                  ← Dark-Theme CSS
│   └── dist/                          ← Gebaut von "npm run build"
├── install.sh          ← Installations-Skript
├── start.sh            ← Manueller Start
├── mc-webapp.service   ← systemd-Unit
├── sudoers.example     ← sudo-Berechtigung für systemctl
└── README.md           ← Diese Datei
```
