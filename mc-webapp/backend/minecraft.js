import { execSync, exec, spawn } from 'child_process';
import fs from 'fs';
import path from 'path';
import { config } from './config.js';

const mc    = config.mc;
const panel = config.panel;

// ── Hilfsfunktionen ──────────────────────────────────────────

function run(cmd) {
  try { return execSync(cmd, { encoding: 'utf8', timeout: 10000 }).trim(); }
  catch (e) { return e.stdout?.trim() || ''; }
}

function runSudo(cmd) {
  try {
    execSync(`sudo ${cmd}`, { encoding: 'utf8', timeout: 30000 });
    return { success: true };
  } catch (e) {
    return { success: false, error: e.stderr?.trim() || e.message };
  }
}

function readJson(file, fallback = null) {
  try {
    if (fs.existsSync(file)) return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {}
  return fallback;
}

function writeJsonAtomic(file, data) {
  const tmp = file + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2));
  fs.renameSync(tmp, file);
}

function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1048576).toFixed(1)} MB`;
}

// ── Status ───────────────────────────────────────────────────

export function isRunning() {
  return run(`systemctl is-active ${mc.serviceName}`) === 'active';
}

export function getUptime() {
  const out = run(`systemctl show ${mc.serviceName} --property=ActiveEnterTimestamp`);
  const match = out.match(/ActiveEnterTimestamp=(.+)/);
  if (!match) return null;
  const ts = new Date(match[1]);
  return isNaN(ts) ? null : ts.toISOString();
}

export function getVersion() {
  const vf = path.join(mc.serverDir, 'version.txt');
  if (fs.existsSync(vf)) return fs.readFileSync(vf, 'utf8').trim();
  try {
    const out = run(`${mc.executable} --version 2>/dev/null | head -1`);
    return out || 'unbekannt';
  } catch { return 'unbekannt'; }
}

export function isInstalled() {
  return fs.existsSync(mc.executable) && !!(fs.statSync(mc.executable).mode & 0o111);
}

// ── Server Start / Stop / Restart ────────────────────────────

export function startServer() {
  return runSudo(`systemctl start ${mc.serviceName}`);
}

export function stopServer() {
  sendCommand('stop');
  try { execSync(`sleep 3 && sudo systemctl stop ${mc.serviceName}`, { timeout: 20000 }); } catch {}
  return { success: true };
}

export function restartServer() {
  sendCommand('stop');
  try {
    execSync(
      `sleep 3 && sudo systemctl stop ${mc.serviceName} && sleep 2 && sudo systemctl start ${mc.serviceName}`,
      { timeout: 30000 }
    );
  } catch {}
  return { success: true };
}

// ── Befehle an Konsole senden ─────────────────────────────────

export function sendCommand(cmd) {
  cmd = (cmd || '').trim();
  if (!cmd) return { success: false, message: 'Leerer Befehl' };

  if (fs.existsSync(mc.fifo)) {
    try {
      const fd = fs.openSync(mc.fifo, fs.constants.O_WRONLY | fs.constants.O_NONBLOCK);
      fs.writeSync(fd, cmd + '\n');
      fs.closeSync(fd);
      return { success: true, message: 'Befehl gesendet' };
    } catch {}
  }

  try {
    execSync(`screen -S minecraft -X stuff ${JSON.stringify(cmd + '\n')} 2>/dev/null`);
    return { success: true, message: 'Befehl gesendet (screen)' };
  } catch {}

  try {
    execSync(`tmux send-keys -t minecraft ${JSON.stringify(cmd)} Enter 2>/dev/null`);
    return { success: true, message: 'Befehl gesendet (tmux)' };
  } catch {}

  return { success: false, message: 'Konnte Befehl nicht senden' };
}

// ── Log-Datei finden ─────────────────────────────────────────

export function findLogFile() {
  for (const f of mc.logFiles) {
    if (fs.existsSync(f)) return f;
  }
  return null;
}

export function getLogLines(n = 100) {
  const logFile = findLogFile();
  if (logFile) {
    try {
      const out = run(`tail -n ${n} "${logFile}"`);
      return out ? out.split('\n').filter(Boolean) : [];
    } catch {}
  }
  const out = run(`journalctl -u ${mc.serviceName} -n ${n} --no-pager --output=short`);
  return out ? out.split('\n').filter(Boolean) : [];
}

export function spawnLogTail(onLine, onClose) {
  const logFile = findLogFile();
  let proc;
  if (logFile) {
    proc = spawn('tail', ['-f', '-n', '0', logFile]);
  } else {
    proc = spawn('journalctl', ['-u', mc.serviceName, '-f', '-n', '0', '--no-pager', '--output=short']);
  }
  let buf = '';
  proc.stdout.on('data', chunk => {
    buf += chunk.toString();
    const lines = buf.split('\n');
    buf = lines.pop();
    lines.filter(Boolean).forEach(onLine);
  });
  proc.on('close', onClose);
  return proc;
}

// ── server.properties ─────────────────────────────────────────

export function readProperties(file) {
  const f = file || mc.propertiesFile;
  if (!fs.existsSync(f)) return {};
  const lines = fs.readFileSync(f, 'utf8').split('\n');
  const props = {};
  for (const line of lines) {
    if (line.startsWith('#') || !line.includes('=')) continue;
    const [k, ...v] = line.split('=');
    props[k.trim()] = v.join('=').trim();
  }
  return props;
}

export function writeProperties(props, file) {
  const f = file || mc.propertiesFile;
  if (fs.existsSync(f)) {
    const lines = fs.readFileSync(f, 'utf8').split('\n');
    const written = new Set();
    const updated = lines.map(line => {
      if (line.startsWith('#') || !line.includes('=')) return line;
      const k = line.split('=')[0].trim();
      if (props[k] !== undefined) { written.add(k); return `${k}=${props[k]}`; }
      return line;
    });
    for (const [k, v] of Object.entries(props)) {
      if (!written.has(k)) updated.push(`${k}=${v}`);
    }
    fs.writeFileSync(f, updated.join('\n') + '\n');
  } else {
    fs.writeFileSync(f, Object.entries(props).map(([k, v]) => `${k}=${v}`).join('\n') + '\n');
  }
  return { success: true };
}

// ── Welt-Properties ───────────────────────────────────────────

export function worldPropertiesFile(worldName) {
  return path.join(mc.worldsDir, worldName, '.server.properties');
}

export function getWorldProperties(worldName) {
  const active = getActiveWorld();
  if (worldName === active) return readProperties();
  const wf = worldPropertiesFile(worldName);
  if (fs.existsSync(wf)) return readProperties(wf);
  const props = readProperties();
  props['level-name'] = worldName;
  return props;
}

export function saveWorldProperties(worldName, props) {
  props['level-name'] = worldName;
  const wf  = worldPropertiesFile(worldName);
  const dir = path.join(mc.worldsDir, worldName);
  if (fs.existsSync(dir)) {
    if (!fs.existsSync(wf) && fs.existsSync(mc.propertiesFile)) fs.copyFileSync(mc.propertiesFile, wf);
    writeProperties(props, fs.existsSync(wf) ? wf : mc.propertiesFile);
  }
  if (worldName === getActiveWorld()) writeProperties(props, mc.propertiesFile);
  return { success: true };
}

// ── Welten ───────────────────────────────────────────────────

export function listWorlds() {
  if (!fs.existsSync(mc.worldsDir)) return [];
  const state       = readJson(panel.stateFile, {});
  const activeWorld = state.active_world || null;

  return fs.readdirSync(mc.worldsDir)
    .filter(n => fs.statSync(path.join(mc.worldsDir, n)).isDirectory())
    .map(name => {
      const wp         = state.world_packs?.[name] || {};
      const packCount  = (wp.behavior||[]).length + (wp.resource||[]).length;
      let missingCount = 0;
      for (const type of ['behavior','resource']) {
        const dir = type === 'behavior' ? mc.behaviorDir : mc.resourceDir;
        for (const ref of (wp[type]||[])) {
          const uuid = (ref.pack_id||ref.uuid||'').toLowerCase();
          if (!uuid) continue;
          let found = false;
          if (fs.existsSync(dir)) {
            for (const d of fs.readdirSync(dir)) {
              const mf = readJson(path.join(dir, d, 'manifest.json'));
              if (mf?.header?.uuid?.toLowerCase() === uuid) { found=true; break; }
            }
          }
          if (!found) missingCount++;
        }
      }
      const sizeKb = parseInt(run(`du -sk "${path.join(mc.worldsDir, name)}" 2>/dev/null`)) || 0;
      return { name, active: name===activeWorld, pack_count: packCount, missing_packs: missingCount, size_human: formatBytes(sizeKb*1024) };
    });
}

export function getActiveWorld() {
  const state = readJson(panel.stateFile, {});
  return state.active_world || readProperties()['level-name'] || null;
}

export function setActiveWorld(worldName) {
  try {
    const state   = readJson(panel.stateFile, {});
    const current = state.active_world;
    if (current && current !== worldName) {
      if (fs.existsSync(mc.propertiesFile)) fs.copyFileSync(mc.propertiesFile, worldPropertiesFile(current));
    }
    const wf = worldPropertiesFile(worldName);
    if (fs.existsSync(wf)) fs.copyFileSync(wf, mc.propertiesFile);
    writeProperties({ 'level-name': worldName });
    state.active_world = worldName;
    writeJsonAtomic(panel.stateFile, state);
    return { success: true, message: `Welt gewechselt zu: ${worldName} — Server neu starten zum Übernehmen` };
  } catch (e) { return { success: false, error: e.message }; }
}

export function createWorld(name, opts = {}) {
  name = (name||'').trim();
  if (!/^[a-zA-Z0-9_\- ]{1,64}$/.test(name)) return { success: false, error: 'Ungültiger Weltname' };
  const dest = path.join(mc.worldsDir, name);
  if (fs.existsSync(dest)) return { success: false, error: `Welt '${name}' existiert bereits` };
  try {
    fs.mkdirSync(dest, { recursive: true });
    fs.writeFileSync(path.join(dest, 'world_behavior_packs.json'), '[]');
    fs.writeFileSync(path.join(dest, 'world_resource_packs.json'), '[]');
    const gamemodes    = { '0':'survival','1':'creative','2':'adventure','survival':'survival','creative':'creative','adventure':'adventure' };
    const difficulties = { '0':'peaceful','1':'easy','2':'normal','3':'hard','peaceful':'peaceful','easy':'easy','normal':'normal','hard':'hard' };
    const base = fs.existsSync(mc.propertiesFile) ? readProperties() : {};
    const props = { ...base,
      'server-name': base['server-name']||'Minecraft Bedrock Server',
      'gamemode':    gamemodes[opts.gamemode]||'survival',
      'difficulty':  difficulties[opts.difficulty]||'easy',
      'allow-cheats':'false', 'max-players':base['max-players']||'10',
      'online-mode': base['online-mode']||'true', 'white-list':base['white-list']||'false',
      'server-port': base['server-port']||'19132', 'server-portv6':base['server-portv6']||'19133',
      'view-distance':base['view-distance']||'10', 'tick-distance':base['tick-distance']||'4',
      'level-name':name, 'level-seed':opts.seed||'',
      'default-player-permission-level':base['default-player-permission-level']||'member',
      'texturepack-required':'false','content-log-file-enabled':'false',
      'force-gamemode':'false','command-blocks-enabled':'false',
    };
    writeProperties(props, path.join(dest, '.server.properties'));
    return { success: true, message: `Welt '${name}' erstellt` };
  } catch (e) { try{execSync(`rm -rf "${dest}"`);}catch{} return { success: false, error: e.message }; }
}

export function renameWorld(oldName, newName) {
  newName = (newName||'').trim();
  if (!/^[a-zA-Z0-9_\- ]{1,64}$/.test(newName)) return { success: false, error: 'Ungültiger neuer Weltname' };
  const oldPath = path.join(mc.worldsDir, oldName);
  const newPath = path.join(mc.worldsDir, newName);
  if (!fs.existsSync(oldPath)) return { success: false, error: 'Welt nicht gefunden' };
  if (fs.existsSync(newPath))  return { success: false, error: `Welt '${newName}' existiert bereits` };
  if (oldName === getActiveWorld()) return { success: false, error: 'Aktive Welt kann nicht umbenannt werden' };
  try {
    fs.renameSync(oldPath, newPath);
    const wf = path.join(newPath, '.server.properties');
    if (fs.existsSync(wf)) writeProperties({ 'level-name': newName }, wf);
    const state = readJson(panel.stateFile, {});
    if (state.world_packs?.[oldName]) { state.world_packs[newName]=state.world_packs[oldName]; delete state.world_packs[oldName]; }
    if (state.world_imported_packs?.[oldName]) { state.world_imported_packs[newName]=state.world_imported_packs[oldName]; delete state.world_imported_packs[oldName]; }
    writeJsonAtomic(panel.stateFile, state);
    return { success: true, message: `Welt '${oldName}' umbenannt zu '${newName}'` };
  } catch (e) { return { success: false, error: e.message }; }
}

export function deleteWorld(worldName) {
  const p = path.join(mc.worldsDir, worldName);
  if (!fs.existsSync(p)) return { success: false, error: 'Welt nicht gefunden' };
  if (worldName === getActiveWorld()) return { success: false, error: 'Aktive Welt kann nicht gelöscht werden' };
  run(`rm -rf "${p}"`);
  const state = readJson(panel.stateFile, {});
  delete state.world_packs?.[worldName];
  delete state.world_imported_packs?.[worldName];
  writeJsonAtomic(panel.stateFile, state);
  return { success: true, message: `Welt '${worldName}' gelöscht` };
}

// ── Packs ────────────────────────────────────────────────────

export function listPacks(type) {
  const dir = type === 'behavior' ? mc.behaviorDir : mc.resourceDir;
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir)
    .filter(n => fs.statSync(path.join(dir, n)).isDirectory())
    .map(name => {
      const mf   = readJson(path.join(dir, name, 'manifest.json'));
      return { name, display_name: mf?.header?.name||name, uuid: mf?.header?.uuid||'', version: mf?.header?.version||[0,0,0], type };
    });
}

export function getWorldPacks(worldName) {
  const state     = readJson(panel.stateFile, {});
  const wp        = state.world_packs?.[worldName] || { behavior:[], resource:[] };
  const nameCache = state.world_packs_name_cache?.[worldName] || {};

  const resolve = (list, type) => {
    const dir = type === 'behavior' ? mc.behaviorDir : mc.resourceDir;
    return list.map(ref => {
      const uuid = (ref.pack_id||ref.uuid||'').toLowerCase();
      let installed = null;
      if (fs.existsSync(dir)) {
        for (const d of fs.readdirSync(dir)) {
          const mf = readJson(path.join(dir, d, 'manifest.json'));
          if (mf?.header?.uuid?.toLowerCase() === uuid) { installed = { folder:d, name:mf.header.name||d, version:mf.header.version||[0,0,0] }; break; }
        }
      }
      return { uuid, version:ref.version||[0,0,0], type, enabled:ref.enabled!==false, missing:!installed,
               name: installed?.name||nameCache[uuid]||uuid.slice(0,8)+'…', folder:installed?.folder||'' };
    });
  };
  const behavior = resolve(wp.behavior||[], 'behavior');
  const resource  = resolve(wp.resource ||[], 'resource');
  return { behavior, resource, behavior_missing:behavior.filter(p=>p.missing), resource_missing:resource.filter(p=>p.missing) };
}

export function toggleWorldPack(worldName, uuid, type, enable) {
  const state = readJson(panel.stateFile, {});
  if (!state.world_packs) state.world_packs = {};
  if (!state.world_packs[worldName]) state.world_packs[worldName] = { behavior:[], resource:[] };
  const list    = state.world_packs[worldName][type] || [];
  const uuidLow = uuid.toLowerCase();
  const idx     = list.findIndex(r => (r.pack_id||r.uuid||'').toLowerCase() === uuidLow);
  if (idx >= 0) { list[idx].enabled = enable; }
  else if (enable) { list.push({ pack_id: uuid, version:[0,0,0], enabled:true }); }
  state.world_packs[worldName][type] = list;
  writeJsonAtomic(panel.stateFile, state);
  _applyPacksJson(worldName, state);
  return { success: true };
}

function _applyPacksJson(worldName, state) {
  const wp   = state.world_packs?.[worldName] || {};
  const base = path.join(mc.worldsDir, worldName);
  if (!fs.existsSync(base)) return;
  for (const type of ['behavior','resource']) {
    const active = (wp[type]||[]).filter(r=>r.enabled!==false).map(r=>({ pack_id:r.pack_id||r.uuid, version:r.version||[0,0,0] }));
    const jsonFile = type==='behavior' ? 'world_behavior_packs.json' : 'world_resource_packs.json';
    fs.writeFileSync(path.join(base, jsonFile), JSON.stringify(active, null, 2));
  }
}

export function removePackFromWorld(worldName, uuid, type) {
  if (!['behavior','resource'].includes(type)) return { success: false, error: 'Ungültiger Typ' };
  const state = readJson(panel.stateFile, {});
  if (!state.world_packs?.[worldName]) return { success: false, error: 'Welt nicht gefunden' };
  const uuidLow = uuid.toLowerCase();
  state.world_packs[worldName][type] = (state.world_packs[worldName][type]||[]).filter(r=>(r.pack_id||r.uuid||'').toLowerCase()!==uuidLow);
  writeJsonAtomic(panel.stateFile, state);
  _applyPacksJson(worldName, state);
  return { success: true };
}

export function deletePack(type, nameOrUuid) {
  const dir = type === 'behavior' ? mc.behaviorDir : mc.resourceDir;
  let packDir = path.join(dir, nameOrUuid);
  if (!fs.existsSync(packDir) && fs.existsSync(dir)) {
    for (const d of fs.readdirSync(dir)) {
      const mf = readJson(path.join(dir, d, 'manifest.json'));
      if (mf?.header?.uuid?.toLowerCase() === nameOrUuid.toLowerCase()) { packDir = path.join(dir, d); break; }
    }
  }
  if (!fs.existsSync(packDir)) return { success: false, error: 'Pack nicht gefunden' };
  run(`rm -rf "${packDir}"`);
  return { success: true };
}

// ── Whitelist ─────────────────────────────────────────────────

export function readWhitelist() { return readJson(mc.whitelistFile, []); }

export function addToWhitelist(name) { return sendCommand(`whitelist add ${name}`); }

export function removeFromWhitelist(name) {
  const list = readWhitelist().filter(p => p.name !== name);
  fs.writeFileSync(mc.whitelistFile, JSON.stringify(list, null, 2));
  sendCommand('whitelist reload');
  return { success: true };
}

// ── Permissions / OP ──────────────────────────────────────────

export function readPermissions() { return readJson(mc.permissionsFile, []); }

export function setOp(xuid, name, level = 'operator') {
  const perms = readPermissions().filter(p => p.xuid !== xuid);
  perms.push({ permission: level, xuid, name });
  fs.writeFileSync(mc.permissionsFile, JSON.stringify(perms, null, 2));
  sendCommand('permissions reload');
  return { success: true };
}

export function removeOp(xuid) {
  const perms = readPermissions().filter(p => p.xuid !== xuid);
  fs.writeFileSync(mc.permissionsFile, JSON.stringify(perms, null, 2));
  sendCommand('permissions reload');
  return { success: true };
}

// ── Online-Spieler (mit is_op + is_whitelisted) ───────────────

export function getOnlinePlayers() {
  const lines  = [...getLogLines(1000)].reverse();
  const active = {};
  for (const line of lines) {
    const join = line.match(/Player connected:\s*([^,]+),\s*xuid:\s*(\d*)/i);
    if (join) { const n=join[1].trim(); if (!active[n]) active[n]={name:n,xuid:join[2].trim(),status:'online'}; continue; }
    const leave = line.match(/Player disconnected:\s*([^,]+)/i);
    if (leave) { const n=leave[1].trim(); if (!active[n]) active[n]={name:n,xuid:'',status:'offline'}; }
  }
  const opXuids = new Set(readPermissions().filter(p=>p.permission==='operator'&&p.xuid).map(p=>p.xuid));
  const wlNames = new Set(readWhitelist().map(p=>p.name));
  return Object.values(active)
    .filter(p => p.status==='online')
    .map(p => ({ name:p.name, xuid:p.xuid, is_op:opXuids.has(p.xuid), is_whitelisted:wlNames.has(p.name) }));
}

// ── Spieler-Aktionen für Dashboard ───────────────────────────

export function opPlayer(name) {
  const xuid = _findXuid(name);
  if (!xuid) return { success: false, error: `XUID für '${name}' nicht gefunden — Spieler muss mindestens einmal verbunden gewesen sein` };
  return setOp(xuid, name, 'operator');
}

export function deopPlayer(name) {
  const xuid = _findXuid(name);
  if (!xuid) return { success: false, error: `XUID für '${name}' nicht gefunden` };
  return removeOp(xuid);
}

export function kickPlayer(name, reason = 'Kicked by admin') {
  return sendCommand(`kick ${name} ${reason}`);
}

function _findXuid(name) {
  for (const p of readWhitelist()) if (p.name?.toLowerCase()===name.toLowerCase()&&p.xuid) return p.xuid;
  for (const p of getOnlinePlayers()) if (p.name?.toLowerCase()===name.toLowerCase()&&p.xuid) return p.xuid;
  return '';
}

// ── Spieler-Statistiken ───────────────────────────────────────

export function getPlayerStats() {
  const lines = getLogLines(50000);
  const stats  = {};
  const active = {};

  function parseTs(line) {
    const m = line.match(/\[(\d{4}[\/\-]\d{2}[\/\-]\d{2} \d{2}:\d{2}:\d{2})/);
    return m ? (Date.parse(m[1].replace(/\//g,'-'))/1000||0) : 0;
  }

  for (const line of lines) {
    const ts    = parseTs(line);
    const joinM = line.match(/Player connected:\s*([^,]+),\s*xuid:\s*(\d*)/i);
    if (joinM) {
      const name=joinM[1].trim(), xuid=joinM[2].trim();
      if (!stats[name]) stats[name]={name,xuid,playtime:0,sessions:0,kicks:0,first_seen:ts,last_seen:ts};
      stats[name].last_seen=ts; stats[name].sessions++; active[name]=ts;
      continue;
    }
    const leaveM = line.match(/Player disconnected:\s*([^,]+)/i);
    if (leaveM) {
      const name=leaveM[1].trim();
      if (active[name]&&ts>active[name]) {
        if (!stats[name]) stats[name]={name,xuid:'',playtime:0,sessions:0,kicks:0,first_seen:ts,last_seen:ts};
        stats[name].playtime+=(ts-active[name]); stats[name].last_seen=ts;
      }
      delete active[name]; continue;
    }
    const kickM = line.match(/Kicked\s+([^\s]+)/i)||line.match(/player\s+([^\s]+)\s+was kicked/i);
    if (kickM&&stats[kickM[1]?.trim()]) stats[kickM[1].trim()].kicks++;
  }

  const now = Math.floor(Date.now()/1000);
  for (const [name,since] of Object.entries(active)) if(stats[name]) stats[name].playtime+=(now-since);

  function fmtTime(s) { const h=Math.floor(s/3600),m=Math.floor((s%3600)/60); return h>0?`${h}h ${m}m`:`${m}m`; }
  function fmtDate(ts) { return ts ? new Date(ts*1000).toLocaleString('de-DE') : '—'; }

  return Object.values(stats).sort((a,b)=>b.playtime-a.playtime).map(s=>({
    ...s, playtime_seconds:s.playtime, playtime_human:fmtTime(s.playtime),
    first_seen_human:fmtDate(s.first_seen), last_seen_human:fmtDate(s.last_seen), online:!!active[s.name],
  }));
}

// ── Willkommensnachrichten ────────────────────────────────────

export function getWelcomeMessage(worldName) {
  const file = path.join(mc.worldsDir, worldName, '.mcadmin_welcome.txt');
  if (!fs.existsSync(file)) return '';
  return fs.readFileSync(file, 'utf8');
}

export function saveWelcomeMessage(worldName, msg) {
  const dir  = path.join(mc.worldsDir, worldName);
  const file = path.join(dir, '.mcadmin_welcome.txt');
  if (!fs.existsSync(dir)) return { success: false, error: 'Welt nicht gefunden' };
  if (!msg||!msg.trim()) { try{fs.unlinkSync(file);}catch{} return {success:true}; }
  fs.writeFileSync(file, msg.replace(/\r\n/g,'\n'));
  return { success: true };
}

// ── Backups ───────────────────────────────────────────────────

export function listBackups() {
  if (!fs.existsSync(panel.backupDir)) return [];
  return fs.readdirSync(panel.backupDir)
    .filter(f => f.endsWith('.tar.gz'))
    .map(f => { const s=fs.statSync(path.join(panel.backupDir,f)); return {name:f,size:s.size,date:s.mtime.toISOString()}; })
    .sort((a,b) => new Date(b.date)-new Date(a.date));
}

export function createBackup(label='') {
  fs.mkdirSync(panel.backupDir, { recursive: true });
  const ts   = new Date().toISOString().replace(/[:.]/g,'-').slice(0,19);
  const name = label ? `backup_${ts}_${label}.tar.gz` : `backup_${ts}.tar.gz`;
  const dest = path.join(panel.backupDir, name);
  try {
    execSync(`tar -czf "${dest}" -C "${path.dirname(mc.serverDir)}" "${path.basename(mc.serverDir)}"`, { timeout: 300000 });
    const maxCount = loadPanelSettings().backup_max_count || panel.maxBackups;
    const all = listBackups();
    if (all.length > maxCount) all.slice(maxCount).forEach(b=>fs.unlinkSync(path.join(panel.backupDir,b.name)));
    return { success: true, name };
  } catch (e) { return { success: false, error: e.message }; }
}

export function deleteBackup(name) {
  const p = path.join(panel.backupDir, path.basename(name));
  if (!fs.existsSync(p)) return { success: false, error: 'Backup nicht gefunden' };
  fs.unlinkSync(p);
  return { success: true };
}

export function restoreBackup(filename) {
  const bname = path.basename(filename);
  if (!/^[a-zA-Z0-9_\-\.]+\.tar\.gz$/.test(bname)) return { success: false, error: 'Ungültiger Dateiname' };
  const p = path.join(panel.backupDir, bname);
  if (!fs.existsSync(p)) return { success: false, error: 'Backup nicht gefunden' };
  const wasRunning = isRunning();
  if (wasRunning) try { execSync(`sudo systemctl stop ${mc.serviceName}`, { timeout: 15000 }); } catch {}
  try {
    execSync(`tar -xzf "${p}" -C "${path.dirname(mc.serverDir)}"`, { timeout: 120000 });
    if (wasRunning) try { execSync(`sudo systemctl start ${mc.serviceName}`, { timeout: 15000 }); } catch {}
    return { success: true, message: `Backup '${bname}' wiederhergestellt` };
  } catch (e) { return { success: false, error: e.message }; }
}

export function importBackup(multerFile) {
  if (!multerFile) return { success: false, error: 'Keine Datei' };
  const name = multerFile.originalname.replace(/[^a-zA-Z0-9_\-\.]/g,'_');
  if (!name.toLowerCase().endsWith('.tar.gz')) return { success: false, error: 'Nur .tar.gz-Dateien erlaubt' };
  fs.mkdirSync(panel.backupDir, { recursive: true });
  const dest = path.join(panel.backupDir, name);
  fs.copyFileSync(multerFile.path, dest);
  try { fs.unlinkSync(multerFile.path); } catch {}
  return { success: true, message: `Backup importiert: ${name}` };
}

// ── Zeitpläne ─────────────────────────────────────────────────

export function getSchedules() {
  const s = loadPanelSettings();
  return {
    backup_schedule_enabled:  !!s.backup_schedule_enabled,
    backup_schedule_time:     s.backup_schedule_time     || '03:00',
    restart_schedule_enabled: !!s.restart_schedule_enabled,
    restart_schedule_time:    s.restart_schedule_time    || '06:00',
    update_check_enabled:     !!s.update_check_enabled,
    update_check_time:        s.update_check_time        || '04:00',
    auto_install_mc:          !!s.auto_install_mc,
    auto_install_panel:       !!s.auto_install_panel,
  };
}

export function saveSchedules(data) {
  const s = loadPanelSettings();
  const t = /^\d{2}:\d{2}$/;
  if (data.backup_schedule_enabled  !== undefined) s.backup_schedule_enabled  = !!data.backup_schedule_enabled;
  if (data.backup_schedule_time     && t.test(data.backup_schedule_time))  s.backup_schedule_time    = data.backup_schedule_time;
  if (data.restart_schedule_enabled !== undefined) s.restart_schedule_enabled = !!data.restart_schedule_enabled;
  if (data.restart_schedule_time    && t.test(data.restart_schedule_time)) s.restart_schedule_time   = data.restart_schedule_time;
  if (data.update_check_enabled     !== undefined) s.update_check_enabled     = !!data.update_check_enabled;
  if (data.update_check_time        && t.test(data.update_check_time))     s.update_check_time       = data.update_check_time;
  if (data.auto_install_mc          !== undefined) s.auto_install_mc          = !!data.auto_install_mc;
  if (data.auto_install_panel       !== undefined) s.auto_install_panel       = !!data.auto_install_panel;
  return savePanelSettings(s);
}

// ── Panel-Einstellungen ───────────────────────────────────────

export function loadPanelSettings() { return readJson(panel.settingsFile, {}); }

export function savePanelSettings(data) {
  const tmp = panel.settingsFile + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2));
  fs.renameSync(tmp, panel.settingsFile);
  return { success: true };
}

// ── Welt-Experimente (NBT level.dat) ─────────────────────────

const KNOWN_EXPERIMENTS = [
  'beta_apis','caves_and_cliffs','upcoming_creator_features',
  'holiday_creator_features','vanilla_experiments',
  'experimental_molang_features','gametest','data_driven_biomes',
];

const PY_READ_EXPERIMENTS = `
import sys, struct, json

