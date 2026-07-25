import express from 'express';
import { WebSocketServer } from 'ws';
import { createServer } from 'http';
import cookieParser from 'cookie-parser';
import cors from 'cors';
import multer from 'multer';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

import { config } from './config.js';
import { verifyCredentials, createToken, requireAuth, verifyWsToken } from './auth.js';
import * as mc from './minecraft.js';
import { phpAction, phpActionWithFile, initBridge, invalidateSecretCache } from './php-bridge.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const app    = express();
const server = createServer(app);
const wss    = new WebSocketServer({ server, path: '/ws' });

// ── Middleware ────────────────────────────────────────────────
app.use(cors({ origin: true, credentials: true }));
app.use(express.json());
app.use(cookieParser());

fs.mkdirSync(config.panel.uploadDir, { recursive: true });
const upload = multer({ dest: config.panel.uploadDir });

// ── Statische Frontend-Dateien ────────────────────────────────
const frontendDist = path.join(__dirname, '../frontend/dist');
if (fs.existsSync(frontendDist)) app.use(express.static(frontendDist));

// ── Auth ──────────────────────────────────────────────────────
app.post('/api/login', async (req, res) => {
  const { user, pass } = req.body;
  if (!user || !pass) return res.status(400).json({ error: 'Benutzername und Passwort erforderlich' });
  const ok = await verifyCredentials(user, pass);
  if (!ok) return res.status(401).json({ error: 'Falsche Zugangsdaten' });
  const token = createToken(user);
  res.cookie('mc_token', token, { httpOnly: true, maxAge: 86400000 });
  res.json({ success: true, token });
});

app.post('/api/logout', (req, res) => { res.clearCookie('mc_token'); res.json({ success: true }); });
app.get('/api/me',     requireAuth, (req, res) => res.json({ user: req.user.user }));
app.get('/api/wstoken',requireAuth, (req, res) => res.json({ token: createToken(req.user.user) }));

// ── Dashboard / Status ────────────────────────────────────────
app.get('/api/status', requireAuth, (req, res) => {
  res.json({
    running:       mc.isRunning(),
    uptime:        mc.getUptime(),
    version:       mc.getVersion(),
    installed:     mc.isInstalled(),
    activeWorld:   mc.getActiveWorld(),
    onlinePlayers: mc.getOnlinePlayers(),
  });
});

// ── Server-Steuerung ──────────────────────────────────────────
app.post('/api/server/start',   requireAuth, (req, res) => res.json(mc.startServer()));
app.post('/api/server/stop',    requireAuth, (req, res) => res.json(mc.stopServer()));
app.post('/api/server/restart', requireAuth, (req, res) => res.json(mc.restartServer()));

// ── Spieler-Aktionen (Dashboard) ─────────────────────────────
app.post('/api/players/op',        requireAuth, (req, res) => res.json(mc.opPlayer(req.body.name || '')));
app.post('/api/players/deop',      requireAuth, (req, res) => res.json(mc.deopPlayer(req.body.name || '')));
app.post('/api/players/kick',      requireAuth, (req, res) => res.json(mc.kickPlayer(req.body.name || '', req.body.reason)));
app.post('/api/players/whitelist', requireAuth, (req, res) => {
  const { name, action } = req.body;
  if (action === 'remove') return res.json(mc.removeFromWhitelist(name));
  return res.json(mc.addToWhitelist(name));
});

// ── Konsole ───────────────────────────────────────────────────
app.get('/api/log', requireAuth, (req, res) => {
  res.json({ lines: mc.getLogLines(parseInt(req.query.n) || 100) });
});
app.post('/api/console/send', requireAuth, (req, res) => res.json(mc.sendCommand(req.body.cmd || '')));

// ── Welten ────────────────────────────────────────────────────
app.get('/api/worlds',         requireAuth, (req, res) => res.json(mc.listWorlds()));
app.get('/api/worlds/active',  requireAuth, (req, res) => res.json({ active: mc.getActiveWorld() }));
app.post('/api/worlds/switch', requireAuth, (req, res) => res.json(mc.setActiveWorld(req.body.name)));
app.post('/api/worlds/create', requireAuth, (req, res) => res.json(mc.createWorld(req.body.name, req.body)));

app.post('/api/worlds/:name/rename', requireAuth, (req, res) =>
  res.json(mc.renameWorld(req.params.name, req.body.newName)));

app.delete('/api/worlds/:name', requireAuth, (req, res) => res.json(mc.deleteWorld(req.params.name)));

// Welt-Properties
app.get('/api/worlds/:name/properties', requireAuth, (req, res) =>
  res.json(mc.getWorldProperties(req.params.name)));
