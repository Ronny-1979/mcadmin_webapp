import { useState, useEffect } from 'react';
import { Save, TestTube, Key, User, Bell, Database } from 'lucide-react';
import { get, post } from '../utils.js';
import { useToast } from '../useToast.jsx';

const PROP_GROUPS = {
  'Allgemein':    ['server-name', 'gamemode', 'difficulty', 'max-players', 'level-name', 'level-seed'],
  'Netzwerk':     ['server-port', 'server-portv6', 'online-mode', 'allow-list'],
  'Gameplay':     ['pvp', 'allow-cheats', 'force-gamemode', 'default-player-permission-level'],
  'Performance':  ['view-distance', 'tick-distance', 'player-idle-timeout'],
};

const BOOL_PROPS = new Set(['online-mode','allow-list','pvp','allow-cheats','force-gamemode',
  'content-log-file-enabled','texturepack-required','command-blocks-enabled','emit-server-telemetry',
  'disable-player-interaction','lan-visibility','client-side-chunk-generation-enabled',
  'block-network-ids-are-hashes','server-authoritative-movement','disable-custom-skins',
  'server-authoritative-sound','enable-lan-visibility']);

const DISCORD_LABELS = {
  server_start:    'Server gestartet',
  server_stop:     'Server gestoppt',
  server_restart:  'Server neugestartet',
  player_join:     'Spieler beigetreten',
  player_leave:    'Spieler verlassen',
  backup_created:  'Backup erstellt',
  backup_failed:   'Backup fehlgeschlagen',
  update_available:'Update verfügbar',
  world_changed:   'Welt gewechselt',
};