def read_tag(data, pos):
    tag_type = data[pos]; pos += 1
    if tag_type == 0: return None, pos, None
    name_len = struct.unpack_from('<H', data, pos)[0]; pos += 2
    name = data[pos:pos+name_len].decode('utf-8', errors='replace'); pos += name_len
    val, pos = read_payload(data, pos, tag_type)
    return (name, tag_type, val), pos, name

def read_payload(data, pos, tag_type):
    if tag_type == 1: v=struct.unpack_from('<b',data,pos)[0]; return v, pos+1
    if tag_type == 2: v=struct.unpack_from('<h',data,pos)[0]; return v, pos+2
    if tag_type == 3: v=struct.unpack_from('<i',data,pos)[0]; return v, pos+4
    if tag_type == 4: v=struct.unpack_from('<q',data,pos)[0]; return v, pos+8
    if tag_type == 5: v=struct.unpack_from('<f',data,pos)[0]; return v, pos+4
    if tag_type == 6: v=struct.unpack_from('<d',data,pos)[0]; return v, pos+8
    if tag_type == 7:
        l=struct.unpack_from('<i',data,pos)[0]; pos+=4; return data[pos:pos+l], pos+l
    if tag_type == 8:
        l=struct.unpack_from('<H',data,pos)[0]; pos+=2; return data[pos:pos+l].decode('utf-8','replace'), pos+l
    if tag_type == 9:
        elem_type=data[pos]; pos+=1; count=struct.unpack_from('<i',data,pos)[0]; pos+=4
        items=[]
        for _ in range(count): v,pos=read_payload(data,pos,elem_type); items.append(v)
        return items, pos
    if tag_type == 10:
        children={}
        while True:
            tag,pos,name=read_tag(data,pos)
            if tag is None: break
            children[name]=(tag[1],tag[2])
        return children, pos
    if tag_type == 11:
        l=struct.unpack_from('<i',data,pos)[0]; pos+=4
        return [struct.unpack_from('<i',data,pos+i*4)[0] for i in range(l)], pos+l*4
    if tag_type == 12:
        l=struct.unpack_from('<i',data,pos)[0]; pos+=4
        return [struct.unpack_from('<q',data,pos+i*8)[0] for i in range(l)], pos+l*8
    return None, pos

