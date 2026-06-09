# ⛏️ Minecraft Bedrock Admin Panel

> Vollständiges Web-Interface zur Verwaltung eines Minecraft Bedrock Servers unter Linux.  
> **PHP-Panel** (Port 80) + **React/Node.js-App** (Port 3001) — beide mit einem einzigen Befehl installiert.

---

## 🚀 Schnellstart

### Installation

> **Voraussetzung** — `curl` und `sudo` müssen installiert sein:
> ```bash
> # Debian / Ubuntu / Raspberry Pi / Linux Mint / Pop!_OS
> apt-get install -y curl sudo
>
> # AlmaLinux / Rocky / Oracle Linux / Fedora / RHEL
> dnf install -y curl sudo
>
> # Arch Linux
> pacman -S --noconfirm curl sudo
> ```

Richtet alles von Grund auf ein — Apache, PHP, Node.js, Minecraft-Server, beide Web-Interfaces und optional HTTPS:

```bash
curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin_webapp/main/install.sh | sudo bash
```

### Update (beide Komponenten, Server läuft weiter)

```bash
curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin_webapp/main/install.sh | sudo bash -s -- --update
```

### Deinstallieren

```bash
curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin_webapp/main/install.sh | sudo bash -s -- --uninstall
```

> **Standard-Login:** `admin` / `admin` — bitte **sofort** nach dem ersten Login unter **Einstellungen → Benutzer & Passwort** ändern!

---

## 🖥️ Was wird installiert?

Der Installer richtet **beide** Interfaces gleichzeitig ein:

| | PHP-Panel | React-App (mc-webapp) |
|---|---|---|
| **Adresse** | `http://IP/mcadmin/` (Port 80) | `http://IP:3001` (Port 3001) |
| **Technik** | Apache + PHP 8.x | Node.js 20 + React 18 |
| **Konsole** | Polling alle 2,5 s | **WebSocket — sofort** |
| **Login** | Gemeinsame `mcadmin_settings.json` | Gleicher Benutzer + Passwort |
| **Backups** | Gleicher Backup-Ordner | Gleicher Backup-Ordner |

Beide laufen parallel und teilen dieselbe Konfiguration — du kannst jederzeit wechseln.

---

## ⚙️ Was der Installer macht

| Schritt | Was passiert |
|:-------:|--------------|
| 1 | 🔍 OS erkennen — Debian/Ubuntu/Raspberry Pi, RHEL/Alma/Rocky, Arch |
| 2 | 📦 Apache + PHP 8.x + alle benötigten Extensions installieren |
| 3 | 📦 Node.js 20.x installieren (für mc-webapp) |
| 4 | 🌐 Apache VirtualHost anlegen und aktivieren |
| 5 | 📥 Alle Dateien von GitHub herunterladen (PHP-Panel + React-App) |
| 6 | ⚛️ React-Frontend bauen (`npm install` + `npm run build`) |
| 7 | 🗂️ Minecraft-Verzeichnis `/opt/minecraft-bedrock` einrichten |
| 8 | ⬇️ Bedrock-Server automatisch von Mojang herunterladen und starten |
| 9 | 🔧 Zwei systemd-Services einrichten: `minecraft-bedrock` + `mc-webapp` |
| 10 | 🔑 sudo-Berechtigungen für `systemctl` und Backup-Restore einrichten |
| 11 | ⏰ Cron-Job für automatische Backups und Zeitpläne einrichten |
| 12 | 🔒 *Optional:* Let's Encrypt — Domain, Certbot, HTTPS + Auto-Renewal |
| 13 | 🛡️ *Optional:* Firewall-Ports freigeben (UFW / firewalld) |
| 14 | ✅ Beide Interfaces starten und URLs anzeigen |

---

## 🌟 Features beider Interfaces

### 🏠 Dashboard
Server-Status, Uptime, aktive Welt und Online-Spieler. Server starten, stoppen und neu starten.  
**React-App zusätzlich:** OP / DeOP / Kick / Whitelist direkt pro Online-Spieler.

### 🌍 Welten
Welten per Drag & Drop importieren (`.mcworld`), umbenennen, löschen, Welt wechseln.  
Welt-eigene `server.properties` — jede Welt hat ihre eigene Konfiguration.  
**React-App zusätzlich:** Neue Welt erstellen (Spielmodus, Schwierigkeit, Seed), Properties-Editor pro Welt, Pack-Status-Badge.

### 📦 Packs & Add-ons
Resource- und Behavior-Packs hochladen (`.mcpack` / `.mcaddon` / `.zip`), ersetzen, löschen.  
Packs pro Welt aktivieren/deaktivieren, fehlende Packs nachliefern.

### 👤 Spieler
Whitelist anzeigen, hinzufügen, entfernen. OP-Status setzen und entziehen. Spieler kicken.

### 📊 Spieler-Statistiken
Spielzeit pro Spieler, Sessions, Kicks, erster Login — aus Log-Analyse.  
**React-App:** CSS-Balkendiagramm für Spielzeit.

### 💻 Konsole
Live-Log mit Farb-Klassifizierung. Befehle senden, Verlauf mit ↑/↓.  
**React-App:** WebSocket — sofortige Anzeige ohne Polling.  
**PHP-Panel:** Polling alle 2,5 s + Schnellbefehle-Panel.

### 💾 Backups
Manuell erstellen (mit Bezeichnung), herunterladen, löschen. Max. 20 Backups, automatische Rotation.  
**React-App zusätzlich:** Backup importieren (`.tar.gz`), Backup wiederherstellen.

