import { useState, useEffect } from 'react';
import { Save } from 'lucide-react';
import { get, post } from '../utils.js';
import { useToast } from '../useToast.jsx';

const PROP_GROUPS = {
  'Allgemein': ['server-name', 'gamemode', 'difficulty', 'max-players', 'level-name', 'level-seed'],
  'Netzwerk':  ['server-port', 'server-port-v6', 'online-mode', 'allow-list'],
  'Gameplay':  ['pvp', 'allow-cheats', 'force-gamemode', 'default-player-permission-level'],
  'Performance':['view-distance', 'tick-distance', 'player-idle-timeout'],
};

export default function SettingsPage() {
  const [props,    setProps]    = useState({});
  const [settings, setSettings] = useState({});
  const [tab,      setTab]      = useState('server');
  const [newUser,  setNewUser]  = useState('');
  const [newPass,  setNewPass]  = useState('');
  const { toast, ToastContainer } = useToast();

  useEffect(() => {
    get('/api/properties').then(r => r && setProps(r));
    get('/api/settings').then(r => r && setSettings(r));
  }, []);

  async function saveProps() {
    const r = await post('/api/properties', props);
    toast(r?.success ? 'Einstellungen gespeichert' : 'Fehler', r?.success ? 'success' : 'error');
  }

  async function saveSettings() {
    const updated = { ...settings };
    if (newUser) updated.admin_user = newUser;
    if (newPass) {
      // Passwort-Hashing erfolgt serverseitig
      updated._new_pass = newPass;
    }
    const r = await post('/api/settings', updated);
    toast(r?.success ? 'Gespeichert' : 'Fehler', r?.success ? 'success' : 'error');
    setNewUser(''); setNewPass('');
  }

  const knownKeys = Object.values(PROP_GROUPS).flat();
  const otherProps = Object.keys(props).filter(k => !knownKeys.includes(k));

  return (
    <div>
      <ToastContainer />
      <h1 style={{ fontSize: 22, fontWeight: 700, marginBottom: 24 }}>Einstellungen</h1>

      <div style={{ display: 'flex', gap: 8, marginBottom: 20 }}>
        {['server', 'panel'].map(t => (
          <button key={t} className={`btn ${tab === t ? 'btn-green' : 'btn-ghost'}`} onClick={() => setTab(t)}>
            {t === 'server' ? 'Server-Einstellungen' : 'Panel & Benutzer'}
          </button>
        ))}
      </div>

      {tab === 'server' && (
        <div>
          {Object.entries(PROP_GROUPS).map(([group, keys]) => (
            <div className="card" key={group} style={{ marginBottom: 16 }}>
              <div className="card-title">{group}</div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px' }}>
                {keys.map(k => (
                  props[k] !== undefined ? (
                    <div key={k}>
                      <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>{k}</label>
                      <input value={props[k] || ''} onChange={e => setProps(p => ({ ...p, [k]: e.target.value }))} />
                    </div>
                  ) : null
                ))}
              </div>
            </div>
          ))}

          {otherProps.length > 0 && (
            <div className="card" style={{ marginBottom: 16 }}>
              <div className="card-title">Weitere Einstellungen</div>
              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px 20px' }}>
                {otherProps.map(k => (
                  <div key={k}>
                    <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>{k}</label>
                    <input value={props[k] || ''} onChange={e => setProps(p => ({ ...p, [k]: e.target.value }))} />
                  </div>
                ))}
              </div>
            </div>
          )}

          <button className="btn btn-green" onClick={saveProps}><Save size={14} /> Speichern</button>
        </div>
      )}

      {tab === 'panel' && (
        <div>
          <div className="card" style={{ marginBottom: 16 }}>
            <div className="card-title">Zugangsdaten ändern</div>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, maxWidth: 360 }}>
              <div>
                <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Neuer Benutzername</label>
                <input value={newUser} onChange={e => setNewUser(e.target.value)} placeholder="Leer lassen = unverändert" />
              </div>
              <div>
                <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Neues Passwort</label>
                <input type="password" value={newPass} onChange={e => setNewPass(e.target.value)} placeholder="Leer lassen = unverändert" />
              </div>
              <button className="btn btn-green" onClick={saveSettings} style={{ alignSelf: 'flex-start' }}>
                <Save size={14} /> Speichern
              </button>
            </div>
          </div>

          <div className="card">
            <div className="card-title">Discord Webhook</div>
            <div style={{ maxWidth: 480 }}>
              <label style={{ fontSize: 11, color: 'var(--text2)', display: 'block', marginBottom: 4 }}>Webhook-URL</label>
              <input
                value={settings.discord_webhook || ''}
                onChange={e => setSettings(s => ({ ...s, discord_webhook: e.target.value }))}
                placeholder="https://discord.com/api/webhooks/..."
              />
              <div style={{ marginTop: 14, display: 'flex', flexDirection: 'column', gap: 8 }}>
                {settings.discord_events && Object.entries(settings.discord_events).map(([k, v]) => (
                  <label key={k} style={{ display: 'flex', alignItems: 'center', gap: 8, cursor: 'pointer' }}>
                    <input type="checkbox" checked={!!v}
                      onChange={e => setSettings(s => ({
                        ...s,
                        discord_events: { ...s.discord_events, [k]: e.target.checked }
                      }))}
                      style={{ width: 'auto' }}
                    />
                    {k.replace(/_/g, ' ')}
                  </label>
                ))}
              </div>
              <button className="btn btn-green" onClick={saveSettings} style={{ marginTop: 14 }}>
                <Save size={14} /> Speichern
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
