import { useState, useEffect } from 'react';
import { Download, RefreshCw, CheckCircle, AlertTriangle } from 'lucide-react';
import { get, post } from '../utils.js';
import { useToast } from '../useToast.jsx';

function UpdateCard({ title, installed, available, updating, onCheck, onUpdate, log }) {
  const hasUpdate = available && available !== installed && available !== 'unbekannt';
  return (
    <div className="card">
      <div className="card-title">{title}</div>
      <div style={{ display:'flex', flexDirection:'column', gap:10 }}>
        <div style={{ display:'flex', gap:16 }}>
          <div>
            <div style={{ fontSize:11, color:'var(--text2)' }}>Installiert</div>
            <div style={{ fontWeight:600 }}>{installed || '—'}</div>
          </div>
          <div>
            <div style={{ fontSize:11, color:'var(--text2)' }}>Verfügbar</div>
            <div style={{ fontWeight:600, color: hasUpdate ? 'var(--yellow)' : 'var(--accent)' }}>
              {available || '—'}
              {hasUpdate && <AlertTriangle size={13} style={{ verticalAlign:'middle', marginLeft:4 }} />}
              {available && !hasUpdate && available !== '—' && <CheckCircle size={13} style={{ verticalAlign:'middle', marginLeft:4, color:'var(--accent)' }} />}
            </div>
          </div>
        </div>
        <div style={{ display:'flex', gap:8 }}>
          <button className="btn btn-ghost btn-sm" onClick={onCheck}>
            <RefreshCw size={12} /> Prüfen
          </button>
          {hasUpdate && (
            <button className="btn btn-green btn-sm" onClick={onUpdate} disabled={updating}>
              <Download size={12} /> {updating ? 'Aktualisierung läuft…' : 'Aktualisieren'}
            </button>
          )}
        </div>
        {log && (
          <pre style={{ background:'var(--bg3)', borderRadius:6, padding:10, fontSize:10, maxHeight:200, overflowY:'auto', margin:0, color:'var(--text2)', whiteSpace:'pre-wrap', wordBreak:'break-all' }}>
            {log}
          </pre>
        )}
      </div>
    </div>
  );
}

export default function Updates() {
  const [mc,    setMc]    = useState({});
  const [panel, setPanel] = useState({});
  const [mcUpdating,    setMcUpdating]    = useState(false);
  const [panelUpdating, setPanelUpdating] = useState(false);
  const [mcLog,    setMcLog]    = useState('');
  const [panelLog, setPanelLog] = useState('');
  const [mcPollTimer,    setMcPollTimer]    = useState(null);
  const [panelPollTimer, setPanelPollTimer] = useState(null);
  const { toast, ToastContainer } = useToast();

  useEffect(() => {
    loadMc(); loadPanel();
    return () => { clearInterval(mcPollTimer); clearInterval(panelPollTimer); };
  }, []);

  async function loadMc(force = false) {
    const r = await get('/api/updates/mc' + (force ? '?force=1' : ''));
    if (r) setMc(r);
  }

  async function loadPanel() {
    const r = await get('/api/updates/panel');
    if (r) setPanel(r);
  }

  async function startMcUpdate() {
    setMcUpdating(true); setMcLog('Starte Update…\n');
    const r = await post('/api/updates/mc/start');
    if (!r?.success) { toast(r?.error || 'Fehler', 'error'); setMcUpdating(false); return; }
    toast('MC-Update gestartet', 'success');
    const iv = setInterval(async () => {
      const s = await get('/api/updates/mc/status');
      if (s?.log) setMcLog(s.log);
      if (!s?.running) { clearInterval(iv); setMcUpdating(false); loadMc(true); }
    }, 2000);
    setMcPollTimer(iv);
  }

  async function startPanelUpdate() {
    setPanelUpdating(true); setPanelLog('Starte Panel-Update…\n');
    const r = await post('/api/updates/panel/start');
    if (!r?.success) { toast(r?.error || 'Fehler', 'error'); setPanelUpdating(false); return; }
    toast('Panel-Update gestartet', 'success');
    const iv = setInterval(async () => {
      const s = await get('/api/updates/panel/status');
      if (s?.log) setPanelLog(s.log);
      if (!s?.running) { clearInterval(iv); setPanelUpdating(false); loadPanel(); }
    }, 2000);
    setPanelPollTimer(iv);
  }

  return (
    <div>
      <ToastContainer />
      <h1 style={{ fontSize:22, fontWeight:700, marginBottom:24 }}>Updates</h1>
      <div className="grid-2">
        <UpdateCard
          title="Minecraft Bedrock Server"
          installed={mc.installed}
          available={mc.available}
          updating={mcUpdating}
          onCheck={() => loadMc(true)}
          onUpdate={startMcUpdate}
          log={mcLog}
        />
        <UpdateCard
          title="mcadmin Panel"
          installed={panel.installed}
          available={panel.available}
          updating={panelUpdating}
          onCheck={loadPanel}
          onUpdate={startPanelUpdate}
          log={panelLog}
        />
      </div>
    </div>
  );
}
