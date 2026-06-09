import { useState, useEffect, useRef, useCallback } from 'react';
import { Send, Trash2, ChevronDown } from 'lucide-react';
import { logClass } from '../utils.js';

const MAX_LINES = 500;

// Schnellbefehle
const QUICK = [
  { label: 'list',      cmd: 'list' },
  { label: 'save all',  cmd: 'save all' },
  { label: 'Tag',       cmd: 'time set day' },
  { label: 'Nacht',     cmd: 'time set night' },
  { label: 'Klar',      cmd: 'weather clear' },
  { label: 'Regen',     cmd: 'weather rain' },
];

export default function Console({ embedded = false } = {}) {
  const [lines, setLines]   = useState([]);
  const [input, setInput]   = useState('');
  const [hist,  setHist]    = useState([]);
  const [histIdx, setHistIdx] = useState(-1);
  const [connected, setConnected] = useState(false);
  const [autoScroll, setAutoScroll] = useState(true);

  const wsRef   = useRef(null);
  const outRef  = useRef(null);
  const inputRef = useRef(null);

  // WebSocket aufbauen
  const connect = useCallback(() => {
    // Token aus Cookie lesen (mc_token ist httpOnly, also JWT aus localStorage falls vorhanden)
    // Besser: eigener Token-Endpunkt
    fetch('/api/me', { credentials: 'include' })
      .then(r => r.json())
      .then(me => {
        if (!me?.user) return;
        // JWT-Token neu holen (Login gibt Token zurück, wir brauchen ihn für WS)
        // Workaround: Token via Cookie wird beim HTTP-Request gesendet,
        // für WS holen wir einen WS-Token
        return fetch('/api/wstoken', { credentials: 'include' }).then(r => r.json());
      })
      .then(data => {
        if (!data?.token) return;
        const proto = location.protocol === 'https:' ? 'wss' : 'ws';
        const ws = new WebSocket(`${proto}://${location.host}/ws?token=${data.token}`);
        wsRef.current = ws;

        ws.onopen = () => setConnected(true);
        ws.onclose = () => {
          setConnected(false);
          // Automatisch wiederverbinden nach 3s
          setTimeout(connect, 3000);
        };
        ws.onerror = () => ws.close();

        ws.onmessage = evt => {
          const msg = JSON.parse(evt.data);
          if (msg.type === 'init') {
            setLines(msg.lines.map(l => ({ l, id: Math.random() })));
          } else if (msg.type === 'line') {
            setLines(prev => {
              const next = [...prev, { l: msg.line, id: Math.random() }];
              return next.length > MAX_LINES ? next.slice(-MAX_LINES) : next;
            });
          }
        };
      })
      .catch(() => setTimeout(connect, 5000));
  }, []);

  useEffect(() => {
    connect();
    return () => wsRef.current?.close();
  }, [connect]);

  // Auto-Scroll
  useEffect(() => {
    if (autoScroll && outRef.current) {
      outRef.current.scrollTop = outRef.current.scrollHeight;
    }
  }, [lines, autoScroll]);

  function onScroll() {
    const el = outRef.current;
    if (!el) return;
    const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 30;
    setAutoScroll(atBottom);
  }

  function send() {
    const cmd = input.trim();
    if (!cmd || !wsRef.current || wsRef.current.readyState !== 1) return;
    // Lokal anzeigen
    setLines(prev => [...prev, { l: `> ${cmd}`, id: Math.random(), isCmd: true }]);
    // Verlauf
    setHist(h => [cmd, ...h.slice(0, 49)]);
    setHistIdx(-1);
    setInput('');
    // Senden
    wsRef.current.send(JSON.stringify({ type: 'cmd', cmd }));
    inputRef.current?.focus();
  }

  function onKeyDown(e) {
    if (e.key === 'Enter')     { send(); return; }
    if (e.key === 'ArrowUp')   { e.preventDefault(); const i = Math.min(histIdx + 1, hist.length - 1); setHistIdx(i); setInput(hist[i] || ''); }
    if (e.key === 'ArrowDown') { e.preventDefault(); const i = Math.max(histIdx - 1, -1); setHistIdx(i); setInput(i < 0 ? '' : hist[i]); }
  }

  return (
    <div>
      {!embedded && (
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
          <h1 style={{ fontSize: 22, fontWeight: 700 }}>Konsole</h1>
          <span className={`badge ${connected ? 'badge-green' : 'badge-red'}`}>
            {connected ? '● Live' : '○ Getrennt'}
          </span>
        </div>
      )}
      {embedded && (
        <div style={{ display:'flex', justifyContent:'flex-end', marginBottom:8 }}>
          <span className={`badge ${connected ? 'badge-green' : 'badge-red'}`}>{connected ? '● Live' : '○ Getrennt'}</span>
        </div>
      )}

      {/* Schnellbefehle */}
      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 10 }}>
        {QUICK.map(q => (
          <button key={q.cmd} className="btn btn-ghost btn-sm"
            onClick={() => { setInput(q.cmd); inputRef.current?.focus(); }}>
            {q.label}
          </button>
        ))}
        <button className="btn btn-ghost btn-sm" style={{ marginLeft: 'auto' }}
          onClick={() => setLines([])}>
          <Trash2 size={12} /> Leeren
        </button>
      </div>

      {/* Log-Ausgabe */}
      <div className="console-box" ref={outRef} onScroll={onScroll}>
        {lines.map(({ l, id, isCmd }) => (
          <div key={id} className={`log-line ${isCmd ? 'log-cmd' : logClass(l)}`}>{l}</div>
        ))}
      </div>

      {/* Auto-Scroll-Button */}
      {!autoScroll && (
        <button className="btn btn-ghost btn-sm"
          style={{ marginTop: 6, display: 'flex', alignItems: 'center', gap: 4 }}
          onClick={() => { setAutoScroll(true); outRef.current.scrollTop = outRef.current.scrollHeight; }}>
          <ChevronDown size={12} /> Ans Ende scrollen
        </button>
      )}

      {/* Eingabe */}
      <div className="console-input">
        <input
          ref={inputRef}
          value={input}
          onChange={e => setInput(e.target.value)}
          onKeyDown={onKeyDown}
          placeholder="Befehl eingeben…"
          disabled={!connected}
        />
        <button className="btn btn-green" onClick={send} disabled={!connected || !input.trim()}>
          <Send size={14} />
        </button>
      </div>
    </div>
  );
}