path = sys.argv[1]
with open(path,'rb') as f: data=f.read()
pos=8  # skip 8-byte header
root,_,_=read_tag(data,pos)
root_val=root[2] if root else {}
exp={}
if 'experiments' in root_val:
    ec=root_val['experiments'][1]
    if ec==10:
        edata=root_val['experiments'][1]
        edata=root_val['experiments']
        if edata[0]==10:
            for k,(t,v) in edata[1].items(): exp[k]=bool(v)
print(json.dumps(exp))
`;

const PY_WRITE_EXPERIMENTS = `
import sys, struct, json

def read_tag(data, pos):
    tag_type = data[pos]; pos += 1
    if tag_type == 0: return None, pos, None
    name_len = struct.unpack_from('<H', data, pos)[0]; pos += 2
    name = data[pos:pos+name_len].decode('utf-8', errors='replace'); pos += name_len
    val, pos = read_payload(data, pos, tag_type)
    return (name, tag_type, val), pos, name

def read_payload(data, pos, tag_type):
    if tag_type == 1: v=struct.unpack_from('<b',data,pos)[0]; return v, pos+1
    if tag_type == 2: v=struct.unpack_from('<h',data,pos)[0]; return v, pos+2
    if tag_type == 3: v=struct.unpack_from('<i',data,pos)[0]; return v, pos+4
    if tag_type == 4: v=struct.unpack_from('<q',data,pos)[0]; return v, pos+8
    if tag_type == 5: v=struct.unpack_from('<f',data,pos)[0]; return v, pos+4
    if tag_type == 6: v=struct.unpack_from('<d',data,pos)[0]; return v, pos+8
    if tag_type == 7:
        l=struct.unpack_from('<i',data,pos)[0]; pos+=4; return data[pos:pos+l], pos+l
    if tag_type == 8:
        l=struct.unpack_from('<H',data,pos)[0]; pos+=2; return data[pos:pos+l].decode('utf-8','replace'), pos+l
    if tag_type == 9:
        elem_type=data[pos]; pos+=1; count=struct.unpack_from('<i',data,pos)[0]; pos+=4
        items=[]
        for _ in range(count): v,pos=read_payload(data,pos,elem_type); items.append(v)
        return items, pos
    if tag_type == 10:
        children={}
        while True:
            tag,pos,name=read_tag(data,pos)
            if tag is None: break
            children[name]=(tag[1],tag[2])
        return children, pos
    if tag_type == 11:
        l=struct.unpack_from('<i',data,pos)[0]; pos+=4
        return [struct.unpack_from('<i',data,pos+i*4)[0] for i in range(l)], pos+l*4
    if tag_type == 12:
        l=struct.unpack_from('<i',data,pos)[0]; pos+=4
        return [struct.unpack_from('<q',data,pos+i*8)[0] for i in range(l)], pos+l*8
    return None, pos

