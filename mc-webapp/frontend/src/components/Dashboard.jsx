import { useState, useEffect } from 'react';
import { Play, Square, RotateCcw, Users, Clock, Box, ShieldCheck, ShieldOff, UserX, UserPlus } from 'lucide-react';
import { get, post, formatUptime } from '../utils.js';
import { useToast } from '../useToast.jsx';

export default function Dashboard() {
  const [status, setStatus] = useState(null);
  const [uptimeTick, setUptimeTick] = useState(0);
  const { toast, ToastContainer } = useToast();

  useEffect(() => {
    load();
    const iv = setInterval(load, 10000);
    return () => clearInterval(iv);
  }, []);

  useEffect(() => {
    const iv = setInterval(() => setUptimeTick(t => t + 1), 1000);
    return () => clearInterval(iv);
  }, []);

  async function load() {
    const r = await get('/api/status');
    if (r) setStatus(r);
  }

  async function action(endpoint, label) {
    const r = await post(endpoint);
    toast(r?.success !== false ? `${label} erfolgreich` : (r?.error || 'Fehler'), r?.success !== false ? 'success' : 'error');
    setTimeout(load, 2000);
  }

  async function playerAction(endpoint, body, label) {
    const r = await post(endpoint, body);
    toast(r?.success ? label : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
    setTimeout(load, 1500);
  }

  if (!status) return <div style={{ color: 'var(--text2)' }}>Lade...</div>;

  const players = status.onlinePlayers || [];

  return (
    <div>
      <ToastContainer />
      <h1 style={{ fontSize: 22, fontWeight: 700, marginBottom: 24 }}>Dashboard</h1>

      <div className="grid-4" style={{ marginBottom: 20 }}>
        <div className="stat-card">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
            {status.running ? <span className="dot dot-green" /> : <span className="dot dot-red" />}
            <span style={{ fontSize: 12, color: 'var(--text2)' }}>Status</span>
          </div>
          <div className="stat-value" style={{ color: status.running ? 'var(--accent)' : 'var(--red)', fontSize: 18 }}>
            {status.running ? 'Online' : 'Offline'}
          </div>
        </div>

        <div className="stat-card">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
            <Clock size={14} color="var(--text2)" />
            <span style={{ fontSize: 12, color: 'var(--text2)' }}>Uptime</span>
          </div>
          <div className="stat-value" style={{ fontSize: 16 }}>
            {status.running ? formatUptime(status.uptime) : '—'}
          </div>
        </div>

        <div className="stat-card">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
            <Users size={14} color="var(--text2)" />
            <span style={{ fontSize: 12, color: 'var(--text2)' }}>Spieler online</span>
          </div>
          <div className="stat-value">{players.length}</div>
        </div>

        <div className="stat-card">
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 8 }}>
            <Box size={14} color="var(--text2)" />
            <span style={{ fontSize: 12, color: 'var(--text2)' }}>Version</span>
          </div>
          <div className="stat-value" style={{ fontSize: 14 }}>{status.version || '—'}</div>
        </div>
      </div>

      <div className="card">
        <div className="card-title">Server-Steuerung</div>
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
          <button className="btn btn-green" onClick={() => action('/api/server/start', 'Start')} disabled={status.running}>
            <Play size={14} /> Starten
          </button>
          <button className="btn btn-red" onClick={() => action('/api/server/stop', 'Stop')} disabled={!status.running}>
            <Square size={14} /> Stoppen
          </button>
          <button className="btn btn-yellow" onClick={() => action('/api/server/restart', 'Neustart')} disabled={!status.running}>
            <RotateCcw size={14} /> Neustart
          </button>
        </div>
      </div>

      <div className="grid-2">
        <div className="card">
          <div className="card-title">Aktive Welt</div>
          <div style={{ fontSize: 16, fontWeight: 600 }}>
            {status.activeWorld || <span style={{ color: 'var(--text2)' }}>Keine Welt aktiv</span>}
          </div>
        </div>

        <div className="card">
          <div className="card-title">Online-Spieler</div>
          {players.length === 0
            ? <span style={{ color: 'var(--text2)' }}>Keine Spieler online</span>
            : players.map(p => {
                const name = typeof p === 'string' ? p : p.name;
                const isOp = p.is_op;
                const isWl = p.is_whitelisted;
                return (
                  <div key={name} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '6px 0', borderBottom: '1px solid var(--border)', flexWrap: 'wrap' }}>
                    <span className="dot dot-green" />
                    <span style={{ flex: 1, fontWeight: 600 }}>{name}</span>
                    {isOp && <span className="badge badge-yellow" title="Operator">OP</span>}
                    {isWl && <span className="badge badge-green" title="Whitelist">WL</span>}
                    <button className="btn btn-ghost btn-sm" title={isOp ? 'DeOP' : 'OP'}
                      onClick={() => playerAction(isOp ? '/api/players/deop' : '/api/players/op', { name }, isOp ? `${name} DeOP'd` : `${name} ist jetzt OP`)}>
                      {isOp ? <ShieldOff size={12} /> : <ShieldCheck size={12} />}
                    </button>
                    <button className="btn btn-ghost btn-sm" title={isWl ? 'Whitelist entfernen' : 'Whitelist hinzufügen'}
                      onClick={() => playerAction('/api/players/whitelist', { name, action: isWl ? 'remove' : 'add' }, isWl ? `${name} von Whitelist entfernt` : `${name} zur Whitelist hinzugefügt`)}>
                      <UserPlus size={12} />
                    </button>
                    <button className="btn btn-red btn-sm" title="Kicken"
                      onClick={() => playerAction('/api/players/kick', { name }, `${name} gekickt`)}>
                      <UserX size={12} />
                    </button>
                  </div>
                );
              })
          }
        </div>
      </div>
    </div>
  );
}
