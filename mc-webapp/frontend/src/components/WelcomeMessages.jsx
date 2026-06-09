import { useState, useEffect } from 'react';
import { MessageSquare, Save } from 'lucide-react';
import { get, post } from '../utils.js';
import { useToast } from '../useToast.jsx';

const PLACEHOLDERS = ['{player}', '{world}', '{server}'];

export default function WelcomeMessages() {
  const [worlds,  setWorlds]  = useState([]);
  const [world,   setWorld]   = useState('');
  const [message, setMessage] = useState('');
  const [loading, setLoading] = useState(false);
  const { toast, ToastContainer } = useToast();

  useEffect(() => {
    async function init() {
      const [w, a] = await Promise.all([get('/api/worlds'), get('/api/worlds/active')]);
      if (w) setWorlds(w);
      if (a?.active) setWorld(a.active);
    }
    init();
  }, []);

  useEffect(() => {
    if (world) loadMessage(world);
  }, [world]);

  async function loadMessage(worldName) {
    setLoading(true);
    const r = await get(`/api/worlds/${encodeURIComponent(worldName)}/welcome`);
    setMessage(r?.message || '');
    setLoading(false);
  }

  async function save() {
    const r = await post(`/api/worlds/${encodeURIComponent(world)}/welcome`, { message });
    toast(r?.success ? 'Willkommensnachricht gespeichert' : (r?.error || 'Fehler'), r?.success ? 'success' : 'error');
  }

  function preview() {
    return message
      .replace(/\{player\}/g, 'Steve')
      .replace(/\{world\}/g, world || 'MeineWelt')
      .replace(/\{server\}/g, 'Mein Server');
  }

  return (
    <div>
      <ToastContainer />
      <h1 style={{ fontSize:22, fontWeight:700, marginBottom:24 }}>Willkommensnachrichten</h1>

      <div className="card" style={{ marginBottom:16 }}>
        <div className="card-title">Welt auswählen</div>
        <select value={world} onChange={e => setWorld(e.target.value)} style={{ maxWidth:300 }}>
          <option value="">-- Welt wählen --</option>
          {worlds.map(w => <option key={w.name} value={w.name}>{w.name}</option>)}
        </select>
      </div>

      {world && (
        <>
          <div className="card" style={{ marginBottom:16 }}>
            <div style={{ display:'flex', justifyContent:'space-between', alignItems:'center', marginBottom:12 }}>
              <div className="card-title" style={{ margin:0 }}>
                <MessageSquare size={14} style={{ verticalAlign:'middle', marginRight:6 }} />
                Nachricht für „{world}"
              </div>
              <button className="btn btn-green btn-sm" onClick={save} disabled={!world || loading}>
                <Save size={12} /> Speichern
              </button>
            </div>
            <textarea
              value={message}
              onChange={e => setMessage(e.target.value)}
              maxLength={2000}
              rows={6}
              placeholder={`Willkommen auf dem Server, {player}!\nAktive Welt: {world}`}
              style={{ width:'100%', resize:'vertical', boxSizing:'border-box' }}
            />
            <div style={{ fontSize:11, color:'var(--text2)', marginTop:4, display:'flex', justifyContent:'space-between' }}>
              <span>Platzhalter: {PLACEHOLDERS.map(p => (
                <code key={p} style={{ marginLeft:6, background:'var(--bg3)', padding:'1px 4px', borderRadius:3 }}>{p}</code>
              ))}</span>
              <span>{message.length} / 2000</span>
            </div>
          </div>

          {message && (
            <div className="card">
              <div className="card-title">Vorschau</div>
              <pre style={{ background:'var(--bg3)', borderRadius:6, padding:12, fontSize:13, margin:0, whiteSpace:'pre-wrap', color:'var(--accent)' }}>
                {preview()}
              </pre>
            </div>
          )}
        </>
      )}
    </div>
  );
}