def encode_tag(name, tag_type, val):
    nb = name.encode('utf-8')
    return bytes([tag_type]) + struct.pack('<H', len(nb)) + nb + encode_payload(tag_type, val)

def encode_payload(tag_type, val):
    if tag_type == 1: return struct.pack('<b', int(val))
    if tag_type == 2: return struct.pack('<h', int(val))
    if tag_type == 3: return struct.pack('<i', int(val))
    if tag_type == 4: return struct.pack('<q', int(val))
    if tag_type == 5: return struct.pack('<f', float(val))
    if tag_type == 6: return struct.pack('<d', float(val))
    if tag_type == 7: return struct.pack('<i', len(val)) + val
    if tag_type == 8:
        s = val.encode('utf-8'); return struct.pack('<H', len(s)) + s
    if tag_type == 9:
        et=val[0] if val else 1; items=val[1] if isinstance(val,tuple) else val
        r=bytes([et])+struct.pack('<i',len(items))
        for v in items: r+=encode_payload(et,v)
        return r
    if tag_type == 10:
        r=b''
        for k,(t,v) in val.items(): r+=encode_tag(k,t,v)
        return r+b'\\x00'
    if tag_type == 11:
        return struct.pack('<i',len(val))+b''.join(struct.pack('<i',v) for v in val)
    if tag_type == 12:
        return struct.pack('<i',len(val))+b''.join(struct.pack('<q',v) for v in val)
    return b''

