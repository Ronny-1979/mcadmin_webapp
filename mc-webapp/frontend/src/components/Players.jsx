import { useState, useEffect } from 'react';
import { Shield, ShieldOff, UserX, UserPlus, RefreshCw } from 'lucide-react';
import { get, post } from '../utils.js';
import { useToast } from '../useToast.jsx';

export default function Players() {
  const [whitelist, setWhitelist]   = useState([]);
  const [perms,     setPerms]       = useState([]);
  const [online,    setOnline]      = useState([]);
  const [newName,   setNewName]     = useState('');
  const [tab,       setTab]         = useState('whitelist');
  const { toast, ToastContainer }   = useToast();

  useEffect(() => { load(); }, []);

  async function load() {
    const [wl, pm, st] = await Promise.all([
      get('/api/whitelist'),
      get('/api/permissions'),
      get('/api/status'),
    ]);
    if (wl) setWhitelist(wl);
    if (pm) setPerms(pm);
    if (st) setOnline(st.onlinePlayers || []);
  }

  async function addWhitelist() {
    if (!newName.trim()) return;
    const r = await post('/api/whitelist/add', { name: newName.trim() });
    toast(r?.success !== false ? `${newName} zur Whitelist hinzugefügt` : (r?.error || 'Fehler'), r?.success !== false ? 'success' : 'error');
    setNewName(''); load();
  }

  async function removeWhitelist(name) {
    const r = await post('/api/whitelist/remove', { name });
    toast(r?.success ? 'Entfernt' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    load();
  }

  async function kick(name) {
    const r = await post('/api/console/send', { cmd: `kick ${name}` });
    toast(`${name} gekickt`, 'success');
    setTimeout(load, 1000);
  }

  async function op(xuid, name) {
    const r = await post('/api/permissions/set', { xuid, name, level: 'operator' });
    toast(r?.success ? `${name} ist jetzt OP` : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    load();
  }

  async function deop(xuid, name) {
    const r = await post('/api/permissions/remove', { xuid });
    toast(r?.success ? `${name} OP entfernt` : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    load();
  }

  const opXuids = new Set(perms.filter(p => p.permission === 'operator').map(p => p.xuid));

  return (
    <div>
      <ToastContainer />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 24 }}>
        <h1 style={{ fontSize: 22, fontWeight: 700 }}>Spieler</h1>
        <button className="btn btn-ghost btn-sm" onClick={load}><RefreshCw size={13} /> Aktualisieren</button>
      </div>

      {/* Online */}
      <div className="card" style={{ marginBottom: 18 }}>
        <div className="card-title">Online ({online.length})</div>
        {online.length === 0
          ? <span style={{ color: 'var(--text2)' }}>Niemand online</span>
          : online.map(p => (
            <div key={p} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0', borderBottom: '1px solid var(--border)' }}>
              <span className="dot dot-green" />
              <span style={{ flex: 1 }}>{p}</span>
              <button className="btn btn-red btn-sm" onClick={() => kick(p)}><UserX size={12} /> Kick</button>
            </div>
          ))
        }
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {['whitelist', 'permissions'].map(t => (
          <button key={t} className={`btn ${tab === t ? 'btn-green' : 'btn-ghost'}`} onClick={() => setTab(t)}>
            {t === 'whitelist' ? 'Whitelist' : 'Berechtigungen'}
          </button>
        ))}
      </div>

      {tab === 'whitelist' && (
        <div className="card">
          <div className="card-title">Whitelist</div>
          <div style={{ display: 'flex', gap: 8, marginBottom: 14 }}>
            <input placeholder="Spielername" value={newName} onChange={e => setNewName(e.target.value)}
              onKeyDown={e => e.key === 'Enter' && addWhitelist()} />
            <button className="btn btn-green" onClick={addWhitelist}><UserPlus size={14} /></button>
          </div>
          <table>
            <thead><tr><th>Name</th><th>XUID</th><th></th></tr></thead>
            <tbody>
              {whitelist.map(p => (
                <tr key={p.name}>
                  <td>{p.name}</td>
                  <td style={{ color: 'var(--text2)', fontSize: 12 }}>{p.xuid || '—'}</td>
                  <td>
                    <button className="btn btn-red btn-sm" onClick={() => removeWhitelist(p.name)}>
                      <UserX size={12} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'permissions' && (
        <div className="card">
          <div className="card-title">Berechtigungen (OP)</div>
          {perms.length === 0
            ? <span style={{ color: 'var(--text2)' }}>Keine Einträge</span>
            : (
              <table>
                <thead><tr><th>Name</th><th>Level</th><th></th></tr></thead>
                <tbody>
                  {perms.map(p => (
                    <tr key={p.xuid}>
                      <td>{p.name}</td>
                      <td><span className="badge badge-yellow">{p.permission}</span></td>
                      <td>
                        <button className="btn btn-red btn-sm" onClick={() => deop(p.xuid, p.name)}>
                          <ShieldOff size={12} /> OP entfernen
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )
          }
        </div>
      )}
    </div>
  );
}
