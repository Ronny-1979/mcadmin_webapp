import { useState } from 'react';
import { Sword } from 'lucide-react';
import { post } from '../utils.js';

export default function Login() {
  const [user, setUser] = useState('');
  const [pass, setPass] = useState('');
  const [err,  setErr]  = useState('');
  const [loading, setLoading] = useState(false);

  async function submit(e) {
    e.preventDefault();
    setLoading(true);
    setErr('');
    try {
      const r = await post('/api/login', { user, pass });
      if (r?.success) {
        window.location.href = '/';
      } else {
        setErr(r?.error || 'Falsche Zugangsdaten');
      }
    } catch {
      setErr('Server nicht erreichbar');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="login-wrap">
      <div className="login-box">
        <div className="login-title">
          <Sword size={28} style={{ display: 'block', margin: '0 auto 8px' }} />
          Minecraft Admin
        </div>
        <form onSubmit={submit} style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          <div>
            <label style={{ fontSize: 12, color: 'var(--text2)', display: 'block', marginBottom: 6 }}>Benutzername</label>
            <input value={user} onChange={e => setUser(e.target.value)} autoFocus autoComplete="username" />
          </div>
          <div>
            <label style={{ fontSize: 12, color: 'var(--text2)', display: 'block', marginBottom: 6 }}>Passwort</label>
            <input type="password" value={pass} onChange={e => setPass(e.target.value)} autoComplete="current-password" />
          </div>
          {err && <div style={{ color: 'var(--red)', fontSize: 13 }}>{err}</div>}
          <button type="submit" className="btn btn-green" disabled={loading} style={{ width: '100%', justifyContent: 'center', marginTop: 4 }}>
            {loading ? 'Anmelden...' : 'Anmelden'}
          </button>
        </form>
      </div>
    </div>
  );
}
