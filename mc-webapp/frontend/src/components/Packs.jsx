import { useState, useEffect, useRef } from 'react';
import { Package, Trash2, UploadCloud, ToggleLeft, ToggleRight, RefreshCw } from 'lucide-react';
import { get, del, upload, post } from '../utils.js';
import { useToast } from '../useToast.jsx';

export default function Packs() {
  const [behavior,    setBehavior]    = useState([]);
  const [resource,    setResource]    = useState([]);
  const [tab,         setTab]         = useState('resource');
  const [worlds,      setWorlds]      = useState([]);
  const [activeWorld, setActiveWorld] = useState('');
  const [worldPacks,  setWorldPacks]  = useState({ resource:[], behavior:[] });
  const [worldTab,    setWorldTab]    = useState('resource');
  const [section,     setSection]     = useState('global'); // 'global' | 'world'
  const fileRef       = useRef();
  const supplyRef     = useRef();
  const replaceRef    = useRef();
  const worldPackRef  = useRef();
  const [replacingPack, setReplacingPack] = useState(null);
  const [supplyingPack, setSupplyingPack] = useState(null);
  const { toast, ToastContainer } = useToast();

  useEffect(() => { loadGlobal(); loadWorlds(); }, []);
  useEffect(() => { if (activeWorld) loadWorldPacks(activeWorld); }, [activeWorld]);

  async function loadGlobal() {
    const [b, r] = await Promise.all([get('/api/packs/behavior'), get('/api/packs/resource')]);
    if (b) setBehavior(b);
    if (r) setResource(r);
  }

  async function loadWorlds() {
    const [w, a] = await Promise.all([get('/api/worlds'), get('/api/worlds/active')]);
    if (w) setWorlds(w);
    if (a?.active) setActiveWorld(prev => prev || a.active);
  }

  async function loadWorldPacks(worldName) {
    const r = await get(`/api/worlds/${encodeURIComponent(worldName)}/packs`);
    if (r) setWorldPacks(r);
  }

  async function deletePack(type, name) {
    if (!confirm(`Pack "${name}" wirklich löschen?`)) return;
    const r = await del(`/api/packs/${type}/${encodeURIComponent(name)}`);
    toast(r?.success ? 'Pack gelöscht' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    loadGlobal();
  }

  async function uploadPack(file) {
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('type', tab);
    toast('Pack wird hochgeladen…', 'info');
    const r = await upload('/api/packs/upload', fd);
    toast(r?.success ? 'Pack hochgeladen' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    loadGlobal();
  }

  async function replacePack(file) {
    if (!file || !replacingPack) return;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('old_uuid', replacingPack.uuid);
    fd.append('old_type', replacingPack.type);
    toast('Pack wird ersetzt…', 'info');
    const r = await upload('/api/packs/replace', fd);
    toast(r?.success ? 'Pack ersetzt' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    setReplacingPack(null);
    loadGlobal();
  }

  async function togglePack(uuid, type, enabled) {
    const r = await post(`/api/worlds/${encodeURIComponent(activeWorld)}/packs/toggle`, { uuid, type, enable: !enabled });
    toast(r?.success ? (enabled ? 'Pack deaktiviert' : 'Pack aktiviert') : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    loadWorldPacks(activeWorld);
  }

  async function supplyMissing(file, uuid, type) {
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    const r = await upload(`/api/worlds/${encodeURIComponent(activeWorld)}/packs/${uuid}/supply?type=${type}`, fd);
    toast(r?.success ? 'Pack nachgeliefert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    setSupplyingPack(null);
    loadWorldPacks(activeWorld); loadGlobal();
  }

  const globalPacks = tab === 'behavior' ? behavior : resource;
  const wPacks = worldTab === 'behavior' ? worldPacks.behavior : worldPacks.resource;

  return (
    <div>
      <ToastContainer />
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:24 }}>
        <h1 style={{ fontSize:22, fontWeight:700 }}>Packs & Add-ons</h1>
        <div style={{ display:'flex', gap:8 }}>
          <button className={`btn ${section==='global'?'btn-green':'btn-ghost'}`} onClick={() => setSection('global')}>Global</button>
          <button className={`btn ${section==='world'?'btn-green':'btn-ghost'}`} onClick={() => setSection('world')}>Pro Welt</button>
        </div>
      </div>

      {section === 'global' && (
        <>
          <div style={{ display:'flex', gap:8, marginBottom:18 }}>
            {['resource','behavior'].map(t => (
              <button key={t} className={`btn ${tab===t?'btn-green':'btn-ghost'}`} onClick={() => setTab(t)}>
                {t==='resource'?'Resource Packs':'Behavior Packs'}
              </button>
            ))}
          </div>

          <div className="card" style={{ marginBottom:18 }}>
            <div className="card-title">Pack hochladen (.mcpack / .mcaddon / .zip)</div>
            <div className="drop-zone" onClick={() => fileRef.current?.click()}>
              <UploadCloud size={24} style={{ margin:'0 auto 8px', display:'block' }} />
              Pack hier ablegen oder klicken
              <input ref={fileRef} type="file" accept=".mcpack,.mcaddon,.zip" hidden onChange={e => uploadPack(e.target.files[0])} />
            </div>
          </div>

          <div className="card">
            <div className="card-title">{tab==='resource'?'Resource':'Behavior'} Packs ({globalPacks.length})</div>
            {globalPacks.length === 0
              ? <span style={{ color:'var(--text2)' }}>Keine Packs installiert</span>
              : (
                <table>
                  <thead><tr><th>Name</th><th>UUID</th><th>Version</th><th></th></tr></thead>
                  <tbody>
                    {globalPacks.map(p => (
                      <tr key={p.name}>
                        <td><Package size={13} style={{ marginRight:6, verticalAlign:'middle', color:'var(--text2)' }} />{p.display_name || p.name}</td>
                        <td style={{ fontSize:10, color:'var(--text2)', fontFamily:'monospace' }}>{(p.uuid||'').slice(0,8)}…</td>
                        <td style={{ fontSize:12 }}>{p.version||'—'}</td>
                        <td>
                          <div style={{ display:'flex', gap:6, justifyContent:'flex-end' }}>
                            <button className="btn btn-ghost btn-sm" title="Ersetzen"
                              onClick={() => { setReplacingPack({uuid: p.uuid, type: tab}); setTimeout(() => replaceRef.current?.click(), 50); }}>
                              <RefreshCw size={12} />
                            </button>
                            <button className="btn btn-red btn-sm" onClick={() => deletePack(tab, p.name)}>
                              <Trash2 size={12} />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )
            }
          </div>
          <input ref={replaceRef} type="file" accept=".mcpack,.mcaddon,.zip" hidden onChange={e => replacePack(e.target.files[0])} />
        </>
      )}

      {section === 'world' && (
        <>
          <div style={{ marginBottom:16, display:'flex', gap:8, alignItems:'center' }}>
            <select value={activeWorld} onChange={e => setActiveWorld(e.target.value)} style={{ maxWidth:250 }}>
              <option value="">-- Welt wählen --</option>
              {worlds.map(w => <option key={w.name} value={w.name}>{w.name}</option>)}
            </select>
            {activeWorld && <button className="btn btn-ghost btn-sm" onClick={() => loadWorldPacks(activeWorld)}><RefreshCw size={13} /></button>}
          </div>

          {activeWorld && (
            <>
              <div style={{ display:'flex', gap:8, marginBottom:18 }}>
                {['resource','behavior'].map(t => (
                  <button key={t} className={`btn ${worldTab===t?'btn-green':'btn-ghost'}`} onClick={() => setWorldTab(t)}>
                    {t==='resource'?'Resource Packs':'Behavior Packs'}
                  </button>
                ))}
              </div>
              <div className="card" style={{ marginBottom:18 }}>
                <div className="card-title">Pack für „{activeWorld}" hochladen</div>
                <div className="drop-zone" style={{ padding:'12px 8px' }} onClick={() => worldPackRef.current?.click()}>
                  <UploadCloud size={20} style={{ margin:'0 auto 6px', display:'block' }} />
                  .mcpack / .mcaddon / .zip hochladen — wird direkt für diese Welt aktiviert
                  <input ref={worldPackRef} type="file" accept=".mcpack,.mcaddon,.zip" hidden onChange={async e => {
                    const file = e.target.files[0]; if (!file) return;
                    const fd = new FormData(); fd.append('pack', file);
                    toast('Pack wird hochgeladen…', 'info');
                    const r = await upload(`/api/worlds/${encodeURIComponent(activeWorld)}/packs/upload`, fd);
                    toast(r?.success ? 'Pack installiert und aktiviert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
                    loadWorldPacks(activeWorld); loadGlobal();
                  }} />
                </div>
              </div>

              <div className="card">
                <div className="card-title">Packs für „{activeWorld}"</div>
                {wPacks.length === 0
                  ? <span style={{ color:'var(--text2)' }}>Keine Packs</span>
                  : (
                    <table>
                      <thead><tr><th>Name</th><th>Status</th><th>Aktiv</th></tr></thead>
                      <tbody>
                        {wPacks.map(p => (
                          <tr key={p.uuid}>
                            <td>{p.display_name || p.name || p.uuid?.slice(0,8)}</td>
                            <td>
                              {p.missing
                                ? <span className="badge badge-yellow" style={{ cursor:'pointer' }}
                                    onClick={() => { setSupplyingPack({uuid:p.uuid,type:worldTab}); setTimeout(()=>supplyRef.current?.click(),50); }}>
                                    ⚠ fehlt – klicken zum Upload
                                  </span>
                                : <span className="badge badge-green">OK</span>}
                            </td>
                            <td>
                              <div style={{ display: 'flex', gap: 4, alignItems: 'center' }}>
                                <button className="btn btn-ghost btn-sm" disabled={p.missing}
                                  onClick={() => togglePack(p.uuid, worldTab, p.enabled)}>
                                  {p.enabled
                                    ? <ToggleRight size={18} color="var(--accent)" />
                                    : <ToggleLeft size={18} color="var(--text2)" />}
                                </button>
                                <button className="btn btn-red btn-sm" title="Aus Welt entfernen"
                                  onClick={async () => {
                                    const r = await post(`/api/worlds/${encodeURIComponent(activeWorld)}/packs/remove`, { uuid: p.uuid, type: worldTab });
                                    toast(r?.success ? 'Pack aus Welt entfernt' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
                                    loadWorldPacks(activeWorld);
                                  }}>
                                  <Trash2 size={12} />
                                </button>
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  )
                }
              </div>
              <input ref={supplyRef} type="file" accept=".mcpack,.mcaddon,.zip" hidden
                onChange={e => supplyMissing(e.target.files[0], supplyingPack?.uuid, supplyingPack?.type)} />
            </>
          )}
        </>
      )}
    </div>
  );
}
