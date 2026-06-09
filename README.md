# ⛏️ Minecraft Bedrock Admin Panel

> Vollständiges PHP Web-Interface zur Verwaltung eines Minecraft Bedrock Servers unter Linux — mit einem einzigen Befehl installiert.

---

## 🚀 Schnellstart

### Installation

> **Voraussetzung** — `curl` und `sudo` müssen installiert sein. Falls nicht:
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

Richtet alles von Grund auf ein — Apache, PHP, Minecraft-Server, Firewall und optional HTTPS:
```bash
curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin/main/install.sh | sudo bash
```

### Panel aktualisieren
Lädt nur die Panel-Dateien neu. Der Minecraft-Server läuft weiter, alle Einstellungen und Passwörter bleiben erhalten:
```bash
curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin/main/install.sh | sudo bash -s -- --update
```

### Deinstallieren
Führt durch jeden Schritt einzeln und fragt vor jeder Aktion nach — nichts wird ohne Bestätigung gelöscht:
```bash
curl -fsSL https://raw.githubusercontent.com/Ronny-1979/mcadmin/main/install.sh | sudo bash -s -- --uninstall
```

> **Standard-Login:** `admin` / `admin` — bitte **sofort** nach dem ersten Login unter **Einstellungen → Benutzer & Passwort** ändern!

---

## ⚙️ Was der Installer beim ersten Mal macht

| Schritt | Was passiert |
|:-------:|--------------|
| 1 | 🔍 OS erkennen — Debian/Ubuntu/Raspberry Pi, RHEL/Alma/Rocky, Arch |
| 2 | 📦 Apache + PHP 8.x + alle benötigten Extensions installieren |
| 3 | 🌐 Apache VirtualHost anlegen und aktivieren |
| 4 | 📥 Panel-Dateien von GitHub herunterladen |
| 5 | 🗂️ Minecraft-Verzeichnis `/opt/minecraft-bedrock` + systemd-Service einrichten |
| 6 | ⬇️ Bedrock-Server automatisch von Mojang herunterladen und starten |
| 7 | ⏰ Cron-Job für automatische Backups und Zeitpläne einrichten |
| 8 | 🔒 *Optional:* Let's Encrypt — Domain, Certbot, HTTPS + Auto-Renewal |
| 9 | 🛡️ *Optional:* Firewall-Ports freigeben (UFW / firewalld) |

---

## 🖥️ Das Web-Interface

### 🏠 Dashboard
Auf einen Blick: Server-Status, Uptime, aktive Welt und Online-Spieler. Server starten, stoppen und neu starten per Knopfdruck. Online-Spieler lassen sich direkt OP-en, kicken oder zur Whitelist hinzufügen. Die Live-Konsole läuft immer sichtbar mit.

### 🌍 Welten
Welten erstellen, per Drag & Drop importieren (`.mcworld`), umbenennen oder löschen. Beim Welt-Wechsel wird die jeweilige `server.properties` automatisch gespeichert und wieder geladen — jede Welt hat also ihre eigene Konfiguration. Packs sind direkt in jede Welt-Karte integriert: fehlende Packs lassen sich per Upload reparieren, vorhandene Packs aktivieren/deaktivieren oder aus der Welt entfernen. Neue Packs können direkt beim Upload sofort für die jeweilige Welt aktiviert werden.

### ⚙️ Server-Einstellungen
Vollständiger Editor für `server.properties` direkt im Browser, aufgeteilt in Allgemein, Gameplay, Performance und Netzwerk. Unbekannte Keys werden automatisch als Freitextfelder dargestellt.

### 📦 Packs & Add-ons
Globale Übersicht aller installierten Resource- und Behavior-Packs mit Welt-Auswahl. Packs aktivieren/deaktivieren, komplett löschen oder direkt durch eine neue Version ersetzen — alle Welt-Referenzen werden automatisch aktualisiert. Der Pack-Upload selbst erfolgt direkt in den Welten-Karten.

### 📋 Whitelist & Spieler
Whitelist verwalten, OP-Status setzen und entziehen. Dazu eine Spieler-Statistik mit Spielzeit-Balkendiagramm, Session-Verlauf, Kicks und erstem Login.

### 🎉 Willkommensnachrichten
Pro Welt eine Willkommensnachricht konfigurieren, die beim Einloggen automatisch ausgespielt wird. Darstellungs-Modus wählbar: 💬 Chat oder 📺 Title (6 s / 10 s Einblendung in der Bildschirmmitte). Die Platzhalter `{player}`, `{world}` und `{server}` werden beim Versenden automatisch ersetzt.

### 💻 Konsole
Live-Log mit Farb-Klassifizierung (Verbindungen, Fehler, Warnungen, Chat). Befehle direkt senden, Verlauf mit ↑/↓ navigieren, Zeilenanzahl wählbar. Schnellbefehle für `list`, `save all`, Tag/Nacht, Wetter und Ankündigungen.

---

## 🔧 Einstellungen

| Tab | Inhalt |
|-----|--------|
| ⬆️ **Updates** | Minecraft-Version prüfen & per Klick updaten (mit automatischem Backup davor), Panel-Update |
| 💾 **Backups** | Manuelle Backups mit Bezeichnung, automatischer Tages-Backup zu einstellbarer Uhrzeit, Import & Restore als `.tar.gz` |
| ⏱️ **Zeitpläne** | Automatischer Server-Neustart + automatische tägliche Update-Prüfung — jeweils mit frei wählbarer Uhrzeit |
| 👤 **Benutzer** | Benutzername und Passwort direkt im Panel ändern |
| 🎮 **Discord** | Webhook-URL konfigurieren, 9 Events einzeln aktivierbar: Start, Stop, Beitritt, Verlassen, Kick, Backup, Update installiert, Update verfügbar, Weltwechsel |

---

## 📝 Wichtige Hinweise

- 🔐 **Passwort sofort ändern** — das Panel zeigt eine deutliche Warnung solange `admin/admin` aktiv ist
- 🔑 Passwörter werden als **bcrypt-Hash** gespeichert, niemals im Klartext
- 📁 Backups liegen unter `/var/www/html/mcadmin/backups/` — maximal 20, älteste werden automatisch gelöscht
- ⚙️ Serverpfad in `config.php` anpassen (`MC_SERVER_DIR`), falls der Server **nicht** unter `/opt/minecraft-bedrock` liegt
- 📋 Cron-Aktivitäten (Backups, Neustarts) werden in `/var/log/mcadmin-cron.log` protokolliert

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