path, exps_json = sys.argv[1], sys.argv[2]
new_exps = json.loads(exps_json)

with open(path,'rb') as f: data=f.read()
header=data[:8]
pos=8
root,_,_=read_tag(data,pos)
if not root: sys.exit(1)
root_name, root_type, root_val = root

if 'experiments' in root_val:
    old_exp = root_val['experiments'][1] if root_val['experiments'][0]==10 else {}
    for k,v in old_exp.items(): new_exps.setdefault(k, bool(v))

root_val['experiments'] = (10, {k:(1,int(bool(v))) for k,v in new_exps.items()})

new_data = header + encode_tag(root_name, root_type, root_val)
tmp = path + '.tmp'
with open(tmp,'wb') as f: f.write(new_data)
import os; os.replace(tmp, path)
print('ok')
`;

export function getWorldExperiments(worldName) {
  const levelDat = path.join(mc.worldsDir, worldName, 'level.dat');
  if (!fs.existsSync(levelDat)) return { success: false, error: 'level.dat nicht gefunden' };
  const tmpPy = '/tmp/mc_read_exp.py';
  try {
    fs.writeFileSync(tmpPy, PY_READ_EXPERIMENTS);
    const out = execSync(`python3 "${tmpPy}" "${levelDat}"`, { encoding: 'utf8', timeout: 10000 }).trim();
    const raw = JSON.parse(out);
    const result = {};
    for (const k of KNOWN_EXPERIMENTS) result[k] = !!raw[k];
    return { success: true, experiments: result };
  } catch (e) {
    return { success: false, error: e.message };
  }
}

export function setWorldExperiments(worldName, experiments) {
  const levelDat = path.join(mc.worldsDir, worldName, 'level.dat');
  if (!fs.existsSync(levelDat)) return { success: false, error: 'level.dat nicht gefunden' };
  const tmpPy = '/tmp/mc_write_exp.py';
  try {
    fs.writeFileSync(tmpPy, PY_WRITE_EXPERIMENTS);
    const expsArg = JSON.stringify(experiments);
    execSync(`python3 "${tmpPy}" "${levelDat}" '${expsArg.replace(/'/g, "'\\''")}'`, { encoding: 'utf8', timeout: 10000 });
    const warning = isRunning() ? 'Server läuft — bitte neu starten, damit die Experimente wirksam werden.' : undefined;
    return { success: true, warning };
  } catch (e) {
    return { success: false, error: e.message };
  }
}

