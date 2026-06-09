import { useEffect, useState } from 'react';
import { Bell, Key, Save, TestTube, User } from 'lucide-react';
import Updates from './Updates.jsx';
import Backups from './Backups.jsx';
import Schedules from './Schedules.jsx';
import { get, post } from '../utils.js';
import { useToast } from '../useToast.jsx';

const DISCORD_LABELS = {
  server_start:     ['🚀 Server gestartet', 'Wenn der Minecraft-Server gestartet wurde'],
  server_stop:      ['🛑 Server gestoppt', 'Wenn der Minecraft-Server gestoppt wurde'],
  server_restart:   ['🔁 Server neugestartet', 'Wenn ein Neustart ausgelöst wurde'],
  player_join:      ['👋 Spieler beigetreten', 'Wenn ein Spieler den Server betritt'],
  player_leave:     ['👋 Spieler verlassen', 'Wenn ein Spieler den Server verlässt'],
  backup_created:   ['💾 Backup erstellt', 'Nach jedem manuellen oder automatischen Backup'],
  backup_failed:    ['⚠️ Backup fehlgeschlagen', 'Wenn ein Backup nicht erstellt werden konnte'],
  update_available: ['🔔 Update verfügbar', 'Wenn die automatische Prüfung ein Update findet'],
  world_changed:    ['🌍 Welt gewechselt', 'Wenn eine andere Welt aktiviert wurde'],
};

function initialTab() {
  const q = new URLSearchParams(window.location.search).get('tab');
  return ['updates','backups','schedules','users','discord'].includes(q) ? q : 'updates';
}

