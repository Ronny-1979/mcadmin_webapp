<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

session_start();

// ── Backup-Download (vor Auth-Check, da eigener Header) ───────
if (($_GET['action'] ?? '') === 'download_backup') {
    if (empty($_SESSION['authenticated'])) { http_response_code(401); exit; }
    $filename = basename($_GET['filename'] ?? '');
    if (!$filename || !preg_match('/^[a-zA-Z0-9_\-\.]+\.tar\.gz$/', $filename)) { http_response_code(400); exit; }
    $path = MC_BACKUP_DIR . '/' . $filename;
    if (!file_exists($path)) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Backup nicht gefunden']); exit; }
    $realPath = realpath($path); $realBkDir = realpath(MC_BACKUP_DIR);
    if (!$realPath || !$realBkDir || strncmp($realPath, $realBkDir . '/', strlen($realBkDir) + 1) !== 0) { http_response_code(403); exit; }
    $safeFilename = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $filename);
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="'.$safeFilename.'"');
    header('Content-Length: '.filesize($path));
    readfile($path); exit;
}

// ── Ab hier nur JSON-Antworten ────────────────────────────────
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ── Auth-Check ────────────────────────────────────────────────
if (empty($_SESSION['authenticated'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Nicht authentifiziert']); exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ── SERVER ────────────────────────────────────────────
        case 'status':           // Server-Status, Version, aktive Welt, Online-Spieler und Uptime
            $running = server_is_running();
            if ($running) ensure_welcome_daemon();
            echo json_encode([
                'running'      => $running,
                'version'      => get_server_version(),
                'active_world' => get_active_world(),
                'players'      => get_online_players(),
                'uptime'       => get_server_uptime(),
            ]); break;
        case 'server_start':   echo json_encode(server_start());   break;  // Startet den Minecraft-Server
        case 'server_stop':    echo json_encode(server_stop());    break;  // Stoppt den Minecraft-Server
        case 'server_restart': echo json_encode(server_restart()); break;  // Startet den Server neu

        // ── VERSION / UPDATE ──────────────────────────────────
        case 'check_version':    // Vergleicht installierte mit neuester Bedrock-Version
            $latest  = get_latest_bedrock_version();
            $current = get_server_version();
            echo json_encode([
                'current'          => $current,
                'latest'           => $latest['version'],
                'update_available' => $latest['version'] !== 'unbekannt' && $latest['version'] !== $current,
            ]); break;
        case 'server_installed':  // Prüft ob der Bedrock-Server installiert ist
            echo json_encode(['installed'=>server_is_installed(),'version'=>get_server_version()]); break;
        case 'start_update':      // Startet den asynchronen Server-Update-Prozess
            $ver = $_POST['version'] ?? '';
            if (!preg_match('/^\d{1,5}\.\d{1,5}\.\d{1,5}\.\d{1,5}$/', $ver)) { echo json_encode(['success'=>false,'message'=>'Ungültige Version']); break; }
            echo json_encode(run_update_async($ver)); break;
        case 'update_status':     // Gibt den aktuellen Fortschritt des Server-Updates zurück
            echo json_encode(get_update_status()); break;
        case 'check_panel_update':  // Vergleicht installierten Panel-Hash mit GitHub-Stand
            $force   = !empty($_POST['force']) || !empty($_GET['force']);
            $latest  = get_latest_panel_version($force);
            $current = get_panel_version();
            $shaKnown = $latest['sha'] !== 'unbekannt';
            echo json_encode([
                'current'          => $current,
                'latest'           => $latest['sha'],
                'update_available' => $shaKnown && $current !== 'unbekannt' && $latest['sha'] !== $current,
                'has_update_script'=> true,
            ]); break;
        case 'start_panel_update':    // Startet das Panel-Update im Hintergrund
            echo json_encode(run_panel_update_async()); break;
        case 'panel_update_status':   // Gibt den aktuellen Panel-Update-Fortschritt zurück
            echo json_encode(get_panel_update_status()); break;
        case 'get_panel_update_log':  // Gibt die letzten Zeilen des Panel-Update-Logs zurück
            echo json_encode(get_panel_update_log()); break;

        // ── PLAYERS ───────────────────────────────────────────
        case 'op_player':
        case 'deop_player':
        case 'kick_player':
        case 'whitelist_add':
        case 'whitelist_remove': {
            $name = $_POST['name'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9_]{1,16}$/', $name)) {
                echo json_encode(['success'=>false,'message'=>'Ungültiger Spielername']); break;
            }
            if ($action === 'op_player')        { echo json_encode(op_player($name)); break; }        // Erteilt OP-Rechte
            if ($action === 'deop_player')      { echo json_encode(deop_player($name)); break; }      // Entzieht OP-Rechte
            if ($action === 'kick_player')      { echo json_encode(kick_player($name, $_POST['reason']??'Kicked by admin')); break; } // Kickt einen Spieler
            if ($action === 'whitelist_add')    { $ok = whitelist_add($name); echo json_encode(['success'=>$ok,'message'=>$ok?"Zu Whitelist hinzugefügt: $name":"$name ist bereits in der Whitelist"]); break; }
            if ($action === 'whitelist_remove') { echo json_encode(['success'=>whitelist_remove($name),'message'=>"Von Whitelist entfernt: $name"]); break; }
        }
        case 'get_whitelist':    echo json_encode(get_whitelist()); break;    // Gibt die vollständige Whitelist zurück
        case 'get_player_stats': echo json_encode(get_player_stats()); break; // Gibt Spielzeit-Statistiken aller Spieler zurück

        // ── WORLDS ────────────────────────────────────────────
        case 'upload_world':  // Importiert eine .mcworld-Datei inkl. Validierung und Pack-Installation
            if (!isset($_FILES['world']) || $_FILES['world']['error'] !== UPLOAD_ERR_OK) {
                $errCodes = [1=>'Datei zu groß (php.ini)',2=>'Datei zu groß (Formular)',3=>'Teilweise hochgeladen',4=>'Keine Datei',6=>'Kein Temp-Ordner',7=>'Schreibfehler'];
                $code = $_FILES['world']['error'] ?? -1;
                echo json_encode(['success' => false, 'message' => 'Upload fehlgeschlagen: ' . ($errCodes[$code] ?? "Fehler $code")]); break;
            }
            try {
                $force = !empty($_POST['force']);
                echo json_encode(install_world($_FILES['world']['tmp_name'], $_FILES['world']['name'], $force));
            } catch (Throwable $ex) {
                error_log('[mcadmin] ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
                echo json_encode(['success' => false, 'message' => 'Interner Fehler beim Importieren der Welt.']);
            }
            break;
        case 'get_worlds':    echo json_encode(get_worlds()); break;                                    // Gibt alle Welten zurück
        case 'switch_world':  // Wechselt die aktive Welt
            if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $_POST['world']??'')) { echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break; }
            echo json_encode(switch_world($_POST['world']??'')); break;
        case 'create_world':  // Erstellt eine neue Welt mit Name, Spielmodus, Schwierigkeit und Seed
            echo json_encode(create_world($_POST['name']??'', [
                'gamemode'   => $_POST['gamemode']??'0',
                'difficulty' => $_POST['difficulty']??'1',
                'seed'       => $_POST['seed']??'',
            ])); break;
        case 'delete_world':  // Löscht eine Welt
            if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $_POST['world']??'')) { echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break; }
            echo json_encode(delete_world($_POST['world']??'')); break;
        case 'rename_world':  // Benennt eine Welt um
            if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $_POST['world']??'') || !preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $_POST['new_name']??'')) { echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break; }
            echo json_encode(rename_world($_POST['world']??'', $_POST['new_name']??'')); break;

        // ── PROPERTIES ────────────────────────────────────────
        case 'get_properties':  // Gibt die server.properties einer Welt zurück
            $world = $_POST['world'] ?? $_GET['world'] ?? get_active_world();
            if ($world && !preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $world)) {
                echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break;
            }
            echo json_encode(['world'=>$world,'properties'=>get_world_properties($world??'')]); break;
        case 'save_properties':  // Speichert geänderte Properties für eine Welt
            $world = $_POST['world'] ?? '';
            if (!$world) { echo json_encode(['success'=>false,'message'=>'Kein Weltname']); break; }
            if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $world)) {
                echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break;
            }
            $props = json_decode($_POST['properties']??'{}', true);
            if (!is_array($props)) { echo json_encode(['success'=>false,'message'=>'Ungültige Daten']); break; }
            echo json_encode(['success'=>save_world_properties_values($world,$props),'message'=>'Einstellungen gespeichert']); break;

        // ── PACKS ─────────────────────────────────────────────
        case 'get_packs':       // Gibt alle vom Panel installierten Packs zurück (Systempacks ausgeblendet)
            echo json_encode(['behavior'=>get_installed_packs('behavior',true),'resource'=>get_installed_packs('resource',true)]); break;
        case 'get_world_packs': // Gibt die aktiven Packs einer Welt zurück
            echo json_encode(get_world_packs($_POST['world']??get_active_world()??'')); break;
        case 'toggle_pack':     // Aktiviert oder deaktiviert ein Pack für eine Welt
            $world  = $_POST['world']??'';
            $uuid   = strtolower($_POST['uuid']??'');
            $type   = $_POST['type']??'resource';
            $enable = filter_var($_POST['enable']??false, FILTER_VALIDATE_BOOLEAN);
            if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $world))  { echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break; }
            if (!preg_match('/^[a-f0-9\-]{36}$/', $uuid))           { echo json_encode(['success'=>false,'message'=>'Ungültige UUID']);    break; }
            if (!in_array($type, ['behavior','resource']))           { echo json_encode(['success'=>false,'message'=>'Ungültiger Typ']);    break; }
            $ok     = toggle_pack_for_world($world,$uuid,$type,$enable);
            if ($ok) apply_world_packs($world);
            echo json_encode(['success'=>$ok]); break;
        case 'upload_pack':     // Installiert ein hochgeladenes Pack (.mcpack/.mcaddon/.zip)
            if (!isset($_FILES['pack'])||$_FILES['pack']['error']!==UPLOAD_ERR_OK) {
                echo json_encode(['success'=>false,'message'=>'Upload fehlgeschlagen']); break;
            }
            echo json_encode(install_pack($_FILES['pack']['tmp_name'], $_FILES['pack']['name'])); break;
        case 'upload_pack_for_world':  // Installiert Pack und aktiviert es direkt für eine Welt
            $world = $_POST['world'] ?? '';
            if (!$world || !preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $world)) {
                echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break;
            }
            if (!isset($_FILES['pack']) || $_FILES['pack']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success'=>false,'message'=>'Upload fehlgeschlagen']); break;
            }
            echo json_encode(install_pack_for_world($_FILES['pack']['tmp_name'], $_FILES['pack']['name'], $world)); break;
        case 'remove_pack_from_world': // Entfernt ein Pack aus einer Welt (ohne es zu löschen)
            $world = $_POST['world'] ?? '';
            $uuid  = strtolower($_POST['uuid'] ?? '');
            $type  = $_POST['type'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $world)) { echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break; }
            if (!preg_match('/^[a-f0-9\-]{36}$/', $uuid))          { echo json_encode(['success'=>false,'message'=>'Ungültige UUID']); break; }
            if (!in_array($type, ['behavior','resource']))          { echo json_encode(['success'=>false,'message'=>'Ungültiger Typ']); break; }
            echo json_encode(remove_pack_from_world($world, $uuid, $type)); break;
        case 'delete_pack':     // Löscht ein selbst installiertes Pack und entfernt es aus allen Welten
            $uuid = $_POST['uuid'] ?? '';
            $type = $_POST['type'] ?? '';
            if (!$uuid || !in_array($type, ['behavior', 'resource'])) {
                echo json_encode(['success'=>false,'message'=>'Ungültige Parameter']); break;
            }
            echo json_encode(delete_pack($uuid, $type)); break;
        case 'replace_pack':    // Ersetzt ein installiertes Pack durch neue Version, aktualisiert Welt-Referenzen
            $oldUuid = strtolower($_POST['old_uuid'] ?? '');
            $oldType = $_POST['old_type'] ?? '';
            if (!$oldUuid || !in_array($oldType, ['behavior', 'resource'], true)) {
                echo json_encode(['success'=>false,'message'=>'Ungültige Parameter']); break;
            }
            if (!isset($_FILES['pack']) || $_FILES['pack']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success'=>false,'message'=>'Upload fehlgeschlagen']); break;
            }
            echo json_encode(replace_pack($oldUuid, $oldType, $_FILES['pack']['tmp_name'], $_FILES['pack']['name']));
            break;
        case 'supply_missing_pack':  // Installiert fehlendes Pack und repariert die Welt-Referenz
            $world = $_POST['world'] ?? '';
            if (!$world || !preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $world)) {
                echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break;
            }
            if (!isset($_FILES['pack']) || $_FILES['pack']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success'=>false,'message'=>'Upload fehlgeschlagen']); break;
            }
            $before = get_world_packs($world);
            $missBefore = count($before['behavior_missing']??[]) + count($before['resource_missing']??[]);
            $r = install_pack($_FILES['pack']['tmp_name'], $_FILES['pack']['name']);
            if (!$r['success']) { echo json_encode($r); break; }
            get_installed_packs('behavior', false, true);
            get_installed_packs('resource', false, true);
            // Wenn eine bestimmte UUID erwartet wird, sofort prüfen ob das hochgeladene Pack passt
            $expectedUuid = strtolower($_POST['expected_uuid'] ?? '');
            if ($expectedUuid !== '') {
                $matched = false;
                foreach ($r['installed'] ?? [] as $pack) {
                    if (strtolower($pack['uuid']) === $expectedUuid) { $matched = true; break; }
                }
                if (!$matched) {
                    echo json_encode(['success'=>false,'message'=>'Falsches Pack: UUID stimmt nicht überein (erwartet: ' . substr($expectedUuid, 0, 8) . '...)']);
                    break;
                }
            }
            apply_world_packs($world);
            $after = get_world_packs($world);
            $missAfter = count($after['behavior_missing']??[]) + count($after['resource_missing']??[]);
            $resolved = $missBefore - $missAfter;
            if ($resolved > 0) {
                // Neu installierte Packs als "für diese Welt importiert" markieren,
                // damit sie beim Weltlöschen automatisch mit aufgeräumt werden.
                foreach ($r['installed'] ?? [] as $pack) {
                    if (!empty($pack['uuid'])) {
                        track_pack_imported_for_world($world, $pack['uuid'], $pack['type'], $pack['version'] ?? [0,0,0], $pack['folder'] ?? '');
                    }
                }
                echo json_encode(['success'=>true,'message'=>"$resolved fehlendes Pack(s) erfolgreich installiert und verknüpft"]);
            } else {
                // UUID-Match trotz Versions-Abweichung prüfen
                $vmMatches = [];
                foreach ($r['installed'] ?? [] as $newPack) {
                    foreach ([
                        'behavior' => $before['behavior_missing'] ?? [],
                        'resource' => $before['resource_missing'] ?? [],
                    ] as $pt => $missList) {
                        foreach ($missList as $mp) {
                            if (strtolower($mp['uuid']) === strtolower($newPack['uuid'])) {
                                $vmMatches[] = [
                                    'uuid'        => $newPack['uuid'],
                                    'type'        => $pt,
                                    'old_version' => $mp['version'],
                                    'new_version' => implode('.', (array)$newPack['version']),
                                ];
                            }
                        }
                    }
                }
                if (!empty($vmMatches)) {
                    echo json_encode(['success'=>false,'version_mismatch'=>true,'matches'=>$vmMatches,'message'=>'Pack installiert, aber Versions-Referenz weicht ab.']);
                } else {
                    echo json_encode(['success'=>false,'message'=>'Pack installiert, aber UUID stimmt mit keinem fehlenden Pack überein']);
                }
            }
            break;
        case 'fix_pack_version_refs':  // Aktualisiert Versions-Referenzen im State auf installierte Version
            $world = $_POST['world'] ?? '';
            $refs  = json_decode($_POST['refs'] ?? '[]', true);
            if (!$world || !is_array($refs)) {
                echo json_encode(['success'=>false,'message'=>'Ungültige Parameter']); break;
            }
            $fixed = 0;
            foreach ($refs as $ref) {
                $ru = $ref['uuid'] ?? '';
                $rt = $ref['type'] ?? '';
                if ($ru && in_array($rt, ['behavior','resource'],true)) {
                    if (update_pack_ref_version($world, $ru, $rt)) $fixed++;
                }
            }
            // Für Welt-Cleanup tracken
            foreach ($refs as $ref) {
                $inst = find_installed_pack($ref['type']??'resource', $ref['uuid']??'');
                if ($inst && !empty($inst['uuid'])) {
                    track_pack_imported_for_world($world, $inst['uuid'], $inst['type'], $inst['version']??[0,0,0], $inst['folder']??'');
                }
            }
            echo json_encode(['success'=>$fixed>0,'message'=>"$fixed Versions-Referenz(en) aktualisiert"]);
            break;

        // ── BACKUPS ───────────────────────────────────────────
        case 'get_backups':    echo json_encode(get_backups()); break;                                                  // Listet alle Backups auf
        case 'start_backup':   echo json_encode(start_backup_async($_POST['label']??'')); break;                        // Startet ein asynchrones Backup
        case 'backup_status':  echo json_encode(get_backup_status()); break;                                            // Gibt den aktuellen Backup-Fortschritt zurück
        case 'delete_backup': echo json_encode(['success'=>delete_backup($_POST['filename']??'')]); break;              // Löscht ein Backup
        case 'restore_backup':  // Stellt Backup wieder her (Server wird ggf. gestoppt und danach gestartet)
            $wasRunning = server_is_running();
            if ($wasRunning) server_stop();
            $result = restore_backup($_POST['filename']??'');
            if ($wasRunning && ($result['success'] ?? false)) server_start();
            echo json_encode($result); break;
        case 'import_backup':   // Importiert eine externe .tar.gz-Backup-Datei per Upload
            if (!isset($_FILES['backup'])||$_FILES['backup']['error']!==UPLOAD_ERR_OK) {
                echo json_encode(['success'=>false,'message'=>'Upload fehlgeschlagen']); break;
            }
            $importName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($_FILES['backup']['name']));
            if (!str_ends_with(strtolower($importName), '.tar.gz')) {
                echo json_encode(['success'=>false,'message'=>'Nur .tar.gz-Dateien erlaubt']); break;
            }
            $dest = MC_BACKUP_DIR.'/'.$importName;
            if (!move_uploaded_file($_FILES['backup']['tmp_name'], $dest)) {
                echo json_encode(['success'=>false,'message'=>'Datei konnte nicht gespeichert werden']); break;
            }
            echo json_encode(['success'=>true,'message'=>'Backup importiert: '.$importName]); break;

        // ── CONSOLE ───────────────────────────────────────────
        case 'console_send':  // Sendet Befehl an Server-Konsole (max. 2 pro Sekunde)
            $now = microtime(true);
            if (($now - (float)($_SESSION['last_cmd_ts'] ?? 0)) < 0.5) {
                echo json_encode(['success'=>false,'message'=>'Bitte kurz warten']); break;
            }
            $_SESSION['last_cmd_ts'] = $now;
            echo json_encode(console_send($_POST['cmd']??'')); break;
        case 'get_log':      // Gibt die letzten N Log-Zeilen zurück
                $lines = max(20, min(100, (int)($_POST['lines'] ?? 100)));
                 echo json_encode(get_log_lines($lines, 0)); break;

        // ── EINSTELLUNGEN ─────────────────────────────────────
        case 'get_settings':  // Gibt alle Panel-Einstellungen zurück (ohne Passwort-Hash)
            $s = load_settings();
            unset($s['admin_pass_hash']); // Passwort-Hash nie zurückgeben
            echo json_encode($s); break;

        case 'save_discord':  // Speichert Discord-Webhook-URL und Event-Konfiguration
            $s = load_settings();
            $s['discord_webhook'] = trim($_POST['webhook']??'');
            $events = json_decode($_POST['events']??'{}', true);
            if (is_array($events)) $s['discord_events'] = $events;
            echo json_encode(['success'=>save_settings($s),'message'=>'Discord-Einstellungen gespeichert']); break;

        case 'test_discord':  // Sendet eine Test-Nachricht an den angegebenen Discord-Webhook
            $webhook = trim($_POST['webhook']??'');
            if (!$webhook) { echo json_encode(['success'=>false,'message'=>'Kein Webhook angegeben']); break; }
            $ok = discord_test($webhook);
            echo json_encode(['success'=>$ok,'message'=>$ok?'Test-Nachricht gesendet!':'Webhook ungültig oder Discord nicht erreichbar']); break;

        case 'change_password':  // Ändert das Admin-Passwort nach Prüfung des alten Passworts
            $oldPass = $_POST['old_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $s = load_settings();
            if (!password_verify($oldPass, $s['admin_pass_hash']))
                { echo json_encode(['success'=>false,'message'=>'Aktuelles Passwort falsch']); break; }
            if (strlen($newPass) < 6)
                { echo json_encode(['success'=>false,'message'=>'Neues Passwort muss mindestens 6 Zeichen haben']); break; }
            if ($newPass !== $confirm)
                { echo json_encode(['success'=>false,'message'=>'Passwörter stimmen nicht überein']); break; }
            $s['admin_pass_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
            echo json_encode(['success'=>save_settings($s),'message'=>'Passwort geändert']); break;

        case 'save_backup_schedule':  // Speichert den täglichen Backup-Zeitplan
            $s = load_settings();
            $oldTime = $s['backup_schedule_time'] ?? '';
            $s['backup_schedule_enabled'] = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $time = $_POST['time'] ?? '03:00';
            $s['backup_schedule_time'] = preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '03:00';
            // Uhrzeit geändert → Tagessperre aufheben damit Backup heute noch läuft
            if ($s['backup_schedule_time'] !== $oldTime) {
                $s['backup_last_date'] = '';
            }
            echo json_encode(['success' => save_settings($s), 'message' => 'Backup-Zeitplan gespeichert']); break;

        case 'save_backup_max_count':  // Speichert das maximale Backup-Limit
            $s = load_settings();
            $count = (int)($_POST['count'] ?? 20);
            $allowed = [5, 10, 15, 20, 25, 30];
            $s['backup_max_count'] = in_array($count, $allowed) ? $count : 20;
            echo json_encode(['success' => save_settings($s), 'message' => 'Limit gespeichert']); break;

        case 'save_restart_schedule':  // Speichert den täglichen Auto-Restart-Zeitplan
            $s = load_settings();
            $oldTime = $s['restart_schedule_time'] ?? '';
            $s['restart_schedule_enabled'] = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $time = $_POST['time'] ?? '06:00';
            $s['restart_schedule_time'] = preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '06:00';
            // Uhrzeit geändert → Tagessperre aufheben damit Neustart heute noch läuft
            if ($s['restart_schedule_time'] !== $oldTime) {
                $s['restart_last_date'] = '';
            }
            echo json_encode(['success' => save_settings($s), 'message' => 'Restart-Zeitplan gespeichert']); break;

        case 'save_update_check_schedule':  // Speichert die tägliche Update-Prüfung für Minecraft + Panel
            $s = load_settings();
            $s['update_check_enabled'] = filter_var($_POST['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $time = $_POST['time'] ?? '04:00';
            $s['update_check_time']    = preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '04:00';
            $s['auto_install_mc']      = filter_var($_POST['auto_install_mc']    ?? false, FILTER_VALIDATE_BOOLEAN);
            $s['auto_install_panel']   = filter_var($_POST['auto_install_panel'] ?? false, FILTER_VALIDATE_BOOLEAN);
            echo json_encode(['success' => save_settings($s), 'message' => 'Update-Prüfung gespeichert']); break;

        case 'run_update_check_now':  // Manuelle Update-Prüfung inkl. Discord-Hinweis bei neuem Update
            echo json_encode(run_update_availability_check(true)); break;

        case 'change_username':  // Ändert den Admin-Benutzernamen nach Passwort-Bestätigung
            $newUser = trim($_POST['username']??'');
            $pass    = $_POST['password']??'';
            $s = load_settings();
            if (!password_verify($pass, $s['admin_pass_hash']))
                { echo json_encode(['success'=>false,'message'=>'Passwort falsch']); break; }
            if (strlen($newUser) < 3)
                { echo json_encode(['success'=>false,'message'=>'Benutzername muss mindestens 3 Zeichen haben']); break; }
            $s['admin_user'] = $newUser;
            $saved = save_settings($s);
            if ($saved) $_SESSION['admin_user'] = $newUser;
            echo json_encode(['success'=>$saved,'message'=>$saved?'Benutzername geändert':'Fehler beim Speichern']); break;

        // ── WILLKOMMENSNACHRICHT ──────────────────────────────
        case 'get_welcome_message':
            $world = $_POST['world'] ?? $_GET['world'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $world)) {
                echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break;
            }
            echo json_encode(['success'=>true,'message'=>get_welcome_message($world)]); break;

        case 'save_welcome_message':
            $world = $_POST['world'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $world)) {
                echo json_encode(['success'=>false,'message'=>'Ungültiger Weltname']); break;
            }
            $msg = substr(str_replace("\r\n", "\n", str_replace("\0", '', $_POST['message'] ?? '')), 0, 2000);
            echo json_encode(['success'=>save_welcome_message($world,$msg),'message'=>'Willkommensnachricht gespeichert']); break;

        default:
            http_response_code(400);
            echo json_encode(['success'=>false,'message'=>'Unbekannte Aktion: '.$action]);
    }
} catch (Throwable $e) {
    error_log('[mcadmin] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode(['error'=>'Interner Serverfehler']);
}
