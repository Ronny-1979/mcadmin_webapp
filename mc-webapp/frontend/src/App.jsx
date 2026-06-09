import { useState, useEffect } from 'react';
import { BrowserRouter, Routes, Route, NavLink, Navigate } from 'react-router-dom';
import {
  LayoutDashboard, Terminal, Globe, Package,
  Users, HardDrive, Settings, LogOut, Sword,
  BarChart2, Download, Clock, MessageSquare
} from 'lucide-react';

import Login           from './components/Login.jsx';
import Dashboard       from './components/Dashboard.jsx';
import Console         from './components/Console.jsx';
import Worlds          from './components/Worlds.jsx';
import Packs           from './components/Packs.jsx';
import Players         from './components/Players.jsx';
import Backups         from './components/Backups.jsx';
import SettingsPage    from './components/SettingsPage.jsx';
import Stats           from './components/Stats.jsx';
import Updates         from './components/Updates.jsx';
import Schedules       from './components/Schedules.jsx';
import WelcomeMessages from './components/WelcomeMessages.jsx';
import { get, post }   from './utils.js';

const NAV = [
  { to: '/',         icon: LayoutDashboard, label: 'Dashboard'    },
  { to: '/console',  icon: Terminal,        label: 'Konsole'      },
  { to: '/worlds',   icon: Globe,           label: 'Welten'       },
  { to: '/packs',    icon: Package,         label: 'Packs'        },
  { to: '/players',  icon: Users,           label: 'Spieler'      },
  { to: '/stats',    icon: BarChart2,       label: 'Statistiken'  },
  { to: '/backups',  icon: HardDrive,       label: 'Backups'      },
  { to: '/updates',  icon: Download,        label: 'Updates'      },
  { to: '/schedules',icon: Clock,           label: 'Zeitpläne'    },
  { to: '/welcome',  icon: MessageSquare,   label: 'Willkommen'   },
  { to: '/settings', icon: Settings,        label: 'Einstellungen'},
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
          MC Admin
        </div>
        {NAV.map(({ to, icon: Icon, label }) => (
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
        <div style={{ flex: 1 }} />
        <button className="nav-item" onClick={onLogout}>
          <LogOut size={16} /> Abmelden
        </button>
      </aside>
      <main className="main">
        <Routes>
          <Route path="/"          element={<Dashboard />} />
          <Route path="/console"   element={<Console />} />
          <Route path="/worlds"    element={<Worlds />} />
          <Route path="/packs"     element={<Packs />} />
          <Route path="/players"   element={<Players />} />
          <Route path="/stats"     element={<Stats />} />
          <Route path="/backups"   element={<Backups />} />
          <Route path="/updates"   element={<Updates />} />
          <Route path="/schedules" element={<Schedules />} />
          <Route path="/welcome"   element={<WelcomeMessages />} />
          <Route path="/settings"  element={<SettingsPage />} />
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
