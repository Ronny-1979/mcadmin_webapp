import { useState, useEffect, useRef } from 'react';
import { Globe, Trash2, UploadCloud, CheckCircle, Plus, Edit2, Settings, ChevronDown, ChevronUp, AlertTriangle, FlaskConical } from 'lucide-react';
import { get, post, del, upload } from '../utils.js';
import { useToast } from '../useToast.jsx';

function Modal({ title, onClose, children }) {
  return (
    <div style={{ position:'fixed',inset:0,background:'rgba(0,0,0,0.6)',display:'flex',alignItems:'center',justifyContent:'center',zIndex:1000 }}>
      <div className="card" style={{ minWidth:340,maxWidth:500,width:'100%' }}>
        <div style={{ display:'flex',justifyContent:'space-between',alignItems:'center',marginBottom:16 }}>
          <strong>{title}</strong>
          <button className="btn btn-ghost btn-sm" onClick={onClose}>✕</button>
        </div>
        {children}
      </div>
    </div>
  );
}

export default function Worlds() {
  const [worlds, setWorlds]         = useState([]);
  const [active, setActive]         = useState(null);
  const [drag,   setDrag]           = useState(false);
  const [uploadName, setUploadName] = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [renameTarget, setRenameTarget] = useState(null);
  const [propsTarget,  setPropsTarget]  = useState(null);
  const [expandedProps, setExpandedProps] = useState({});
  const [newName, setNewName]       = useState('');
  const [create, setCreate]         = useState({ name:'', gamemode:'survival', difficulty:'normal', seed:'' });
  const [worldProps, setWorldProps] = useState({});
  const [uploadResult, setUploadResult] = useState(null);
  const [expTarget, setExpTarget]       = useState(null);
  const [experiments, setExperiments]   = useState({});
  const [expWarning, setExpWarning]     = useState('');
  const fileRef = useRef();
  const { toast, ToastContainer } = useToast();

  useEffect(() => { load(); }, []);

  async function load() {
    const [w, a] = await Promise.all([get('/api/worlds'), get('/api/worlds/active')]);
    if (w) setWorlds(w);
    if (a) setActive(a.active);
  }

  async function switchWorld(name) {
    const r = await post('/api/worlds/switch', { name });
    toast(r?.success ? `Welt gewechselt zu ${name}` : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    load();
  }

  async function deleteWorld(name) {
    if (!confirm(`Welt "${name}" wirklich löschen?`)) return;
    const r = await del(`/api/worlds/${encodeURIComponent(name)}`);
    toast(r?.success ? 'Welt gelöscht' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    load();
  }

  async function uploadWorld(file) {
    if (!file) return;
    const wName = uploadName || file.name.replace(/\.[^.]+$/, '');
    const fd = new FormData();
    fd.append('file', file);
    fd.append('name', wName);
    toast('Welt wird hochgeladen…', 'info');
    const r = await upload('/api/worlds/upload', fd);
    if (r?.success) {
      setUploadResult(r);
      toast('Welt hochgeladen', 'success');
    } else {
      toast(r?.error || 'Fehler beim Hochladen', 'error');
    }
    setUploadName('');
    load();
  }

  async function createWorld() {
    if (!create.name.trim()) return toast('Name erforderlich', 'error');
    const r = await post('/api/worlds/create', create);
    toast(r?.success ? `Welt "${create.name}" erstellt` : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    if (r?.success) { setShowCreate(false); setCreate({ name:'', gamemode:'survival', difficulty:'normal', seed:'' }); load(); }
  }

  async function renameWorld() {
    if (!newName.trim()) return toast('Neuer Name erforderlich', 'error');
    const r = await post(`/api/worlds/${encodeURIComponent(renameTarget)}/rename`, { newName });
    toast(r?.success ? 'Welt umbenannt' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    if (r?.success) { setRenameTarget(null); setNewName(''); load(); }
  }

  async function openProps(worldName) {
    setPropsTarget(worldName);
    const r = await get(`/api/worlds/${encodeURIComponent(worldName)}/properties`);
    setWorldProps(r || {});
  }

  async function saveProps() {
    const r = await post(`/api/worlds/${encodeURIComponent(propsTarget)}/properties`, worldProps);
    toast(r?.success ? 'Eigenschaften gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    if (r?.success) setPropsTarget(null);
  }

  async function openExp(worldName) {
    setExpTarget(worldName);
    setExpWarning('');
    const r = await get(`/api/worlds/${encodeURIComponent(worldName)}/experiments`);
    setExperiments(r?.experiments || {});
  }

  async function saveExp() {
    const r = await post(`/api/worlds/${encodeURIComponent(expTarget)}/experiments`, experiments);
    if (r?.success) {
      toast(r.warning ? `Gespeichert — ${r.warning}` : 'Experimente gespeichert', r.warning ? 'info' : 'success');
      if (r.warning) setExpWarning(r.warning);
      else setExpTarget(null);
    } else {
      toast(r?.error || 'Fehler', 'error');
    }
  }

  function onDrop(e) {
    e.preventDefault(); setDrag(false);
    const file = e.dataTransfer.files[0];
    if (file) uploadWorld(file);
  }

  return (
    <div>
      <ToastContainer />
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:24 }}>
        <h1 style={{ fontSize:22, fontWeight:700 }}>Welten</h1>
        <button className="btn btn-green" onClick={() => setShowCreate(true)}>
          <Plus size={14} /> Neue Welt
        </button>
      </div>

      {/* Upload */}
      <div className="card" style={{ marginBottom:20 }}>
        <div className="card-title">Welt hochladen (.mcworld)</div>
        <input placeholder="Welt-Name (optional)" value={uploadName} onChange={e => setUploadName(e.target.value)} style={{ marginBottom:10 }} />
        <div
          className={`drop-zone${drag ? ' drag' : ''}`}
          onDragOver={e => { e.preventDefault(); setDrag(true); }}
          onDragLeave={() => setDrag(false)}
          onDrop={onDrop}
          onClick={() => fileRef.current?.click()}
        >
          <UploadCloud size={24} style={{ margin:'0 auto 8px', display:'block' }} />
          .mcworld hier ablegen oder klicken
          <input ref={fileRef} type="file" accept=".mcworld,.zip" hidden onChange={e => uploadWorld(e.target.files[0])} />
        </div>
        {uploadResult && (
          <div style={{ marginTop:12, padding:10, background:'var(--bg3)', borderRadius:6, fontSize:12 }}>
            <strong>Upload-Ergebnis:</strong>
            {uploadResult.packs_installed > 0 && <div style={{ color:'var(--accent)' }}>✓ {uploadResult.packs_installed} Pack(s) installiert</div>}
            {uploadResult.packs_missing?.length > 0 && (
              <div style={{ color:'var(--yellow)' }}>
                <AlertTriangle size={11} style={{ verticalAlign:'middle' }} /> Fehlende Packs: {uploadResult.packs_missing.join(', ')}
              </div>
            )}
            {uploadResult.experiments?.length > 0 && <div style={{ color:'var(--text2)' }}>Experimente: {uploadResult.experiments.join(', ')}</div>}
            <button className="btn btn-ghost btn-sm" style={{ marginTop:6 }} onClick={() => setUploadResult(null)}>Schließen</button>
          </div>
        )}
      </div>

      {/* Weltliste */}
      <div className="card">
        <div className="card-title">Installierte Welten</div>
        {worlds.length === 0
          ? <span style={{ color:'var(--text2)' }}>Keine Welten gefunden</span>
          : (
            <table>
              <thead><tr><th>Name</th><th>Status</th><th>Packs</th><th>Größe</th><th></th></tr></thead>
              <tbody>
                {worlds.map(w => (
                  <tr key={w.name}>
                    <td style={{ fontWeight: w.name === active ? 700 : 400 }}>
                      {w.name === active && <CheckCircle size={14} style={{ color:'var(--accent)', marginRight:6, verticalAlign:'middle' }} />}
                      {w.name}
                    </td>
                    <td>
                      {w.name === active
                        ? <span className="badge badge-green">Aktiv</span>
                        : <span className="badge" style={{ background:'var(--bg3)', color:'var(--text2)' }}>Inaktiv</span>}
                    </td>
                    <td style={{ fontSize:12 }}>
                      {w.pack_count > 0
                        ? <span style={{ color: w.missing_packs > 0 ? 'var(--yellow)' : 'var(--text2)' }}>
                            {w.pack_count} Pack{w.pack_count !== 1 ? 's' : ''}
                            {w.missing_packs > 0 && ` ⚠ ${w.missing_packs} fehlt`}
                          </span>
                        : <span style={{ color:'var(--text2)' }}>—</span>}
                    </td>
                    <td style={{ color:'var(--text2)', fontSize:12 }}>{w.size_human || '—'}</td>
                    <td>
                      <div style={{ display:'flex', gap:6, justifyContent:'flex-end' }}>
                        {w.name !== active && (
                          <button className="btn btn-green btn-sm" onClick={() => switchWorld(w.name)}>
                            <Globe size={12} /> Aktivieren
                          </button>
                        )}
                        <button className="btn btn-ghost btn-sm" title="Umbenennen" onClick={() => { setRenameTarget(w.name); setNewName(w.name); }}>
                          <Edit2 size={12} />
                        </button>
                        <button className="btn btn-ghost btn-sm" title="Eigenschaften" onClick={() => openProps(w.name)}>
                          <Settings size={12} />
                        </button>
                        <button className="btn btn-ghost btn-sm" title="Experimente" onClick={() => openExp(w.name)}>
                          <FlaskConical size={12} />
                        </button>
                        <button className="btn btn-red btn-sm" onClick={() => deleteWorld(w.name)}>
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

      {/* Create Modal */}
      {showCreate && (
        <Modal title="Neue Welt erstellen" onClose={() => setShowCreate(false)}>
          <div style={{ display:'flex', flexDirection:'column', gap:10 }}>
            <input placeholder="Welt-Name *" value={create.name} onChange={e => setCreate(c => ({...c, name: e.target.value}))} />
            <select value={create.gamemode} onChange={e => setCreate(c => ({...c, gamemode: e.target.value}))}>
              <option value="survival">Überleben</option>
              <option value="creative">Kreativ</option>
              <option value="adventure">Abenteuer</option>
            </select>
            <select value={create.difficulty} onChange={e => setCreate(c => ({...c, difficulty: e.target.value}))}>
              <option value="peaceful">Friedlich</option>
              <option value="easy">Einfach</option>
              <option value="normal">Normal</option>
              <option value="hard">Schwer</option>
            </select>
            <input placeholder="Seed (optional)" value={create.seed} onChange={e => setCreate(c => ({...c, seed: e.target.value}))} />
            <div style={{ display:'flex', gap:8, justifyContent:'flex-end' }}>
              <button className="btn btn-ghost" onClick={() => setShowCreate(false)}>Abbrechen</button>
              <button className="btn btn-green" onClick={createWorld}>Erstellen</button>
            </div>
          </div>
        </Modal>
      )}

      {/* Rename Modal */}
      {renameTarget && (
        <Modal title={`Welt umbenennen: ${renameTarget}`} onClose={() => setRenameTarget(null)}>
          <input value={newName} onChange={e => setNewName(e.target.value)} onKeyDown={e => e.key==='Enter' && renameWorld()} />
          <div style={{ display:'flex', gap:8, justifyContent:'flex-end', marginTop:12 }}>
            <button className="btn btn-ghost" onClick={() => setRenameTarget(null)}>Abbrechen</button>
            <button className="btn btn-green" onClick={renameWorld}>Umbenennen</button>
          </div>
        </Modal>
      )}

      {/* Experiments Modal */}
      {expTarget && (
        <Modal title={`Experimente: ${expTarget}`} onClose={() => setExpTarget(null)}>
          <div style={{ display:'flex', flexDirection:'column', gap:10 }}>
            {expWarning && (
              <div style={{ background:'var(--yellow-bg,#2a2000)', color:'var(--yellow)', padding:'8px 12px', borderRadius:6, fontSize:12 }}>
                <AlertTriangle size={12} style={{ verticalAlign:'middle', marginRight:6 }} />{expWarning}
              </div>
            )}
            {[
              ['beta_apis',                   'Beta APIs (Scripting)'],
              ['caves_and_cliffs',            'Caves & Cliffs'],
              ['upcoming_creator_features',   'Upcoming Creator Features'],
              ['holiday_creator_features',    'Holiday Creator Features'],
              ['vanilla_experiments',         'Vanilla Experiments'],
              ['experimental_molang_features','Experimental Molang'],
              ['gametest',                    'GameTest Framework'],
              ['data_driven_biomes',          'Data Driven Biomes'],
            ].map(([key, label]) => (
              <label key={key} style={{ display:'flex', alignItems:'center', justifyContent:'space-between', cursor:'pointer' }}>
                <span style={{ fontSize:13 }}>{label}</span>
                <select value={experiments[key] ? '1' : '0'}
                  onChange={e => setExperiments(ex => ({ ...ex, [key]: e.target.value === '1' }))}
                  style={{ width:90 }}>
                  <option value="0">Aus</option>
                  <option value="1">An</option>
                </select>
              </label>
            ))}
          </div>
          <div style={{ display:'flex', gap:8, justifyContent:'flex-end', marginTop:16 }}>
            <button className="btn btn-ghost" onClick={() => setExpTarget(null)}>Abbrechen</button>
            <button className="btn btn-green" onClick={saveExp}>Speichern</button>
          </div>
        </Modal>
      )}

      {/* Properties Modal */}
      {propsTarget && (
        <Modal title={`Eigenschaften: ${propsTarget}`} onClose={() => setPropsTarget(null)}>
          <div style={{ maxHeight:400, overflowY:'auto', display:'flex', flexDirection:'column', gap:8 }}>
            {Object.entries(worldProps).map(([k, v]) => (
              <div key={k} style={{ display:'flex', gap:8, alignItems:'center' }}>
                <span style={{ fontSize:11, color:'var(--text2)', width:180, flexShrink:0 }}>{k}</span>
                <input value={v} onChange={e => setWorldProps(p => ({...p, [k]: e.target.value}))} style={{ flex:1 }} />
              </div>
            ))}
          </div>
          <div style={{ display:'flex', gap:8, justifyContent:'flex-end', marginTop:12 }}>
            <button className="btn btn-ghost" onClick={() => setPropsTarget(null)}>Abbrechen</button>
            <button className="btn btn-green" onClick={saveProps}>Speichern</button>
          </div>
        </Modal>
      )}
    </div>
  );
}
