import { useState, useEffect, useRef } from 'react';
import { HardDrive, Plus, Trash2, Download, RefreshCw, UploadCloud, RotateCcw } from 'lucide-react';
import { get, post, del, upload, formatBytes, formatDate } from '../utils.js';
import { useToast } from '../useToast.jsx';

export default function Backups() {
  const [backups,   setBackups]   = useState([]);
  const [label,     setLabel]     = useState('');
  const [creating,  setCreating]  = useState(false);
  const [restoring, setRestoring] = useState(null);
  const [drag,      setDrag]      = useState(false);
  const fileRef = useRef();
  const { toast, ToastContainer } = useToast();

  const [maxCount, setMaxCount] = useState(20);

  useEffect(() => { load(); }, []);

  async function load() {
    const [b, s] = await Promise.all([get('/api/backups'), get('/api/settings')]);
    if (b) setBackups(b);
    if (s?.backup_max_count) setMaxCount(s.backup_max_count);
  }

  async function create() {
    setCreating(true);
    toast('Backup wird erstellt…', 'info');
    const r = await post('/api/backups/create', { label });
    toast(r?.success ? `Backup erstellt: ${r.name}` : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    setLabel(''); setCreating(false); load();
  }

  async function remove(name) {
    if (!confirm(`Backup "${name}" wirklich löschen?`)) return;
    const r = await del(`/api/backups/${encodeURIComponent(name)}`);
    toast(r?.success ? 'Backup gelöscht' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    load();
  }

  async function restore(name) {
    if (!confirm(`Backup "${name}" wirklich wiederherstellen?\n\nDer Server wird gestoppt und alle aktuellen Welten überschrieben!`)) return;
    setRestoring(name);
    toast('Backup wird wiederhergestellt…', 'info');
    const r = await post('/api/backups/restore', { filename: name });
    toast(r?.success ? 'Backup wiederhergestellt. Server gestartet.' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    setRestoring(null); load();
  }

  async function importBackup(file) {
    if (!file) return;
    if (!file.name.endsWith('.tar.gz')) return toast('Nur .tar.gz Dateien', 'error');
    const fd = new FormData();
    fd.append('file', file);
    toast('Backup wird importiert…', 'info');
    const r = await upload('/api/backups/import', fd);
    toast(r?.success ? `Backup importiert: ${r.name}` : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    load();
  }

  function onDrop(e) {
    e.preventDefault(); setDrag(false);
    importBackup(e.dataTransfer.files[0]);
  }

  return (
    <div>
      <ToastContainer />
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:24 }}>
        <h1 style={{ fontSize:22, fontWeight:700 }}>Backups</h1>
        <button className="btn btn-ghost btn-sm" onClick={load}><RefreshCw size={13} /></button>
      </div>

      <div className="grid-2" style={{ marginBottom:18 }}>
        <div className="card">
          <div className="card-title">Backup erstellen</div>
          <div style={{ display:'flex', gap:8 }}>
            <input placeholder="Bezeichnung (optional)" value={label} onChange={e => setLabel(e.target.value)}
              onKeyDown={e => e.key==='Enter' && create()} />
            <button className="btn btn-green" onClick={create} disabled={creating}>
              <Plus size={14} /> {creating ? 'Erstelle…' : 'Erstellen'}
            </button>
          </div>
        </div>

        <div className="card">
          <div className="card-title">Backup importieren (.tar.gz)</div>
          <div
            className={`drop-zone${drag?' drag':''}`}
            style={{ padding:'12px 8px' }}
            onDragOver={e => { e.preventDefault(); setDrag(true); }}
            onDragLeave={() => setDrag(false)}
            onDrop={onDrop}
            onClick={() => fileRef.current?.click()}
          >
            <UploadCloud size={20} style={{ margin:'0 auto 6px', display:'block' }} />
            .tar.gz hier ablegen oder klicken
            <input ref={fileRef} type="file" accept=".gz,.tar.gz" hidden onChange={e => importBackup(e.target.files[0])} />
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-title">Vorhandene Backups ({backups.length} / {maxCount})</div>
        {backups.length === 0
          ? <span style={{ color:'var(--text2)' }}>Keine Backups vorhanden</span>
          : (
            <table>
              <thead><tr><th>Name</th><th>Größe</th><th>Datum</th><th></th></tr></thead>
              <tbody>
                {backups.map(b => (
                  <tr key={b.name}>
                    <td style={{ fontSize:12, fontFamily:'monospace' }}>{b.name}</td>
                    <td>{formatBytes(b.size)}</td>
                    <td style={{ color:'var(--text2)' }}>{formatDate(b.date)}</td>
                    <td>
                      <div style={{ display:'flex', gap:6, justifyContent:'flex-end' }}>
                        <a href={`/api/backups/download/${encodeURIComponent(b.name)}`}
                          className="btn btn-blue btn-sm" download>
                          <Download size={12} />
                        </a>
                        <button className="btn btn-yellow btn-sm" title="Wiederherstellen"
                          disabled={restoring === b.name}
                          onClick={() => restore(b.name)}>
                          <RotateCcw size={12} />
                        </button>
                        <button className="btn btn-red btn-sm" onClick={() => remove(b.name)}>
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
    </div>
  );
}
