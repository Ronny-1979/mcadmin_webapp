import { useState, useEffect, useRef } from 'react';
import { CheckCircle, Edit2, FlaskConical, Globe, MessageSquare, PackagePlus, Plus, Save, Settings, Trash2, UploadCloud } from 'lucide-react';
import { get, post, del, upload } from '../utils.js';
import { useToast } from '../useToast.jsx';

function Modal({ title, children, onClose }) {
  return (
    <div style={{ position:'fixed', inset:0, background:'rgba(0,0,0,.55)', display:'flex', alignItems:'center', justifyContent:'center', zIndex:1000 }} onClick={onClose}>
      <div className="card" style={{ width:'min(760px, calc(100vw - 32px))', maxHeight:'90vh', overflowY:'auto', margin:0 }} onClick={e => e.stopPropagation()}>
        <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:14 }}>
          <div className="card-title" style={{ margin:0 }}>{title}</div>
          <button className="btn btn-ghost btn-sm" onClick={onClose}>✕</button>
        </div>
        {children}
      </div>
    </div>
  );
}

const PLACEHOLDERS = ['{player}', '{world}', '{server}'];

export default function Worlds() {
  const [worlds, setWorlds] = useState([]);
  const [active, setActive] = useState('');
  const [selected, setSelected] = useState('');
  const [props, setProps] = useState({});
  const [uploadResult, setUploadResult] = useState(null);
  const [drag, setDrag] = useState(false);
  const [showCreate, setShowCreate] = useState(false);
  const [create, setCreate] = useState({ name:'', gamemode:'survival', difficulty:'normal', seed:'' });
  const [renameTarget, setRenameTarget] = useState(null);
  const [newName, setNewName] = useState('');
  const [packTarget, setPackTarget] = useState(null);
  const [welcomeTarget, setWelcomeTarget] = useState(null);
  const [welcomeMessage, setWelcomeMessage] = useState('');
  const [welcomeLoading, setWelcomeLoading] = useState(false);
  const [expTarget, setExpTarget]         = useState(null);
  const [experiments, setExperiments]     = useState({});
  const [expWarning, setExpWarning]       = useState('');

  const worldFileRef = useRef(null);
  const packFileRef = useRef(null);
  const { toast, ToastContainer } = useToast();

  useEffect(() => { load(); }, []);
  useEffect(() => { if (selected) openProps(selected); }, [selected]);

  async function load() {
    const [w, a] = await Promise.all([get('/api/worlds'), get('/api/worlds/active')]);
    if (w) {
      setWorlds(w);
      if (!selected && w[0]?.name) setSelected(w[0].name);
    }
    if (a?.active) setActive(a.active);
  }

  async function switchWorld(name) {
    if (!confirm(`Welt "${name}" aktivieren? Der Server wird ggf. neugestartet.`)) return;
    const r = await post('/api/worlds/switch', { name });
    toast(r?.success ? 'Welt aktiviert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    load();
  }

  async function deleteWorld(name) {
    if (!confirm(`Welt "${name}" wirklich löschen?`)) return;
    const r = await del(`/api/worlds/${encodeURIComponent(name)}`);
    toast(r?.success ? 'Welt gelöscht' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    if (selected === name) { setSelected(''); setProps({}); }
    load();
  }

  async function uploadWorld(file) {
    if (!file) return;
    const fd = new FormData();
    fd.append('file', file);
    fd.append('name', file.name.replace(/\.[^.]+$/, ''));
    toast('Welt wird hochgeladen…', 'info');
    const r = await upload('/api/worlds/upload', fd);
    if (r?.success) {
      setUploadResult(r);
      toast('Welt hochgeladen', 'success');
    } else {
      toast(r?.error || 'Fehler beim Hochladen', 'error');
    }
    load();
  }

  function onDrop(e) {
    e.preventDefault();
    setDrag(false);
    uploadWorld(e.dataTransfer.files[0]);
  }

  async function createWorld() {
    if (!create.name.trim()) return toast('Name erforderlich', 'error');
    const r = await post('/api/worlds/create', create);
    toast(r?.success ? `Welt "${create.name}" erstellt` : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    if (r?.success) {
      setShowCreate(false);
      setCreate({ name:'', gamemode:'survival', difficulty:'normal', seed:'' });
      load();
    }
  }

  async function renameWorld() {
    if (!newName.trim()) return toast('Neuer Name erforderlich', 'error');
    const r = await post(`/api/worlds/${encodeURIComponent(renameTarget)}/rename`, { newName });
    toast(r?.success ? 'Welt umbenannt' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    if (r?.success) { setRenameTarget(null); setNewName(''); load(); }
  }

  async function openProps(worldName) {
    setSelected(worldName);
    const r = await get(`/api/worlds/${encodeURIComponent(worldName)}/properties`);
    setProps(r || {});
  }

  async function saveProps() {
    if (!selected) return;
    const r = await post(`/api/worlds/${encodeURIComponent(selected)}/properties`, props);
    toast(r?.success ? 'Server-Einstellungen gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
  }

  async function uploadPackForWorld(file) {
    if (!file || !packTarget) return;
    const fd = new FormData();
    fd.append('pack', file);
    toast('Pack wird hochgeladen…', 'info');
    const r = await upload(`/api/worlds/${encodeURIComponent(packTarget)}/packs/upload`, fd);
    toast(r?.success ? 'Pack installiert und dieser Welt zugeordnet' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    setPackTarget(null);
    load();
  }

  async function openWelcome(worldName) {
    setWelcomeTarget(worldName);
    setWelcomeLoading(true);
    const r = await get(`/api/worlds/${encodeURIComponent(worldName)}/welcome`);
    setWelcomeMessage(r?.message || '');
    setWelcomeLoading(false);
  }

  async function saveWelcome() {
    if (!welcomeTarget) return;
    const r = await post(`/api/worlds/${encodeURIComponent(welcomeTarget)}/welcome`, { message: welcomeMessage });
    toast(r?.success ? 'Willkommensnachricht gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
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
      if (r.warning) setExpWarning(r.warning); else setExpTarget(null);
    } else {
      toast(r?.error || 'Fehler', 'error');
    }
  }

  function previewWelcome() {
    return welcomeMessage
      .replace(/\{player\}/g, 'Steve')
      .replace(/\{world\}/g, welcomeTarget || 'MeineWelt')
      .replace(/\{server\}/g, 'Mein Server');
  }

  return (
    <div>
      <ToastContainer />
      <h1 style={{ fontSize:22, fontWeight:700, marginBottom:20 }}>Welten</h1>

      <div className="panel-grid-2">
        <div>
          <div className="card">
            <div className="card-title">📥 Welt importieren (.mcworld)</div>
            <div
              className={`drop-zone${drag ? ' drag' : ''}`}
              onDragOver={e => { e.preventDefault(); setDrag(true); }}
              onDragLeave={() => setDrag(false)}
              onDrop={onDrop}
              onClick={() => worldFileRef.current?.click()}
            >
              <UploadCloud size={24} style={{ margin:'0 auto 8px', display:'block' }} />
              Welt-Datei (.mcworld) hierher ziehen oder klicken
              <input ref={worldFileRef} type="file" accept=".mcworld" hidden onChange={e => uploadWorld(e.target.files[0])} />
            </div>
            {uploadResult && (
              <div style={{ marginTop:12, padding:10, background:'var(--bg3)', borderRadius:6, fontSize:12 }}>
                <strong>Upload-Ergebnis:</strong>
                {uploadResult.packs_installed > 0 && <div style={{ color:'var(--accent)' }}>✓ {uploadResult.packs_installed} Pack(s) installiert</div>}
                {uploadResult.packs_missing?.length > 0 && <div style={{ color:'var(--yellow)' }}>⚠ Fehlende Packs: {uploadResult.packs_missing.join(', ')}</div>}
                {uploadResult.experiments?.length > 0 && <div style={{ color:'var(--text2)' }}>Experimente: {uploadResult.experiments.join(', ')}</div>}
                <button className="btn btn-ghost btn-sm" style={{ marginTop:6 }} onClick={() => setUploadResult(null)}>Schließen</button>
              </div>
            )}
          </div>

          <div className="card">
            <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', gap:8, marginBottom:14 }}>
              <div className="card-title" style={{ margin:0 }}>🌍 Welten</div>
              <button className="btn btn-green btn-sm" onClick={() => setShowCreate(true)}><Plus size={12} /> Neue Welt</button>
            </div>

            {worlds.length === 0 ? <span style={{ color:'var(--text2)' }}>Keine Welten gefunden</span> : (
              <table>
                <thead><tr><th>Name</th><th>Status</th><th>Packs</th><th>Aktion</th></tr></thead>
                <tbody>
                  {worlds.map(w => {
                    const isActive = w.name === active;
                    return (
                      <tr key={w.name}>
                        <td style={{ fontWeight:isActive ? 700 : 400, cursor:'pointer' }} onClick={() => openProps(w.name)}>
                          {isActive && <CheckCircle size={14} style={{ color:'var(--accent)', marginRight:6, verticalAlign:'middle' }} />}
                          {w.name}
                        </td>
                        <td>{isActive ? <span className="badge badge-green">Aktiv</span> : <span className="badge">Inaktiv</span>}</td>
                        <td style={{ fontSize:12 }}>
                          {w.pack_count > 0 ? <span style={{ color:w.missing_packs > 0 ? 'var(--yellow)' : 'var(--text2)' }}>{w.pack_count} Pack(s){w.missing_packs > 0 && ` ⚠ ${w.missing_packs} fehlt`}</span> : <span style={{ color:'var(--text2)' }}>—</span>}
                        </td>
                        <td>
                          <div className="action-row">
                            {!isActive && <button className="btn btn-green btn-sm" onClick={() => switchWorld(w.name)}><Globe size={12} /> Aktivieren</button>}
                            {!isActive && <button className="btn btn-ghost btn-sm" title="Pack hochladen" onClick={() => { setPackTarget(w.name); setTimeout(() => packFileRef.current?.click(), 50); }}><PackagePlus size={12} /> Pack</button>}
                            <button className="btn btn-ghost btn-sm" title="Willkommen" onClick={() => openWelcome(w.name)}><MessageSquare size={12} /> Willkommen</button>
                            <button className="btn btn-ghost btn-sm" title="Experimente" onClick={() => openExp(w.name)}><FlaskConical size={12} /></button>
                            <button className="btn btn-ghost btn-sm" title="Eigenschaften" onClick={() => openProps(w.name)}><Settings size={12} /></button>
                            <button className="btn btn-ghost btn-sm" title="Umbenennen" onClick={() => { setRenameTarget(w.name); setNewName(w.name); }}><Edit2 size={12} /></button>
                            <button className="btn btn-red btn-sm" title="Löschen" onClick={() => deleteWorld(w.name)}><Trash2 size={12} /></button>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            )}
          </div>
        </div>

        <div>
          <div className="card">
            <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', gap:8, marginBottom:14 }}>
              <div className="card-title" style={{ margin:0 }}>⚙️ Server-Einstellungen</div>
              <div style={{ display:'flex', alignItems:'center', gap:8 }}>
                <span className="badge">{selected || 'Keine Welt'}</span>
                <button className="btn btn-green btn-sm" onClick={saveProps} disabled={!selected}><Save size={12} /> Speichern</button>
              </div>
            </div>
            {!selected ? (
              <div style={{ color:'var(--text2)', textAlign:'center', padding:28 }}>Wähle eine Welt aus.</div>
            ) : (
              <div style={{ maxHeight:'calc(100vh - 190px)', overflowY:'auto', display:'flex', flexDirection:'column', gap:8 }}>
                {Object.entries(props).map(([k,v]) => (
                  <div key={k} style={{ display:'grid', gridTemplateColumns:'minmax(120px, 200px) 1fr', gap:8, alignItems:'center' }}>
                    <label style={{ fontSize:11, color:'var(--text2)', overflow:'hidden', textOverflow:'ellipsis' }}>{k}</label>
                    <input value={v ?? ''} onChange={e => setProps(p => ({ ...p, [k]: e.target.value }))} />
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      <input ref={packFileRef} type="file" accept=".mcpack,.mcaddon,.zip" hidden onChange={e => uploadPackForWorld(e.target.files[0])} />

      {showCreate && (
        <Modal title="Neue Welt erstellen" onClose={() => setShowCreate(false)}>
          <div style={{ display:'flex', flexDirection:'column', gap:10 }}>
            <input placeholder="Welt-Name *" value={create.name} onChange={e => setCreate(c => ({...c, name:e.target.value}))} />
            <select value={create.gamemode} onChange={e => setCreate(c => ({...c, gamemode:e.target.value}))}>
              <option value="survival">Überleben</option><option value="creative">Kreativ</option><option value="adventure">Abenteuer</option>
            </select>
            <select value={create.difficulty} onChange={e => setCreate(c => ({...c, difficulty:e.target.value}))}>
              <option value="peaceful">Friedlich</option><option value="easy">Einfach</option><option value="normal">Normal</option><option value="hard">Schwer</option>
            </select>
            <input placeholder="Seed (optional)" value={create.seed} onChange={e => setCreate(c => ({...c, seed:e.target.value}))} />
            <div style={{ display:'flex', gap:8, justifyContent:'flex-end' }}>
              <button className="btn btn-ghost" onClick={() => setShowCreate(false)}>Abbrechen</button>
              <button className="btn btn-green" onClick={createWorld}>Erstellen</button>
            </div>
          </div>
        </Modal>
      )}

      {renameTarget && (
        <Modal title={`Welt umbenennen: ${renameTarget}`} onClose={() => setRenameTarget(null)}>
          <input value={newName} onChange={e => setNewName(e.target.value)} onKeyDown={e => e.key === 'Enter' && renameWorld()} />
          <div style={{ display:'flex', gap:8, justifyContent:'flex-end', marginTop:12 }}>
            <button className="btn btn-ghost" onClick={() => setRenameTarget(null)}>Abbrechen</button>
            <button className="btn btn-green" onClick={renameWorld}>Umbenennen</button>
          </div>
        </Modal>
      )}

      {expTarget && (
        <Modal title={`Experimente: ${expTarget}`} onClose={() => setExpTarget(null)}>
          <div style={{ display:'flex', flexDirection:'column', gap:10 }}>
            {expWarning && (
              <div style={{ background:'rgba(255,200,0,.12)', color:'var(--yellow,#f0b429)', padding:'8px 12px', borderRadius:6, fontSize:12 }}>
                ⚠ {expWarning}
              </div>
            )}
            {[
              ['beta_apis',                    'Beta APIs (Scripting)'],
              ['caves_and_cliffs',             'Caves & Cliffs'],
              ['upcoming_creator_features',    'Upcoming Creator Features'],
              ['holiday_creator_features',     'Holiday Creator Features'],
              ['vanilla_experiments',          'Vanilla Experiments'],
              ['experimental_molang_features', 'Experimental Molang'],
              ['gametest',                     'GameTest Framework'],
              ['data_driven_biomes',           'Data Driven Biomes'],
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
            <button className="btn btn-green" onClick={saveExp}><Save size={14} /> Speichern</button>
          </div>
        </Modal>
      )}

      {welcomeTarget && (
        <Modal title={`Willkommensnachricht: ${welcomeTarget}`} onClose={() => setWelcomeTarget(null)}>
          {welcomeLoading ? <div style={{ color:'var(--text2)' }}>Lade…</div> : (
            <>
              <textarea rows={7} value={welcomeMessage} onChange={e => setWelcomeMessage(e.target.value)} maxLength={2000}
                placeholder={`Willkommen auf dem Server, {player}!\nAktive Welt: {world}`} style={{ resize:'vertical' }} />
              <div style={{ fontSize:11, color:'var(--text2)', marginTop:6, display:'flex', justifyContent:'space-between', gap:8, flexWrap:'wrap' }}>
                <span>Platzhalter: {PLACEHOLDERS.map(p => <code key={p} style={{ marginLeft:6, background:'var(--bg3)', padding:'1px 4px', borderRadius:3 }}>{p}</code>)}</span>
                <span>{welcomeMessage.length} / 2000</span>
              </div>
              {welcomeMessage && <pre style={{ background:'var(--bg3)', borderRadius:6, padding:12, fontSize:13, margin:'12px 0 0', whiteSpace:'pre-wrap', color:'var(--accent)' }}>{previewWelcome()}</pre>}
              <div style={{ display:'flex', gap:8, justifyContent:'flex-end', marginTop:12 }}>
                <button className="btn btn-ghost" onClick={() => setWelcomeTarget(null)}>Abbrechen</button>
                <button className="btn btn-green" onClick={saveWelcome}><Save size={14} /> Speichern</button>
              </div>
            </>
          )}
        </Modal>
      )}
    </div>
  );
}
