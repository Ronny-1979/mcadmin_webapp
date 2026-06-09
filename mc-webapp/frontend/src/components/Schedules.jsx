import { useState, useEffect } from 'react';
import { Clock, Save } from 'lucide-react';
import { get, post } from '../utils.js';
import { useToast } from '../useToast.jsx';

const DEFAULT = {
  backup_schedule_enabled: false,
  backup_schedule_time: '03:00',
  restart_schedule_enabled: false,
  restart_schedule_time: '04:00',
  update_check_enabled: false,
  update_check_time: '05:00',
  update_auto_install: false,
  panel_update_auto_install: false,
};

function Toggle({ value, onChange, label, description }) {
  return (
    <div style={{ display:'flex', alignItems:'center', gap:12, padding:'10px 0', borderBottom:'1px solid var(--border)' }}>
      <div style={{ flex:1 }}>
        <div style={{ fontWeight:600, fontSize:14 }}>{label}</div>
        {description && <div style={{ fontSize:12, color:'var(--text2)', marginTop:2 }}>{description}</div>}
      </div>
      <button
        className={`btn btn-sm ${value ? 'btn-green' : 'btn-ghost'}`}
        style={{ minWidth:60 }}
        onClick={() => onChange(!value)}
      >
        {value ? 'AN' : 'AUS'}
      </button>
    </div>
  );
}

export default function Schedules() {
  const [data,    setData]    = useState(DEFAULT);
  const [loading, setLoading] = useState(true);
  const { toast, ToastContainer } = useToast();

  useEffect(() => { load(); }, []);

  async function load() {
    setLoading(true);
    const r = await get('/api/schedules');
    if (r) setData({ ...DEFAULT, ...r });
    setLoading(false);
  }

  async function save() {
    const r = await post('/api/schedules', data);
    toast(r?.success ? 'Zeitpläne gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
  }

  function set(key, val) { setData(d => ({ ...d, [key]: val })); }

  if (loading) return <div style={{ color:'var(--text2)' }}>Lade…</div>;

  return (
    <div>
      <ToastContainer />
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:24 }}>
        <h1 style={{ fontSize:22, fontWeight:700 }}>Zeitpläne</h1>
        <button className="btn btn-green" onClick={save}><Save size={14} /> Speichern</button>
      </div>

      <div style={{ display:'flex', flexDirection:'column', gap:16 }}>
        {/* Auto-Backup */}
        <div className="card">
          <div className="card-title"><Clock size={14} style={{ verticalAlign:'middle', marginRight:6 }} />Automatische Backups</div>
          <Toggle
            label="Auto-Backup aktiviert"
            description="Erstellt täglich zur eingestellten Zeit ein Backup"
            value={data.backup_schedule_enabled}
            onChange={v => set('backup_schedule_enabled', v)}
          />
          {data.backup_schedule_enabled && (
            <div style={{ paddingTop:12, display:'flex', alignItems:'center', gap:10 }}>
              <label style={{ fontSize:13, color:'var(--text2)' }}>Uhrzeit:</label>
              <input type="time" value={data.backup_schedule_time}
                onChange={e => set('backup_schedule_time', e.target.value)}
                style={{ width:120 }} />
            </div>
          )}
        </div>

        {/* Auto-Restart */}
        <div className="card">
          <div className="card-title"><Clock size={14} style={{ verticalAlign:'middle', marginRight:6 }} />Automatischer Neustart</div>
          <Toggle
            label="Auto-Restart aktiviert"
            description="Startet den Server täglich zur eingestellten Zeit neu"
            value={data.restart_schedule_enabled}
            onChange={v => set('restart_schedule_enabled', v)}
          />
          {data.restart_schedule_enabled && (
            <div style={{ paddingTop:12, display:'flex', alignItems:'center', gap:10 }}>
              <label style={{ fontSize:13, color:'var(--text2)' }}>Uhrzeit:</label>
              <input type="time" value={data.restart_schedule_time}
                onChange={e => set('restart_schedule_time', e.target.value)}
                style={{ width:120 }} />
            </div>
          )}
        </div>

        {/* Update-Check */}
        <div className="card">
          <div className="card-title"><Clock size={14} style={{ verticalAlign:'middle', marginRight:6 }} />Automatische Update-Prüfung</div>
          <Toggle
            label="Update-Check aktiviert"
            description="Prüft täglich zur eingestellten Zeit auf neue Versionen"
            value={data.update_check_enabled}
            onChange={v => set('update_check_enabled', v)}
          />
          {data.update_check_enabled && (
            <div style={{ paddingTop:12, display:'flex', flexDirection:'column', gap:10 }}>
              <div style={{ display:'flex', alignItems:'center', gap:10 }}>
                <label style={{ fontSize:13, color:'var(--text2)' }}>Uhrzeit:</label>
                <input type="time" value={data.update_check_time}
                  onChange={e => set('update_check_time', e.target.value)}
                  style={{ width:120 }} />
              </div>
              <Toggle
                label="MC-Update automatisch installieren"
                description="Installiert neue Minecraft-Versionen ohne Nachfrage"
                value={data.update_auto_install}
                onChange={v => set('update_auto_install', v)}
              />
              <Toggle
                label="Panel-Update automatisch installieren"
                description="Aktualisiert das mcadmin Panel automatisch"
                value={data.panel_update_auto_install}
                onChange={v => set('panel_update_auto_install', v)}
              />
            </div>
          )}
        </div>
      </div>

      <div className="card" style={{ marginTop: 16 }}>
        <div className="card-title">Manuelle Aktionen</div>
        <button className="btn btn-ghost" onClick={async () => {
          const r = await post('/api/updates/check-now');
          toast(r?.success ? (r.message || 'Update-Check abgeschlossen') : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
        }}>
          <Clock size={14} /> Jetzt Update-Check ausführen
        </button>
        <div style={{ marginTop: 12, fontSize: 12, color: 'var(--text2)' }}>
          Hinweis: Zeitpläne werden vom PHP-Cron-Job (cron.php) ausgeführt. Dieser muss als Systemcron eingerichtet sein.
        </div>
      </div>
    </div>
  );
}