app.post('/api/worlds/:name/properties', requireAuth, (req, res) =>
  res.json(mc.saveWorldProperties(req.params.name, req.body)));

// Welt-Upload (.mcworld) — via PHP-Bridge (NBT-Parsing, Spawn-Fix, Pack-Extraktion)
app.post('/api/worlds/upload', requireAuth, upload.single('file'), async (req, res) => {
  if (!req.file) return res.status(400).json({ success: false, error: 'Keine Datei' });
  try {
    const r = await phpActionWithFile('upload_world', { force: req.body.force || '0' }, req.file, 'world');
    try { fs.unlinkSync(req.file.path); } catch {}
    res.json(r);
  } catch (e) {
    try { fs.unlinkSync(req.file.path); } catch {}
    res.status(500).json({ success: false, error: e.message });
  }
});

// Welt-Packs
app.get('/api/worlds/:name/packs', requireAuth, (req, res) =>
  res.json(mc.getWorldPacks(req.params.name)));

app.post('/api/worlds/:name/packs/toggle', requireAuth, (req, res) =>
  res.json(mc.toggleWorldPack(req.params.name, req.body.uuid, req.body.type, !!req.body.enable)));

// Pack für Welt hochladen — via PHP-Bridge
app.post('/api/worlds/:name/packs/upload', requireAuth, upload.single('pack'), async (req, res) => {
  if (!req.file) return res.status(400).json({ success: false, error: 'Keine Datei' });
  try {
    const r = await phpActionWithFile('upload_pack_for_world', { world: req.params.name }, req.file, 'pack');
    try { fs.unlinkSync(req.file.path); } catch {}
    res.json(r);
  } catch (e) {
    try { fs.unlinkSync(req.file.path); } catch {}
    res.status(500).json({ success: false, error: e.message });
  }
});

// Fehlendes Pack liefern — via PHP-Bridge
app.post('/api/worlds/:name/packs/:uuid/supply', requireAuth, upload.single('pack'), async (req, res) => {
  if (!req.file) return res.status(400).json({ success: false, error: 'Keine Datei' });
  try {
    const r = await phpActionWithFile('supply_missing_pack', { world: req.params.name, expected_uuid: req.params.uuid }, req.file, 'pack');
    try { fs.unlinkSync(req.file.path); } catch {}
    res.json(r);
  } catch (e) {
    try { fs.unlinkSync(req.file.path); } catch {}
    res.status(500).json({ success: false, error: e.message });
  }
});

// Willkommensnachrichten
app.get('/api/worlds/:name/welcome',  requireAuth, (req, res) =>
  res.json({ success: true, message: mc.getWelcomeMessage(req.params.name) }));
app.post('/api/worlds/:name/welcome', requireAuth, (req, res) =>
  res.json(mc.saveWelcomeMessage(req.params.name, req.body.message || '')));

// ── Packs (global) ────────────────────────────────────────────
app.get('/api/packs/behavior', requireAuth, (req, res) => res.json(mc.listPacks('behavior')));
app.get('/api/packs/resource', requireAuth, (req, res) => res.json(mc.listPacks('resource')));

app.delete('/api/packs/:type/:name', requireAuth, (req, res) =>
  res.json(mc.deletePack(req.params.type, req.params.name)));

// Pack hochladen (global) — via PHP-Bridge
app.post('/api/packs/upload', requireAuth, upload.single('file'), async (req, res) => {
  if (!req.file) return res.status(400).json({ success: false, error: 'Keine Datei' });
  try {
    const r = await phpActionWithFile('upload_pack', {}, req.file, 'pack');
    try { fs.unlinkSync(req.file.path); } catch {}
    res.json(r);
  } catch (e) {
    try { fs.unlinkSync(req.file.path); } catch {}
    res.status(500).json({ success: false, error: e.message });
  }
});

// Pack ersetzen — via PHP-Bridge
app.post('/api/packs/replace', requireAuth, upload.single('pack'), async (req, res) => {
  if (!req.file) return res.status(400).json({ success: false, error: 'Keine Datei' });
  try {
    const r = await phpActionWithFile('replace_pack', { old_uuid: req.body.old_uuid, old_type: req.body.old_type }, req.file, 'pack');
    try { fs.unlinkSync(req.file.path); } catch {}
    res.json(r);
  } catch (e) {
    try { fs.unlinkSync(req.file.path); } catch {}
    res.status(500).json({ success: false, error: e.message });
  }
});

