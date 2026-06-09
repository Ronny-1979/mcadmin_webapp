// ============================================================
// PHP-Bridge — leitet komplexe Operationen an das PHP-Panel weiter
// Authentifizierung via Shared-Secret (X-McAdmin-Secret Header)
// ============================================================

import fs from 'fs';
import http from 'http';
import { randomBytes } from 'crypto';
import { config } from './config.js';

let _cachedSecret = null;

// Secret aus mcadmin_settings.json lesen
function getBridgeSecret() {
  if (_cachedSecret) return _cachedSecret;
  try {
    if (fs.existsSync(config.panel.settingsFile)) {
      const s = JSON.parse(fs.readFileSync(config.panel.settingsFile, 'utf8'));
      if (s.php_bridge_secret) {
        _cachedSecret = s.php_bridge_secret;
        return _cachedSecret;
      }
    }
  } catch {}
  return '';
}

// Secret-Cache invalidieren (nach Settings-Änderung)
export function invalidateSecretCache() {
  _cachedSecret = null;
}

// Beim Start: Secret generieren falls nicht vorhanden
export async function initBridge() {
  try {
    let settings = {};
    if (fs.existsSync(config.panel.settingsFile)) {
      settings = JSON.parse(fs.readFileSync(config.panel.settingsFile, 'utf8'));
    }
    if (!settings.php_bridge_secret) {
      settings.php_bridge_secret = randomBytes(32).toString('hex');
      const tmp = config.panel.settingsFile + '.tmp';
      fs.writeFileSync(tmp, JSON.stringify(settings, null, 2));
      fs.renameSync(tmp, config.panel.settingsFile);
      console.log('[php-bridge] Shared Secret generiert und in mcadmin_settings.json gespeichert');
    }
    _cachedSecret = settings.php_bridge_secret;
    console.log('[php-bridge] Bereit');
  } catch (e) {
    console.warn('[php-bridge] Secret-Init fehlgeschlagen:', e.message,
      '(mcadmin PHP-Panel möglicherweise nicht installiert — Bridge-Funktionen nicht verfügbar)');
  }
}

// ── Multipart FormData Builder ────────────────────────────────

function buildMultipart(fields, files) {
  const boundary = '----McAdminBridge' + randomBytes(8).toString('hex');
  const chunks = [];

  for (const [key, val] of Object.entries(fields)) {
    chunks.push(Buffer.from(
      `--${boundary}\r\n` +
      `Content-Disposition: form-data; name="${key}"\r\n\r\n` +
      `${val}\r\n`,
      'utf8'
    ));
  }

  for (const { fieldName, buffer, filename, mimeType } of files) {
    chunks.push(Buffer.from(
      `--${boundary}\r\n` +
      `Content-Disposition: form-data; name="${fieldName}"; filename="${filename}"\r\n` +
      `Content-Type: ${mimeType || 'application/octet-stream'}\r\n\r\n`,
      'utf8'
    ));
    chunks.push(buffer);
    chunks.push(Buffer.from('\r\n', 'utf8'));
  }

  chunks.push(Buffer.from(`--${boundary}--\r\n`, 'utf8'));
  return { boundary, body: Buffer.concat(chunks) };
}

// ── Hauptfunktion: PHP-Action aufrufen ────────────────────────

/**
 * Ruft eine PHP-API-Action via POST auf
 * @param {string} action   - PHP handler action (z.B. 'upload_world')
 * @param {Object} fields   - Formularfelder als { key: value }
 * @param {Array}  files    - Datei-Uploads als [{ fieldName, buffer, filename, mimeType }]
 * @returns {Promise<Object>} JSON-Antwort vom PHP-Handler
 */
export async function phpAction(action, fields = {}, files = []) {
  const secret = getBridgeSecret();
  const { boundary, body } = buildMultipart({ action, ...fields }, files);
  const urlObj = new URL(config.panel.phpApiUrl);

  return new Promise((resolve, reject) => {
    const req = http.request({
      hostname: urlObj.hostname,
      port:     parseInt(urlObj.port) || 80,
      path:     urlObj.pathname,
      method:   'POST',
      headers: {
        'Content-Type':     `multipart/form-data; boundary=${boundary}`,
        'Content-Length':   body.length,
        'X-McAdmin-Secret': secret,
      },
    }, (res) => {
      const chunks = [];
      res.on('data', c => chunks.push(c));
      res.on('end', () => {
        const raw = Buffer.concat(chunks).toString('utf8');
        try {
          resolve(JSON.parse(raw));
        } catch {
          reject(new Error(`PHP-Bridge: Ungültige Antwort (HTTP ${res.statusCode}): ${raw.slice(0, 300)}`));
        }
      });
    });

    req.setTimeout(30000, () => {
      req.destroy(new Error('PHP-Bridge: Timeout nach 30s'));
    });
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

/**
 * Hilfsfunktion: Multer-Datei-Upload an PHP-Bridge weiterleiten
 */
export async function phpActionWithFile(action, fields, multerFile, phpFieldName = 'file') {
  const buffer = fs.readFileSync(multerFile.path);
  return phpAction(action, fields, [{
    fieldName: phpFieldName,
    buffer,
    filename:  multerFile.originalname,
    mimeType:  multerFile.mimetype || 'application/octet-stream',
  }]);
}