export default function SettingsPage() {
  const [props,      setProps]      = useState({});
  const [settings,   setSettings]   = useState({});
  const [tab,        setTab]        = useState('server');
  const [oldPass,    setOldPass]    = useState('');
  const [newPass,    setNewPass]    = useState('');
  const [confirmPass,setConfirmPass]= useState('');
  const [newUser,    setNewUser]    = useState('');
  const [maxCount,   setMaxCount]   = useState(20);
  const [testing,    setTesting]    = useState(false);
  const { toast, ToastContainer } = useToast();

  useEffect(() => {
    get('/api/properties').then(r => r && setProps(r));
    get('/api/settings').then(r => {
      if (r) {
        setSettings(r);
        setMaxCount(r.backup_max_count || 20);
      }
    });
  }, []);

  async function saveProps() {
    const r = await post('/api/properties', props);
    toast(r?.success ? 'Server-Einstellungen gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
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
    toast(r?.success ? 'Zugangsdaten gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    if (r?.success) { setOldPass(''); setNewPass(''); setConfirmPass(''); setNewUser(''); }
  }

  async function saveDiscord() {
    const r = await post('/api/settings', {
      discord_webhook: settings.discord_webhook || '',
      discord_events:  settings.discord_events  || {},
    });
    toast(r?.success ? 'Discord-Einstellungen gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
  }

  async function testDiscord() {
    if (!settings.discord_webhook) return toast('Keine Webhook-URL eingetragen', 'error');
    setTesting(true);
    const r = await post('/api/settings/test-discord', { webhook: settings.discord_webhook });
    toast(r?.success ? 'Test-Nachricht gesendet!' : (r?.error || 'Webhook nicht erreichbar'), r?.success ? 'success' : 'error');
    setTesting(false);
  }

  async function saveMaxCount() {
    const r = await post('/api/settings/backup-max-count', { count: maxCount });
    toast(r?.success ? `Backup-Limit auf ${maxCount} gesetzt` : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
  }

  const knownKeys = Object.values(PROP_GROUPS).flat();
  const otherProps = Object.keys(props).filter(k => !knownKeys.includes(k));

  function PropField({ k }) {
    if (props[k] === undefined) return null;
    if (BOOL_PROPS.has(k)) {
      return (
        <div key={k}>
          <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>{k}</label>
          <select value={props[k]} onChange={e => setProps(p => ({ ...p, [k]: e.target.value }))}>
            <option value="true">true</option>
            <option value="false">false</option>
          </select>
        </div>
      );
    }
    if (k === 'gamemode') return (
      <div key={k}>
        <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>{k}</label>
        <select value={props[k]} onChange={e => setProps(p => ({ ...p, [k]: e.target.value }))}>
          <option value="survival">survival</option>
          <option value="creative">creative</option>
          <option value="adventure">adventure</option>
          <option value="spectator">spectator</option>
        </select>
      </div>
    );
    if (k === 'difficulty') return (
      <div key={k}>
        <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>{k}</label>
        <select value={props[k]} onChange={e => setProps(p => ({ ...p, [k]: e.target.value }))}>
          <option value="peaceful">peaceful</option>
          <option value="easy">easy</option>
          <option value="normal">normal</option>
          <option value="hard">hard</option>
        </select>
      </div>
    );
    if (k === 'default-player-permission-level') return (
      <div key={k}>
        <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>{k}</label>
        <select value={props[k]} onChange={e => setProps(p => ({ ...p, [k]: e.target.value }))}>
          <option value="visitor">visitor</option>
          <option value="member">member</option>
          <option value="operator">operator</option>
        </select>
      </div>
    );
    return (
      <div key={k}>
        <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>{k}</label>
        <input value={props[k] || ''} onChange={e => setProps(p => ({ ...p, [k]: e.target.value }))} />
      </div>
    );
  }

  return (
    <div>
      <ToastContainer />
      <h1 style={{ fontSize: 22, fontWeight: 700, marginBottom: 24 }}>Einstellungen</h1>

      <div style={{ display: 'flex', gap: 8, marginBottom: 20, flexWrap: 'wrap' }}>
        {[['server','Server-Einstellungen'],['panel','Panel & Benutzer'],['discord','Discord'],['backup','Backups']].map(([t,l]) => (
          <button key={t} className={`btn ${tab === t ? 'btn-green' : 'btn-ghost'}`} onClick={() => setTab(t)}>{l}</button>
        ))}
      </div>

      {tab === 'server' && (
        <div>
          {Object.entries(PROP_GROUPS).map(([group, keys]) => (
            <div className="card" key={group} style={{ marginBottom: 16 }}>
              <div className="card-title">{group}</div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px' }}>
                {keys.map(k => <PropField key={k} k={k} />)}
              </div>
            </div>
          ))}
          {otherProps.length > 0 && (
            <div className="card" style={{ marginBottom: 16 }}>
              <div className="card-title">Weitere Einstellungen</div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px' }}>
                {otherProps.map(k => <PropField key={k} k={k} />)}
              </div>
            </div>
          )}
          <button className="btn btn-green" onClick={saveProps}><Save size={14} /> Speichern</button>
        </div>
      )}

      {tab === 'panel' && (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div className="card">
            <div className="card-title"><Key size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />Passwort ändern</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, maxWidth: 360 }}>
              <div>
                <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Aktuelles Passwort *</label>
                <input type="password" value={oldPass} onChange={e => setOldPass(e.target.value)} />
              </div>
              <div>
                <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Neues Passwort (min. 6 Zeichen)</label>
                <input type="password" value={newPass} onChange={e => setNewPass(e.target.value)} placeholder="Leer = unverändert" />
              </div>
              <div>
                <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Neues Passwort bestätigen</label>
                <input type="password" value={confirmPass} onChange={e => setConfirmPass(e.target.value)} />
              </div>
            </div>
          </div>
          <div className="card">
            <div className="card-title"><User size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />Benutzername ändern</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, maxWidth: 360 }}>
              <div>
                <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Aktuelles Passwort zur Bestätigung *</label>
                <input type="password" value={oldPass} onChange={e => setOldPass(e.target.value)} placeholder="Gleich wie oben" />
              </div>
              <div>
                <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Neuer Benutzername (min. 3 Zeichen)</label>
                <input value={newUser} onChange={e => setNewUser(e.target.value)} placeholder="Leer = unverändert" />
              </div>
            </div>
          </div>
          <div>
            <button className="btn btn-green" onClick={saveCredentials}><Save size={14} /> Speichern</button>
          </div>
        </div>
      )}

      {tab === 'discord' && (
        <div className="card">
          <div className="card-title"><Bell size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />Discord Webhook</div>
          <div style={{ maxWidth: 520 }}>
            <div style={{ marginBottom: 14 }}>
              <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Webhook-URL</label>
              <input
                value={settings.discord_webhook || ''}
                onChange={e => setSettings(s => ({ ...s, discord_webhook: e.target.value }))}
                placeholder="https://discord.com/api/webhooks/..."
              />
            </div>
            <div style={{ marginBottom: 14 }}>
              <div style={{ fontSize: 13, fontWeight: 600, marginBottom: 8 }}>Ereignisse benachrichtigen:</div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
                {Object.entries(DISCORD_LABELS).map(([k, label]) => (
                  <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer', fontSize: 13 }}>
                    <input type="checkbox"
                      checked={!!(settings.discord_events?.[k])}
                      onChange={e => setSettings(s => ({
                        ...s,
                        discord_events: { ...(s.discord_events || {}), [k]: e.target.checked }
                      }))}
                      style={{ width: 'auto' }}
                    />
                    {label}
                  </label>
                ))}
              </div>
            </div>
            <div style={{ display: 'flex', gap: 8 }}>
              <button className="btn btn-green" onClick={saveDiscord}><Save size={14} /> Speichern</button>
              <button className="btn btn-ghost" onClick={testDiscord} disabled={testing}>
                <TestTube size={14} /> {testing ? 'Sende…' : 'Testen'}
              </button>
            </div>
          </div>
        </div>
      )}

      {tab === 'backup' && (
        <div className="card">
          <div className="card-title"><Database size={14} style={{ verticalAlign: 'middle', marginRight: 6 }} />Backup-Einstellungen</div>
          <div style={{ maxWidth: 360 }}>
            <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Maximale Anzahl Backups</label>
            <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
              <select value={maxCount} onChange={e => setMaxCount(Number(e.target.value))} style={{ maxWidth: 120 }}>
                {[5, 10, 15, 20, 25, 30].map(n => <option key={n} value={n}>{n} Backups</option>)}
              </select>
              <button className="btn btn-green" onClick={saveMaxCount}><Save size={14} /> Speichern</button>
            </div>
            <div style={{ fontSize: 12, color: 'var(--text2)', marginTop: 8 }}>
              Älteste Backups werden automatisch gelöscht wenn das Limit erreicht ist.
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