export default function SettingsPage() {
  const [tab, setTab] = useState(initialTab);
  const [settings, setSettings] = useState({});
  const [oldPass, setOldPass] = useState('');
  const [newPass, setNewPass] = useState('');
  const [confirmPass, setConfirmPass] = useState('');
  const [newUser, setNewUser] = useState('');
  const [testing, setTesting] = useState(false);
  const { toast, ToastContainer } = useToast();

  useEffect(() => { loadSettings(); }, []);

  async function loadSettings() {
    const r = await get('/api/settings');
    if (r) setSettings(r);
  }

  function switchTab(next) {
    setTab(next);
    const url = new URL(window.location.href);
    url.searchParams.set('tab', next);
    window.history.replaceState(null, '', url.toString());
  }

  async function saveCredentials() {
    if (!oldPass) return toast('Aktuelles Passwort eingeben', 'error');
    if (!newPass && !newUser) return toast('Neues Passwort oder Benutzername eingeben', 'error');
    if (newPass && newPass !== confirmPass) return toast('Passwörter stimmen nicht überein', 'error');
    if (newPass && newPass.length < 6) return toast('Passwort muss mindestens 6 Zeichen haben', 'error');
    if (newUser && newUser.length < 3) return toast('Benutzername muss mindestens 3 Zeichen haben', 'error');

    const payload = { _old_pass: oldPass };
    if (newPass) { payload._new_pass = newPass; payload._confirm_pass = confirmPass; }
    if (newUser) payload.admin_user = newUser;

    const r = await post('/api/settings', payload);
    toast(r?.success ? 'Benutzer & Passwort gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    if (r?.success) {
      setOldPass(''); setNewPass(''); setConfirmPass(''); setNewUser('');
      loadSettings();
    }
  }

  async function saveDiscord() {
    const r = await post('/api/settings', {
      discord_webhook: settings.discord_webhook || '',
      discord_events: settings.discord_events || {},
    });
    toast(r?.success ? 'Discord gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
  }

  async function testDiscord() {
    if (!settings.discord_webhook) return toast('Keine Webhook-URL eingetragen', 'error');
    setTesting(true);
    const r = await post('/api/settings/test-discord', { webhook: settings.discord_webhook });
    toast(r?.success ? 'Test-Nachricht gesendet' : (r?.error || 'Webhook nicht erreichbar'), r?.success ? 'success' : 'error');
    setTesting(false);
  }

  return (
    <div>
      <ToastContainer />
      <h1 style={{ fontSize:22, fontWeight:700, marginBottom:20 }}>Einstellungen</h1>

      <div className="sub-tabs">
        <button className={`btn ${tab === 'updates' ? 'btn-green' : 'btn-ghost'}`} onClick={() => switchTab('updates')}>⬆️ Updates</button>
        <button className={`btn ${tab === 'backups' ? 'btn-green' : 'btn-ghost'}`} onClick={() => switchTab('backups')}>📦 Backups</button>
        <button className={`btn ${tab === 'schedules' ? 'btn-green' : 'btn-ghost'}`} onClick={() => switchTab('schedules')}>⏱ Zeitpläne</button>
        <button className={`btn ${tab === 'users' ? 'btn-green' : 'btn-ghost'}`} onClick={() => switchTab('users')}>👤 Benutzer & Passwort</button>
        <button className={`btn ${tab === 'discord' ? 'btn-green' : 'btn-ghost'}`} onClick={() => switchTab('discord')}>🔔 Discord</button>
      </div>

      {tab === 'updates' && <Updates />}
      {tab === 'backups' && <Backups />}
      {tab === 'schedules' && <Schedules />}

      {tab === 'users' && (
        <div style={{ display:'flex', flexDirection:'column', gap:16 }}>
          <div className="card">
            <div className="card-title"><Key size={14} style={{ verticalAlign:'middle', marginRight:6 }} />Benutzer & Passwort</div>
            <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:16, maxWidth:780 }}>
              <div>
                <label style={{ fontSize:11, color:'var(--text2)', display:'block', marginBottom:4 }}>Aktuelles Passwort *</label>
                <input type="password" value={oldPass} onChange={e => setOldPass(e.target.value)} />
              </div>
              <div>
                <label style={{ fontSize:11, color:'var(--text2)', display:'block', marginBottom:4 }}>Neuer Benutzername</label>
                <input value={newUser} onChange={e => setNewUser(e.target.value)} placeholder="Leer = unverändert" />
              </div>
              <div>
                <label style={{ fontSize:11, color:'var(--text2)', display:'block', marginBottom:4 }}>Neues Passwort</label>
                <input type="password" value={newPass} onChange={e => setNewPass(e.target.value)} placeholder="Leer = unverändert" />
              </div>
              <div>
                <label style={{ fontSize:11, color:'var(--text2)', display:'block', marginBottom:4 }}>Neues Passwort bestätigen</label>
                <input type="password" value={confirmPass} onChange={e => setConfirmPass(e.target.value)} />
              </div>
            </div>
            <button className="btn btn-green" style={{ marginTop:14 }} onClick={saveCredentials}><Save size={14} /> Speichern</button>
          </div>
          <div className="card">
            <div className="card-title"><User size={14} style={{ verticalAlign:'middle', marginRight:6 }} />Hinweis</div>
            <div style={{ color:'var(--text2)', fontSize:13 }}>Diese Seite entspricht im Aufbau dem Panel-Bereich „Benutzer & Passwort“. Server-Einstellungen liegen wie im Panel bei „Welten“ rechts neben der Weltliste.</div>
          </div>
        </div>
      )}

      {tab === 'discord' && (
        <div className="card">
          <div className="card-title"><Bell size={14} style={{ verticalAlign:'middle', marginRight:6 }} />Discord</div>
          <div style={{ maxWidth:720 }}>
            <label style={{ fontSize:11, color:'var(--text2)', display:'block', marginBottom:4 }}>Webhook-URL</label>
            <input value={settings.discord_webhook || ''} onChange={e => setSettings(s => ({ ...s, discord_webhook:e.target.value }))} placeholder="https://discord.com/api/webhooks/..." />

            <div style={{ fontSize:13, fontWeight:600, margin:'16px 0 8px' }}>Ereignisse benachrichtigen:</div>
            <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:8 }}>
              {Object.entries(DISCORD_LABELS).map(([key, [label, desc]]) => (
                <label key={key} style={{ display:'flex', gap:8, alignItems:'flex-start', cursor:'pointer', fontSize:13, padding:8, background:'var(--bg3)', borderRadius:8 }}>
                  <input type="checkbox" style={{ width:'auto', marginTop:3 }} checked={!!settings.discord_events?.[key]}
                    onChange={e => setSettings(s => ({ ...s, discord_events:{ ...(s.discord_events || {}), [key]: e.target.checked } }))} />
                  <span><strong>{label}</strong><br /><span style={{ color:'var(--text2)', fontSize:11 }}>{desc}</span></span>
                </label>
              ))}
            </div>

            <div style={{ display:'flex', gap:8, marginTop:16 }}>
              <button className="btn btn-green" onClick={saveDiscord}><Save size={14} /> Discord speichern</button>
              <button className="btn btn-ghost" onClick={testDiscord} disabled={testing}><TestTube size={14} /> {testing ? 'Teste…' : 'Test senden'}</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