// ── MC-Version prüfen (von Mojang) ───────────────────────────

const MOJANG_URL    = 'https://net-secondary.web.minecraft-services.net/api/v1.0/download/links';
const VERSION_CACHE = '/tmp/mc_webapp_version_cache.json';
const CACHE_TTL     = 900;

export async function checkMcVersion(force = false) {
  const current = getVersion();
  if (!force && fs.existsSync(VERSION_CACHE)) {
    try {
      const c = JSON.parse(fs.readFileSync(VERSION_CACHE,'utf8'));
      if ((Date.now()/1000-(c.ts||0)) < CACHE_TTL) {
        return { current, latest:c.version, update_available:c.version!==current&&c.version!=='unbekannt' };
      }
    } catch {}
  }
  try {
    const { default: https } = await import('https');
    const data = await new Promise((resolve, reject) => {
      const req = https.get(MOJANG_URL, {
        headers: { 'User-Agent':'mc-webapp/1.0', 'Accept-Language':'en' }, timeout: 15000
      }, res => {
        const chunks = [];
        res.on('data', c => chunks.push(c));
        res.on('end', () => { try { resolve(JSON.parse(Buffer.concat(chunks).toString())); } catch(e){ reject(e); } });
      });
      req.on('error', reject);
      req.on('timeout', () => req.destroy(new Error('Timeout')));
    });
    let latest = 'unbekannt';
    const links = data?.result?.links || data?.links || [];
    for (const link of links) {
      const url = link?.downloadUrl||link?.url||link||'';
      const m = String(url).match(/bedrock-server-([\d.]+)\.zip/);
      if (m) { latest=m[1]; break; }
    }
    fs.writeFileSync(VERSION_CACHE, JSON.stringify({ version:latest, ts:Math.floor(Date.now()/1000) }));
    return { current, latest, update_available: latest!=='unbekannt'&&latest!==current };
  } catch (e) {
    return { current, latest:'unbekannt', update_available:false, error:e.message };
  }
}