### ⬆️ Updates
Minecraft Bedrock-Version prüfen und per Klick aktualisieren (mit automatischem Backup).  
Panel-Update direkt aus dem Browser starten.

### ⏱️ Zeitpläne
Auto-Backup, Auto-Neustart, Auto-Update-Prüfung — jeweils mit frei wählbarer Uhrzeit.  
**React-App:** Toggle-Interface direkt im Browser.

### 🎉 Willkommensnachrichten
Pro Welt eine Nachricht konfigurieren, die beim Einloggen ausgespielt wird.  
Platzhalter `{player}`, `{world}`, `{server}` — Modus wählbar: Chat oder Title.

### ⚙️ Einstellungen
Vollständiger `server.properties`-Editor. Benutzername und Passwort ändern.  
Discord-Webhook mit 9 einzeln aktivierbaren Events.

---

## 🔒 Sicherheit

- Passwörter als **bcrypt-Hash** gespeichert, niemals im Klartext
- PHP-Panel: Session-basierte Authentifizierung
- React-App: JWT-Token (24 h), httpOnly-Cookie — kein localStorage
- **PHP-Bridge:** interne Kommunikation (Node.js → PHP) via Shared-Secret, nur `127.0.0.1`
- Alle API-Endpunkte erfordern Authentifizierung
- 🔐 **Passwort sofort ändern** — Panel zeigt Warnung solange `admin/admin` aktiv ist

---

## 🖥️ Unterstützte Betriebssysteme

![Debian](https://img.shields.io/badge/Debian-11%20%7C%2012%20%7C%2013-A81D33?logo=debian&logoColor=white)
![Ubuntu](https://img.shields.io/badge/Ubuntu-20.04%2B-E95420?logo=ubuntu&logoColor=white)
![Raspberry Pi](https://img.shields.io/badge/Raspberry%20Pi%20OS-✓-C51A4A?logo=raspberry-pi&logoColor=white)
![Linux Mint](https://img.shields.io/badge/Linux%20Mint-✓-87CF3E?logo=linux-mint&logoColor=white)
![Pop!_OS](https://img.shields.io/badge/Pop!__OS-✓-48B9C7?logo=pop-os&logoColor=white)
![AlmaLinux](https://img.shields.io/badge/AlmaLinux%20%7C%20Rocky%20%7C%20CentOS-8%20%7C%209-0F4266?logo=almalinux&logoColor=white)
![Fedora](https://img.shields.io/badge/Fedora-aktuell-51A2DA?logo=fedora&logoColor=white)
![Oracle Linux](https://img.shields.io/badge/Oracle%20Linux-8%20%7C%209-F80000?logo=oracle&logoColor=white)
![Arch](https://img.shields.io/badge/Arch%20Linux-✓-1793D1?logo=arch-linux&logoColor=white)

---

## 📝 Wichtige Hinweise

- 📁 Backups: `/var/www/html/mcadmin/backups/` — max. 20, älteste werden automatisch gelöscht
- ⚙️ Serverpfad in `config.php` anpassen (`MC_SERVER_DIR`), falls nicht `/opt/minecraft-bedrock`
- 📋 Cron-Aktivitäten (Backups, Neustarts) werden in `/var/log/mcadmin-cron.log` protokolliert
- ⚛️ mc-webapp Konfiguration: `/opt/mc-webapp/backend/config.js`
- 🔄 Service-Status: `systemctl status mc-webapp` / `systemctl status minecraft-bedrock`

---

## 📁 Dateistruktur im Repository

```
mcadmin_webapp/
├── install.sh              ← 🚀 Einziger Installer für alles
├── README.md               ← Diese Datei
├── mcadmin/                ← PHP-Panel (Apache + PHP 8.x, Port 80)
│   ├── api/handler.php     ← API-Endpunkte + PHP-Bridge-Auth
│   ├── config.php          ← PHP-Konfiguration + Pfade
│   ├── includes/
│   │   └── functions.php   ← Kernfunktionen (3000+ Zeilen)
│   ├── index.php           ← Frontend-Einstiegspunkt
│   └── cron.php            ← Zeitplan-Ausführung (Backup, Restart, Update)
└── mc-webapp/              ← React + Node.js App (Port 3001)
    ├── backend/
    │   ├── server.js       ← Express + WebSocket + alle API-Routen
    │   ├── minecraft.js    ← MC-Interaktionen (FIFO, Log, Datei-Ops)
    │   ├── php-bridge.js   ← Proxy für komplexe PHP-Ops
    │   ├── auth.js         ← JWT + bcrypt Login
    │   └── config.js       ← Konfiguration (Pfade, Port, Secret)
    └── frontend/src/
        ├── App.jsx          ← Router + Sidebar
        └── components/
            ├── Dashboard.jsx       ← Status + Spieler-Aktionen
            ├── Console.jsx         ← WebSocket-Echtzeit-Konsole
            ├── Worlds.jsx          ← Welten (erstellen, rename, props)
            ├── Packs.jsx           ← Global + Pro-Welt Pack-Management
            ├── Players.jsx         ← Whitelist + OP-Verwaltung
            ├── Stats.jsx           ← Spieler-Statistiken
            ├── Backups.jsx         ← Backup + Import + Restore
            ├── Updates.jsx         ← MC + Panel Updates
            ├── Schedules.jsx       ← Auto-Backup/Restart/Update
            ├── WelcomeMessages.jsx ← Willkommensnachrichten pro Welt
            └── SettingsPage.jsx    ← server.properties + Passwort + Discord
```