// ── Whitelist ─────────────────────────────────────────────────
app.get('/api/whitelist',         requireAuth, (req, res) => res.json(mc.readWhitelist()));
app.post('/api/whitelist/add',    requireAuth, (req, res) => res.json(mc.addToWhitelist(req.body.name)));
app.post('/api/whitelist/remove', requireAuth, (req, res) => res.json(mc.removeFromWhitelist(req.body.name)));

// ── Permissions / OP ──────────────────────────────────────────
app.get('/api/permissions',         requireAuth, (req, res) => res.json(mc.readPermissions()));
app.post('/api/permissions/set',    requireAuth, (req, res) =>
  res.json(mc.setOp(req.body.xuid, req.body.name, req.body.level)));
app.post('/api/permissions/remove', requireAuth, (req, res) => res.json(mc.removeOp(req.body.xuid)));

// ── Spieler-Statistiken ───────────────────────────────────────
app.get('/api/stats', requireAuth, (req, res) => res.json(mc.getPlayerStats()));

// ── server.properties ─────────────────────────────────────────
app.get('/api/properties',  requireAuth, (req, res) => res.json(mc.readProperties()));
app.post('/api/properties', requireAuth, (req, res) => res.json(mc.writeProperties(req.body)));

// ── Backups ───────────────────────────────────────────────────
app.get('/api/backups',          requireAuth, (req, res) => res.json(mc.listBackups()));
app.post('/api/backups/create',  requireAuth, (req, res) => res.json(mc.createBackup(req.body.label)));
app.post('/api/backups/restore', requireAuth, (req, res) => res.json(mc.restoreBackup(req.body.filename)));
app.delete('/api/backups/:name', requireAuth, (req, res) => res.json(mc.deleteBackup(req.params.name)));

app.post('/api/backups/import', requireAuth, upload.single('backup'), (req, res) => {
  res.json(mc.importBackup(req.file));
});

app.get('/api/backups/download/:name', requireAuth, (req, res) => {
  const p = path.join(config.panel.backupDir, path.basename(req.params.name));
  if (!fs.existsSync(p)) return res.status(404).json({ error: 'Nicht gefunden' });
  res.download(p);
});

// ── Updates — via PHP-Bridge ──────────────────────────────────
app.get('/api/updates/mc', requireAuth, async (req, res) => {
  try {
    const r = await mc.checkMcVersion(!!req.query.force);
    res.json({ installed: r.current, available: r.latest, update_available: r.update_available, error: r.error });
  } catch (e) {
    res.json({ installed: mc.getVersion(), available: 'unbekannt', update_available: false, error: e.message });
  }
});

app.post('/api/updates/mc/start', requireAuth, async (req, res) => {
  try { res.json(await phpAction('start_update', { version: req.body.version || '' })); }
  catch (e) { res.json({ success: false, error: e.message }); }
});

app.get('/api/updates/mc/status', requireAuth, async (req, res) => {
  try { res.json(await phpAction('update_status')); }
  catch (e) { res.json({ done: true, error: e.message }); }
});

app.get('/api/updates/panel', requireAuth, async (req, res) => {
  try {
    const r = await phpAction('check_panel_update', { force: req.query.force || '0' });
    res.json({ installed: r.current, available: r.latest, update_available: r.update_available, ...r });
  } catch (e) {
    res.json({ installed: 'unbekannt', available: 'unbekannt', update_available: false, error: e.message });
  }
});

app.post('/api/updates/panel/start', requireAuth, async (req, res) => {
  try { res.json(await phpAction('start_panel_update')); }
  catch (e) { res.json({ success: false, error: e.message }); }
});

app.get('/api/updates/panel/status', requireAuth, async (req, res) => {
  try { res.json(await phpAction('panel_update_status')); }
  catch (e) { res.json({ done: true, error: e.message }); }
});

app.get('/api/updates/panel/log', requireAuth, async (req, res) => {
  try { res.json(await phpAction('get_panel_update_log')); }
  catch (e) { res.json({ lines: [] }); }
});

// ── Zeitpläne ─────────────────────────────────────────────────
app.get('/api/schedules',  requireAuth, (req, res) => res.json(mc.getSchedules()));
app.post('/api/schedules', requireAuth, (req, res) => res.json(mc.saveSchedules(req.body)));

// ── Einstellungen ─────────────────────────────────────────────
app.get('/api/settings', requireAuth, (req, res) => {
  const s = mc.loadPanelSettings();
  delete s.admin_pass_hash;
  delete s.php_bridge_secret;
  res.json(s);
});

