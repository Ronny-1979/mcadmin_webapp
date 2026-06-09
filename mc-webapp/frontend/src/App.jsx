import { useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, NavLink, Navigate } from 'react-router-dom';
import {
  LayoutDashboard, Globe, Package, Settings, LogOut, Sword, BarChart2, ScrollText
} from 'lucide-react';

import Login        from './components/Login.jsx';
import Dashboard    from './components/Dashboard.jsx';
import Worlds       from './components/Worlds.jsx';
import Packs        from './components/Packs.jsx';
import Players      from './components/Players.jsx';
import SettingsPage from './components/SettingsPage.jsx';
import Stats        from './components/Stats.jsx';
import { get, post } from './utils.js';

const NAV_GROUPS = [
  {
    title: 'Dashboard',
    items: [{ to: '/', icon: LayoutDashboard, label: 'Übersicht' }],
  },
  {
    title: 'Welten',
    items: [
      { to: '/worlds', icon: Globe, label: 'Welten' },
      { to: '/packs',  icon: Package, label: 'Packs'  },
    ],
  },
  {
    title: 'Spieler',
    items: [
      { to: '/stats',   icon: BarChart2, label: 'Statistiken' },
      { to: '/players', icon: ScrollText, label: 'Whitelist'   },
    ],
  },
  {
    title: 'System',
    items: [{ to: '/settings', icon: Settings, label: 'Einstellungen' }],
  },
];

function RequireAuth({ children }) {
  const [auth, setAuth] = useState(null);
  useEffect(() => {
    get('/api/me').then(r => setAuth(!!r?.user)).catch(() => setAuth(false));
  }, []);
  if (auth === null) return null;
  return auth ? children : <Navigate to="/login" replace />;
}

function Layout({ onLogout }) {
  return (
    <div className="layout">
      <aside className="sidebar">
        <div className="sidebar-logo">
          <Sword size={20} />
          <div>
            <div>MC Bedrock</div>
            <div style={{ fontSize: 11, color: 'var(--text2)', fontWeight: 500 }}>Admin Panel</div>
          </div>
        </div>
        {NAV_GROUPS.map(group => (
          <div key={group.title}>
            <div className="nav-section-title">{group.title}</div>
            {group.items.map(({ to, icon: Icon, label }) => (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                className={({ isActive }) => `nav-item${isActive ? ' active' : ''}`}
              >
                <Icon size={16} />
                {label}
              </NavLink>
            ))}
          </div>
        ))}
        <div style={{ flex: 1 }} />
        <button className="nav-item" onClick={onLogout}>
          <LogOut size={16} /> Abmelden
        </button>
      </aside>
      <main className="main">
        <Routes>
          <Route path="/"         element={<Dashboard />} />
          <Route path="/worlds"   element={<Worlds />} />
          <Route path="/packs"    element={<Packs />} />
          <Route path="/players"  element={<Players />} />
          <Route path="/stats"    element={<Stats />} />
          <Route path="/settings" element={<SettingsPage />} />

          {/* alte Direktlinks bleiben erreichbar, leiten aber an die Panel-Stellen weiter */}
          <Route path="/console"   element={<Navigate to="/" replace />} />
          <Route path="/backups"   element={<Navigate to="/settings?tab=backups" replace />} />
          <Route path="/updates"   element={<Navigate to="/settings?tab=updates" replace />} />
          <Route path="/schedules" element={<Navigate to="/settings?tab=schedules" replace />} />
          <Route path="/welcome"   element={<Navigate to="/worlds" replace />} />
          <Route path="*"          element={<Navigate to="/" replace />} />
        </Routes>
      </main>
    </div>
  );
}

export default function App() {
  async function logout() {
    await post('/api/logout');
    window.location.href = '/login';
  }

  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route path="/*" element={
          <RequireAuth>
            <Layout onLogout={logout} />
          </RequireAuth>
        } />
      </Routes>
    </BrowserRouter>
  );
}
