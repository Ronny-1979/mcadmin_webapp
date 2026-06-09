import { useState, useEffect } from 'react';
import { RefreshCw, BarChart2 } from 'lucide-react';
import { get } from '../utils.js';

function fmtTime(seconds) {
  if (!seconds) return '0m';
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

function fmtDate(ts) {
  if (!ts) return '—';
  return new Date(ts * 1000).toLocaleDateString('de-DE', { day:'2-digit', month:'2-digit', year:'numeric' });
}

export default function Stats() {
  const [stats,   setStats]   = useState([]);
  const [loading, setLoading] = useState(true);
  const [sort,    setSort]    = useState('playtime');

  useEffect(() => { load(); }, []);

  async function load() {
    setLoading(true);
    const r = await get('/api/stats');
    if (r) setStats(r);
    setLoading(false);
  }

  const sorted = [...stats].sort((a, b) => {
    if (sort === 'name')     return a.name.localeCompare(b.name);
    if (sort === 'sessions') return b.sessions - a.sessions;
    if (sort === 'kicks')    return b.kicks - a.kicks;
    if (sort === 'last')     return b.last_seen - a.last_seen;
    return b.playtime - a.playtime;
  });

  const maxPlaytime = Math.max(...stats.map(s => s.playtime || 0), 1);

  return (
    <div>
      <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:24 }}>
        <h1 style={{ fontSize:22, fontWeight:700 }}>Spieler-Statistiken</h1>
        <button className="btn btn-ghost btn-sm" onClick={load}><RefreshCw size={13} /></button>
      </div>

      {loading
        ? <div style={{ color:'var(--text2)' }}>Lade…</div>
        : stats.length === 0
          ? <div className="card" style={{ color:'var(--text2)' }}>Keine Log-Daten gefunden. Server muss mindestens einmal gelaufen sein.</div>
          : (
            <>
              <div style={{ marginBottom:12, display:'flex', gap:8, alignItems:'center', flexWrap:'wrap' }}>
                <span style={{ fontSize:12, color:'var(--text2)' }}>Sortieren:</span>
                {[['playtime','Spielzeit'],['sessions','Sessions'],['kicks','Kicks'],['last','Zuletzt online'],['name','Name']].map(([k,l]) => (
                  <button key={k} className={`btn btn-sm ${sort===k?'btn-green':'btn-ghost'}`} onClick={() => setSort(k)}>{l}</button>
                ))}
              </div>

              <div className="card" style={{ marginBottom:24 }}>
                <div className="card-title"><BarChart2 size={14} style={{ verticalAlign:'middle', marginRight:6 }} />Spielzeit-Übersicht</div>
                <div style={{ display:'flex', flexDirection:'column', gap:8 }}>
                  {sorted.slice(0,15).map(s => (
                    <div key={s.name} style={{ display:'flex', alignItems:'center', gap:10 }}>
                      <span style={{ width:140, fontSize:12, overflow:'hidden', textOverflow:'ellipsis', whiteSpace:'nowrap' }}>{s.name}</span>
                      <div style={{ flex:1, background:'var(--bg3)', borderRadius:4, height:16, overflow:'hidden' }}>
                        <div style={{
                          width: `${Math.max(2, (s.playtime / maxPlaytime) * 100)}%`,
                          background: 'var(--accent)',
                          height: '100%',
                          borderRadius: 4,
                          transition: 'width 0.3s'
                        }} />
                      </div>
                      <span style={{ fontSize:11, color:'var(--text2)', width:60, textAlign:'right' }}>{fmtTime(s.playtime)}</span>
                    </div>
                  ))}
                </div>
              </div>

              <div className="card">
                <div className="card-title">Alle Spieler ({stats.length})</div>
                <table>
                  <thead>
                    <tr>
                      <th>Spieler</th>
                      <th>Spielzeit</th>
                      <th>Sessions</th>
                      <th>Kicks</th>
                      <th>Erster Login</th>
                      <th>Zuletzt online</th>
                    </tr>
                  </thead>
                  <tbody>
                    {sorted.map(s => (
                      <tr key={s.name}>
                        <td style={{ fontWeight:600 }}>{s.name}</td>
                        <td>{fmtTime(s.playtime)}</td>
                        <td>{s.sessions}</td>
                        <td style={{ color: s.kicks > 0 ? 'var(--red)' : 'inherit' }}>{s.kicks || 0}</td>
                        <td style={{ fontSize:12, color:'var(--text2)' }}>{fmtDate(s.first_seen)}</td>
                        <td style={{ fontSize:12, color:'var(--text2)' }}>{fmtDate(s.last_seen)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )
      }
    </div>
  );
}
