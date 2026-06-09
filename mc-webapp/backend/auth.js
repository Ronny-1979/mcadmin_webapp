import jwt from 'jsonwebtoken';
import bcrypt from 'bcrypt';
import fs from 'fs';
import { config } from './config.js';

// Lädt die Einstellungen aus mcadmin_settings.json (gleiche Datei wie mcadmin)
function loadSettings() {
  try {
    if (fs.existsSync(config.panel.settingsFile)) {
      return JSON.parse(fs.readFileSync(config.panel.settingsFile, 'utf8'));
    }
  } catch {}
  return null;
}

// Prüft Benutzername + Passwort gegen mcadmin_settings.json oder Defaults
export async function verifyCredentials(user, pass) {
  const settings = loadSettings();
  const storedUser = settings?.admin_user ?? config.defaultUser;
  const storedHash = settings?.admin_pass_hash;

  if (user !== storedUser) return false;

  // Wenn kein Hash gespeichert → Default-Passwort prüfen
  if (!storedHash) return pass === config.defaultPass;

  // PHP generiert $2y$-Hashes, Node.js bcrypt erwartet $2b$ (algorithmisch identisch)
  const normalizedHash = storedHash.replace(/^\$2y\$/, '$2b$');
  return await bcrypt.compare(pass, normalizedHash);
}

// Erstellt einen JWT-Token
export function createToken(user) {
  return jwt.sign({ user }, config.jwtSecret, { expiresIn: config.jwtExpiry });
}

// Middleware: JWT aus Cookie oder Authorization-Header prüfen
export function requireAuth(req, res, next) {
  const token =
    req.cookies?.mc_token ||
    req.headers.authorization?.replace('Bearer ', '');

  if (!token) return res.status(401).json({ error: 'Nicht angemeldet' });

  try {
    req.user = jwt.verify(token, config.jwtSecret);
    next();
  } catch {
    res.status(401).json({ error: 'Token ungültig oder abgelaufen' });
  }
}

// WebSocket-Token prüfen (wird beim WS-Handshake aus Query-Param gelesen)
export function verifyWsToken(token) {
  try {
    return jwt.verify(token, config.jwtSecret);
  } catch {
    return null;
  }
}
