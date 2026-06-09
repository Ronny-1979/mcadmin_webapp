import { useState, useEffect, useRef } from 'react';
import { RefreshCw, ToggleLeft, ToggleRight, Trash2, UploadCloud } from 'lucide-react';
import { get, post, upload } from '../utils.js';
import { useToast } from '../useToast.jsx';

export default function Packs() {
  const [worlds, setWorlds] = useState([]);
  const [world, setWorld] = useState('');
  const [activeWorld, setActiveWorld] = useState('');
  const [packs, setPacks] = useState({ resource: [], behavior: [] });
  const [tab, setTab] = useState('resource');
  const [supplyingPack, setSupplyingPack] = useState(null);
  const supplyRef = useRef(null);
  const { toast, ToastContainer } = useToast();

  useEffect(() => { loadWorlds(); }, []);
  useEffect(() => { if (world) loadPacks(world); }, [world]);

  async function loadWorlds() {
    const [w, a] = await Promise.all([get('/api/worlds'), get('/api/worlds/active')]);
    if (w) setWorlds(w);
    if (a?.active) setActiveWorld(a.active);
    if (!world) setWorld(a?.active || w?.[0]?.name || '');
  }

  async function loadPacks(worldName = world) {
    if (!worldName) return;
    const r = await get(`/api/worlds/${encodeURIComponent(worldName)}/packs`);
    if (r) setPacks({ resource: r.resource || [], behavior: r.behavior || [] });
  }

  async function togglePack(uuid, type, enabled) {
    const r = await post(`/api/worlds/${encodeURIComponent(world)}/packs/toggle`, { uuid, type, enable: !enabled });
    toast(r?.success ? (enabled ? 'Pack deaktiviert' : 'Pack aktiviert') : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    loadPacks();
  }

  async function removePack(uuid, type) {
    if (!confirm('Pack aus dieser Welt entfernen?')) return;
    const r = await post(`/api/worlds/${encodeURIComponent(world)}/packs/remove`, { uuid, type });
    toast(r?.success ? 'Pack aus Welt entfernt' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    loadPacks();
  }

  async function supplyMissing(file) {
    if (!file || !supplyingPack) return;
    const fd = new FormData();
    fd.append('pack', file);
    const r = await upload(`/api/worlds/${encodeURIComponent(world)}/packs/${supplyingPack.uuid}/supply?type=${supplyingPack.type}`, fd);
    toast(r?.success ? 'Pack nachgeliefert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    setSupplyingPack(null);
    loadPacks();
  }

  const list = tab === 'resource' ? packs.resource : packs.behavior;

  return (
    <div>
      <ToastContainer />
      <h1 style={{ fontSize:22, fontWeight:700, marginBottom:20 }}>Packs</h1>

      <div className="card">
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', gap:12, marginBottom:14, flexWrap:'wrap' }}>
          <div className="card-title" style={{ margin:0 }}>🌍 Packs für Welt</div>
          <div style={{ display:'flex', alignItems:'center', gap:8 }}>
            <label style={{ color:'var(--text2)', fontSize:12 }}>Welt:</label>
            <select value={world} onChange={e => setWorld(e.target.value)} style={{ width:'auto', minWidth:220 }}>
              {worlds.map(w => <option key={w.name} value={w.name}>{w.name}{w.name === activeWorld ? ' (aktiv)' : ''}</option>)}
            </select>
            <button className="btn btn-ghost btn-sm" onClick={() => loadPacks()}><RefreshCw size={12} /></button>
          </div>
        </div>

        <div className="sub-tabs">
          <button className={`btn ${tab === 'resource' ? 'btn-green' : 'btn-ghost'}`} onClick={() => setTab('resource')}>🎨 Resource Packs</button>
          <button className={`btn ${tab === 'behavior' ? 'btn-green' : 'btn-ghost'}`} onClick={() => setTab('behavior')}>⚙️ Behavior Packs</button>
        </div>

        {!world ? <span style={{ color:'var(--text2)' }}>Keine Welt ausgewählt</span> : list.length === 0 ? (
          <span style={{ color:'var(--text2)' }}>Keine Packs für diese Welt</span>
        ) : (
          <table>
            <thead><tr><th>Name</th><th>UUID</th><th>Version</th><th>Status</th><th>Aktiv</th><th></th></tr></thead>
            <tbody>
              {list.map(p => (
                <tr key={`${tab}-${p.uuid || p.name}`}>
                  <td>{p.display_name || p.name || p.uuid?.slice(0,8)}</td>
                  <td style={{ fontSize:10, color:'var(--text2)', fontFamily:'monospace' }}>{p.uuid || '—'}</td>
                  <td>{p.version || '—'}</td>
                  <td>
                    {p.missing ? (
                      <span className="badge badge-yellow" style={{ cursor:'pointer' }} onClick={() => { setSupplyingPack({ uuid:p.uuid, type:tab }); setTimeout(() => supplyRef.current?.click(), 50); }}>
                        ⚠ fehlt – Upload
                      </span>
                    ) : <span className="badge badge-green">OK</span>}
                  </td>
                  <td>
                    <button className="btn btn-ghost btn-sm" disabled={p.missing} onClick={() => togglePack(p.uuid, tab, p.enabled)}>
                      {p.enabled ? <ToggleRight size={18} color="var(--accent)" /> : <ToggleLeft size={18} color="var(--text2)" />}
                    </button>
                  </td>
                  <td style={{ textAlign:'right' }}>
                    <button className="btn btn-red btn-sm" onClick={() => removePack(p.uuid, tab)}><Trash2 size={12} /></button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <input ref={supplyRef} type="file" accept=".mcpack,.mcaddon,.zip" hidden onChange={e => supplyMissing(e.target.files[0])} />
    </div>
  );
}
