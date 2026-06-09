<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
if ($_SERVER['REQUEST_METHOD']==='POST' && !empty($_POST['remember'])) {
    session_set_cookie_params(30 * 24 * 60 * 60);
}
session_start();

$settings = load_settings();

// Login
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['password'])) {
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if ($u === $settings['admin_user'] && password_verify($p, $settings['admin_pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['admin_user']    = $u;
        header('Location: index.php'); exit;
    } else {
        $login_error = 'Falscher Benutzername oder Passwort';
    }
}
if (isset($_POST['logout'])) { session_destroy(); header('Location: index.php'); exit; }
$authenticated = !empty($_SESSION['authenticated']);
$admin_user    = $_SESSION['admin_user'] ?? $settings['admin_user'];

// Warnung wenn noch Standard-Zugangsdaten
$default_creds = ($settings['admin_user'] === DEFAULT_ADMIN_USER
    && password_verify(DEFAULT_ADMIN_PASS, $settings['admin_pass_hash']));
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>⛏ MC Bedrock Admin</title>
<script>
try {
  const t = localStorage.getItem('mcadmin_theme') || 'dark';
  document.documentElement.dataset.theme = t === 'light' ? 'light' : 'dark';
} catch(e) {
  document.documentElement.dataset.theme = 'dark';
}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Press+Start+2P&display=swap">
<link rel="stylesheet" href="assets/style.css?v=9">
</head>
<body>
<!-- ═══ SVG SPRITES ═══ -->
<svg style="display:none" xmlns="http://www.w3.org/2000/svg">
  <defs>
    <pattern id="mc-g" patternUnits="userSpaceOnUse" width="4" height="4">
      <rect x="0" y="0" width="1" height="1" fill="#5F9E38"/>
      <rect x="1" y="0" width="1" height="1" fill="#6DB438"/>
      <rect x="2" y="0" width="1" height="1" fill="#4A8228"/>
      <rect x="3" y="0" width="1" height="1" fill="#7DC442"/>
      <rect x="0" y="1" width="1" height="1" fill="#7DC442"/>
      <rect x="1" y="1" width="1" height="1" fill="#4A8228"/>
      <rect x="2" y="1" width="1" height="1" fill="#6DB438"/>
      <rect x="3" y="1" width="1" height="1" fill="#5F9E38"/>
      <rect x="0" y="2" width="1" height="1" fill="#4A8228"/>
      <rect x="1" y="2" width="1" height="1" fill="#5F9E38"/>
      <rect x="2" y="2" width="1" height="1" fill="#7DC442"/>
      <rect x="3" y="2" width="1" height="1" fill="#4A8228"/>
      <rect x="0" y="3" width="1" height="1" fill="#6DB438"/>
      <rect x="1" y="3" width="1" height="1" fill="#7DC442"/>
      <rect x="2" y="3" width="1" height="1" fill="#5F9E38"/>
      <rect x="3" y="3" width="1" height="1" fill="#6DB438"/>
    </pattern>
    <pattern id="mc-d" patternUnits="userSpaceOnUse" width="4" height="4">
      <rect x="0" y="0" width="1" height="1" fill="#8B6143"/>
      <rect x="1" y="0" width="1" height="1" fill="#6B4422"/>
      <rect x="2" y="0" width="1" height="1" fill="#9B7253"/>
      <rect x="3" y="0" width="1" height="1" fill="#7A5235"/>
      <rect x="0" y="1" width="1" height="1" fill="#7A5235"/>
      <rect x="1" y="1" width="1" height="1" fill="#9B7253"/>
      <rect x="2" y="1" width="1" height="1" fill="#6B4422"/>
      <rect x="3" y="1" width="1" height="1" fill="#8B6143"/>
      <rect x="0" y="2" width="1" height="1" fill="#9B7253"/>
      <rect x="1" y="2" width="1" height="1" fill="#8B6143"/>
      <rect x="2" y="2" width="1" height="1" fill="#7A5235"/>
      <rect x="3" y="2" width="1" height="1" fill="#9B7253"/>
      <rect x="0" y="3" width="1" height="1" fill="#6B4422"/>
      <rect x="1" y="3" width="1" height="1" fill="#7A5235"/>
      <rect x="2" y="3" width="1" height="1" fill="#8B6143"/>
      <rect x="3" y="3" width="1" height="1" fill="#6B4422"/>
    </pattern>
  </defs>
  <!-- Grass Block -->
  <symbol id="mc-grass" viewBox="0 0 16 16">
    <rect width="16" height="4" fill="url(#mc-g)"/>
    <rect y="4" width="16" height="12" fill="url(#mc-d)"/>
    <rect x="0" y="0" width="1" height="16" fill="rgba(255,255,255,.08)"/>
    <rect x="0" y="0" width="16" height="1" fill="rgba(255,255,255,.12)"/>
    <rect x="15" y="0" width="1" height="16" fill="rgba(0,0,0,.25)"/>
    <rect x="0" y="15" width="16" height="1" fill="rgba(0,0,0,.3)"/>
  </symbol>
  <!-- Steve Head -->
  <symbol id="mc-steve" viewBox="0 0 8 8">
    <rect width="8" height="8" fill="#4D3526"/>
    <rect x="1" y="1" width="6" height="6" fill="#C8986C"/>
    <rect x="2" y="2" width="1" height="2" fill="#fff"/>
    <rect x="5" y="2" width="1" height="2" fill="#fff"/>
    <rect x="2" y="3" width="1" height="1" fill="#1D4D8A"/>
    <rect x="5" y="3" width="1" height="1" fill="#1D4D8A"/>
    <rect x="3" y="5" width="2" height="1" fill="#7A3A18"/>
    <rect x="1" y="1" width="6" height="1" fill="#5A3020"/>
    <rect x="1" y="2" width="1" height="2" fill="#B07B55"/>
    <rect x="6" y="2" width="1" height="2" fill="#B07B55"/>
  </symbol>
  <!-- Chest -->
  <symbol id="mc-chest" viewBox="0 0 16 16">
    <rect width="16" height="16" fill="#5A3A1A"/>
    <rect x="1" y="1" width="14" height="4" fill="#B07B45"/>
    <rect x="1" y="7" width="14" height="8" fill="#9B6B3A"/>
    <rect x="1" y="5" width="14" height="2" fill="#4A2A0A"/>
    <rect x="2" y="2" width="12" height="2" fill="#C08A50"/>
    <rect x="2" y="8" width="12" height="6" fill="#B07B45"/>
    <rect x="6" y="5" width="4" height="2" fill="#D4A620"/>
    <rect x="7" y="4" width="2" height="1" fill="#D4A620"/>
    <rect x="7" y="7" width="2" height="1" fill="#C09010"/>
    <rect x="1" y="1" width="14" height="1" fill="rgba(255,255,255,.15)"/>
    <rect x="1" y="1" width="1" height="14" fill="rgba(255,255,255,.1)"/>
  </symbol>
  <!-- Diamond Pickaxe -->
  <symbol id="mc-pickaxe" viewBox="0 0 16 16">
    <rect x="2" y="4" width="2" height="1" fill="#55BBCC"/>
    <rect x="2" y="5" width="1" height="2" fill="#55BBCC"/>
    <rect x="3" y="3" width="3" height="1" fill="#55BBCC"/>
    <rect x="3" y="4" width="1" height="1" fill="#7DDDDD"/>
    <rect x="4" y="4" width="1" height="1" fill="#3A9AAA"/>
    <rect x="5" y="3" width="1" height="2" fill="#3A9AAA"/>
    <rect x="6" y="2" width="2" height="2" fill="#55BBCC"/>
    <rect x="7" y="4" width="1" height="1" fill="#7DDDDD"/>
    <rect x="6" y="5" width="1" height="1" fill="#3A9AAA"/>
    <rect x="6"  y="6"  width="2" height="1" fill="#A0703C"/>
    <rect x="7"  y="7"  width="2" height="1" fill="#8A5C2C"/>
    <rect x="8"  y="8"  width="2" height="1" fill="#A0703C"/>
    <rect x="9"  y="9"  width="2" height="1" fill="#8A5C2C"/>
    <rect x="10" y="10" width="2" height="1" fill="#A0703C"/>
    <rect x="11" y="11" width="2" height="1" fill="#8A5C2C"/>
    <rect x="12" y="12" width="2" height="1" fill="#A0703C"/>
    <rect x="13" y="13" width="1" height="1" fill="#8A5C2C"/>
  </symbol>
</svg>

<?php if(!$authenticated): ?>
<!-- ═══ LOGIN ═══ -->
<div class="lw">
  <form class="lb" method="post">
    <div class="ll"><svg width="64" height="64" style="image-rendering:pixelated"><use href="#mc-pickaxe"/></svg></div>
    <div class="lt">Minecraft Admin</div>
    <div class="ls">Bedrock Server Management</div>
    <?php if($default_creds): ?>
    <div class="ldef">⚠️ Standard-Zugangsdaten aktiv: <strong>admin / admin</strong><br>Bitte nach dem Login unter Einstellungen ändern!</div>
    <?php endif; ?>
    <?php if(!empty($login_error)): ?>
    <div class="le">🔒 <?=htmlspecialchars($login_error)?></div>
    <?php endif; ?>
    <div class="fr"><label>Benutzername</label><input type="text" name="username" autocomplete="username"></div>
    <div class="fr"><label>Passwort</label><input type="password" name="password" autofocus autocomplete="current-password" placeholder="Passwort eingeben..."></div>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:16px">
      <input type="checkbox" name="remember" id="lrem" value="1" style="width:auto;accent-color:#5db85c;cursor:pointer">
      <label for="lrem" style="margin:0;cursor:pointer;font-size:12px;color:#444">Angemeldet bleiben (30 Tage)</label>
    </div>
    <button type="submit" class="btn primary" style="width:100%;justify-content:center;padding:10px">Anmelden</button>
  </form>
</div>

<?php else: ?>
<!-- ═══ APP ═══ -->

<!-- MODALS -->
<div id="modal-create-world" class="modal-bg hidden">
  <div class="modal">
    <div class="mh">🌍 Neue Welt erstellen</div>
    <div class="fr"><label>Weltname *</label><input type="text" id="cw-name" placeholder="Meine Welt" maxlength="64"></div>
    <div class="g2 mb12">
      <div class="fr" style="margin:0"><label>Spielmodus</label>
        <select id="cw-gamemode"><option value="0">Survival</option><option value="1">Creative</option><option value="2">Adventure</option></select>
      </div>
      <div class="fr" style="margin:0"><label>Schwierigkeit</label>
        <select id="cw-diff"><option value="0">Friedlich</option><option value="1" selected>Einfach</option><option value="2">Normal</option><option value="3">Schwer</option></select>
      </div>
    </div>
    <div class="fr"><label>Seed (optional)</label><input type="text" id="cw-seed" placeholder="Leer = zufällig"></div>
    <div class="mf">
      <button class="btn ghost sm" onclick="closeModal('modal-create-world')">Abbrechen</button>
      <button class="btn primary sm" onclick="createWorld()">🌍 Erstellen</button>
    </div>
  </div>
</div>

<div id="modal-rename-world" class="modal-bg hidden">
  <div class="modal">
    <div class="mh">✏️ Welt umbenennen</div>
    <input type="hidden" id="rw-old">
    <div class="fr"><label>Aktueller Name</label><input type="text" id="rw-old-disp" disabled style="opacity:.5"></div>
    <div class="fr"><label>Neuer Name *</label><input type="text" id="rw-new" maxlength="64"></div>
    <div class="mf">
      <button class="btn ghost sm" onclick="closeModal('modal-rename-world')">Abbrechen</button>
      <button class="btn warn sm" onclick="renameWorld()">✏️ Umbenennen</button>
    </div>
  </div>
</div>

<div id="modal-del-world" class="modal-bg hidden">
  <div class="modal">
    <div class="mh" style="color:var(--red,#ef4444)">Welt löschen</div>
    <div style="margin:12px 0 16px;line-height:1.6">Welt <strong id="del-world-name"></strong> unwiderruflich löschen?<br><span class="dim xs2">Alle Welt-Daten gehen verloren. Diese Aktion kann nicht rückgängig gemacht werden.</span></div>
    <div class="mf">
      <button class="btn ghost sm" onclick="closeModal('modal-del-world')">Abbrechen</button>
      <button class="btn danger sm" onclick="confirmDelWorld()">🗑 Löschen</button>
    </div>
  </div>
</div>

<div id="modal-world-compat" class="modal-bg hidden">
  <div class="modal">
    <div class="mh" style="color:var(--yellow,#f59e0b)">⚠️ Versions-Kompatibilität</div>
    <div id="compat-issues-section">
      <div style="margin:8px 0 4px;font-weight:600;font-size:.9rem">Inkompatible Packs:</div>
      <ul id="compat-issues-list" style="margin:4px 0 12px;padding-left:18px;line-height:1.7;font-size:.85rem"></ul>
    </div>
    <div id="compat-exp-section">
      <div style="margin:8px 0 4px;font-weight:600;font-size:.9rem">Aktive Experimente in dieser Welt:</div>
      <ul id="compat-exp-list" style="margin:4px 0 12px;padding-left:18px;line-height:1.7;font-size:.85rem"></ul>
      <div class="dim xs2" style="margin-bottom:8px">Experimente können sich je nach BDS-Version unterschiedlich verhalten (z.B. holiday_creator_features ab 1.21.20).</div>
    </div>
    <div class="dim xs2" style="margin-bottom:16px">Die Welt könnte sich anders verhalten oder Inhalte könnten nicht funktionieren.</div>
    <div class="mf">
      <button class="btn ghost sm" onclick="closeModal('modal-world-compat');pendingWorldFile=null;">Abbrechen</button>
      <button id="compat-install-btn" class="btn warn sm" onclick="forceInstallWorld()">Trotzdem installieren</button>
    </div>
  </div>
</div>

<div id="sidebar-overlay" class="sidebar-overlay" onclick="toggleSidebar()"></div>
<div class="layout">
  <nav class="sidebar" id="sidebar">
    <div class="slogo">
      <div class="slogo-i"><svg width="28" height="28" style="image-rendering:pixelated"><use href="#mc-grass"/></svg></div>
      <div><div class="slogo-t">MC Bedrock</div><div class="slogo-s">Admin Panel</div><div id="sver-mc" class="dim xs2" style="margin-top:2px;line-height:1.4"></div><div id="sver-panel" class="dim xs2" style="line-height:1.4"></div></div>
    </div>
    <div class="snav">
      <div class="ns">Dashboard</div>
      <div class="ni active" onclick="showPage('dashboard',this)"><span class="ic">🧱</span> Übersicht</div>
      <div class="ns">Welten</div>
      <div class="ni" onclick="showPage('worlds',this)"><span class="ic">🗺️</span> Welten</div>
      <div class="ni" onclick="showPage('packs',this)"><span class="ic">🎒</span> Packs</div>
      <div class="ns">Spieler</div>
      <div class="ni" onclick="showPage('stats',this)"><span class="ic">📈</span> Statistiken</div>
      <div class="ni" onclick="showPage('whitelist',this)"><span class="ic">📜</span> Whitelist</div>
      <div class="ns">System</div>
      <div class="ni" onclick="showPage('settings',this)"><span class="ic">⚙️</span> Einstellungen</div>
    </div>
    <div class="sfooter">
      <div class="suser">
        <div class="suser-av"><?=strtoupper(substr($admin_user,0,1))?></div>
        <div class="suser-name"><?=htmlspecialchars($admin_user)?></div>
      </div>
      <form method="post">
        <button type="submit" name="logout" class="btn ghost" style="width:100%;justify-content:center;font-size:12px">🚪 Abmelden</button>
      </form>
    </div>
  </nav>

  <div class="main">
    <div class="topbar">
      <div class="fx ac g8">
        <button class="btn ghost sm mob-menu-btn" onclick="toggleSidebar()" aria-label="Menü">☰</button>
        <span class="pt" id="page-title">Übersicht</span>
      </div>
      <div class="fx ac g8">
        <?php if($default_creds): ?><span class="badge badge-y" onclick="showPage('settings',document.querySelector('[onclick*=settings]'))" style="cursor:pointer">⚠️ Standard-Passwort</span><?php endif; ?>
        <span id="tb-status" class="sb off"><span class="dot"></span> Offline</span>
        <span id="tb-uptime" class="dim xs2" style="margin-left:6px"></span>
        <button id="theme-toggle" class="btn ghost sm theme-toggle" onclick="toggleTheme()" title="Hell/Dunkel wechseln" aria-label="Hell/Dunkel wechseln">🌙</button>
      </div>
    </div>
    <div class="topbar2">
      <span id="tb-world" class="tb-wn"></span>
      <div class="fx ac g6">
        <button class="btn success sm" onclick="srvAction('start')">▶ Start</button>
        <button class="btn danger sm" onclick="srvAction('stop')">⏹ Stop</button>
        <button class="btn warn sm" onclick="srvAction('restart')">↺ Restart</button>
      </div>
    </div>
    <div class="content">

    <!-- ═══ DASHBOARD ═══ -->
    <div id="page-dashboard" class="pc2">
      <?php if($default_creds): ?>
      <div class="ub warn2 mb14">
        <div>⚠️</div>
        <div><strong style="color:var(--red)">Sicherheitswarnung!</strong> Du verwendest noch Standard-Zugangsdaten (admin/admin).
        <a href="#" onclick="showPage('settings',document.querySelector('[onclick*=settings]'));return false;" style="color:var(--yellow);margin-left:6px">Jetzt ändern →</a></div>
      </div>
      <?php endif; ?>
      <div class="card">
        <div class="ch"><div class="ct">👥 Online Spieler</div><span id="d-pcnt" class="dim xs2">0 online</span></div>
        <div class="cb" id="d-plist"><div class="dim xs2" style="text-align:center;padding:18px">Keine Spieler online</div></div>
      </div>
      <div class="card">
        <div class="ch">
          <div class="ct">💻 Server-Konsole</div>
          <div class="fx ac g6">
            <select id="con-lines" onchange="G.conLines=+this.value;startCon()" style="font-size:11px;padding:3px 6px;border:1px solid var(--border);border-radius:6px;background:var(--bg3);color:var(--text);cursor:pointer">
              <option value="50" selected>50 Zeilen</option>
              <option value="100">100 Zeilen</option>
              <option value="150">150 Zeilen</option>
              <option value="300">300 Zeilen</option>
              <option value="500">500 Zeilen</option>
            </select>
            <button class="btn ghost sm" onclick="conPause()" id="con-pbtn">⏸ Pause</button>
            <button class="btn ghost sm" onclick="conClear()">🗑 Leeren</button>
            <button class="btn success sm" onclick="conScroll()">↓ Ende</button>
          </div>
        </div>
        <div class="console-box">
          <div class="con-out" id="con-out"></div>
          <div class="con-in">
            <input type="text" id="con-inp" placeholder="Befehl eingeben... (Enter zum Senden)" onkeydown="if(event.key==='Enter')conSend()">
            <button onclick="conSend()">➤ Senden</button>
          </div>
        </div>
        <div class="cb" style="padding:9px 14px">
          <div class="dim xs2">Schnellbefehle:
            <?php foreach(['list','save all','say Server Restart in 5 Min!','time set day','weather clear','gamerule doDaylightCycle false'] as $cmd): ?>
            <button class="btn ghost xs" onclick="quickCmd('<?=htmlspecialchars($cmd,ENT_QUOTES)?>')" style="margin:2px"><?=htmlspecialchars($cmd)?></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ WORLDS ═══ -->
    <div id="page-worlds" class="pc2 hidden">
      <div class="g2">
        <div>
          <div class="card">
            <div class="ch"><div class="ct">📥 Welt importieren (.mcworld)</div></div>
            <div class="cb">
              <div class="uz" onclick="document.getElementById('wld-file').click()" id="wld-drop">
                <div class="ui">🌍</div><div>Welt-Datei (.mcworld) hierher ziehen</div>
                <div class="xs2 dim" style="margin-top:3px">oder klicken</div>
                <input type="file" id="wld-file" accept=".mcworld" onchange="uploadWorld(this)">
              </div>
            </div>
          </div>
          <div class="card">
            <div class="ch">
              <div class="ct">🌍 Welten</div>
              <button class="btn primary sm" onclick="openModal('modal-create-world')">+ Neue Welt</button>
            </div>
            <div class="cb" id="worlds-list"><div class="dim xs2" style="text-align:center;padding:18px">Lade...</div></div>
          </div>
        </div>
        <div>
          <div class="card">
            <div class="ch">
              <div class="ct">⚙️ Server-Einstellungen</div>
              <div class="fx ac g6">
                <span id="props-lbl" class="badge badge-d">Keine Welt</span>
                <button class="btn primary sm" onclick="saveProps()" id="btn-save-props" disabled>💾 Speichern</button>
              </div>
            </div>
            <div class="cb" id="props-body" style="max-height:calc(100vh - 200px);overflow-y:auto">
              <div class="dim xs2" style="text-align:center;padding:28px">Wähle eine Welt aus.</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ PACKS ═══ -->
    <div id="page-packs" class="pc2 hidden">
      <div class="card">
        <div class="ch">
          <div class="ct">🌍 Packs für Welt</div>
          <div class="fx ac g6">
            <label style="margin:0;white-space:nowrap">Welt:</label>
            <select id="pk-world" onchange="loadWPacks()" style="width:auto"></select>
          </div>
        </div>
        <div class="cb">
          <div class="tabs">
            <button class="tb active" onclick="switchPkTab('resource',this)">🎨 Resource Packs</button>
            <button class="tb" onclick="switchPkTab('behavior',this)">⚙️ Behavior Packs</button>
          </div>
          <div id="pt-res" class="tp active"><div id="res-list"></div></div>
          <div id="pt-beh" class="tp"><div id="beh-list"></div></div>
        </div>
      </div>
    </div>

    <!-- ═══ STATS ═══ -->
    <div id="page-stats" class="pc2 hidden">
      <div class="card">
        <div class="ch"><div class="ct">📊 Spieler-Statistiken</div><button class="btn ghost sm" onclick="loadStats()">⟳</button></div>
        <div id="stats-body"><div class="cb dim xs2" style="text-align:center;padding:26px"><span class="spin">⟳</span> Analysiere...</div></div>
      </div>
    </div>

    <!-- ═══ WHITELIST ═══ -->
    <div id="page-whitelist" class="pc2 hidden">
      <div class="card">
        <div class="ch"><div class="ct">➕ Spieler hinzufügen</div></div>
        <div class="cb"><div class="ig"><input type="text" id="wl-name" placeholder="Spielername..."><button class="btn success" onclick="wlAdd2()">+ Hinzufügen</button></div></div>
      </div>
      <div class="card">
        <div class="ch"><div class="ct">📋 Whitelist</div><button class="btn ghost sm" onclick="loadWl()">⟳</button></div>
        <div class="tw">
          <table><thead><tr><th>Spieler</th><th>OP</th><th>Aktion</th></tr></thead>
          <tbody id="wl-tbody"><tr><td colspan="3" class="dim xs2" style="text-align:center">Lade...</td></tr></tbody></table>
        </div>
      </div>
    </div>

    <!-- ═══ EINSTELLUNGEN ═══ -->
    <div id="page-settings" class="pc2 hidden">

      <?php if($default_creds): ?>
      <div class="ub warn2 mb14">
        <div style="font-size:20px">🔐</div>
        <div><strong style="color:var(--red)">Standard-Zugangsdaten!</strong> Ändere Benutzername und Passwort bevor du dieses Panel ins Netzwerk gibst.</div>
      </div>
      <?php endif; ?>

      <div class="tabs mb14" id="settings-tabs">
        <button class="tb active" onclick="switchSettingsTab('updates',this)">⬆️ Updates</button>
        <button class="tb" onclick="switchSettingsTab('backups',this)">📦 Backups</button>
        <button class="tb" onclick="switchSettingsTab('schedules',this)">⏱ Zeitpläne</button>
        <button class="tb" onclick="switchSettingsTab('account',this)">👤 Benutzer & Passwort</button>
        <button class="tb" onclick="switchSettingsTab('discord',this)">🎮 Discord</button>
      </div>

      <!-- ── Tab: Updates ── -->
      <div id="set-updates" class="stp active">
        <div class="g2 mb14">
          <div class="card" style="margin:0">
            <div class="ch"><div class="ct">🔄 Minecraft Server</div><button class="btn ghost sm" onclick="checkVer()">⟳ Prüfen</button></div>
            <div class="cb">
              <div id="mc-ub" style="display:none;margin-bottom:12px"></div>
              <div class="g2 mb12">
                <div class="stat"><div class="sl">Installiert</div><div id="v-cur" style="font-size:14px;font-weight:700;margin-top:3px">...</div></div>
                <div class="stat"><div class="sl">Verfügbar</div><div id="v-lat" style="font-size:14px;font-weight:700;margin-top:3px">...</div></div>
              </div>
              <div class="lbox mb12" id="upd-log"><span class="dim">Kein aktiver Prozess.</span></div>
              <button class="btn warn" onclick="startUpdate()" id="btn-upd" disabled>⬆ Update installieren</button>
            </div>
          </div>
          <div class="card" style="margin:0">
            <div class="ch">
              <div class="ct">🔧 Panel-Update</div>
              <button class="btn ghost sm" onclick="checkPanelUpdate(true)">⟳ Prüfen</button>
            </div>
            <div class="cb">
              <div id="panel-ub" style="display:none;margin-bottom:12px"></div>
              <div class="g2 mb12">
                <div class="stat"><div class="sl">Installiert</div><div id="p-cur" style="font-size:14px;font-weight:700;margin-top:3px">...</div></div>
                <div class="stat"><div class="sl">Verfügbar (GitHub)</div><div id="p-lat" style="font-size:14px;font-weight:700;margin-top:3px">...</div></div>
              </div>
              <div class="lbox mb12" id="panel-upd-log"><span class="dim">Kein aktiver Prozess.</span></div>
              <button class="btn warn" onclick="startPanelUpdate()" id="btn-panel-upd" disabled>⬆ Panel aktualisieren</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Tab: Zeitpläne ── -->
      <div id="set-schedules" class="stp">
        <div class="g2">
          <div class="card" style="margin:0">
          <div class="ch"><div class="ct">🔄 Automatischer Neustart</div></div>
          <div class="cb">
            <div class="fx ac mb12" style="gap:12px">
              <label class="tgl" style="margin:0">
                <input type="checkbox" id="restart-sched-on">
                <span class="tsl"></span>
              </label>
              <span>Täglichen automatischen Server-Neustart aktivieren</span>
            </div>
            <div class="fr">
              <label>Uhrzeit</label>
              <input type="time" id="restart-sched-time" value="06:00" style="width:auto">
            </div>
            <button class="btn primary" onclick="saveRestartSchedule()">💾 Zeitplan speichern</button>
          </div>
          </div>
          <div class="card" style="margin:0">
          <div class="ch"><div class="ct">🔔 Automatische Update-Prüfung</div></div>
          <div class="cb">
            <div class="fx ac mb12" style="gap:12px">
              <label class="tgl" style="margin:0">
                <input type="checkbox" id="upd-check-on">
                <span class="tsl"></span>
              </label>
              <span>Nach Minecraft- und Panel-Updates suchen</span>
            </div>
            <div class="fr">
              <label>Uhrzeit</label>
              <input type="time" id="upd-check-time" value="04:00" style="width:auto">
            </div>
            <div class="fx ac mb12" style="gap:12px">
              <label class="tgl" style="margin:0">
                <input type="checkbox" id="auto-install-mc">
                <span class="tsl"></span>
              </label>
              <span>⚠️ Minecraft automatisch aktualisieren <span class="dim xs2">(Server wird neugestartet)</span></span>
            </div>
            <div class="fx ac mb12" style="gap:12px">
              <label class="tgl" style="margin:0">
                <input type="checkbox" id="auto-install-panel">
                <span class="tsl"></span>
              </label>
              <span>⚠️ Panel automatisch aktualisieren</span>
            </div>
            <div class="fx g8" style="flex-wrap:wrap">
              <button class="btn primary" onclick="saveUpdateCheckSchedule()">💾 Update-Prüfung speichern</button>
              <button class="btn ghost" onclick="runUpdateCheckNow()">🔎 Jetzt prüfen</button>
            </div>
          </div>
          </div>
        </div>
      </div>

      <!-- ── Tab: Backups ── -->
      <div id="set-backups" class="stp">
        <div class="g2 mb14">
          <div class="card" style="margin:0">
            <div class="ch"><div class="ct">💾 Backup erstellen</div></div>
            <div class="cb">
              <div class="fr"><label>Bezeichnung (optional)</label><input type="text" id="bk-label" placeholder="z.B. vor_event"></div>
              <button class="btn primary" id="btn-bk-create" onclick="createBackup()">💾 Backup erstellen</button>
              <div id="bk-progress" style="display:none;margin-top:12px"></div>
            </div>
          </div>
          <div class="card" style="margin:0">
            <div class="ch"><div class="ct">⏰ Automatische Backups</div></div>
            <div class="cb">
              <div class="fx ac mb12" style="gap:12px">
                <label class="tgl" style="margin:0">
                  <input type="checkbox" id="bk-sched-on">
                  <span class="tsl"></span>
                </label>
                <span>Tägliches automatisches Backup aktivieren</span>
              </div>
              <div class="fr">
                <label>Uhrzeit</label>
                <input type="time" id="bk-sched-time" value="03:00" style="width:auto">
              </div>
              <button class="btn primary" onclick="saveBackupSchedule()">💾 Zeitplan speichern</button>
            </div>
          </div>
        </div>
        <div class="g2">
          <div class="card" style="margin:0">
            <div class="ch"><div class="ct">📥 Backup importieren</div></div>
            <div class="cb">
              <div class="uz" onclick="document.getElementById('bk-imp').click()" id="bk-drop">
                <div class="ui">📦</div><div>Backup (.tar.gz) hierher ziehen</div>
                <div class="xs2 dim" style="margin-top:3px">oder klicken</div>
                <input type="file" id="bk-imp" accept=".tar.gz,.tgz" onchange="importBk(this)">
              </div>
            </div>
          </div>
          <div class="card" style="margin:0">
            <div class="ch"><div class="ct">📦 Backups</div><div style="display:flex;align-items:center;gap:8px"><label style="font-size:11px;color:var(--text2);margin:0">Max. Backups</label><select id="bk-max-count" class="btn ghost sm" style="padding:4px 7px;font-size:11px;width:auto" onchange="saveBackupMaxCount(this.value)"><option value="5">5</option><option value="10">10</option><option value="15">15</option><option value="20" selected>20</option><option value="25">25</option><option value="30">30</option></select><button class="btn ghost sm" onclick="loadBk()">⟳</button></div></div>
            <div id="bk-list"><div class="cb dim xs2" style="text-align:center;padding:26px">Lade...</div></div>
          </div>
        </div>
      </div>

      <!-- ── Tab: Benutzer & Passwort ── -->
      <div id="set-account" class="stp">
        <div class="card">
          <div class="ch"><div class="ct">👤 Zugangsdaten ändern</div></div>
          <div class="cb">
            <div class="tabs">
              <button class="tb active" onclick="switchSetTab('pw',this)">🔑 Passwort</button>
              <button class="tb" onclick="switchSetTab('user',this)">👤 Benutzername</button>
            </div>
            <div id="set-tab-pw" class="tp active">
              <div class="fr"><label>Aktuelles Passwort</label><input type="password" id="pw-old" autocomplete="current-password"></div>
              <div class="fr"><label>Neues Passwort (min. 6 Zeichen)</label><input type="password" id="pw-new" autocomplete="new-password"></div>
              <div class="fr"><label>Neues Passwort bestätigen</label><input type="password" id="pw-confirm" autocomplete="new-password"></div>
              <button class="btn primary" onclick="changePw()">🔑 Passwort ändern</button>
            </div>
            <div id="set-tab-user" class="tp">
              <div class="fr"><label>Neuer Benutzername (min. 3 Zeichen)</label><input type="text" id="user-new" value="<?=htmlspecialchars($admin_user)?>"></div>
              <div class="fr"><label>Passwort zur Bestätigung</label><input type="password" id="user-pw"></div>
              <button class="btn primary" onclick="changeUser()">👤 Benutzername ändern</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Tab: Discord ── -->
      <div id="set-discord" class="stp">
        <div class="card">
          <div class="ch">
            <div class="ct"><span style="color:#7289da">🎮</span> Discord Webhook</div>
            <button class="btn purp sm" onclick="testDiscord()">🔔 Test</button>
          </div>
          <div class="cb">
            <div class="fr">
              <label>Webhook URL</label>
              <input type="url" id="dc-webhook" placeholder="https://discord.com/api/webhooks/...">
            </div>
            <div class="divider"></div>
            <div class="xs2 dim mb8" style="font-weight:600;text-transform:uppercase;letter-spacing:.05em">Benachrichtigungen</div>
            <div class="set-group" style="margin:0" id="dc-events">
              <?php
              $eventLabels = [
                  'server_start'     => ['▶️ Server gestartet',   'Wenn der Server startet oder neu startet'],
                  'server_stop'      => ['⏹️ Server gestoppt',    'Wenn der Server gestoppt wird'],
                  'player_join'      => ['🟢 Spieler beigetreten','Wenn ein Spieler sich verbindet'],
                  'player_leave'     => ['🔴 Spieler verlassen',  'Wenn ein Spieler sich trennt'],
                  'player_kick'      => ['🚫 Spieler gekickt',    'Wenn ein Spieler gekickt wird'],
                  'backup_created'   => ['💾 Backup erstellt',    'Nach jedem manuellen oder automatischen Backup'],
                  'server_update'    => ['🔄 Server aktualisiert','Wenn ein Server-Update gestartet wird'],
                  'update_available' => ['🔔 Update verfügbar',   'Wenn die automatische Prüfung ein Minecraft- oder Panel-Update findet'],
                  'world_switch'     => ['🌍 Welt gewechselt',    'Wenn die aktive Welt gewechselt wird'],
              ];
              foreach($eventLabels as $key => [$label, $desc]): ?>
              <div class="discord-event">
                <div>
                  <div class="set-label"><?=$label?></div>
                  <div class="set-desc"><?=$desc?></div>
                </div>
                <label class="tgl">
                  <input type="checkbox" class="dc-evt" data-event="<?=$key?>">
                  <span class="tsl"></span>
                </label>
              </div>
              <?php endforeach; ?>
            </div>
            <div style="margin-top:12px">
              <button class="btn primary" onclick="saveDc()">💾 Discord speichern</button>
            </div>
          </div>
        </div>
      </div>

    </div>

    </div><!-- /content -->
  </div><!-- /main -->
</div><!-- /layout -->
<div id="tc"></div>

<script src="assets/app.js?v=22" defer></script>
<?php endif; ?>
</body>
</html>