// ── Panel-Version prüfen (GitHub mcadmin_webapp) ──────────────

const PANEL_GITHUB_API   = 'https://api.github.com/repos/Ronny-1979/mcadmin_webapp/commits/main';
const PANEL_VERSION_CACHE = '/tmp/mc_webapp_panel_version_cache.json';

export async function checkPanelVersion(force = false) {
  // Lokale Version: git SHA im Webapp-Verzeichnis
  const repoDir = path.resolve(path.join(path.dirname(new URL(import.meta.url).pathname), '../../'));
  const localSha = run(`git -C "${repoDir}" rev-parse --short HEAD 2>/dev/null`) || 'unbekannt';

  if (!force && fs.existsSync(PANEL_VERSION_CACHE)) {
    try {
      const c = JSON.parse(fs.readFileSync(PANEL_VERSION_CACHE, 'utf8'));
      if ((Date.now()/1000 - (c.ts||0)) < 300) {
        const update_available = c.sha !== 'unbekannt' && localSha !== 'unbekannt' && c.sha !== localSha;
        return { installed: localSha, available: c.sha, update_available };
      }
    } catch {}
  }

  try {
    const { default: https } = await import('https');
    const data = await new Promise((resolve, reject) => {
      const req = https.get(PANEL_GITHUB_API, {
        headers: { 'User-Agent': 'mc-webapp/1.0', 'Accept': 'application/vnd.github.v3+json' },
        timeout: 10000,
      }, res => {
        const chunks = [];
        res.on('data', c => chunks.push(c));
        res.on('end', () => { try { resolve(JSON.parse(Buffer.concat(chunks).toString())); } catch(e){ reject(e); } });
      });
      req.on('error', reject);
      req.on('timeout', () => req.destroy(new Error('Timeout')));
    });
    const remoteSha = (data?.sha || '').slice(0, 7) || 'unbekannt';
    fs.writeFileSync(PANEL_VERSION_CACHE, JSON.stringify({ sha: remoteSha, ts: Math.floor(Date.now()/1000) }));
    const update_available = remoteSha !== 'unbekannt' && localSha !== 'unbekannt' && remoteSha !== localSha;
    return { installed: localSha, available: remoteSha, update_available };
  } catch (e) {
    return { installed: localSha, available: 'unbekannt', update_available: false, error: e.message };
  }
}