app.post('/api/settings', requireAuth, async (req, res) => {
  const data = { ...req.body };
  delete data.php_bridge_secret;
  if (data._new_pass) {
    if (!data._old_pass) return res.status(400).json({ success: false, error: 'Aktuelles Passwort erforderlich' });
    const { verifyCredentials } = await import('./auth.js');
    const valid = await verifyCredentials(req.user.user, data._old_pass);
    if (!valid) return res.status(400).json({ success: false, error: 'Aktuelles Passwort falsch' });
    if (data._new_pass.length < 6) return res.status(400).json({ success: false, error: 'Passwort muss mindestens 6 Zeichen haben' });
    if (data._new_pass !== data._confirm_pass) return res.status(400).json({ success: false, error: 'Passwörter stimmen nicht überein' });
    const { default: bcrypt } = await import('bcrypt');
    data.admin_pass_hash = await bcrypt.hash(data._new_pass, 12);
    delete data._new_pass; delete data._old_pass; delete data._confirm_pass;
  }
  if (data.admin_user !== undefined && data.admin_user.length < 3)
    return res.status(400).json({ success: false, error: 'Benutzername muss mindestens 3 Zeichen haben' });
  const existing = mc.loadPanelSettings();
  const result = mc.savePanelSettings({ ...existing, ...data });
  invalidateSecretCache();
  res.json(result);
});

// ── Discord Test ──────────────────────────────────────────────
app.post('/api/settings/test-discord', requireAuth, async (req, res) => {
  try {
    const r = await phpAction('test_discord', { webhook: req.body.webhook || '' });
    res.json(r);
  } catch (e) {
    res.json({ success: false, error: e.message });
  }
});

// ── Backup-Limit ──────────────────────────────────────────────
app.post('/api/settings/backup-max-count', requireAuth, (req, res) => {
  const allowed = [5, 10, 15, 20, 25, 30];
  const count = parseInt(req.body.count);
  if (!allowed.includes(count)) return res.status(400).json({ success: false, error: 'Ungültiger Wert' });
  const s = mc.loadPanelSettings();
  s.backup_max_count = count;
  res.json(mc.savePanelSettings(s));
});

// ── Update-Check jetzt ausführen ──────────────────────────────
app.post('/api/updates/check-now', requireAuth, async (req, res) => {
  try { res.json(await phpAction('run_update_check_now')); }
  catch (e) { res.json({ success: false, error: e.message }); }
});

// ── Pack aus Welt entfernen ───────────────────────────────────
app.post('/api/worlds/:name/packs/remove', requireAuth, (req, res) => {
  const { uuid, type } = req.body;
  res.json(mc.removePackFromWorld(req.params.name, uuid, type));
});

// ── Welt-Experimente ─────────────────────────────────────────
app.get('/api/worlds/:name/experiments', requireAuth, (req, res) =>
  res.json(mc.getWorldExperiments(req.params.name)));

app.post('/api/worlds/:name/experiments', requireAuth, (req, res) =>
  res.json(mc.setWorldExperiments(req.params.name, req.body)));

// ── SPA-Fallback ──────────────────────────────────────────────
app.get('*', (req, res) => {
  const idx = path.join(frontendDist, 'index.html');
  if (fs.existsSync(idx)) res.sendFile(idx);
  else res.status(404).send('Frontend nicht gebaut. Bitte "npm run build" im frontend/-Verzeichnis ausführen.');
});

// ── WebSocket: Live-Log-Streaming ────────────────────────────
wss.on('connection', (ws, req) => {
  const url   = new URL(req.url, 'http://localhost');
  const token = url.searchParams.get('token');
  const user  = verifyWsToken(token);

  if (!user) { ws.close(1008, 'Unauthorized'); return; }
  console.log(`[WS] Client verbunden: ${user.user}`);

  // Letzte 100 Zeilen sofort senden
  ws.send(JSON.stringify({ type: 'init', lines: mc.getLogLines(100) }));

  // tail -f starten
  const tail = mc.spawnLogTail(
    line => { if (ws.readyState === 1) ws.send(JSON.stringify({ type: 'line', line })); },
    ()   => { if (ws.readyState === 1) ws.send(JSON.stringify({ type: 'disconnected' })); }
  );

  ws.on('message', data => {
    try {
      const msg = JSON.parse(data.toString());
      if (msg.type === 'cmd' && msg.cmd) {
        const result = mc.sendCommand(msg.cmd);
        ws.send(JSON.stringify({ type: 'cmdResult', ...result }));
      }
    } catch {}
  });

  ws.on('close', () => { console.log('[WS] Client getrennt'); tail.kill(); });
  ws.on('error', () => tail.kill());
});

// ── Starten ───────────────────────────────────────────────────
await initBridge();

server.listen(config.port, () => {
  console.log(`mc-webapp läuft auf http://localhost:${config.port}`);
  console.log(`WebSocket:  ws://localhost:${config.port}/ws`);
});
