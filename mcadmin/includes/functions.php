<?php
require_once __DIR__ . '/../config.php';

// Prüft ob eine Discord-Webhook-URL das korrekte Format hat
function is_valid_discord_webhook(string $url): bool {
    return (bool) preg_match('#^https://discord\.com/api/webhooks/\d+/[\w-]+$#', $url);
}

// ============================================================
// SERVER STATUS & CONTROL
// ============================================================

// Prüft ob der Minecraft-Server aktuell über systemctl aktiv ist
function server_is_running(): bool {
    $out = shell_exec('systemctl is-active ' . escapeshellarg(MC_SERVICE_NAME) . ' 2>/dev/null');
    return trim($out ?? '') === 'active';
}

// Gibt die Laufzeit des Servers als lesbaren String zurück (z.B. "2h 15m"), leer wenn offline
function get_server_uptime(): string {
    if (!server_is_running()) return '';
    $out = shell_exec('systemctl show ' . escapeshellarg(MC_SERVICE_NAME) . ' --property=ActiveEnterTimestamp 2>/dev/null');
    if (!preg_match('/ActiveEnterTimestamp=(.+)/', $out ?? '', $m)) return '';
    $ts = strtotime(trim($m[1]));
    if (!$ts || $ts <= 0) return '';
    $diff = max(0, time() - $ts);
    $d  = intdiv($diff, 86400);
    $h  = intdiv($diff % 86400, 3600);
    $mi = intdiv($diff % 3600, 60);
    $parts = [];
    if ($d > 0) $parts[] = $d . ' Tag' . ($d > 1 ? 'e' : '');
    if ($h > 0) $parts[] = $h . ' Std.';
    $parts[] = $mi . ' Min.';
    return implode(', ', $parts);
}

// Startet den Minecraft-Server via systemctl und sendet Discord-Benachrichtigung
function server_start(): array {
    // Packs und Experimente (z.B. holiday_creator_features) vor dem Start sicherstellen
    $activeWorld = get_active_world();
    if ($activeWorld) apply_world_packs($activeWorld);

    exec('sudo systemctl start ' . escapeshellarg(MC_SERVICE_NAME) . ' 2>&1', $out, $code);
    if ($code === 0) {
        discord_notify('server_start', '▶️ **Server gestartet**');
        start_welcome_daemon();
    }
    return ['success' => $code === 0, 'output' => implode("\n", $out)];
}

function kill_stale_server_procs(): void {
    // Kill any bedrock_server processes running outside systemd (e.g. orphaned screen/tmux sessions)
    shell_exec('pkill -f bedrock_server 2>/dev/null');
    shell_exec('screen -S minecraft -X quit 2>/dev/null');
    shell_exec('tmux kill-session -t minecraft 2>/dev/null');
    sleep(1);
}

// Stoppt den Server und beendet verwaiste Prozesse (screen/tmux)
function server_stop(): array {
    exec('sudo systemctl stop ' . escapeshellarg(MC_SERVICE_NAME) . ' 2>&1', $out, $code);
    kill_stale_server_procs();
    stop_welcome_daemon();
    if ($code === 0) discord_notify('server_stop', '⏹️ **Server gestoppt**');
    return ['success' => $code === 0, 'output' => implode("\n", $out)];
}

// Stoppt und startet den Server neu, mit bis zu 3 Versuchen bei Port-Konflikten
function server_restart(): array {
    set_time_limit(120);

    // Packs und Experimente (z.B. holiday_creator_features) vor dem Neustart sicherstellen
    $activeWorld = get_active_world();
    if ($activeWorld) apply_world_packs($activeWorld);

    exec('sudo systemctl stop ' . escapeshellarg(MC_SERVICE_NAME) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        return ['success' => false, 'output' => implode("\n", $out)];
    }
    kill_stale_server_procs();
    stop_welcome_daemon();
    $out2 = [];
    $code2 = 1;
    // Start and verify the server survived the first few seconds (port-conflict crashes happen within ~3 s).
    // Retry up to 3 times with increasing delay so the OS has time to release the UDP sockets.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        sleep($attempt === 0 ? 2 : 5);
        exec('sudo systemctl start ' . escapeshellarg(MC_SERVICE_NAME) . ' 2>&1', $out2, $code2);
        if ($code2 !== 0) break;
        sleep(4);
        $active = trim(shell_exec('systemctl is-active ' . escapeshellarg(MC_SERVICE_NAME) . ' 2>/dev/null') ?? '');
        if ($active === 'active') {
            discord_notify('server_start', '↺ **Server neu gestartet**');
            start_welcome_daemon();
            return ['success' => true, 'output' => implode("\n", array_merge($out, $out2))];
        }
        exec('sudo systemctl stop ' . escapeshellarg(MC_SERVICE_NAME) . ' 2>&1');
        kill_stale_server_procs();
    }
    return ['success' => false, 'output' => implode("\n", array_merge($out, $out2))];
}

// Startet den Willkommens-Daemon im Hintergrund (nohup)
function start_welcome_daemon(): void {
    stop_welcome_daemon();
    $script = dirname(MC_STATE_FILE) . '/welcome_daemon.sh';
    if (!file_exists($script)) return;
    exec('setsid nohup bash ' . escapeshellarg($script) . ' < /dev/null > /dev/null 2>&1 &');
}

// Beendet den Willkommens-Daemon anhand von PID-Datei und Prozessname
function stop_welcome_daemon(): void {
    shell_exec('pkill -f "welcome_daemon.sh" 2>/dev/null');
    $pidFile = '/tmp/mcadmin_welcome.pid';
    if (file_exists($pidFile)) {
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid > 0) shell_exec('pkill -P ' . $pid . ' 2>/dev/null');
        @unlink($pidFile);
    }
}

// Startet den Willkommens-Daemon nur wenn er nicht bereits läuft (kein Kill)
function ensure_welcome_daemon(): void {
    $pidFile = '/tmp/mcadmin_welcome.pid';
    if (file_exists($pidFile)) {
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid > 0 && file_exists('/proc/' . $pid)) return;
        @unlink($pidFile);
    }
    $script = dirname(MC_STATE_FILE) . '/welcome_daemon.sh';
    if (!file_exists($script)) return;
    exec('setsid nohup bash ' . escapeshellarg($script) . ' < /dev/null > /dev/null 2>&1 &');
}

// Liest die Willkommensnachricht einer Welt
function get_welcome_message(string $world): string {
    $file = MC_WORLDS_DIR . '/' . $world . '/.mcadmin_welcome.txt';
    if (!file_exists($file)) return '';
    return file_get_contents($file) ?: '';
}

// Speichert die Willkommensnachricht einer Welt (leerer String löscht die Datei)
function save_welcome_message(string $world, string $msg): bool {
    $dir = MC_WORLDS_DIR . '/' . $world;
    if (!is_dir($dir)) return false;
    $file = $dir . '/.mcadmin_welcome.txt';
    if (trim($msg) === '') { @unlink($file); return true; }
    return file_put_contents($file, $msg) !== false;
}

// Sendet einen Befehl an die laufende Server-Konsole via FIFO, screen oder tmux
function server_send_command(string $cmd): array {
    $fifo = MC_SERVER_DIR . '/server.stdin';
    if (file_exists($fifo) && filetype($fifo) === 'fifo') {
        $fp = @fopen($fifo, 'w');
        if ($fp !== false) {
            fwrite($fp, $cmd . "\n");
            fclose($fp);
            return ['success' => true, 'output' => 'Befehl gesendet'];
        }
    }
    exec('screen -S minecraft -X stuff ' . escapeshellarg($cmd . "\n") . ' 2>&1', $out, $code);
    if ($code !== 0) exec('tmux send-keys -t minecraft ' . escapeshellarg($cmd) . ' Enter 2>&1', $out, $code);
    return ['success' => $code === 0, 'output' => implode("\n", $out)];
}

// Prüft ob die bedrock_server-Datei vorhanden und ausführbar ist
function server_is_installed(): bool {
    return file_exists(MC_SERVER_EXECUTABLE) && is_executable(MC_SERVER_EXECUTABLE);
}

// Gibt die installierte Bedrock-Server-Version zurück (aus version.txt oder Binary)
function get_server_version(): string {
    if (!file_exists(MC_SERVER_EXECUTABLE)) return 'nicht installiert';
    $vf = MC_SERVER_DIR . '/version.txt';
    clearstatcache(true, $vf);
    if (file_exists($vf)) return trim(file_get_contents($vf));
    $out = shell_exec(escapeshellarg(MC_SERVER_EXECUTABLE) . ' --version 2>/dev/null | head -1');
    preg_match('/(\d+\.\d+\.\d+\.\d+)/', $out ?? '', $m);
    return $m[1] ?? 'unbekannt';
}

// Prüft eingebettete Pack-Manifeste und Experiment-Flags der Welt auf BDS-Versions-Kompatibilität
function check_world_compat_issues(string $worldRoot, string $bdVersion): array {
    $bdParts      = array_map('intval', array_slice(explode('.', $bdVersion), 0, 3));
    $versionKnown = ($bdParts[0] > 0); // "unbekannt"/"nicht installiert" → [0,0,0] → kein Pack-Check
    $issues       = [];

    if ($versionKnown) {
        foreach (['behavior_packs', 'resource_packs'] as $packDir) {
            $base = $worldRoot . '/' . $packDir;
            if (!is_dir($base)) continue;
            foreach (array_diff((array)@scandir($base), ['.', '..']) as $pack) {
                $manifest = $base . '/' . $pack . '/manifest.json';
                if (!file_exists($manifest)) continue;
                $data   = json_decode((string)file_get_contents($manifest), true);
                $minVer = $data['header']['min_engine_version'] ?? null;
                if (!is_array($minVer) || count($minVer) < 2) continue;
                $minParts = array_map('intval', array_slice($minVer, 0, 3));
                foreach ([0, 1, 2] as $i) {
                    $bd  = $bdParts[$i]  ?? 0;
                    $min = $minParts[$i] ?? 0;
                    if ($min > $bd) {
                        $issues[] = [
                            'type'      => $packDir === 'behavior_packs' ? 'Behavior' : 'Resource',
                            'pack'      => $data['header']['name'] ?? $pack,
                            'requires'  => implode('.', $minParts),
                            'installed' => implode('.', $bdParts),
                        ];
                        break;
                    }
                    if ($bd > $min) break;
                }
            }
        }
    }

    $experiments = get_world_experiments($worldRoot);
    return ['issues' => $issues, 'experiments' => $experiments];
}

// ============================================================
// DISCORD WEBHOOK
// ============================================================

// Sendet eine Discord-Embed-Nachricht für ein bestimmtes Ereignis (Start, Backup usw.)
function discord_notify(string $event, string $message, array $fields = []): void {
    $settings = load_settings();
    $webhook  = $settings['discord_webhook'] ?? '';
    $events   = $settings['discord_events']  ?? [];
    if (!$webhook || !is_valid_discord_webhook($webhook) || empty($events[$event])) return;

    $colors = [
        'server_start'   => 0x4ade80,
        'server_stop'    => 0xf87171,
        'player_join'    => 0x60a5fa,
        'player_leave'   => 0x94a3b8,
        'player_kick'    => 0xfbbf24,
        'backup_created' => 0xa78bfa,
        'server_update'     => 0xfbbf24,
        'update_available' => 0xfbbf24,
        'world_switch'      => 0x60a5fa,
    ];

    $payload = [
        'embeds' => [[
            'description' => $message,
            'color'       => $colors[$event] ?? 0x5865f2,
            'footer'      => ['text' => 'MC Bedrock Admin · ' . date('d.m.Y H:i:s')],
            'fields'      => $fields,
        ]],
    ];

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    @file_get_contents($webhook, false, $ctx);
}

// Sendet eine Test-Nachricht an den angegebenen Webhook und prüft den HTTP-Statuscode
function discord_test(string $webhook): bool {
    $payload = ['embeds' => [[
        'description' => '✅ **Verbindung erfolgreich!**',
        'color'       => 0x4ade80,
        'footer'      => ['text' => 'MC Bedrock Admin · Test-Nachricht'],
    ]]];
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    if (!is_valid_discord_webhook($webhook)) return false;
    $result = @file_get_contents($webhook, false, $ctx);
    $code   = $http_response_header[0] ?? '';
    return str_contains($code, '204') || str_contains($code, '200');
}

// ============================================================
// ONLINE PLAYERS
// ============================================================

// Gibt alle aktuell eingeloggten Spieler zurück (aus Logdatei oder journalctl)
function get_online_players(): array {
    $lines = [];

    // Erst normale Logdateien versuchen
    foreach (get_log_files() as $f) {
        if (file_exists($f)) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            break;
        }
    }

    // Fallback auf journalctl/systemd
    if (empty($lines)) {
        $out = shell_exec(
            'journalctl -u ' . escapeshellarg(MC_SERVICE_NAME) .
            ' -n 1000 --no-pager --output=short 2>/dev/null'
        );

        if ($out) {
            $lines = array_filter(explode("\n", $out));
        }
    }

    if (empty($lines)) return [];

    $players = [];

    // Rückwärts lesen = neueste Einträge zuerst
    foreach (array_reverse(array_slice($lines, -1000)) as $line) {

        // Spieler verbunden
        if (preg_match('/Player connected:\s*([^,]+),\s*xuid:\s*([0-9]+)/i', $line, $m)) {

            $name = trim($m[1]);

            // Nur ersten (also neuesten) Status übernehmen
            if (!isset($players[$name])) {
                $players[$name] = [
                    'name'   => $name,
                    'xuid'   => trim($m[2]),
                    'status' => 'online'
                ];
            }

            continue;
        }

        // Spieler getrennt
        if (preg_match('/Player disconnected:\s*([^,]+)/i', $line, $m)) {

            $name = trim($m[1]);

            // Nur setzen wenn noch kein neuerer Connect gefunden wurde
            if (!isset($players[$name])) {
                $players[$name] = [
                    'name'   => $name,
                    'xuid'   => '',
                    'status' => 'offline'
                ];
            }
        }
    }

    $online = array_values(array_filter($players, fn($p) => $p['status'] === 'online'));
    $opXuids = [];
    foreach (get_permissions() as $p) {
        if (($p['permission'] ?? '') === 'operator' && !empty($p['xuid'])) {
            $opXuids[$p['xuid']] = true;
        }
    }
    $wlNames = array_column(_wl_raw(), 'name');
    foreach ($online as &$p) {
        $p['is_op']          = !empty($p['xuid']) && isset($opXuids[$p['xuid']]);
        $p['is_whitelisted'] = in_array($p['name'], $wlNames, true);
    }
    return $online;
}

// ============================================================
// WHITELIST & PERMISSIONS
// ============================================================

// Liest die rohe whitelist.json und gibt sie als Array zurück
function _wl_raw(): array {
    if (!file_exists(MC_WHITELIST_FILE)) return [];
    return json_decode(file_get_contents(MC_WHITELIST_FILE), true) ?? [];
}

// Gibt die Whitelist zurück, angereichert mit dem OP-Status jedes Spielers
function get_whitelist(): array {
    $list = _wl_raw();
    $opXuids = [];
    foreach (get_permissions() as $p) {
        if (($p['permission'] ?? '') === 'operator' && !empty($p['xuid'])) {
            $opXuids[$p['xuid']] = true;
        }
    }
    return array_map(function($entry) use ($opXuids) {
        $entry['is_op'] = !empty($entry['xuid']) && isset($opXuids[$entry['xuid']]);
        return $entry;
    }, $list);
}

// Fügt einen Spieler zur Whitelist hinzu und lädt sie im laufenden Server neu
function whitelist_add(string $name, string $xuid = ''): bool {
    $list = _wl_raw();
    foreach ($list as $p) {
        if (strtolower($p['name']) === strtolower($name)) return false;
    }
    $list[] = ['ignoresPlayerLimit' => false, 'name' => $name, 'xuid' => $xuid];
    $ok = file_put_contents(MC_WHITELIST_FILE, json_encode($list, JSON_PRETTY_PRINT)) !== false;
    if ($ok) server_send_command('whitelist reload');
    return $ok;
}

// Entfernt einen Spieler aus der Whitelist und lädt sie neu
function whitelist_remove(string $name): bool {
    $list = array_values(array_filter(_wl_raw(),
        fn($p) => strtolower($p['name']) !== strtolower($name)));
    $ok = file_put_contents(MC_WHITELIST_FILE, json_encode($list, JSON_PRETTY_PRINT)) !== false;
    if ($ok) server_send_command('whitelist reload');
    return $ok;
}

// Liest permissions.json und gibt sie als Array zurück
function get_permissions(): array {
    if (!file_exists(MC_PERMISSIONS_FILE)) return [];
    return json_decode(file_get_contents(MC_PERMISSIONS_FILE), true) ?? [];
}

// Sucht die XUID eines Spielers in der Whitelist oder unter den Online-Spielern
function _find_player_xuid(string $name): string {
    foreach (_wl_raw() as $p) {
        if (strtolower($p['name'] ?? '') === strtolower($name) && !empty($p['xuid'])) {
            return $p['xuid'];
        }
    }
    foreach (get_online_players() as $p) {
        if (strtolower($p['name'] ?? '') === strtolower($name) && !empty($p['xuid'])) {
            return $p['xuid'];
        }
    }
    return '';
}

// Setzt OP-Rechte für einen Spieler in permissions.json und sendet den op-Befehl
function op_player(string $name): array {
    $xuid = _find_player_xuid($name);
    if (!$xuid) {
        return ['success' => false, 'output' => "XUID für '$name' nicht gefunden — Spieler muss mindestens einmal verbunden oder auf der Whitelist sein"];
    }
    $perms = get_permissions();
    $perms = array_values(array_filter($perms, fn($p) => ($p['xuid'] ?? '') !== $xuid));
    $perms[] = ['permission' => 'operator', 'xuid' => $xuid];
    $ok = file_put_contents(MC_PERMISSIONS_FILE, json_encode($perms, JSON_PRETTY_PRINT)) !== false;
    if ($ok) server_send_command("op $name");
    return ['success' => $ok, 'output' => $ok ? "OP-Rechte für '$name' gesetzt" : 'Fehler beim Schreiben von permissions.json'];
}

// Entfernt OP-Rechte eines Spielers aus permissions.json
function deop_player(string $name): array {
    $xuid = _find_player_xuid($name);
    $perms = get_permissions();
    $new = array_values(array_filter($perms, fn($p) => !($xuid && ($p['xuid'] ?? '') === $xuid)));
    if (count($new) === count($perms)) {
        return ['success' => false, 'output' => "'$name' hat keine OP-Rechte"];
    }
    $ok = file_put_contents(MC_PERMISSIONS_FILE, json_encode($new, JSON_PRETTY_PRINT)) !== false;
    if ($ok) server_send_command("deop $name");
    return ['success' => $ok, 'output' => $ok ? "OP-Rechte für '$name' entfernt" : 'Fehler beim Schreiben von permissions.json'];
}

// Kickt einen Spieler mit Grund und sendet Discord-Benachrichtigung
function kick_player(string $name, string $reason = 'Kicked by admin'): array {
    $r = server_send_command("kick $name $reason");
    if ($r['success']) discord_notify('player_kick',
        "🚫 **{$name} wurde gekickt**",
        [['name' => 'Grund', 'value' => $reason, 'inline' => true]]);
    return $r;
}

// ============================================================
// SERVER.PROPERTIES
// ============================================================

// Liest eine server.properties-Datei und gibt Einträge strukturiert zurück (inkl. Kommentare und Leerzeilen)
function parse_properties(string $file): array {
    $entries = [];
    if (!file_exists($file)) return $entries;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (trim($line) === '') {
            $entries[] = ['type' => 'blank', 'raw' => ''];
        } elseif (str_starts_with(ltrim($line), '#')) {
            $entries[] = ['type' => 'comment', 'raw' => $line];
        } else {
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $entries[] = ['type' => 'property', 'raw' => $line, 'key' => trim($k), 'value' => trim($v)];
        }
    }
    return $entries;
}

// Serialisiert ein Array von Property-Einträgen zurück in den Datei-String
function serialize_properties(array $entries): string {
    return implode("\n", array_map(fn($e) =>
        $e['type'] === 'property' ? "{$e['key']}={$e['value']}" : $e['raw'],
    $entries)) . "\n";
}

// Gibt alle Key-Value-Paare einer Properties-Datei als assoziatives Array zurück
function get_all_properties(?string $file = null): array {
    $file ??= MC_PROPERTIES_FILE;
    $props = [];
    foreach (parse_properties($file) as $e) {
        if ($e['type'] === 'property') $props[$e['key']] = $e['value'];
    }
    return $props;
}

// Gibt den Wert eines einzelnen Properties-Schlüssels zurück
function get_server_property(string $key, ?string $file = null): ?string {
    return get_all_properties($file)[$key] ?? null;
}

// Schreibt neue Werte in eine Properties-Datei; unbekannte Keys werden angehängt
function set_properties(array $newValues, ?string $file = null): bool {
    $file ??= MC_PROPERTIES_FILE;
    if (!file_exists($file)) {
        $lines = [];
        foreach ($newValues as $k => $v) $lines[] = "$k=$v";
        return file_put_contents($file, implode("\n", $lines) . "\n") !== false;
    }
    $entries = parse_properties($file);
    $written = [];
    foreach ($entries as &$e) {
        if ($e['type'] === 'property' && array_key_exists($e['key'], $newValues)) {
            $e['value'] = str_replace(["\r", "\n"], '', $newValues[$e['key']]);
            $written[$e['key']] = true;
        }
    }
    unset($e);
    foreach ($newValues as $k => $v) {
        if (!isset($written[$k])) $entries[] = ['type' => 'property', 'raw' => '', 'key' => $k, 'value' => str_replace(["\r", "\n"], '', $v)];
    }
    return file_put_contents($file, serialize_properties($entries)) !== false;
}

// Setzt einen einzelnen Schlüssel in server.properties
function set_server_property(string $key, string $value, ?string $file = null): bool {
    return set_properties([$key => $value], $file);
}

// Gibt den Pfad zur welt-eigenen .server.properties-Datei zurück
function world_properties_file(string $worldName): string {
    return MC_WORLDS_DIR . '/' . $worldName . '/.server.properties';
}

// Kopiert die aktive server.properties in die welt-eigene Sicherungsdatei
function save_world_properties(string $worldName): bool {
    if (!file_exists(MC_PROPERTIES_FILE)) return false;
    return copy(MC_PROPERTIES_FILE, world_properties_file($worldName));
}

// Lädt die welt-eigene Properties-Datei in die aktive server.properties
function load_world_properties(string $worldName): bool {
    $src = world_properties_file($worldName);
    if (file_exists($src)) {
        if (!copy($src, MC_PROPERTIES_FILE)) return false;
        set_server_property('level-name', $worldName);
        return true;
    }
    return set_server_property('level-name', $worldName);
}

// Gibt die Properties einer Welt zurück (welt-eigene Datei oder aktive server.properties)
function get_world_properties(string $worldName): array {
    $file   = world_properties_file($worldName);
    $active = get_active_world();
    if ($worldName === $active || !file_exists($file)) return get_all_properties();
    return get_all_properties($file);
}

// Speichert geänderte Property-Werte für eine Welt (auch in die aktive Datei, falls aktiv)
function save_world_properties_values(string $worldName, array $values): bool {
    $values['level-name'] = $worldName;
    $worldFile = world_properties_file($worldName);
    $worldDir  = MC_WORLDS_DIR . '/' . $worldName;
    if (is_dir($worldDir)) {
        if (!file_exists($worldFile) && file_exists(MC_PROPERTIES_FILE))
            copy(MC_PROPERTIES_FILE, $worldFile);
        if (!set_properties($values, file_exists($worldFile) ? $worldFile : MC_PROPERTIES_FILE)) return false;
    }
    if ($worldName === get_active_world()) return set_properties($values, MC_PROPERTIES_FILE);
    return true;
}



// ============================================================
// WORLD MANAGEMENT
// ============================================================


// Konvertiert Item-JSON-Dateien eines Behavior Packs von altem format_version (< "1.20")
// auf "1.20.80". Ab BDS 1.21.20 wurde das "Holiday Creator Features"-Experiment entfernt,
// das ältere format_version-Items aktivierte. In 1.20.80+ laden Custom Items ohne Experiment.
// Entfernt auch veraltete Event-Trigger-Komponenten die in 1.20+ nicht mehr existieren.
function upgrade_legacy_item_formats(string $packDir): void {
    $itemsDir = $packDir . '/items';
    if (!is_dir($itemsDir)) return;

    // Item-Event-Komponenten die in format_version 1.20+ nicht mehr existieren
    $obsoleteComponents = [
        'minecraft:on_use', 'minecraft:on_hurt_entity',
        'minecraft:on_player_destroyed', 'minecraft:on_interact',
    ];

    foreach ((array)@glob($itemsDir . '/*.json') as $itemFile) {
        $raw = @file_get_contents($itemFile);
        if ($raw === false) continue;
        $data = json_decode($raw, true);
        if (!is_array($data)) continue;
        $fv = (string)($data['format_version'] ?? '');
        if ($fv === '' || version_compare($fv, '1.20', '>=')) continue;

        $data['format_version'] = '1.20.80';

        // 1.10-style Item-Events entfernen (in 1.20.80+ durch Script-API ersetzt)
        unset($data['minecraft:item']['events']);
        foreach ($obsoleteComponents as $comp) {
            unset($data['minecraft:item']['components'][$comp]);
        }

        file_put_contents(
            $itemFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );
    }
}

// Liest die letzte Spielerposition aus der Bedrock-LevelDB (~local_player → TAG_List "Pos")
// Gibt ['x'=>int, 'y'=>int, 'z'=>int] zurück oder null wenn nicht verfügbar.
// Benötigt Python 3 + plyvel auf dem Server (optional, kein harter Fehler bei Fehlen).
function read_bedrock_local_player_pos(string $dbPath): ?array {
    if (!is_dir($dbPath)) return null;
    $py = trim((string)shell_exec('command -v python3 2>/dev/null'));
    if ($py === '') return null;

    $script = <<<'PYEOF'
import sys, struct, json
try:
    import plyvel
    db = plyvel.DB(sys.argv[1])
    val = db.get(b'~local_player')
    db.close()
    if not val: sys.exit(0)
    pat = b'\x09\x03\x00Pos'
    pi = val.find(pat)
    if pi == -1: sys.exit(0)
    off = pi + len(pat)
    et = val[off]; cnt = struct.unpack('<i', val[off+1:off+5])[0]; off += 5
    c = []
    if et == 5:
        for _ in range(cnt): c.append(struct.unpack('<f', val[off:off+4])[0]); off+=4
    elif et == 6:
        for _ in range(cnt): c.append(struct.unpack('<d', val[off:off+8])[0]); off+=8
    if len(c) == 3:
        print(json.dumps({'x': int(round(c[0])), 'y': int(round(c[1])), 'z': int(round(c[2]))}))
except Exception:
    pass
PYEOF;

    $tmpBase   = tempnam(sys_get_temp_dir(), 'mcadmin_plv');
    $tmpScript = $tmpBase . '.py';
    @unlink($tmpBase); // Platzhalter-Datei entfernen, nur .py-Datei verwenden
    file_put_contents($tmpScript, $script);
    $out = shell_exec(escapeshellarg($py) . ' ' . escapeshellarg($tmpScript) . ' ' . escapeshellarg($dbPath) . ' 2>/dev/null');
    @unlink($tmpScript);

    $data = json_decode(trim((string)$out), true);
    if (is_array($data) && isset($data['x'], $data['y'], $data['z'])) {
        // Plausibilitätsprüfung: Bedrock-Welt geht von Y=-64 bis Y=320
        if ($data['y'] >= -64 && $data['y'] <= 320) return $data;
    }
    return null;
}

// Importiert eine .mcworld-Datei: entpackt, validiert, kopiert Packs und legt die Welt an
function install_world(string $tmpPath, string $originalName, bool $force = false): array {
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'mcworld') {
        return ['success' => false, 'message' => 'Nur .mcworld-Dateien erlaubt'];
    }

    // 1. In temp-Verzeichnis entpacken
    $tmpDir = sys_get_temp_dir() . '/mcworld_' . uniqid();
    if (!extract_zip($tmpPath, $tmpDir)) {
        exec('rm -rf ' . escapeshellarg($tmpDir));
        return ['success' => false, 'message' => 'Fehler beim Entpacken der .mcworld-Datei'];
    }

    // 2. Welt-Root finden: flache Struktur (db/ direkt drin) oder einzelner Unterordner
    $worldRoot = null;
    if (is_dir($tmpDir . '/db')) {
        $worldRoot = $tmpDir;
    } else {
        foreach (array_diff((array)@scandir($tmpDir), ['.', '..']) as $entry) {
            $sub = $tmpDir . '/' . $entry;
            if (is_dir($sub) && is_dir($sub . '/db')) { $worldRoot = $sub; break; }
        }
    }
    if ($worldRoot === null) {
        $isJava = is_dir($tmpDir . '/dimensions') || is_dir($tmpDir . '/region');
        if (!$isJava) {
            foreach (array_diff((array)@scandir($tmpDir), ['.', '..']) as $e) {
                $s = $tmpDir . '/' . $e;
                if (is_dir($s) && (is_dir($s . '/dimensions') || is_dir($s . '/region'))) { $isJava = true; break; }
            }
        }
        exec('rm -rf ' . escapeshellarg($tmpDir));
        return ['success' => false, 'message' => $isJava
            ? 'Java Edition erkannt — dieser Server nutzt Bedrock Edition. Java-Welten sind nicht kompatibel.'
            : 'Kein gültiges Bedrock-World (kein db/-Verzeichnis gefunden)'];
    }

    if (!$force) {
        $bdVer     = get_server_version();
        $compat    = check_world_compat_issues($worldRoot, $bdVer);
        $hasIssues = !empty($compat['issues']);
        $hasExps   = !empty($compat['experiments']);
        if ($hasIssues || $hasExps) {
            exec('rm -rf ' . escapeshellarg($tmpDir));
            return [
                'success'               => false,
                'compatibility_warning' => true,
                'soft_warning'          => !$hasIssues, // nur Experiments → kein harter Versions-Konflikt
                'issues'                => $compat['issues'],
                'experiments'           => $compat['experiments'],
                'message'               => 'Mögliche Versions-Kompatibilitätsprobleme gefunden.',
            ];
        }
    }
	


    // 3. Weltname: levelname.txt → Dateiname, §Farb-Codes entfernen, Sonderzeichen bereinigen
    $worldName = '';
    if (file_exists($worldRoot . '/levelname.txt')) {
        $worldName = trim((string)file_get_contents($worldRoot . '/levelname.txt'));
        $worldName = trim(preg_replace('/§[0-9a-fk-orA-FK-OR]/u', '', $worldName));
    }
    if ($worldName === '') $worldName = pathinfo($originalName, PATHINFO_FILENAME);
    $worldName = trim(preg_replace('/[^a-zA-Z0-9 _\-]/', '_', $worldName), '_ ');
    $worldName = (string)preg_replace('/_+/', '_', $worldName);
    if ($worldName === '') $worldName = 'Imported_' . date('YmdHis');

    $destPath = MC_WORLDS_DIR . '/' . $worldName;
    if (is_dir($destPath)) {
        exec('rm -rf ' . escapeshellarg($tmpDir));
        return ['success' => false, 'message' => "Welt '$worldName' existiert bereits"];
    }

    // 4. level.dat parsen: Spielmodus, Schwierigkeit, Cheats (Bedrock-Binär-NBT, 8-Byte-Header überspringen)
    $lvlSettings = [];
    if (file_exists($worldRoot . '/level.dat')) {
        $raw = (string)file_get_contents($worldRoot . '/level.dat');
        if (strlen($raw) > 8) {
            $nbt = substr($raw, 8);
            foreach (['GameType', 'Difficulty', 'serverChunkTickRange', 'playerPermissionsLevel',
                      'SpawnX', 'SpawnY', 'SpawnZ', 'Generator'] as $tag) {
                $pat = "\x03" . pack('v', strlen($tag)) . $tag;
                $pos = strpos($nbt, $pat);
                if ($pos !== false) {
                    $off = $pos + 3 + strlen($tag);
                    if (strlen($nbt) >= $off + 4) {
                        $v = unpack('V', substr($nbt, $off, 4))[1];
                        $lvlSettings[$tag] = (int)($v > 0x7FFFFFFF ? $v - 0x100000000 : $v);
                        if (in_array($tag, ['GameType', 'Difficulty', 'serverChunkTickRange', 'playerPermissionsLevel'], true)) {
                            $lvlSettings[$tag] = max(0, $lvlSettings[$tag]);
                        }
                    }
                }
            }
            foreach (['commandsEnabled', 'ForceGameType', 'commandblocksenabled', 'useMsaGamertagsOnly',
                      'gametest', 'upcoming_creator_features', 'holiday_creator_features',
                      'experimental_molang_features', 'cameras', 'custom_biomes',
                      'data_driven_items', 'data_driven_biomes', 'y_2025_drop_1',
                      'experimental_creator_cameras', 'jigsaw_structures',
                      'villager_trades_rebalance', 'y_2025_drop_3'] as $tag) {
                $pat = "\x01" . pack('v', strlen($tag)) . $tag;
                $pos = strpos($nbt, $pat);
                if ($pos !== false) {
                    $off = $pos + 3 + strlen($tag);
                    if (strlen($nbt) >= $off + 1) $lvlSettings[$tag] = ord($nbt[$off]);
                }
            }
            // FlatWorldLayers ist ein TAG_String (0x08): beschreibt Schichten einer Flachen Welt
            $strPat = "\x08" . pack('v', strlen('FlatWorldLayers')) . 'FlatWorldLayers';
            $strPos = strpos($nbt, $strPat);
            if ($strPos !== false) {
                $off = $strPos + 1 + 2 + strlen('FlatWorldLayers');
                if (strlen($nbt) >= $off + 2) {
                    $strLen = unpack('v', substr($nbt, $off, 2))[1];
                    $off += 2;
                    if (strlen($nbt) >= $off + $strLen) {
                        $lvlSettings['FlatWorldLayers'] = substr($nbt, $off, $strLen);
                    }
                }
            }
        }
    }

    // 5. Mitgelieferte Packs in Server-Pack-Verzeichnisse kopieren
    $packNames  = [];
    $packUuids  = ['behavior' => [], 'resource' => []];

    foreach (['behavior' => MC_PACKS_BEHAVIOR_DIR, 'resource' => MC_PACKS_RESOURCE_DIR] as $pt => $srvDir) {
        $srcDir = $worldRoot . '/' . $pt . '_packs';
        if (!is_dir($srcDir)) continue;
        if (!is_dir($srvDir)) @mkdir($srvDir, 0755, true);
        install_packs_from_dir($srcDir, $srvDir, $pt, $packUuids, $packNames);
    }

    // 5b. Neu importierte Packs sofort in die aktive Pack-Liste aufnehmen
    foreach (['behavior', 'resource'] as $pt) {
        foreach ($packUuids[$pt . '_imported'] ?? [] as $p) {
            add_pack_ref($packUuids[$pt], $p['pack_id'], $p['version']);
        }
    }

    // 6. Aktive Pack-Refs aus world_*_packs.json merken, inklusive Version
    foreach (['behavior' => 'world_behavior_packs', 'resource' => 'world_resource_packs'] as $pt => $jsonFile) {
        $p = $worldRoot . '/' . $jsonFile . '.json';
        if (!file_exists($p)) continue;

        foreach (json_decode((string)file_get_contents($p), true) ?? [] as $entry) {
            if (empty($entry['pack_id'])) continue;

            add_pack_ref(
                $packUuids[$pt],
                $entry['pack_id'],
                $entry['version'] ?? [0, 0, 0]
            );
        }
    }
	
	    // 6b. Dependencies der aktiven Packs ergänzen, falls sie nicht schon in world_*_packs.json stehen
    $tmpState = [
        'world_packs' => [
            $worldName => [
                'behavior' => $packUuids['behavior'] ?? [],
                'resource' => $packUuids['resource'] ?? [],
            ],
        ],
    ];

    foreach (['behavior', 'resource'] as $depSourceType) {
        foreach (($packUuids[$depSourceType] ?? []) as $entry) {
            $ref = normalize_pack_ref($entry);
            if ($ref === null) continue;

            $installed = find_installed_pack($depSourceType, $ref['pack_id'], $ref['version']);
            if (!$installed && $ref['version'] === null) {
                $installed = find_installed_pack($depSourceType, $ref['pack_id']);
            }

            if ($installed) {
                add_pack_dependencies_for_world($tmpState, $worldName, $installed);
            }
        }
    }

    $packUuids['behavior'] = $tmpState['world_packs'][$worldName]['behavior'] ?? [];
    $packUuids['resource'] = $tmpState['world_packs'][$worldName]['resource'] ?? [];

    // 7. Welt-Inhalt in Zielordner kopieren
    if (!is_dir(MC_WORLDS_DIR)) @mkdir(MC_WORLDS_DIR, 0755, true);
    copy_dir($worldRoot, $destPath);
    if (!is_dir($destPath . '/db')) {
        exec('rm -rf ' . escapeshellarg($tmpDir));
        exec('rm -rf ' . escapeshellarg($destPath));
        return ['success' => false, 'message' => 'Welt konnte nicht kopiert werden — bitte Schreibrechte prüfen'];
    }
    // Pack-Verzeichnisse aus der mcworld nicht im Welt-Ordner behalten —
    // Packs sind global installiert und werden via UUID referenziert.
    exec('rm -rf ' . escapeshellarg($destPath . '/behavior_packs'));
    exec('rm -rf ' . escapeshellarg($destPath . '/resource_packs'));

// 7b. Spawn in level.dat korrigieren.
// Wichtig:
// Manche Adventure-/Horror-Maps speichern in level.dat nur:
// SpawnX=0, SpawnY=32767, SpawnZ=0.
// Minecraft am PC nutzt dann oft die gespeicherte ~local_player-Position.
// Der Bedrock Dedicated Server nutzt aber den Weltspawn aus level.dat.
// Darum müssen bei solchen Maps SpawnX, SpawnY und SpawnZ aus ~local_player gesetzt werden.
$destLevelDat = $destPath . '/level.dat';

if (file_exists($destLevelDat)) {
    $spawnX = (int)($lvlSettings['SpawnX'] ?? 0);
    $spawnY = (int)($lvlSettings['SpawnY'] ?? 0);
    $spawnZ = (int)($lvlSettings['SpawnZ'] ?? 0);

    $playerPos = read_bedrock_local_player_pos($destPath . '/db');

    $spawnLooksInvalid =
        $spawnY === 32767
        || (
            $spawnX === 0
            && $spawnZ === 0
            && in_array($spawnY, [0, 64, 32767], true)
        );

    $fixedX = null;
    $fixedY = null;
    $fixedZ = null;

    if ($spawnLooksInvalid && $playerPos !== null) {
        // Wichtig:
        // local_player hat Vorrang vor FlatWorldLayers.
        // Bei Redstone Horror House 2 ist das der richtige Startpunkt:
        // Screenshot: 39 / -59 / 87

        $fixedX = (int)floor((float)$playerPos['x']);
        $fixedZ = (int)floor((float)$playerPos['z']);

        // Spielerposition ist Augen-/Körperposition.
        // Für den angezeigten Blockwert passt hier -1.62.
        $fixedY = (int)floor(((float)$playerPos['y']) - 1.62);
    } elseif ($spawnY === 32767 && !empty($lvlSettings['FlatWorldLayers'])) {
        // Nur Notfall-Fallback, wenn KEIN local_player gelesen werden konnte.
        $flatData = json_decode($lvlSettings['FlatWorldLayers'], true) ?? [];
        $totalLayers = array_sum(array_column($flatData['block_layers'] ?? [], 'count'));

        $fixedX = $spawnX;
        $fixedY = -64 + max(1, (int)$totalLayers);
        $fixedZ = $spawnZ;
    } elseif ($spawnY === 32767) {
        // Letzter Notfall-Fallback.
        $fixedX = $spawnX;
        $fixedY = 64;
        $fixedZ = $spawnZ;
    }

    if ($fixedX !== null && $fixedY !== null && $fixedZ !== null) {
        @copy($destLevelDat, $destLevelDat . '.mcadmin_bak');
        $ldRaw = (string)file_get_contents($destLevelDat);

        if (strlen($ldRaw) > 8) {
            $patchIntTag = function (string $tag, int $val) use (&$ldRaw): void {
                $ldNbt = substr($ldRaw, 8);
                $tagPat = "\x03" . pack('v', strlen($tag)) . $tag;
                $tagPos = strpos($ldNbt, $tagPat);

                if ($tagPos !== false) {
                    $valOff = 8 + $tagPos + strlen($tagPat);
                    $u32 = $val < 0 ? ($val + 0x100000000) : $val;

                    $ldRaw =
                        substr($ldRaw, 0, $valOff)
                        . pack('V', $u32)
                        . substr($ldRaw, $valOff + 4);
                }
            };

            $patchIntTag('SpawnX', $fixedX);
            $patchIntTag('SpawnY', $fixedY);
            $patchIntTag('SpawnZ', $fixedZ);
            $patchIntTag('spawnradius', 0);

            file_put_contents($destLevelDat, $ldRaw);

            $lvlSettings['SpawnX'] = $fixedX;
            $lvlSettings['SpawnY'] = $fixedY;
            $lvlSettings['SpawnZ'] = $fixedZ;
        }
    }

    // spawnradius immer auf 0 setzen — auch wenn Spawn-Koordinaten bereits korrekt waren.
    // Sonst spawnen Spieler zufällig im spawnradius-Umkreis statt exakt am Spawnpunkt.
    if ($fixedX === null) {
        $ldRaw = (string)file_get_contents($destLevelDat);
        if (strlen($ldRaw) > 8) {
            $ldNbt  = substr($ldRaw, 8);
            $tagPat = "\x03" . pack('v', strlen('spawnradius')) . 'spawnradius';
            $tagPos = strpos($ldNbt, $tagPat);
            if ($tagPos !== false) {
                $valOff = 8 + $tagPos + strlen($tagPat);
                $ldRaw  = substr($ldRaw, 0, $valOff) . pack('V', 0) . substr($ldRaw, $valOff + 4);
                file_put_contents($destLevelDat, $ldRaw);
            }
        }
    }

    // Alte Item-Formate in neu installierten Behavior Packs direkt upgraden.
    // apply_world_packs() (weiter unten) erledigt dasselbe für bereits installierte Packs.
    foreach ($packUuids['behavior_imported'] ?? [] as $p) {
        upgrade_legacy_item_formats(MC_PACKS_BEHAVIOR_DIR . '/' . $p['folder']);
    }
}

    // 8. Pack-JSON-Dateien in Weltordner sicherstellen
    foreach (['world_behavior_packs.json', 'world_resource_packs.json'] as $f) {
        if (!file_exists($destPath . '/' . $f)) @file_put_contents($destPath . '/' . $f, '[]');
    }

    // 9. Aktive Packs im State speichern (für Welt-Wechsel)
    // Zusätzlich merken, welche Pack-Ordner durch genau diesen Welt-Import neu installiert wurden.
    $state = get_state();

    $state['world_packs'][$worldName] = [
        'behavior' => $packUuids['behavior'] ?? [],
        'resource' => $packUuids['resource'] ?? [],
    ];

    $state['world_imported_packs'][$worldName] = [
        'behavior' => $packUuids['behavior_imported'] ?? [],
        'resource' => $packUuids['resource_imported'] ?? [],
    ];

    // Pack-Namens-Cache aus History-Dateien und gebündelten Manifesten aufbauen.
    // Ermöglicht spätere Namensanzeige auch wenn ein Pack nicht (mehr) installiert ist.
    $nameHints = $state['world_packs_name_cache'][$worldName] ?? [];
    foreach (['world_behavior_pack_history.json', 'world_resource_pack_history.json'] as $hf) {
        $hp = $worldRoot . '/' . $hf;
        if (!file_exists($hp)) continue;
        $hd = json_decode((string)file_get_contents($hp), true) ?? [];
        foreach ($hd['packs'] ?? [] as $pack) {
            $hu = strtolower($pack['uuid'] ?? '');
            if ($hu !== '' && !empty($pack['name'])) $nameHints[$hu] = $pack['name'];
        }
    }
    foreach (['behavior_packs', 'resource_packs'] as $pdir) {
        $psrc = $worldRoot . '/' . $pdir;
        if (!is_dir($psrc)) continue;
        foreach (array_diff((array)@scandir($psrc), ['.', '..']) as $pe) {
            $pmf = $psrc . '/' . $pe . '/manifest.json';
            if (!file_exists($pmf)) continue;
            $pd = json_decode((string)file_get_contents($pmf), true) ?? [];
            $pu = strtolower($pd['header']['uuid'] ?? '');
            $pn = $pd['header']['name'] ?? '';
            if ($pu !== '' && $pn !== '') $nameHints[$pu] = $pn;
        }
    }
    if ($nameHints) $state['world_packs_name_cache'][$worldName] = $nameHints;

    // Importierte Welt NICHT automatisch als aktive Welt setzen.
    // Wichtig:
    // - Der Server wird NICHT gestartet.
    // - active_world bleibt unverändert.
    // - die aktive server.properties bleibt unverändert.
    // Die Welt wird nur vollständig vorbereitet und kann später bewusst über "Welt wechseln" aktiviert werden.
    save_state($state);

    // Finale Packlisten direkt in die importierte Welt schreiben:
    // - world_behavior_packs.json
    // - world_resource_packs.json
    apply_world_packs($worldName);

    // 10. Welt-spezifische server.properties schreiben
    $gamemodes    = [0 => 'survival', 1 => 'creative', 2 => 'adventure', 3 => 'spectator'];
    $difficulties = [0 => 'peaceful', 1 => 'easy', 2 => 'normal', 3 => 'hard'];

    $props = file_exists(MC_PROPERTIES_FILE) ? get_all_properties() : [];

    $props['level-name'] = $worldName;

    if (isset($lvlSettings['GameType'])) {
        $props['gamemode'] = $gamemodes[$lvlSettings['GameType']] ?? 'survival';
    }

    if (isset($lvlSettings['Difficulty'])) {
        $props['difficulty'] = $difficulties[$lvlSettings['Difficulty']] ?? 'easy';
    }

    if (isset($lvlSettings['commandsEnabled'])) {
        $props['allow-cheats'] = $lvlSettings['commandsEnabled'] ? 'true' : 'false';
    }

    if (isset($lvlSettings['ForceGameType'])) {
        $props['force-gamemode'] = $lvlSettings['ForceGameType'] ? 'true' : 'false';
    }

    // Adventure-Maps: Wenn Welt im Adventure-Modus ist, force-gamemode aktivieren.
    // Außerdem: Wenn SpawnY unter 0 liegt (unterirdischer Spawn), Game-Mode auf Adventure
    // erzwingen — BDS ignoriert in Survival SpawnY und spawnt an der Oberfläche.
    // Adventure-Mode umgeht außerdem Bedrock-Biom-Beschränkungen beim Spawn.
    $spawnYVal = (int)($lvlSettings['SpawnY'] ?? 0);
    $gameTypeVal = (int)($lvlSettings['GameType'] ?? 0);
    if ($gameTypeVal === 2) {
        // Map ist als Adventure deklariert → force-gamemode sicherstellen
        if (!isset($props['force-gamemode'])) $props['force-gamemode'] = 'true';
    } elseif ($spawnYVal < 0 && !empty($packUuids['behavior'])) {
        // Unterirdischer Spawn + Behavior Packs → Adventure-Map → Adventure erzwingen
        $props['gamemode']      = 'adventure';
        $props['force-gamemode'] = 'true';
    }

    if (isset($lvlSettings['commandblocksenabled'])) {
        $props['command-blocks-enabled'] = $lvlSettings['commandblocksenabled'] ? 'true' : 'false';
    }

    if (isset($lvlSettings['useMsaGamertagsOnly'])) {
        $props['online-mode'] = $lvlSettings['useMsaGamertagsOnly'] ? 'true' : 'false';
    }

    if (isset($lvlSettings['playerPermissionsLevel'])) {
        $permMap = [0 => 'visitor', 1 => 'member', 2 => 'operator'];
        $props['default-player-permission-level'] = $permMap[$lvlSettings['playerPermissionsLevel']] ?? 'member';
    }

    if (isset($lvlSettings['serverChunkTickRange'])) {
        $props['tick-distance'] = max(4, min(12, (int)$lvlSettings['serverChunkTickRange']));
    }

    $props['texturepack-required'] = !empty($packUuids['resource'])
        ? ($props['texturepack-required'] ?? 'false')
        : 'false';

    // Bei Addon-/Adventure-Maps Content-Log aktivieren, damit Pack-Fehler im Server-Log sichtbar sind
    if (!empty($packUuids['behavior'])) {
        $props['content-log-file-enabled'] = 'true';
    }

    $lines = [];
    foreach ($props as $k => $v) {
        $lines[] = "$k=$v";
    }

    @file_put_contents($destPath . '/.server.properties', implode("\n", $lines) . "\n");

    // Die gerade frisch erzeugte welt-eigene .server.properties wird NICHT
    // automatisch in die aktive server.properties übernommen.
    // Aktiv wird die importierte Welt erst durch switch_world($worldName) / "Welt wechseln".

    exec('rm -rf ' . escapeshellarg($tmpDir));

    // Fehlende Packs: referenziert in world_*_packs.json, aber nicht auf dem Server installiert
    $missingPacks = [];
    foreach (['behavior', 'resource'] as $pt) {
        foreach ($packUuids[$pt] as $entry) {
            $ref = normalize_pack_ref($entry);
            if ($ref === null) continue;
            $found = find_installed_pack($pt, $ref['pack_id'], $ref['version']);
            if (!$found) $found = find_installed_pack($pt, $ref['pack_id']);
            if (!$found) {
                $ver = is_array($ref['version']) ? implode('.', $ref['version']) : ($ref['version'] ?? '?');
                $missingPacks[] = ucfirst($pt) . ' ' . substr((string)$ref['pack_id'], 0, 8) . '… v' . $ver;
            }
        }
    }

    // Erfolgsmeldung
    $msg = "Welt '$worldName' importiert";
    if ($packNames) {
        $n = count($packNames);
        $msg .= " — $n Pack" . ($n !== 1 ? 's' : '') . ' installiert: ' . implode(', ', array_slice($packNames, 0, 3)) . ($n > 3 ? ' …' : '');
    }
    if (!empty($packUuids['behavior']) || !empty($packUuids['resource'])) {
        $p = [];
        if ($c = count($packUuids['behavior'])) $p[] = "$c Behavior";
        if ($c = count($packUuids['resource']))  $p[] = "$c Resource";
        $msg .= ' — aktiviert: ' . implode(' + ', $p);
    }
    if (isset($lvlSettings['GameType']) && isset($lvlSettings['Difficulty'])) {
        $msg .= ' | ' . ($gamemodes[$lvlSettings['GameType']] ?? '?') . ', ' . ($difficulties[$lvlSettings['Difficulty']] ?? '?');
        if (isset($lvlSettings['commandsEnabled'])) $msg .= ', Cheats: ' . ($lvlSettings['commandsEnabled'] ? 'an' : 'aus');
    }
    if (isset($lvlSettings['SpawnX'], $lvlSettings['SpawnY'], $lvlSettings['SpawnZ'])) {
        $msg .= ' | Spawn: ' . $lvlSettings['SpawnX'] . '/' . $lvlSettings['SpawnY'] . '/' . $lvlSettings['SpawnZ'];
    }

    // Aktive Experimente melden
    $expLabels = [
        'gametest'                      => 'GameTest Framework',
        'upcoming_creator_features'     => 'Upcoming Creator Features',
        'holiday_creator_features'      => 'Holiday Creator Features',
        'experimental_molang_features'  => 'Experimental Molang',
        'cameras'                       => 'Cameras',
        'experimental_creator_cameras'  => 'Creator Cameras',
        'custom_biomes'                 => 'Custom Biomes',
        'data_driven_items'             => 'Data-Driven Items',
        'data_driven_biomes'            => 'Data-Driven Biomes',
        'jigsaw_structures'             => 'Jigsaw Structures',
        'villager_trades_rebalance'     => 'Villager Trade Rebalancing',
        'y_2025_drop_1'                 => 'Beta-APIs (2025 Drop 1)',
        'y_2025_drop_3'                 => 'Beta-APIs (2025 Drop 3)',
    ];
    $activeExps = [];
    foreach ($expLabels as $key => $label) {
        if (!empty($lvlSettings[$key])) $activeExps[] = $label;
    }
    if ($activeExps) {
        $msg .= ' | ⚠️ Experimente: ' . implode(', ', $activeExps);
    }
    if ($missingPacks) {
        $msg .= ' | ⚠️ Fehlende Packs: ' . implode(', ', array_slice($missingPacks, 0, 4)) . (count($missingPacks) > 4 ? ' …' : '');
    }

    return ['success' => true, 'message' => $msg, 'world_name' => $worldName,
            'experiments' => $activeExps, 'missing_packs' => $missingPacks];
}

// Liest Experiment-Flags aus einer level.dat und gibt aktive Labels zurück
function get_world_experiments(string $worldPath): array {
    $levelDat = $worldPath . '/level.dat';
    if (!file_exists($levelDat)) return [];
    $raw = (string)file_get_contents($levelDat);
    if (strlen($raw) <= 8) return [];
    $nbt = substr($raw, 8);
    $expLabels = [
        'gametest'                     => 'GameTest',
        'upcoming_creator_features'    => 'Creator Features',
        'holiday_creator_features'     => 'Holiday Features (entfernt ab BDS 1.21.20)',
        'experimental_molang_features' => 'Molang',
        'cameras'                      => 'Cameras',
        'experimental_creator_cameras' => 'Creator Cameras',
        'custom_biomes'                => 'Custom Biomes',
        'data_driven_items'            => 'Data-Driven Items',
        'data_driven_biomes'           => 'Data-Driven Biomes',
        'jigsaw_structures'            => 'Jigsaw Structures',
        'villager_trades_rebalance'    => 'Villager Trades',
        'y_2025_drop_1'                => 'Beta-APIs Drop 1',
        'y_2025_drop_3'                => 'Beta-APIs Drop 3',
    ];
    $active = [];
    foreach ($expLabels as $key => $label) {
        $pat = "\x01" . pack('v', strlen($key)) . $key;
        $pos = strpos($nbt, $pat);
        if ($pos !== false) {
            $off = $pos + 3 + strlen($key);
            if (strlen($nbt) > $off && ord($nbt[$off]) !== 0) $active[] = $label;
        }
    }
    return $active;
}

// Gibt eine Liste aller Welt-Ordner mit Name, Größe und Properties-Status zurück
function get_worlds(): array {
    if (!is_dir(MC_WORLDS_DIR)) return [];
    $state = get_state();

    // Installierte Packs einmal laden statt für jede Welt einzeln
    $instPacks = [
        'behavior' => get_installed_packs('behavior'),
        'resource'  => get_installed_packs('resource'),
    ];

    $worlds = [];
    foreach (scandir(MC_WORLDS_DIR) as $d) {
        if ($d === '.' || $d === '..') continue;
        $path = MC_WORLDS_DIR . '/' . $d;
        if (!is_dir($path)) continue;
        $size = dir_size($path);

        // Fehlende Packs zählen (in State vermerkt aber nicht installiert)
        $missingCount = 0;
        foreach (['behavior', 'resource'] as $pt) {
            foreach ($state['world_packs'][$d][$pt] ?? [] as $ref) {
                if (isset($ref['enabled']) && $ref['enabled'] === false) continue;
                $normRef = normalize_pack_ref($ref);
                if ($normRef === null) continue;
                $found = false;
                foreach ($instPacks[$pt] as $ip) {
                    if (strtolower($ip['uuid']) !== strtolower($normRef['pack_id'])) continue;
                    if ($normRef['version'] === null || pack_version_array($ip['version']) === $normRef['version']) {
                        $found = true; break;
                    }
                }
                if (!$found) $missingCount++;
            }
        }

        $wp = $state['world_packs'][$d] ?? [];
        $worlds[] = [
            'name'                => $d,
            'size_human'          => format_bytes($size),
            'has_own_properties'  => file_exists(world_properties_file($d)),
            'experiments'         => get_world_experiments($path),
            'missing_packs_count' => $missingCount,
            'pack_count'          => count($wp['behavior'] ?? []) + count($wp['resource'] ?? []),
        ];
    }
    return $worlds;
}

// Gibt den Namen der aktuell aktiven Welt zurück (aus State oder server.properties)
function get_active_world(): ?string {
    $state = get_state();
    return $state['active_world'] ?? get_server_property('level-name');
}

// Wechselt zur angegebenen Welt: speichert aktuelle Properties, lädt neue Packs
function switch_world(string $worldName): array {
    $worlds = array_column(get_worlds(), 'name');
    if (!in_array($worldName, $worlds)) return ['success' => false, 'message' => 'Welt nicht gefunden'];
    $currentWorld = get_active_world();
    if ($currentWorld && $currentWorld !== $worldName) {
        if (!save_world_properties($currentWorld))
            return ['success' => false, 'message' => "Welteinstellungen konnten nicht gespeichert werden: $currentWorld"];
    }
    load_world_properties($worldName);
    apply_world_packs($worldName);
    $state = get_state();
    $state['active_world'] = $worldName;
    save_state($state);
    discord_notify('world_switch', "🌍 **Welt gewechselt zu: {$worldName}**");
    return ['success' => true, 'message' => "Welt gewechselt zu: $worldName — Server neu starten zum Übernehmen"];
}

// Erstellt eine neue leere Welt mit Standard-Properties und optionalem Seed
function create_world(string $worldName, array $options = []): array {
    $worldName = trim($worldName);
    if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $worldName))
        return ['success' => false, 'message' => 'Ungültiger Weltname (nur Buchstaben, Zahlen, Leerzeichen, _ -)'];
    $path = MC_WORLDS_DIR . '/' . $worldName;
    if (is_dir($path)) return ['success' => false, 'message' => "Welt '$worldName' existiert bereits"];
    if (!mkdir($path, 0755, true)) return ['success' => false, 'message' => 'Ordner konnte nicht erstellt werden'];
    if (file_put_contents($path . '/world_behavior_packs.json', '[]') === false
        || file_put_contents($path . '/world_resource_packs.json', '[]') === false) {
        exec('rm -rf ' . escapeshellarg($path));
        return ['success' => false, 'message' => 'Weltordner konnte nicht initialisiert werden'];
    }
    $props = [
    'server-name' => 'Minecraft Bedrock Server',
    'gamemode' => 'survival',
    'difficulty' => 'easy',
    'allow-cheats' => 'false',
    'max-players' => '10',
    'online-mode' => 'true',
    'white-list' => 'false',
    'server-port' => '19132',
    'server-portv6' => '19133',
    'view-distance' => '10',
    'tick-distance' => '4',
    'player-idle-timeout' => '30',
    'max-threads' => '0',
    'level-name' => $worldName,
    'level-seed' => '',
    'default-player-permission-level' => 'member',
    'texturepack-required' => 'false',
    'content-log-file-enabled' => 'false',
    'compression-threshold' => '1',
    'server-authoritative-movement' => 'server-auth',
    'server-authoritative-block-breaking' => 'true',
    'correct-player-movement' => 'true',
    'force-gamemode' => 'false',
    'command-blocks-enabled' => 'false',
];
    $props['level-name'] = $worldName;
    $props['gamemode']   = ['survival','creative','adventure'][$options['gamemode'] ?? 0] ?? 'survival';
    $props['difficulty'] = ['peaceful','easy','normal','hard'][$options['difficulty'] ?? 1] ?? 'easy';
    if (!empty($options['seed'])) $props['level-seed'] = $options['seed'];
    $lines = [];
    foreach ($props as $k => $v) $lines[] = "$k=$v";
    if (file_put_contents(world_properties_file($worldName), implode("\n", $lines) . "\n") === false) {
        exec('rm -rf ' . escapeshellarg($path));
        return ['success' => false, 'message' => 'Welteinstellungen konnten nicht gespeichert werden'];
    }
    return ['success' => true, 'message' => "Welt '$worldName' erstellt"];
}

// Löscht Packs, die beim Import einer Welt mitinstalliert wurden, sofern nicht anderweitig genutzt
function delete_imported_packs_for_world(string $worldName): array {
    $state = get_state();

    $imported = $state['world_imported_packs'][$worldName] ?? [
        'behavior' => [],
        'resource' => [],
    ];

    $deleted = [];
    $kept    = [];

    foreach (['behavior' => MC_PACKS_BEHAVIOR_DIR, 'resource' => MC_PACKS_RESOURCE_DIR] as $type => $baseDir) {
        foreach (($imported[$type] ?? []) as $pack) {
            $folder  = $pack['folder'] ?? '';
            $uuid    = $pack['pack_id'] ?? '';
            $version = $pack['version'] ?? null;

            if ($uuid === '') continue;

            // Kein Ordner direkt gespeichert → per UUID im Pack-Verzeichnis suchen (Fallback für ältere State-Einträge)
            if ($folder === '') {
                foreach (array_diff((array)@scandir($baseDir), ['.', '..']) as $d) {
                    $p  = $baseDir . '/' . $d;
                    if (!is_dir($p)) continue;
                    $mf = json_decode((string)@file_get_contents($p . '/manifest.json'), true) ?? [];
                    if (strtolower($mf['header']['uuid'] ?? '') === strtolower($uuid)) {
                        $folder = $d;
                        break;
                    }
                }
            }
            if ($folder === '') { $kept[] = "$uuid ($type) — Ordner nicht gefunden"; continue; }

            $usedByOtherWorld = false;

            foreach (($state['world_packs'] ?? []) as $otherWorld => $worldPacks) {
                if ($otherWorld === $worldName) continue;

                foreach (($worldPacks[$type] ?? []) as $entry) {
                    $ref = normalize_pack_ref($entry);
                    if ($ref === null) continue;

                    if (strtolower($ref['pack_id']) !== strtolower($uuid)) continue;

                    if ($version === null || $ref['version'] === null || $ref['version'] === pack_version_array($version)) {
                        $usedByOtherWorld = true;
                        break 2;
                    }
                }
            }

            $packPath = $baseDir . '/' . $folder;

            if ($usedByOtherWorld) {
                $kept[] = "$folder ($type) — wird noch von anderer Welt genutzt";
                continue;
            }

            clearstatcache(true, $packPath);

            // Wenn der Ordner schon nicht mehr existiert, ist er praktisch bereits aufgeräumt.
            if (!is_dir($packPath)) {
                $deleted[] = "$folder ($type) — war bereits gelöscht";
                continue;
            }

            // Sicherheit: Nur Packs löschen, die vom Panel/importierten Welten markiert wurden.
            if (!is_mcadmin_user_pack($packPath)) {
                $kept[] = "$folder ($type) — kein mcadmin-Import-Pack";
                continue;
            }

            $out = [];
            $code = 1;

            exec('rm -rf ' . escapeshellarg($packPath) . ' 2>&1', $out, $code);

            clearstatcache(true, $packPath);

            // Wichtig: Wenn der Ordner weg ist, gilt es als Erfolg — auch falls rm komisch zurückmeldet.
            if (!is_dir($packPath)) {
                $deleted[] = "$folder ($type)";
            } else {
                $err = trim(implode(' ', $out));
                $kept[] = "$folder ($type) — konnte nicht gelöscht werden" . ($err ? ": $err" : "");
            }
        }
    }

    unset($state['world_imported_packs'][$worldName]);

    save_state($state);

    return [
        'deleted' => $deleted,
        'kept'    => $kept,
    ];
}

// Löscht einen Welt-Ordner und bereinigt zugehörige Import-Packs und State-Einträge
function delete_world(string $worldName): array {
    $path = MC_WORLDS_DIR . '/' . $worldName;

    if (!is_dir($path)) {
        return ['success' => false, 'message' => 'Welt nicht gefunden'];
    }

    if ($worldName === get_active_world()) {
        return ['success' => false, 'message' => 'Aktive Welt kann nicht gelöscht werden'];
    }

    $packCleanup = delete_imported_packs_for_world($worldName);

    exec('rm -rf ' . escapeshellarg($path), $out, $code);

    $state = get_state();
    unset($state['world_packs'][$worldName]);
    unset($state['world_imported_packs'][$worldName]);
    save_state($state);

    if ($code !== 0) {
        return ['success' => false, 'message' => 'Fehler beim Löschen'];
    }

    $msg = "Welt '$worldName' gelöscht";

    if (!empty($packCleanup['deleted'])) {
        $msg .= ' — Packs gelöscht: ' . implode(', ', array_slice($packCleanup['deleted'], 0, 5));
        if (count($packCleanup['deleted']) > 5) $msg .= ' …';
    }

    if (!empty($packCleanup['kept'])) {
        $msg .= ' — Packs behalten: ' . implode(', ', array_slice($packCleanup['kept'], 0, 3));
        if (count($packCleanup['kept']) > 3) $msg .= ' …';
    }

    return [
        'success' => true,
        'message' => $msg,
    ];
}

// Benennt einen Welt-Ordner um und aktualisiert State, Properties und aktive Welt
function rename_world(string $oldName, string $newName): array {
    $oldName = trim($oldName);
    $newName = trim($newName);
    if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $oldName)) return ['success' => false, 'message' => 'Ungültiger alter Name'];
    if (!preg_match('/^[a-zA-Z0-9_\- ]{1,64}$/', $newName)) return ['success' => false, 'message' => 'Ungültiger Name'];
    $src = MC_WORLDS_DIR . '/' . $oldName;
    $dst = MC_WORLDS_DIR . '/' . $newName;
    if (!is_dir($src)) return ['success' => false, 'message' => 'Welt nicht gefunden'];
    if (is_dir($dst))  return ['success' => false, 'message' => "Name '$newName' existiert bereits"];
    if (!rename($src, $dst)) return ['success' => false, 'message' => 'Umbenennen fehlgeschlagen'];
    $propsFile = world_properties_file($newName);
    if (file_exists($propsFile)) set_server_property('level-name', $newName, $propsFile);
    $state = get_state();
    if (isset($state['world_packs'][$oldName])) {
        $state['world_packs'][$newName] = $state['world_packs'][$oldName];
        unset($state['world_packs'][$oldName]);
    }
    if ($state['active_world'] === $oldName) {
        $state['active_world'] = $newName;
        set_server_property('level-name', $newName);
    }
    if (isset($state['world_imported_packs'][$oldName])) {
        $state['world_imported_packs'][$newName] = $state['world_imported_packs'][$oldName];
        unset($state['world_imported_packs'][$oldName]);
    }
    save_state($state);
    return ['success' => true, 'message' => "Welt umbenannt: '$oldName' → '$newName'"];
}

// ============================================================
// PACK MANAGEMENT
// ============================================================

// Erkennt ob ein Pack ein eingebautes Bedrock-Server-Pack ist (wird im Panel ausgeblendet)
function is_builtin_bedrock_pack(string $folder, array $manifest = []): bool {
    $folderLower = strtolower(trim($folder));
    $uuid        = strtolower(trim((string)(($manifest['header'] ?? [])['uuid'] ?? '')));

    // Exakte Ordnernamen der Bedrock-Server-Standardpacks
    $builtinFolders = [
        'chemistry',
        'chemistry_behavior_pack',
        'chemistry_resource_pack',
        'editor',
        'editor_behavior_pack',
        'editor_resources',
        'editor_resource_pack',
        'education',
        'education_behavior_pack',
        'education_resource_pack',
        'experimental',
        'experimental_behavior_pack',
        'experimental_resource_pack',
        'persona',
        'vanilla',
        'vanilla_behavior_pack',
        'vanilla_resource_pack',
        'vanilla_music',
        'vanilla_nether',
        'vanilla_raytracing',
        'vanilla_texture',
        'vanilla_texture_pack',
        'vanilla_world',
    ];

    if (in_array($folderLower, $builtinFolders, true)) return true;

    // Ordnerpräfix-Prüfung (z.B. vanilla_1.21.x, chemistry_v2)
    foreach (['vanilla_', 'chemistry_', 'editor_', 'education_', 'experimental_', 'persona_'] as $prefix) {
        if (str_starts_with($folderLower, $prefix)) return true;
    }

    // Bekannte interne Bedrock/Mojang-Pack-UUIDs
    $builtinUuids = [
        'b3b2d6f4-9f03-4d3f-a6f7-6d9c0b6f8f9f',
        '6f4b6893-1bb6-42fd-b458-7fa3d0c89616',
        '0fba4063-dba1-4281-9b89-ff9390653530',
        '5bf4cbef-4818-4a4d-a02d-b16b4d7c3ff9',
    ];
    if ($uuid && in_array($uuid, $builtinUuids, true)) return true;

    return false;
}

// Prüft ob ein Pack durch mcadmin installiert wurde (anhand der Marker-Datei)
function is_mcadmin_user_pack(string $path): bool {
    return file_exists($path . '/.mcadmin-user-pack');
}

// Erstellt die mcadmin-Marker-Datei in einem Pack-Ordner
function mark_mcadmin_user_pack(string $path): void {
    @file_put_contents($path . '/.mcadmin-user-pack', date('c') . "\n");
}

// Kopiert ein Pack-Verzeichnis, überspringt dabei Unterordner die eigene manifest.json haben
// (diese werden als separate Top-Level-Packs installiert => kein "Multiple manifests"-Fehler)
function copy_pack_skip_subpacks(string $src, string $dst): void {
    if (!is_dir($dst) && !mkdir($dst, 0755, true)) return;
    foreach (array_diff((array)@scandir($src), ['.', '..']) as $entry) {
        $s = $src . '/' . $entry;
        $d = $dst . '/' . $entry;
        if (is_dir($s)) {
            if (file_exists($s . '/manifest.json')) continue;
            copy_pack_skip_subpacks($s, $d);
        } else {
            copy($s, $d);
        }
    }
}

// Installiert einen einzelnen gefundenen Pack in das Server-Pack-Verzeichnis
function install_single_world_pack(
    string $packSrc, string $srvDir, string $pt,
    array &$packUuids, array &$packNames
): void {
    $mf     = json_decode((string)file_get_contents($packSrc . '/manifest.json'), true) ?? [];
    $header = $mf['header'] ?? [];
    $uuid   = $header['uuid'] ?? '';
    if ($uuid === '') return;

    $version = pack_version_array($header['version'] ?? [0, 0, 0]);
    $rawName = (string)($header['name'] ?? basename($packSrc));
    $name    = trim(preg_replace('/§[0-9a-fk-orA-FK-OR]/u', '', resolve_pack_name($packSrc, $rawName)));
    if ($name === '') $name = basename($packSrc);

    $packDest = $srvDir . '/' . basename($packSrc);

    if (is_dir($packDest)) {
        $destMf   = json_decode((string)@file_get_contents($packDest . '/manifest.json'), true) ?? [];
        $destUuid = $destMf['header']['uuid'] ?? '';
        $destVer  = pack_version_array($destMf['header']['version'] ?? [0, 0, 0]);
        $samePack = strtolower($destUuid) === strtolower($uuid) && $destVer === $version;

        if (!$samePack) {
            $base = sanitize_dirname($name . '_' . substr($uuid, 0, 8) . '_v' . implode('_', $version));
            $packDest = $srvDir . '/' . $base;
            $i = 2;
            while (is_dir($packDest)) {
                $destMf   = json_decode((string)@file_get_contents($packDest . '/manifest.json'), true) ?? [];
                $destUuid = $destMf['header']['uuid'] ?? '';
                $destVer  = pack_version_array($destMf['header']['version'] ?? [0, 0, 0]);
                if (strtolower($destUuid) === strtolower($uuid) && $destVer === $version) break;
                $packDest = $srvDir . '/' . $base . '_' . $i++;
            }
        }
    }

    if (!is_dir($packDest)) {
        copy_pack_skip_subpacks($packSrc, $packDest);
        if (is_dir($packDest)) {
            mark_mcadmin_user_pack($packDest);
            // Alte Item-Formate (format_version < 1.20) sofort auf 1.20.80 upgraden.
            // BDS 1.21.20+ hat das Holiday Creator Features-Experiment entfernt;
            // 1.20.80-Items laden ohne jedes Experiment.
            if ($pt === 'behavior') upgrade_legacy_item_formats($packDest);
            $packUuids[$pt . '_imported'][] = [
                'folder'  => basename($packDest),
                'pack_id' => $uuid,
                'version' => $version,
            ];
        }
    }

    $packNames[] = "$name v" . implode('.', $version) . " ($pt)";
}

// Liest den Pack-Typ ('behavior' oder 'resource') aus den Manifest-Modulen
function detect_pack_type(string $packDir): ?string {
    $mf = json_decode((string)@file_get_contents($packDir . '/manifest.json'), true) ?? [];
    foreach ($mf['modules'] ?? [] as $module) {
        $type = strtolower($module['type'] ?? '');
        if ($type === 'resources') return 'resource';
        if (in_array($type, ['data', 'script', 'interface', 'javascript', 'client_data'], true)) return 'behavior';
        // 'world_template' bewusst entfernt: World-Templates sind keine BDS-Server-Packs
        // und landen sonst fälschlicherweise in behavior_packs/. Stattdessen: null → überspringen.
    }
    return null;
}

// Installiert alle Packs aus einem extrahierten .mcaddon/.mcpack Verzeichnis
// Erkennt den Typ jedes Packs automatisch aus dem Manifest
function install_addon_packs(string $dir, array &$packUuids, array &$packNames): void {
    foreach (array_diff((array)@scandir($dir), ['.', '..']) as $entry) {
        $path = $dir . '/' . $entry;

        if (is_file($path) && preg_match('/\.(mcaddon|mcpack)$/i', $entry)) {
            $tmpDir = sys_get_temp_dir() . '/mcadmin_addon_' . bin2hex(random_bytes(8));
            if (@mkdir($tmpDir, 0755, true) && extract_zip($path, $tmpDir)) {
                install_addon_packs($tmpDir, $packUuids, $packNames);
            }
            exec('rm -rf ' . escapeshellarg($tmpDir));
            continue;
        }

        if (!is_dir($path)) continue;

        if (file_exists($path . '/manifest.json')) {
            $pt = detect_pack_type($path);
            if ($pt !== null) {
                $srvDir = $pt === 'behavior' ? MC_PACKS_BEHAVIOR_DIR : MC_PACKS_RESOURCE_DIR;
                if (!is_dir($srvDir)) @mkdir($srvDir, 0755, true);
                install_single_world_pack($path, $srvDir, $pt, $packUuids, $packNames);
                // Sub-Packs separat installieren
                foreach (array_diff((array)@scandir($path), ['.', '..']) as $sub) {
                    $subPath = $path . '/' . $sub;
                    if (is_dir($subPath) && file_exists($subPath . '/manifest.json')) {
                        install_addon_packs($subPath, $packUuids, $packNames);
                    }
                }
            }
        } else {
            install_addon_packs($path, $packUuids, $packNames);
        }
    }
}

// Rekursive Pack-Erkennung: findet alle Packs egal wie tief sie verschachtelt sind
// Behandelt auch .mcaddon/.mcpack Dateien
function install_packs_from_dir(
    string $srcDir, string $srvDir, string $pt,
    array &$packUuids, array &$packNames
): void {
    foreach (array_diff((array)@scandir($srcDir), ['.', '..']) as $entry) {
        $packSrc = $srcDir . '/' . $entry;

        // .mcaddon / .mcpack Dateien: entpacken und Packs darin installieren
        if (is_file($packSrc) && preg_match('/\.(mcaddon|mcpack)$/i', $entry)) {
            $tmpDir = sys_get_temp_dir() . '/mcadmin_addon_' . bin2hex(random_bytes(8));
            if (@mkdir($tmpDir, 0755, true) && extract_zip($packSrc, $tmpDir)) {
                install_addon_packs($tmpDir, $packUuids, $packNames);
            }
            exec('rm -rf ' . escapeshellarg($tmpDir));
            continue;
        }

        if (!is_dir($packSrc)) continue;

        if (file_exists($packSrc . '/manifest.json')) {
            install_single_world_pack($packSrc, $srvDir, $pt, $packUuids, $packNames);
            // Sub-Packs in diesem Pack-Ordner ebenfalls als Top-Level installieren
            foreach (array_diff((array)@scandir($packSrc), ['.', '..']) as $sub) {
                $subPath = $packSrc . '/' . $sub;
                if (is_dir($subPath) && file_exists($subPath . '/manifest.json')) {
                    install_packs_from_dir($subPath, $srvDir, $pt, $packUuids, $packNames);
                }
            }
        } else {
            // Kein Manifest hier => tiefer suchen
            install_packs_from_dir($packSrc, $srvDir, $pt, $packUuids, $packNames);
        }
    }
}

// Normalisiert eine Pack-Version (String oder Array) zu einem int[3]-Array
function pack_version_array($version): array {
    if (is_array($version)) {
        return array_values(array_map('intval', $version));
    }

    if (is_string($version)) {
        $parts = array_map('intval', explode('.', $version));
        return array_pad(array_slice($parts, 0, 3), 3, 0);
    }

    return [0, 0, 0];
}

// Wandelt ein Pack-Versions-Array in einen Punkt-getrennten String um
function pack_version_string($version): string {
    return implode('.', pack_version_array($version));
}

// Normalisiert eine Pack-Referenz (UUID-String oder Array) zu {pack_id, version}
function normalize_pack_ref($entry): ?array {
    if (is_string($entry)) {
        return [
            'pack_id' => $entry,
            'version' => null,
        ];
    }

    if (is_array($entry)) {
        $uuid = $entry['pack_id'] ?? $entry['uuid'] ?? '';
        if ($uuid === '') return null;

        return [
            'pack_id' => $uuid,
            'version' => array_key_exists('version', $entry) ? pack_version_array($entry['version']) : null,
        ];
    }

    return null;
}

// Prüft ob eine Pack-Referenz mit einer gegebenen UUID übereinstimmt (UUID-Vergleich)
function pack_ref_matches_uuid($entry, string $uuid): bool {
    $ref = normalize_pack_ref($entry);
    return $ref !== null && strtolower($ref['pack_id']) === strtolower($uuid);
}

// Prüft ob eine Pack-Referenz exakt mit UUID und Version übereinstimmt
function pack_ref_matches_exact($entry, string $uuid, $version): bool {
    $ref = normalize_pack_ref($entry);
    if ($ref === null) return false;
    if (strtolower($ref['pack_id']) !== strtolower($uuid)) return false;
    if ($ref['version'] === null) return true;
    return $ref['version'] === pack_version_array($version);
}

// Fügt eine Pack-Referenz zur Liste hinzu, wenn sie noch nicht vorhanden ist
function add_pack_ref(array &$list, string $uuid, $version): void {
    $ref = [
        'pack_id' => $uuid,
        'version' => pack_version_array($version),
    ];

    foreach ($list as $existing) {
        if (pack_ref_matches_exact($existing, $uuid, $ref['version'])) {
            return;
        }
    }

    $list[] = $ref;
}


// Sucht ein installiertes Pack nach Typ, UUID und optionaler Version
function find_installed_pack(string $type, string $uuid, $version = null): ?array {
    foreach (get_installed_packs($type) as $pack) {
        if (strtolower($pack['uuid']) !== strtolower($uuid)) continue;

        if ($version !== null) {
            if (pack_version_array($pack['version']) !== pack_version_array($version)) continue;
        }

        return $pack;
    }

    return null;
}

// Fügt Abhängigkeits-Packs eines Packs automatisch zur Welt-Pack-Liste hinzu
function add_pack_dependencies_for_world(array &$state, string $worldName, array $pack, array &$visited = []): void {
    foreach (($pack['dependencies'] ?? []) as $dep) {
        $depUuid = $dep['uuid'] ?? '';
        if ($depUuid === '' || in_array(strtolower($depUuid), $visited)) continue;

        $depVersion = $dep['version'] ?? [0, 0, 0];

        foreach (['resource', 'behavior'] as $depType) {
            $found = find_installed_pack($depType, $depUuid, $depVersion);
            if (!$found) continue;

            if (!isset($state['world_packs'][$worldName][$depType])) {
                $state['world_packs'][$worldName][$depType] = [];
            }

            add_pack_ref(
                $state['world_packs'][$worldName][$depType],
                $found['uuid'],
                $found['version']
            );

            $visited[] = strtolower($depUuid);
            add_pack_dependencies_for_world($state, $worldName, $found, $visited);
            break;
        }
    }
}

// Gibt zurück in welchen Welten ein Pack genutzt oder importiert wird
function get_pack_world_usage(string $type, string $uuid, $version = null): array {
    $state = get_state();
    $usedBy = [];
    $importedBy = [];

    foreach (($state['world_packs'] ?? []) as $worldName => $worldPacks) {
        foreach (($worldPacks[$type] ?? []) as $entry) {
            $ref = normalize_pack_ref($entry);
            if ($ref === null) continue;

            if (strtolower($ref['pack_id']) !== strtolower($uuid)) continue;

            if ($version === null || $ref['version'] === null || $ref['version'] === pack_version_array($version)) {
                $usedBy[] = $worldName;
                break;
            }
        }
    }

    foreach (($state['world_imported_packs'] ?? []) as $worldName => $worldPacks) {
        foreach (($worldPacks[$type] ?? []) as $entry) {
            $ref = normalize_pack_ref($entry);
            if ($ref === null) continue;

            if (strtolower($ref['pack_id']) !== strtolower($uuid)) continue;

            if ($version === null || $ref['version'] === null || $ref['version'] === pack_version_array($version)) {
                $importedBy[] = $worldName;
                break;
            }
        }
    }

    return [
        'used_by_worlds' => array_values(array_unique($usedBy)),
        'imported_by_worlds' => array_values(array_unique($importedBy)),
    ];
}

// Gibt alle installierten Behavior- oder Resource-Packs zurück.
// $userOnly=true: nur vom Panel installierte Packs (für UI-Anzeige).
// $userOnly=false: alle Packs inkl. Bedrock-Systempacks (für interne Lookups).
function get_installed_packs(string $type = 'behavior', bool $userOnly = false, bool $refresh = false): array {
    static $cache = [];
    $cacheKey = $type . ($userOnly ? ':u' : ':a');
    if (!$refresh && isset($cache[$cacheKey])) return $cache[$cacheKey];
    unset($cache[$cacheKey]);

    $dir = $type === 'behavior' ? MC_PACKS_BEHAVIOR_DIR : MC_PACKS_RESOURCE_DIR;
    if (!is_dir($dir)) return $cache[$cacheKey] = [];

    $packs = [];

    foreach (scandir($dir) as $d) {
        if ($d === '.' || $d === '..') continue;

        $path     = $dir . '/' . $d;
        $manifest = $path . '/manifest.json';

        if (is_dir($path) && file_exists($manifest)) {
            $data = json_decode(file_get_contents($manifest), true) ?: [];

            if ($userOnly) {
                // UI-Anzeige: nur vom Panel selbst installierte Packs zeigen
                if (!is_mcadmin_user_pack($path)) continue;
            } else {
                // Interne Lookups: nur bekannte Bedrock-Systempacks ausblenden
                if (!is_mcadmin_user_pack($path) && is_builtin_bedrock_pack($d, $data)) continue;
            }

            $header = $data['header'] ?? [];
            $uuid = $header['uuid'] ?? $d;
            $versionArr = pack_version_array($header['version'] ?? [0, 0, 0]);
            $versionStr = pack_version_string($versionArr);
            $usage = get_pack_world_usage($type, $uuid, $versionArr);

            $hasScript = false;
            $hasData   = false;
            foreach ($data['modules'] ?? [] as $mod) {
                $mt = strtolower(trim((string)($mod['type'] ?? '')));
                if (in_array($mt, ['script', 'javascript'], true)) $hasScript = true;
                if ($mt === 'data') $hasData = true;
            }
            $subtype = $hasScript ? 'script' : ($hasData ? 'data' : 'resources');

            $packs[] = [
                'folder'              => $d,
                'uuid'                => $uuid,
                'name'                => resolve_pack_name($path, $header['name'] ?? $d),
                'description'         => $header['description'] ?? '',
                'version'             => $versionStr,
                'version_arr'         => $versionArr,
                'type'                => $type,
                'subtype'             => $subtype,
                'dependencies'        => $data['dependencies'] ?? [],
                'used_by_worlds'      => $usage['used_by_worlds'],
                'imported_by_worlds'  => $usage['imported_by_worlds'],
                'user_pack'           => is_mcadmin_user_pack($path),
            ];
        }
    }

    return $cache[$cacheKey] = $packs;
}

// Löst einen Übersetzungs-Key im Pack-Namen auf (z.B. "pack.name" oder "%pack.name%").
// Liest den echten Namen aus der Pack-Sprachdatei texts/en_US.lang o.ä.
function resolve_pack_name(string $packPath, string $rawName): string {
    $key = trim($rawName, '%');
    // Kein Übersetzungs-Key: enthält Leerzeichen oder keinen Punkt
    if ($key === $rawName && (strpos($key, ' ') !== false || strpos($key, '.') === false)) {
        return $rawName;
    }
    foreach (['texts/en_US.lang', 'texts/en_us.lang', 'texts/en_GB.lang', 'texts/en_gb.lang'] as $lf) {
        $lp = $packPath . '/' . $lf;
        if (!file_exists($lp)) continue;
        foreach (file($lp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strncmp($line, $key . '=', strlen($key) + 1) === 0) {
                $val = trim(substr($line, strlen($key) + 1));
                if ($val !== '') return $val;
            }
        }
    }
    return $rawName;
}

// Sucht einen Pack-Namen anhand der UUID in ALLEN Pack-Verzeichnissen (inkl. Bedrock-Systempacks).
// Gibt null zurück wenn nichts gefunden wurde.
function find_pack_name_anywhere(string $type, string $uuid): ?string {
    $dir = $type === 'behavior' ? MC_PACKS_BEHAVIOR_DIR : MC_PACKS_RESOURCE_DIR;
    if (!is_dir($dir)) return null;
    foreach (scandir($dir) as $d) {
        if ($d === '.' || $d === '..') continue;
        $path     = $dir . '/' . $d;
        $manifest = $path . '/manifest.json';
        if (!is_dir($path) || !file_exists($manifest)) continue;
        $data = json_decode(file_get_contents($manifest), true) ?: [];
        if (strtolower($data['header']['uuid'] ?? '') === strtolower($uuid)) {
            $rawName = $data['header']['name'] ?? null;
            return $rawName !== null ? resolve_pack_name($path, $rawName) : null;
        }
    }
    return null;
}

// Sucht den Pack-Namen in den World-History-Dateien (world_*_pack_history.json).
// Fallback wenn das Pack nicht installiert ist aber die Welt es referenziert hat.
function find_pack_name_in_history(string $worldName, string $uuid): ?string {
    $base = MC_WORLDS_DIR . '/' . $worldName;
    foreach (['world_behavior_pack_history.json', 'world_resource_pack_history.json'] as $file) {
        $path = $base . '/' . $file;
        if (!file_exists($path)) continue;
        $data = json_decode((string)file_get_contents($path), true) ?? [];
        foreach ($data['packs'] ?? [] as $pack) {
            if (strtolower($pack['uuid'] ?? '') === strtolower($uuid)) {
                return $pack['name'] ?? null;
            }
        }
    }
    return null;
}

// Sucht einen Pack-Namen im beim Import gespeicherten Name-Cache des States.
function find_pack_name_in_state_cache(string $worldName, string $uuid): ?string {
    $cache = get_state()['world_packs_name_cache'][$worldName] ?? [];
    return $cache[strtolower($uuid)] ?? null;
}

// Sucht welche installierten Packs die gegebene UUID als Abhängigkeit listen.
// Gibt Array mit Pack-Namen zurück (leer wenn keiner gefunden).
function find_packs_requiring_uuid(string $uuid): array {
    $requiring = [];
    foreach (['behavior', 'resource'] as $type) {
        foreach (get_installed_packs($type) as $pack) {
            foreach ($pack['dependencies'] ?? [] as $dep) {
                if (strtolower($dep['uuid'] ?? '') === strtolower($uuid)) {
                    $requiring[] = $pack['name'];
                    break;
                }
            }
        }
    }
    return array_unique($requiring);
}

// Gibt die aktiven Pack-Referenzen einer Welt zurück, plus fehlende Packs (UUID-Liste)
function get_world_packs(string $worldName): array {
    $state = get_state();
    $result = $state['world_packs'][$worldName] ?? ['behavior' => [], 'resource' => []];

    // Installierte Packs mit Namen auflösen + fehlende Packs ermitteln
    foreach (['behavior', 'resource'] as $pt) {
        $resolved = [];
        $missing   = [];
        foreach ($result[$pt] ?? [] as $ref) {
            $normRef = normalize_pack_ref($ref);
            if ($normRef === null) continue;
            $enabled = $ref['enabled'] ?? true;
            $inst = find_installed_pack($pt, $normRef['pack_id'], $normRef['version']);
            if (!$inst && $normRef['version'] === null) {
                $inst = find_installed_pack($pt, $normRef['pack_id']);
            }
            $resolvedName = $inst ? ($inst['name'] ?? 'Unbekannt')
                                  : (find_pack_name_anywhere($pt, $normRef['pack_id'])
                                     ?? find_pack_name_in_history($worldName, $normRef['pack_id'])
                                     ?? find_pack_name_in_state_cache($worldName, $normRef['pack_id'])
                                     ?? 'Unbekannt');
            $resolved[] = [
                'uuid'    => $normRef['pack_id'],
                'version' => $normRef['version'] ? implode('.', $normRef['version']) : '?',
                'name'    => $resolvedName,
                'enabled' => $enabled,
            ];
            if (!$inst && $enabled) {
                $missing[] = [
                    'uuid'        => $normRef['pack_id'],
                    'version'     => $normRef['version'] ? implode('.', $normRef['version']) : '?',
                    'name'        => find_pack_name_anywhere($pt, $normRef['pack_id'])
                                     ?? find_pack_name_in_history($worldName, $normRef['pack_id'])
                                     ?? find_pack_name_in_state_cache($worldName, $normRef['pack_id']),
                    'required_by' => find_packs_requiring_uuid($normRef['pack_id']),
                ];
            }
        }
        $result[$pt]            = $resolved;
        $result[$pt . '_missing'] = $missing;
    }

    return $result;
}

// Aktiviert oder deaktiviert ein Pack für eine Welt inkl. automatischer Abhängigkeitsauflösung
function toggle_pack_for_world(string $worldName, string $packUuid, string $packType, bool $enable): bool {
    $state = get_state();

    if (!isset($state['world_packs'][$worldName])) {
        $state['world_packs'][$worldName] = ['behavior' => [], 'resource' => []];
    }

    if (!isset($state['world_packs'][$worldName][$packType])) {
        $state['world_packs'][$worldName][$packType] = [];
    }

    if ($enable) {
        $pack = find_installed_pack($packType, $packUuid);
        if (!$pack) return false;

        $alreadyIn = false;
        foreach ($state['world_packs'][$worldName][$packType] as &$ref) {
            if (pack_ref_matches_uuid($ref, $packUuid)) {
                $ref['enabled'] = true;
                $alreadyIn = true;
                break;
            }
        }
        unset($ref);

        if (!$alreadyIn) {
            $state['world_packs'][$worldName][$packType][] = [
                'pack_id' => $pack['uuid'],
                'version' => pack_version_array($pack['version']),
                'enabled' => true,
            ];
            add_pack_dependencies_for_world($state, $worldName, $pack);
        }
    } else {
        foreach ($state['world_packs'][$worldName][$packType] as &$ref) {
            if (pack_ref_matches_uuid($ref, $packUuid)) {
                $ref['enabled'] = false;
                break;
            }
        }
        unset($ref);
    }

    return save_state($state);
}

// Entfernt ein einzelnes Pack aus einer Welt und schreibt die Packs neu
function remove_pack_from_world(string $worldName, string $uuid, string $type): array {
    $state = get_state();
    $packs = $state['world_packs'][$worldName][$type] ?? [];
    $state['world_packs'][$worldName][$type] = array_values(array_filter($packs, function ($ref) use ($uuid) {
        $n = normalize_pack_ref($ref);
        return !($n && strtolower($n['pack_id']) === strtolower($uuid));
    }));
    save_state($state);
    apply_world_packs($worldName);
    return ['success' => true];
}

// Aktualisiert die Versions-Referenz eines Packs in einer Welt auf die installierte Version
function update_pack_ref_version(string $worldName, string $uuid, string $type): bool {
    $installed = find_installed_pack($type, $uuid); // UUID-only: findet jede installierte Version
    if (!$installed) return false;

    $state = get_state();
    $found = false;
    foreach ($state['world_packs'][$worldName][$type] ?? [] as &$ref) {
        if (pack_ref_matches_uuid($ref, $uuid)) {
            $ref['pack_id'] = $installed['uuid'];
            $ref['version'] = pack_version_array($installed['version']);
            $found = true;
            break;
        }
    }
    unset($ref);
    if (!$found) return false;
    save_state($state);
    apply_world_packs($worldName);
    return true;
}

// Schreibt die aktiven Packs einer Welt in die world_*_packs.json-Dateien
function apply_world_packs(string $worldName): void {
    $worldPath = MC_WORLDS_DIR . '/' . $worldName;
    if (!is_dir($worldPath)) return;

    $packs = get_world_packs($worldName);

    foreach (['behavior' => 'world_behavior_packs', 'resource' => 'world_resource_packs'] as $type => $file) {
        $activePacks = [];

        foreach (($packs[$type] ?? []) as $entry) {
            if (($entry['enabled'] ?? true) === false) continue;
            $ref = normalize_pack_ref($entry);
            if ($ref === null) continue;

            $uuid    = $ref['pack_id'];
            $version = $ref['version'];

            $installed = find_installed_pack($type, $uuid, $version);

            // Rückwärtskompatibilität für alten State:
            // Wenn früher nur UUID gespeichert war, nimm das installierte Pack.
            if (!$installed && $version === null) {
                $installed = find_installed_pack($type, $uuid);
            }

            // Pack nicht installiert → nicht in die World-JSON schreiben,
            // sonst wirft der Server "pack not found and was ignored".
            if (!$installed) continue;

            if ($installed) {
                $activePacks[] = [
                    'pack_id' => $installed['uuid'],
                    'version' => $version !== null ? pack_version_array($version) : pack_version_array($installed['version']),
                ];
            }
        }

        if (file_put_contents(
            $worldPath . '/' . $file . '.json',
            json_encode($activePacks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) === false) {
            error_log("[mcadmin] apply_world_packs: Konnte $file.json für '$worldName' nicht schreiben");
        }
    }

    // Alte Item-Formate (format_version < "1.20") in aktiven Behavior Packs upgraden.
    // BDS 1.21.20+ hat das Holiday Creator Features-Experiment entfernt, das ältere
    // Items aktivierte. upgrade_legacy_item_formats() patcht die Pack-Dateien direkt
    // auf format_version "1.20.80" — damit laden Items ohne Experiment in BDS 1.21.20+.
    // Wird auch für bereits installierte Packs ausgeführt (retroaktiver Fix).
    foreach (($packs['behavior'] ?? []) as $ref) {
        if (($ref['enabled'] ?? true) === false) continue;
        $normRef = normalize_pack_ref($ref);
        if ($normRef === null) continue;
        $installedP = find_installed_pack('behavior', $normRef['pack_id'], $normRef['version']);
        if (!$installedP && $normRef['version'] === null) {
            $installedP = find_installed_pack('behavior', $normRef['pack_id']);
        }
        if (!$installedP) continue;
        upgrade_legacy_item_formats(MC_PACKS_BEHAVIOR_DIR . '/' . $installedP['folder']);
    }

    // spawnradius immer auf 0 setzen — auch für bereits importierte Welten.
    // Sonst spawnen Spieler zufällig im Umkreis statt exakt am Spawnpunkt.
    $levelDat = $worldPath . '/level.dat';
    if (file_exists($levelDat)) {
        $ldRaw = (string)file_get_contents($levelDat);
        if (strlen($ldRaw) > 8) {
            $ldNbt  = substr($ldRaw, 8);
            $srPat  = "\x03" . pack('v', strlen('spawnradius')) . 'spawnradius';
            $srPos  = strpos($ldNbt, $srPat);
            if ($srPos !== false) {
                $srValOff = 8 + $srPos + strlen($srPat);
                $curVal = unpack('V', substr($ldRaw, $srValOff, 4))[1];
                if ($curVal !== 0) {
                    $ldRaw = substr($ldRaw, 0, $srValOff) . pack('V', 0) . substr($ldRaw, $srValOff + 4);
                    file_put_contents($levelDat, $ldRaw);
                }
            }
        }
    }
}

// Installiert eine Pack-Datei (.mcpack, .mcaddon, .zip) oder leitet .mcworld weiter
function install_pack(string $tmpPath, string $originalName): array {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mcpack', 'mcaddon', 'mcworld', 'zip']))
        return ['success' => false, 'message' => "Ungültiger Dateityp: .$ext"];
    if ($ext === 'mcworld') {
        return install_world($tmpPath, $originalName);
    }
    $tmpDir = sys_get_temp_dir() . '/mcpack_' . uniqid();
    mkdir($tmpDir, 0755, true);
    extract_zip($tmpPath, $tmpDir);
    $results = [];
    install_pack_dir($tmpDir, $results);
    exec('rm -rf ' . escapeshellarg($tmpDir));
    if (empty($results)) return ['success' => false, 'message' => 'Kein gültiges Pack gefunden'];
    $msg = implode(', ', array_map(fn($r) => "{$r['name']} ({$r['type']} pack)", $results));
    return ['success' => true, 'message' => $msg, 'installed' => $results];
}

// Merkt ein Pack als "für diese Welt importiert" im State, damit es beim Weltlöschen aufgeräumt wird.
function track_pack_imported_for_world(string $worldName, string $uuid, string $type, $version, string $folder = ''): void {
    $state = get_state();
    if (!isset($state['world_imported_packs'][$worldName])) {
        $state['world_imported_packs'][$worldName] = ['behavior' => [], 'resource' => []];
    }
    $list = &$state['world_imported_packs'][$worldName][$type];
    foreach ($list as $entry) {
        $ref = normalize_pack_ref($entry);
        if ($ref && strtolower($ref['pack_id']) === strtolower($uuid)) return; // bereits eingetragen
    }
    $list[] = ['folder' => $folder, 'pack_id' => $uuid, 'version' => pack_version_array($version)];
    save_state($state);
}

// Installiert ein Pack und aktiviert es sofort für eine bestimmte Welt
function install_pack_for_world(string $tmpPath, string $originalName, string $worldName): array {
    $r = install_pack($tmpPath, $originalName);
    if (!$r['success']) return $r;
    $activated = 0;
    foreach ($r['installed'] as $pack) {
        if (!empty($pack['uuid']) && toggle_pack_for_world($worldName, $pack['uuid'], $pack['type'], true)) {
            $activated++;
            track_pack_imported_for_world($worldName, $pack['uuid'], $pack['type'], $pack['version'] ?? [0,0,0], $pack['folder'] ?? '');
        }
    }
    if ($activated > 0) apply_world_packs($worldName);
    $count = count($r['installed']);
    return ['success' => true, 'message' => "$count Pack(s) installiert und für '$worldName' aktiviert"];
}

// Löscht ein selbst installiertes Pack: deaktiviert es in allen Welten, entfernt den Ordner.
// Schlägt fehl, wenn das Pack kein user-pack ist (Schutz vor Löschen von Bedrock-Systempacks).
function delete_pack(string $uuid, string $type): array {
    $dir = $type === 'behavior' ? MC_PACKS_BEHAVIOR_DIR : MC_PACKS_RESOURCE_DIR;
    if (!is_dir($dir)) return ['success' => false, 'message' => 'Pack-Verzeichnis nicht gefunden'];

    // Pack-Ordner anhand UUID suchen
    $packPath = null;
    foreach (scandir($dir) as $d) {
        if ($d === '.' || $d === '..') continue;
        $path     = $dir . '/' . $d;
        $manifest = $path . '/manifest.json';
        if (!is_dir($path) || !file_exists($manifest)) continue;
        $data = json_decode(file_get_contents($manifest), true) ?: [];
        if (strtolower($data['header']['uuid'] ?? '') === strtolower($uuid)) {
            $packPath = $path;
            break;
        }
    }
    if (!$packPath) return ['success' => false, 'message' => 'Pack nicht gefunden'];
    if (!is_mcadmin_user_pack($packPath))
        return ['success' => false, 'message' => 'Nur selbst installierte Packs können gelöscht werden'];

    // Pack aus allen Welten deaktivieren
    $state  = get_state();
    $worlds = array_keys($state['world_packs'] ?? []);
    foreach ($worlds as $world) {
        toggle_pack_for_world($world, $uuid, $type, false);
        apply_world_packs($world);
    }

    exec('rm -rf ' . escapeshellarg($packPath));
    return ['success' => true, 'message' => 'Pack gelöscht'];
}

// Ersetzt ein installiertes Pack durch eine neue Version und aktualisiert alle Welt-Referenzen
function replace_pack(string $oldUuid, string $oldType, string $tmpPath, string $originalName): array {
    // Betroffene Welten VOR der Installation ermitteln
    $usage    = get_pack_world_usage($oldType, $oldUuid);
    $affected = $usage['used_by_worlds'];

    $r = install_pack($tmpPath, $originalName);
    if (!$r['success']) return $r;

    get_installed_packs('behavior', false, true);
    get_installed_packs('resource', false, true);

    // Neues Pack ermitteln — gleicher Typ bevorzugt
    $newPack = null;
    foreach ($r['installed'] ?? [] as $p) {
        if ($p['type'] === $oldType) { $newPack = $p; break; }
    }
    if (!$newPack) $newPack = $r['installed'][0] ?? null;
    if (!$newPack) return ['success' => false, 'message' => 'Kein gültiges Pack im Upload gefunden'];

    $newUuid = $newPack['uuid'];
    $newType = $newPack['type'];

    foreach ($affected as $world) {
        toggle_pack_for_world($world, $oldUuid, $oldType, false);
        toggle_pack_for_world($world, $newUuid, $newType, true);
        apply_world_packs($world);
    }

    // Alten Pack-Ordner entfernen wenn UUID sich geändert hat
    if (strtolower($newUuid) !== strtolower($oldUuid)) {
        $dir = $oldType === 'behavior' ? MC_PACKS_BEHAVIOR_DIR : MC_PACKS_RESOURCE_DIR;
        foreach (array_diff((array)@scandir($dir), ['.', '..']) as $d) {
            $p = $dir . '/' . $d;
            if (!is_dir($p)) continue;
            $mf = json_decode((string)@file_get_contents($p . '/manifest.json'), true) ?? [];
            if (strtolower($mf['header']['uuid'] ?? '') === strtolower($oldUuid)) {
                exec('rm -rf ' . escapeshellarg($p));
                break;
            }
        }
    }

    $cnt = count($affected);
    $msg = "{$newPack['name']} aktualisiert";
    if ($cnt > 0) $msg .= " · $cnt Welt(en) angepasst";
    return ['success' => true, 'message' => $msg, 'affected_worlds' => $cnt];
}

// Durchsucht ein Verzeichnis rekursiv und installiert gefundene Packs (anhand manifest.json)
function install_pack_dir(string $dir, array &$results): void {
    $manifest = $dir . '/manifest.json';
    if (file_exists($manifest)) {
        $data    = json_decode(file_get_contents($manifest), true);
        $modules  = $data['modules'] ?? [];
        $type     = 'resource'; // Default für unbekannte/fehlende Typen (tolerant)
        $skipPack = false;
        foreach ($modules as $m) {
            $mtype = strtolower($m['type'] ?? '');
            // Behavior-Pack-Typen (inkl. alter Script-API 1.0 und Client-Scripts)
            if (in_array($mtype, ['data', 'script', 'interface', 'javascript', 'client_data'], true)) {
                $type = 'behavior';
                break;
            }
            // Skin-Packs, Welt-Templates und Persona-Pieces sind keine BDS-Server-Packs
            if (in_array($mtype, ['skin_pack', 'world_template', 'persona_piece'], true)) {
                $skipPack = true;
                break;
            }
        }
        if ($skipPack) return; // Kein installierbares Server-Pack → überspringen
        $rawName = (string)($data['header']['name'] ?? basename($dir));
        $name    = trim(preg_replace('/§[0-9a-fk-orA-FK-OR]/u', '', resolve_pack_name($dir, $rawName)));
        if ($name === '') $name = basename($dir);
        $destBase = $type === 'behavior' ? MC_PACKS_BEHAVIOR_DIR : MC_PACKS_RESOURCE_DIR;
        if (!is_dir($destBase)) mkdir($destBase, 0755, true);
        $destPath = $destBase . '/' . sanitize_dirname($name);
        copy_pack_skip_subpacks($dir, $destPath);
        mark_mcadmin_user_pack($destPath);
        $results[] = [
            'name'    => $name,
            'type'    => $type,
            'uuid'    => strtolower($data['header']['uuid'] ?? ''),
            'folder'  => basename($destPath),
            'version' => $data['header']['version'] ?? [0, 0, 0],
        ];
        return;
    }
    foreach (scandir($dir) as $sub) {
        if ($sub === '.' || $sub === '..') continue;
        $p = $dir . '/' . $sub;
        if (is_dir($p)) {
            install_pack_dir($p, $results);
        } elseif (is_file($p) && preg_match('/\.(mcaddon|mcpack|zip)$/i', $sub)) {
            // Verschachtelte Archive entpacken und rekursiv verarbeiten.
            // Laut offizieller Bedrock-Doku darf ein .mcaddon beliebig viele
            // weitere .mcaddon/.mcpack-Dateien enthalten (jede Tiefe erlaubt).
            $nestedTmp = sys_get_temp_dir() . '/mcpack_nested_' . bin2hex(random_bytes(8));
            if (@mkdir($nestedTmp, 0755, true) && extract_zip($p, $nestedTmp)) {
                install_pack_dir($nestedTmp, $results);
            }
            exec('rm -rf ' . escapeshellarg($nestedTmp));
        }
    }
}

// ============================================================
// BACKUP MANAGEMENT
// ============================================================

// Gibt alle Backup-Archive sortiert nach Datum zurück (neueste zuerst)
function get_backups(): array {
    $backups = [];
    foreach (glob(MC_BACKUP_DIR . '/*.tar.gz') as $file) {
        $backups[] = [
            'filename'   => basename($file),
            'size_human' => format_bytes(filesize($file)),
            'date'       => date('d.m.Y H:i:s', filemtime($file)),
            'timestamp'  => filemtime($file),
        ];
    }
    usort($backups, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
    return $backups;
}

// Erstellt synchron ein tar.gz-Backup des Server-Verzeichnisses
function create_backup(string $label = ''): array {
    $timestamp = date('Y-m-d_H-i-s');
    $label     = $label ? '_' . sanitize_dirname($label) : '';
    $filename  = "backup_{$timestamp}{$label}.tar.gz";
    $dest      = MC_BACKUP_DIR . '/' . $filename;
    exec('tar -czf ' . escapeshellarg($dest) . ' -C ' . escapeshellarg(dirname(MC_SERVER_DIR))
        . ' ' . escapeshellarg(basename(MC_SERVER_DIR)) . ' 2>&1', $out, $code);
    if ($code !== 0)
        exec('tar -czf ' . escapeshellarg($dest) . ' -C ' . escapeshellarg(MC_SERVER_DIR)
            . ' worlds server.properties whitelist.json permissions.json 2>&1', $out, $code);
    cleanup_old_backups();
    if ($code === 0) discord_notify('backup_created', "💾 **Backup erstellt:** `$filename`");
    return ['success' => $code === 0, 'filename' => $filename,
        'message' => $code === 0 ? "Backup erstellt: $filename" : "Fehler: " . implode("\n", $out)];
}

// Startet ein Backup im Hintergrund (nohup) und schreibt den Fortschritt in eine Status-Datei
function start_backup_async(string $label = ''): array {
    $statusFile = '/tmp/mcadmin_backup_status.json';
    $wrapper    = '/tmp/mcadmin_backup_wrapper.sh';
    $lockFile   = '/tmp/mcadmin_backup.lock';

    // Exklusives Lock verhindert Race Condition bei gleichzeitigen Anfragen
    $lock = fopen($lockFile, 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['success' => false, 'message' => 'Backup läuft bereits'];
    }

    $cur = json_decode(@file_get_contents($statusFile) ?: '{}', true);
    if (($cur['status'] ?? '') === 'running' && (time() - ($cur['ts'] ?? 0)) < 300) {
        flock($lock, LOCK_UN); fclose($lock);
        return ['success' => false, 'message' => 'Backup läuft bereits'];
    }

    $timestamp = date('Y-m-d_H-i-s');
    $safeLabel = $label ? '_' . sanitize_dirname($label) : '';
    $filename  = "backup_{$timestamp}{$safeLabel}.tar.gz";
    $dest      = MC_BACKUP_DIR . '/' . $filename;
    $parentDir = dirname(MC_SERVER_DIR);
    $baseName  = basename(MC_SERVER_DIR);
    $serverDir = MC_SERVER_DIR;

    $sh = <<<'BASH'
#!/bin/bash
STATUS="__STATUS__"
DEST="__DEST__"
PARENT_DIR="__PARENT_DIR__"
BASE_NAME="__BASE_NAME__"
SERVER_DIR="__SERVER_DIR__"
FILENAME="__FILENAME__"

upd_status() {
    local msg
    msg=$(printf '%s' "$3" | tr -d '"\\' | tr '\n' ' ')
    printf '{"status":"%s","message":"%s","filename":"%s","ts":%s}\n' "$1" "$msg" "$FILENAME" "$(date +%s)" > "$STATUS"
}

upd_status "running" "Komprimiere Daten..." "$FILENAME"

if tar -czf "$DEST" -C "$PARENT_DIR" "$BASE_NAME" > /dev/null 2>&1; then
    SIZE=$(du -sh "$DEST" 2>/dev/null | cut -f1 || echo "?")
    upd_status "done" "Backup erstellt: $FILENAME ($SIZE)" "$FILENAME"
elif tar -czf "$DEST" -C "$SERVER_DIR" worlds server.properties whitelist.json permissions.json > /dev/null 2>&1; then
    SIZE=$(du -sh "$DEST" 2>/dev/null | cut -f1 || echo "?")
    upd_status "done" "Backup erstellt: $FILENAME ($SIZE)" "$FILENAME"
else
    upd_status "error" "Backup fehlgeschlagen" ""
fi
BASH;

    $sh = str_replace(
        ['__STATUS__', '__DEST__', '__PARENT_DIR__', '__BASE_NAME__', '__SERVER_DIR__', '__FILENAME__'],
        [$statusFile, $dest, $parentDir, $baseName, $serverDir, $filename],
        $sh
    );

    file_put_contents($wrapper, $sh);
    chmod($wrapper, 0755);
    file_put_contents($statusFile, json_encode([
        'status'   => 'running',
        'message'  => 'Starte Backup...',
        'filename' => $filename,
        'ts'       => time(),
    ]));
    exec('nohup bash ' . escapeshellarg($wrapper) . ' > /dev/null 2>&1 &');
    flock($lock, LOCK_UN); fclose($lock);
    return ['success' => true, 'filename' => $filename];
}

// Liest den aktuellen Backup-Status und sendet Discord-Nachricht bei Abschluss
function get_backup_status(): array {
    $f = '/tmp/mcadmin_backup_status.json';
    if (!file_exists($f)) return ['status' => 'idle', 'message' => ''];
    $fp = fopen($f, 'c+');
    if (!$fp) return ['status' => 'idle', 'message' => ''];
    if (!flock($fp, LOCK_EX)) { fclose($fp); return ['status' => 'idle', 'message' => '']; }
    $data = json_decode(stream_get_contents($fp), true) ?? ['status' => 'idle', 'message' => ''];
    if (($data['status'] ?? '') === 'done' && empty($data['discord_sent']) && !empty($data['filename'])) {
        discord_notify('backup_created', "💾 **Backup erstellt:** `{$data['filename']}`");
        cleanup_old_backups();
        $data['discord_sent'] = true;
        ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($data));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $data;
}

// Stellt ein Backup wieder her (tar.gz wird ins Server-Verzeichnis entpackt)
function restore_backup(string $filename): array {
    $path = MC_BACKUP_DIR . '/' . basename($filename);
    if (!file_exists($path)) return ['success' => false, 'message' => 'Backup nicht gefunden'];
    exec('tar -xzf ' . escapeshellarg($path) . ' -C ' . escapeshellarg(dirname(MC_SERVER_DIR)) . ' 2>&1', $out, $code);
    return ['success' => $code === 0, 'message' => $code === 0 ? 'Backup wiederhergestellt' : "Fehler: " . implode("\n", $out)];
}

// Löscht eine einzelne Backup-Datei
function delete_backup(string $filename): bool {
    $path = MC_BACKUP_DIR . '/' . basename($filename);
    return file_exists($path) && unlink($path);
}

// Löscht die ältesten Backups, sobald das konfigurierbare Limit überschritten wird
function cleanup_old_backups(): void {
    $settings = load_settings();
    $limit    = max(1, (int)($settings['backup_max_count'] ?? MAX_BACKUP_COUNT));
    $backups  = get_backups();
    if (count($backups) > $limit)
        foreach (array_slice($backups, $limit) as $b) unlink(MC_BACKUP_DIR . '/' . $b['filename']);
}

// ============================================================
// VERSION CHECK & ASYNC UPDATE
// ============================================================

// Ruft die neueste Bedrock-Server-Version von der Mojang-API ab (mit Datei-Cache)
function get_latest_bedrock_version(): array {
    if (file_exists(MC_VERSION_CACHE_FILE)) {
        $cache = json_decode(file_get_contents(MC_VERSION_CACHE_FILE), true);
        if ($cache && ($cache['version'] ?? '') !== 'unbekannt'
            && (time() - ($cache['timestamp'] ?? 0)) < MC_VERSION_CACHE_TTL) return $cache;
    }

    $endpoint = 'https://net-secondary.web.minecraft-services.net/api/v1.0/download/links';

    // Methode 1: offizielle Minecraft-Download-API
    $json = shell_exec('curl -sL --max-time 15 -A ' . escapeshellarg('Mozilla/5.0') . ' ' . escapeshellarg($endpoint) . ' 2>/dev/null') ?: '';
    if ($json) {
        $data = json_decode($json, true);
        foreach (($data['result']['links'] ?? []) as $link) {
            if (($link['downloadType'] ?? '') === 'serverBedrockLinux' && !empty($link['downloadUrl'])) {
                $url = $link['downloadUrl'];
                if (preg_match('/bedrock-server-([0-9.]+)\.zip/', $url, $m)) {
                    $result = ['version' => $m[1], 'download_url' => $url, 'timestamp' => time()];
                    file_put_contents(MC_VERSION_CACHE_FILE, json_encode($result));
                    return $result;
                }
            }
        }
    }

    // Methode 2: file_get_contents Fallback auf dieselbe API
    $ctx  = stream_context_create([
        'http' => [
            'timeout' => 15,
            'header'  => "User-Agent: Mozilla/5.0\r\n",
        ],
    ]);
    $json = @file_get_contents($endpoint, false, $ctx) ?: '';
    if ($json) {
        $data = json_decode($json, true);
        foreach (($data['result']['links'] ?? []) as $link) {
            if (($link['downloadType'] ?? '') === 'serverBedrockLinux' && !empty($link['downloadUrl'])) {
                $url = $link['downloadUrl'];
                if (preg_match('/bedrock-server-([0-9.]+)\.zip/', $url, $m)) {
                    $result = ['version' => $m[1], 'download_url' => $url, 'timestamp' => time()];
                    file_put_contents(MC_VERSION_CACHE_FILE, json_encode($result));
                    return $result;
                }
            }
        }
    }

    // Methode 3: Fallback über die offizielle Bedrock-Downloadseite
    $page = 'https://www.minecraft.net/en-us/download/server/bedrock';
    $html = shell_exec('curl -sL --max-time 15 -A ' . escapeshellarg('Mozilla/5.0') . ' ' . escapeshellarg($page) . ' 2>/dev/null') ?: '';
    if ($html && preg_match('#https://www\.minecraft\.net/bedrockdedicatedserver/bin-linux/bedrock-server-([0-9.]+)\.zip#', $html, $m)) {
        $result = ['version' => $m[1], 'download_url' => $m[0], 'timestamp' => time()];
        file_put_contents(MC_VERSION_CACHE_FILE, json_encode($result));
        return $result;
    }

    return ['version' => 'unbekannt', 'download_url' => null, 'timestamp' => time()];
}

// Startet ein Server-Update im Hintergrund (Backup → Stop → Download → Install → Start)
function run_update_async(string $version): array {
    $statusFile = sys_get_temp_dir() . '/mc_update_status.json';
    $script     = '/tmp/mc_update.sh';
    $mcDir      = MC_SERVER_DIR;
    $svcName    = MC_SERVICE_NAME;
    $backupDir  = MC_BACKUP_DIR;
    $latest     = get_latest_bedrock_version();
    $downloadUrl = $latest['download_url'] ?? '';
    if (($latest['version'] ?? '') !== $version || !$downloadUrl) {
        $downloadUrl = "https://www.minecraft.net/bedrockdedicatedserver/bin-linux/bedrock-server-{$version}.zip";
    }

    $sh = <<<BASH
#!/bin/bash
STATUS_FILE="{$statusFile}"
MC_DIR="{$mcDir}"
SVC="{$svcName}"
VER="{$version}"
BACKUP_DIR="{$backupDir}"
URL="{$downloadUrl}"
LOG="/tmp/mc_update.log"
TMP="/tmp/bedrock-server-\$VER.zip"
TMPDIR="/tmp/mc_update_\$\$"
PRESERVE="/tmp/mc_update_preserve_\$\$"

exec >> "\$LOG" 2>&1

write() { printf '{"step":"%s","status":"%s","message":"%s","ts":%s}\n' "\$1" "\$2" "\$3" "\$(date +%s)" > "\$STATUS_FILE"; }
fail() { write "\$1" "error" "\$2"; echo "FEHLER: \$2"; cleanup_tmp; exit 1; }
cleanup_tmp() { rm -rf "\$TMPDIR" "\$TMP" "\$PRESERVE"; }

PROTECTED_FILES=(server.properties whitelist.json allowlist.json permissions.json valid_known_packs.json)
PROTECTED_DIRS=(worlds behavior_packs resource_packs)

copy_if_exists() {
    src="\$1"
    dst="\$2"
    if [ -e "\$src" ]; then
        mkdir -p "\$(dirname "\$dst")" || return 1
        cp -a "\$src" "\$dst" || return 1
    fi
    return 0
}

restore_protected_data() {
    echo "Stelle geschuetzte Daten wieder her..."

    for file in "\${PROTECTED_FILES[@]}"; do
        if [ -f "\$PRESERVE/\$file" ]; then
            cp -a "\$PRESERVE/\$file" "\$MC_DIR/\$file" || return 1
            echo "Wiederhergestellt: \$file"
        fi
    done

    # Welten exakt wiederherstellen, damit aktive Map und Weltinhalte unveraendert bleiben.
    if [ -d "\$PRESERVE/worlds" ]; then
        rm -rf "\$MC_DIR/worlds"
        cp -a "\$PRESERVE/worlds" "\$MC_DIR/worlds" || return 1
        echo "Wiederhergestellt: worlds"
    fi

    # Packs vorsichtig zurueck mergen: vorhandene/importierte Packs bleiben erhalten.
    # Neue Mojang-Dateien aus dem Update werden dabei nicht komplett geloescht.
    for dir in behavior_packs resource_packs; do
        if [ -d "\$PRESERVE/\$dir" ]; then
            mkdir -p "\$MC_DIR/\$dir" || return 1
            cp -a "\$PRESERVE/\$dir/." "\$MC_DIR/\$dir/" || return 1
            echo "Zusammengefuehrt: \$dir"
        fi
    done

    return 0
}

echo "============================================================"
echo "Minecraft Bedrock Update gestartet: \$(date)"
echo "Zielversion: \$VER"
echo "MC_DIR: \$MC_DIR"
echo "URL: \$URL"
echo "============================================================"

write "stop" "running" "Server wird gestoppt..."
sudo systemctl stop "\$SVC" 2>/dev/null || true
pkill -f bedrock_server 2>/dev/null || true
screen -S minecraft -X quit 2>/dev/null || true
tmux kill-session -t minecraft 2>/dev/null || true
sleep 3
write "stop" "done" "Server gestoppt"

write "backup" "running" "Erstelle Backup..."
BFILE="\$BACKUP_DIR/backup_\$(date +%Y-%m-%d_%H-%M-%S)_pre_update_\$VER.tar.gz"
mkdir -p "\$BACKUP_DIR" || fail "backup" "Backup-Ordner konnte nicht erstellt werden"
mkdir -p "\$PRESERVE" || fail "backup" "Temp-Sicherung konnte nicht erstellt werden"

# Vollbackup fuer manuelle Wiederherstellung.
tar -czf "\$BFILE" -C "\$(dirname "\$MC_DIR")" "\$(basename "\$MC_DIR")" 2>/dev/null || \
tar -czf "\$BFILE" -C "\$MC_DIR" worlds server.properties whitelist.json allowlist.json permissions.json behavior_packs resource_packs 2>/dev/null || \
fail "backup" "Backup fehlgeschlagen"

# Zusaetzliche Arbeitskopie der wichtigen Nutzerdaten fuer automatische Wiederherstellung nach dem Update.
for file in "\${PROTECTED_FILES[@]}"; do
    copy_if_exists "\$MC_DIR/\$file" "\$PRESERVE/\$file" || fail "backup" "Konnte \$file nicht sichern"
done
for dir in "\${PROTECTED_DIRS[@]}"; do
    copy_if_exists "\$MC_DIR/\$dir" "\$PRESERVE/\$dir" || fail "backup" "Konnte \$dir nicht sichern"
done

write "backup" "done" "Backup erstellt"

write "download" "running" "Lade bedrock-server-\$VER.zip..."
rm -f "\$TMP"
wget -q -U "Mozilla/5.0" -O "\$TMP" "\$URL" 2>/dev/null \
    || curl -sL -A "Mozilla/5.0" -o "\$TMP" "\$URL" 2>/dev/null
[ -s "\$TMP" ] || fail "download" "Download fehlgeschlagen (leere Datei)"
unzip -t "\$TMP" > /dev/null 2>&1 || fail "download" "Download fehlgeschlagen (ungueltige ZIP-Datei)"
write "download" "done" "Download abgeschlossen"

write "install" "running" "Entpacke und installiere neue Serverdateien..."
mkdir -p "\$TMPDIR" || fail "install" "Temp-Ordner konnte nicht erstellt werden"
unzip -o -q "\$TMP" -d "\$TMPDIR" 2>/dev/null || fail "install" "Entpacken fehlgeschlagen"
[ -s "\$TMPDIR/bedrock_server" ] || fail "install" "Entpacken fehlgeschlagen – bedrock_server nicht gefunden"

if [ -f "\$MC_DIR/bedrock_server" ]; then
    cp -a "\$MC_DIR/bedrock_server" "\$MC_DIR/bedrock_server.bak_before_update" || fail "install" "Alte bedrock_server konnte nicht gesichert werden"
fi

mkdir -p "\$MC_DIR" || fail "install" "Minecraft-Ordner fehlt und konnte nicht erstellt werden"

for f in "\$TMPDIR"/*; do
    base="\$(basename "\$f")"

    case "\$base" in
        worlds|server.properties|whitelist.json|allowlist.json|permissions.json|valid_known_packs.json)
            echo "Schuetze vorhandene Nutzerdaten: \$base"
            continue
            ;;
    esac

    # Pack-Ordner werden gemergt, nicht geloescht.
    if [ "\$base" = "behavior_packs" ] || [ "\$base" = "resource_packs" ]; then
        echo "Merge Update-Pack-Ordner: \$base"
        mkdir -p "\$MC_DIR/\$base" || fail "install" "Pack-Ordner konnte nicht erstellt werden: \$base"
        cp -a "\$f/." "\$MC_DIR/\$base/" || fail "install" "Pack-Ordner konnte nicht kopiert werden: \$base"
        continue
    fi

    echo "Installiere Serverdatei: \$base"
    rm -rf "\$MC_DIR/\$base"
    cp -a "\$f" "\$MC_DIR/\$base" || fail "install" "Kopieren fehlgeschlagen: \$base"
done

[ -s "\$MC_DIR/bedrock_server" ] || fail "install" "Installation fehlgeschlagen – bedrock_server fehlt oder ist leer"
chmod +x "\$MC_DIR/bedrock_server" || fail "install" "chmod auf bedrock_server fehlgeschlagen"
write "install" "done" "Neue Serverdateien installiert"

write "restore" "running" "Stelle Welten, Einstellungen und Packs wieder her..."
restore_protected_data || fail "restore" "Wiederherstellung der gesicherten Daten fehlgeschlagen"
chmod +x "\$MC_DIR/bedrock_server" 2>/dev/null || true

# version.txt sicher neu schreiben:
# Falls die alte Datei root-owned/readonly ist, kann direktes Überschreiben per > scheitern.
# Darum schreiben wir zuerst eine neue Temp-Datei und ersetzen version.txt danach per mv.
write_version_file() {
    local tmpver="\$MC_DIR/.version.txt.tmp.\$\$"

    rm -f "\$tmpver" 2>/dev/null || true

    printf '%s
' "\$VER" > "\$tmpver" || return 1
    chmod 664 "\$tmpver" 2>/dev/null || true

    # Alte version.txt entfernen, falls möglich. Wenn sie nicht existiert, ist das okay.
    rm -f "\$MC_DIR/version.txt" 2>/dev/null || true

    mv "\$tmpver" "\$MC_DIR/version.txt" 2>/dev/null || {
        cp "\$tmpver" "\$MC_DIR/version.txt" 2>/dev/null || return 1
        rm -f "\$tmpver" 2>/dev/null || true
    }

    return 0
}

write_version_file || fail "restore" "version.txt konnte nicht geschrieben werden – bitte Schreibrechte fuer \$MC_DIR pruefen"

# Besitzrechte nach dem Schreiben korrigieren. Fehler ignorieren, weil das Script je nach Setup als www-data läuft.
if command -v chown >/dev/null 2>&1; then
    chown -R www-data:www-data "\$MC_DIR" 2>/dev/null || true
fi

sync
write "restore" "done" "Gesicherte Daten wiederhergestellt"

cleanup_tmp

write "start" "running" "Server wird gestartet..."
pkill -f bedrock_server 2>/dev/null || true
sleep 2
STARTED=0
for attempt in 1 2 3; do
    sudo systemctl start "\$SVC" 2>/dev/null
    sleep 5
    if [ "\$(systemctl is-active "\$SVC" 2>/dev/null)" = "active" ]; then
        STARTED=1
        break
    fi
    sudo systemctl stop "\$SVC" 2>/dev/null || true
    pkill -f bedrock_server 2>/dev/null || true
    sleep \$((attempt * 3))
done
if [ "\$STARTED" = "1" ]; then
    write "complete" "done" "Update auf \$VER abgeschlossen!"
else
    write "complete" "warn" "Update installiert – Server bitte manuell starten"
fi
BASH;

    file_put_contents($script, $sh); chmod($script, 0755);
    file_put_contents($statusFile, json_encode(['step'=>'init','status'=>'running','message'=>'Starte...','ts'=>time()]));
    exec("nohup bash " . escapeshellarg($script) . " > /tmp/mc_update.log 2>&1 &");
    discord_notify('server_update', "🔄 **Server-Update gestartet:** v{$version}");
    return ['success' => true];
}

// Liest den aktuellen Fortschritt des Server-Updates aus der Status-Datei
function get_update_status(): array {
    $f = sys_get_temp_dir() . '/mc_update_status.json';
    if (!file_exists($f)) return ['step'=>'idle','status'=>'idle','message'=>''];
    return json_decode(file_get_contents($f), true) ?? [];
}


// Prüft Minecraft-Server- und Panel-Versionen und sendet bei verfügbaren Updates eine Discord-Nachricht.
// Wird von cron.php genutzt. Es wird nichts installiert und kein Server gestartet/gestoppt.
function run_update_availability_check(bool $notify = true): array {
    $settings = load_settings();

    $mcCurrent = get_server_version();
    $mcLatest  = get_latest_bedrock_version();

    $panelCurrent = get_panel_version();
    $panelLatest  = get_latest_panel_version(true);

    $mcLatestVer = $mcLatest['version'] ?? 'unbekannt';
    $panelLatestSha = $panelLatest['sha'] ?? 'unbekannt';

    $mcUpdateAvailable = $mcLatestVer !== 'unbekannt'
        && $mcCurrent !== 'nicht installiert'
        && $mcLatestVer !== $mcCurrent;

    $panelUpdateAvailable = $panelLatestSha !== 'unbekannt'
        && $panelCurrent !== 'unbekannt'
        && $panelLatestSha !== $panelCurrent;

    $events = $settings['discord_events'] ?? [];
    $webhook = $settings['discord_webhook'] ?? '';
    $canNotify = $notify
        && !empty($events['update_available'])
        && $webhook !== ''
        && is_valid_discord_webhook($webhook);

    $sent = [];

    if ($canNotify && $mcUpdateAvailable && (($settings['update_check_last_mc_version'] ?? '') !== $mcLatestVer)) {
        discord_notify(
            'update_available',
            "🔔 **Minecraft Bedrock Update verfügbar**\nInstalliert: `{$mcCurrent}`\nVerfügbar: `{$mcLatestVer}`\n\nÖffne das Panel → **Updates**, um das Update zu starten."
        );
        $settings['update_check_last_mc_version'] = $mcLatestVer;
        $sent[] = 'minecraft';
    }

    if ($canNotify && $panelUpdateAvailable && (($settings['update_check_last_panel_sha'] ?? '') !== $panelLatestSha)) {
        discord_notify(
            'update_available',
            "🔔 **Panel-Update verfügbar**\nInstalliert: `{$panelCurrent}`\nGitHub: `{$panelLatestSha}`\n\nÖffne das Panel → **Einstellungen → Panel-Update**, um das Update zu starten."
        );
        $settings['update_check_last_panel_sha'] = $panelLatestSha;
        $sent[] = 'panel';
    }

    if ($sent) {
        save_settings($settings);
    }

    return [
        'success' => true,
        'minecraft' => [
            'current' => $mcCurrent,
            'latest' => $mcLatestVer,
            'update_available' => $mcUpdateAvailable,
        ],
        'panel' => [
            'current' => $panelCurrent,
            'latest' => $panelLatestSha,
            'update_available' => $panelUpdateAvailable,
        ],
        'notifications_sent' => $sent,
    ];
}

// ============================================================
// PANEL UPDATE
// ============================================================

// Gibt den installierten Panel-Commit-Hash aus der Versionsdatei zurück
function get_panel_version(): string {
    if (!file_exists(MC_PANEL_VERSION_FILE)) return 'unbekannt';
    return trim(file_get_contents(MC_PANEL_VERSION_FILE)) ?: 'unbekannt';
}

// Ruft den neuesten Panel-Commit-Hash von GitHub ab (mit Cache, überspringbar per $force)
function get_latest_panel_version(bool $force = false): array {
    $cacheFile = MC_PANEL_UPDATE_CACHE;
    $cacheTtl  = 300;
    if (!$force && file_exists($cacheFile)) {
        $c = json_decode(file_get_contents($cacheFile), true);
        if ($c && (time() - ($c['ts'] ?? 0)) < $cacheTtl) return $c;
    }
    $json = shell_exec('curl -sL --max-time 10 -A ' . escapeshellarg('Mozilla/5.0') .
        ' https://api.github.com/repos/Ronny-1979/mcadmin/commits/main 2>/dev/null') ?: '';
    $data = json_decode($json, true);
    $sha  = is_array($data) ? (substr($data['sha'] ?? '', 0, 7) ?: 'unbekannt') : 'unbekannt';
    $result = ['sha' => $sha, 'ts' => time()];
    @file_put_contents($cacheFile, json_encode($result));
    return $result;
}

// Startet das Panel-Update im Hintergrund über das install.sh-Update-Skript
function run_panel_update_async(): array {
    $statusFile = '/tmp/mcadmin_panel_update_status.json';
    $logFile    = '/tmp/mcadmin_panel_update.log';
    $wrapper    = '/tmp/mcadmin_panel_upd_wrapper.sh';
    $updateCmd  = '/usr/local/sbin/mcadmin-panel-update.sh';

    $cur = json_decode(@file_get_contents($statusFile) ?: '{}', true);
    if (($cur['status'] ?? '') === 'running' && (time() - ($cur['ts'] ?? 0)) < 300) {
        return ['success' => false, 'message' => 'Update läuft bereits'];
    }

    if (!file_exists($updateCmd)) {
        return [
            'success' => false,
            'message' => 'Panel-Update-Skript fehlt. Bitte einmal install.sh --update über die Shell ausführen.'
        ];
    }

    $sh = <<<'BASH'
#!/bin/bash

STATUS="/tmp/mcadmin_panel_update_status.json"
LOG="/tmp/mcadmin_panel_update.log"
UPDATE_CMD="/usr/local/sbin/mcadmin-panel-update.sh"
YES_FLAG="/tmp/mcadmin_yes"

export TERM=xterm

write_status() {
    local step="$1"
    local status="$2"
    local msg="$3"
    msg=$(printf '%s' "$msg" | tr -d '"\\' | tr '\n' ' ')
    printf '{"step":"%s","status":"%s","message":"%s","ts":%s}\n' "$step" "$status" "$msg" "$(date +%s)" > "$STATUS"
}

cleanup() {
    rm -f "$YES_FLAG"
}

trap cleanup EXIT

: > "$LOG"

write_status "running" "running" "Starte Panel-Update über install.sh..."
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starte Panel-Update über sudo /bin/bash $UPDATE_CMD" >> "$LOG"

touch "$YES_FLAG"

if sudo -n /bin/bash "$UPDATE_CMD" >> "$LOG" 2>&1; then
    rm -f "$YES_FLAG"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Panel-Update erfolgreich abgeschlossen" >> "$LOG"
    write_status "complete" "done" "Panel aktualisiert! Seite neu laden."
else
    CODE=$?
    rm -f "$YES_FLAG"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] FEHLER: Panel-Update fehlgeschlagen. Exit-Code: $CODE" >> "$LOG"
    write_status "error" "error" "Panel-Update fehlgeschlagen. Log prüfen."
    exit "$CODE"
fi
BASH;

    file_put_contents($wrapper, $sh);
    chmod($wrapper, 0755);

    file_put_contents($statusFile, json_encode([
        'step'    => 'init',
        'status'  => 'running',
        'message' => 'Starte Panel-Update...',
        'ts'      => time(),
    ]));

    exec('nohup bash ' . escapeshellarg($wrapper) . ' < /dev/null > /dev/null 2>&1 &');

    return ['success' => true];
}

// Gibt die letzten 100 Zeilen des Panel-Update-Logs zurück
function get_panel_update_log(): array {
    $f = '/tmp/mcadmin_panel_update.log';
    if (!file_exists($f)) return ['lines' => [], 'exists' => false];
    $lines = file($f, FILE_IGNORE_NEW_LINES) ?: [];
    return ['lines' => array_slice($lines, -100), 'exists' => true];
}

// Liest den aktuellen Panel-Update-Status aus der Status-Datei
function get_panel_update_status(): array {
    $f = '/tmp/mcadmin_panel_update_status.json';
    if (!file_exists($f)) return ['step'=>'idle','status'=>'idle','message'=>''];
    return json_decode(file_get_contents($f), true) ?? [];
}

// ============================================================
// PLAYER STATISTICS
// ============================================================

// Gibt mögliche Server-Log-Dateipfade in Reihenfolge zurück
function get_log_files(): array {
    return [MC_LOG_FILE, MC_SERVER_DIR.'/server.log', MC_SERVER_DIR.'/logs/server.log'];
}

// Analysiert das Server-Log und erstellt Spielzeit-, Sessions- und Kick-Statistiken je Spieler
function get_player_stats(): array {
    $cacheFile = sys_get_temp_dir() . '/mcadmin_pstats.json';
    $logFiles  = get_log_files();
    $logFile   = null;
    foreach ($logFiles as $f) { if (file_exists($f)) { $logFile = $f; break; } }
    if ($logFile && file_exists($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true) ?? [];
        if (($c['mtime'] ?? 0) >= filemtime($logFile) && isset($c['data'])) return $c['data'];
    }

    $lines = [];
    foreach ($logFiles as $f) { if (file_exists($f)) { $lines = file($f, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[]; break; } }
    if (empty($lines)) {
        $out = shell_exec('journalctl -u '.escapeshellarg(MC_SERVICE_NAME).' --no-pager --output=short 2>/dev/null');
        if ($out) $lines = array_filter(explode("\n", $out));
    }
    $stats = []; $active = [];
    foreach ($lines as $line) {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*Player connected: ([^,]+),\s*xuid:\s*(\d*)/', $line, $m)
         || preg_match('/(\w{3}\s+\d+\s+[\d:]+).*Player connected: ([^,]+),\s*xuid:\s*(\d*)/', $line, $m)) {
            $ts = strtotime($m[1]) ?: time(); $name = trim($m[2]); $xuid = trim($m[3]??'');
            if (!isset($stats[$name])) $stats[$name]=['name'=>$name,'xuid'=>$xuid,'sessions'=>0,'playtime_seconds'=>0,'first_seen'=>$ts,'last_seen'=>$ts,'kicks'=>0];
            $stats[$name]['sessions']++;
            $stats[$name]['last_seen']  = max($stats[$name]['last_seen'],  $ts);
            $stats[$name]['first_seen'] = min($stats[$name]['first_seen'], $ts);
            if ($xuid) $stats[$name]['xuid'] = $xuid;
            $active[$name] = $ts;
        }
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*Player disconnected: ([^,]+)/', $line, $m)
         || preg_match('/(\w{3}\s+\d+\s+[\d:]+).*Player disconnected: ([^,]+)/', $line, $m)) {
            $ts = strtotime($m[1]) ?: time(); $name = trim($m[2]);
            if (isset($active[$name])) { $d=$ts-$active[$name]; if($d>0&&$d<604800) $stats[$name]['playtime_seconds']+=$d; unset($active[$name]); }
            if (isset($stats[$name])) $stats[$name]['last_seen']=max($stats[$name]['last_seen'],$ts);
        }
        if (preg_match('/Kicking player ([^:]+):/', $line, $m)) { $name=trim($m[1]); if(isset($stats[$name])) $stats[$name]['kicks']++; }
    }
    foreach ($active as $name => $loginTs) { if (isset($stats[$name])) { $delta = time() - $loginTs; if ($delta > 0) $stats[$name]['playtime_seconds'] += $delta; } }
    foreach ($stats as &$s) {
        $s['playtime_human']   = format_duration($s['playtime_seconds']);
        $s['first_seen_human'] = $s['first_seen'] ? date('d.m.Y H:i', $s['first_seen']) : '—';
        $s['last_seen_human']  = $s['last_seen']  ? date('d.m.Y H:i', $s['last_seen'])  : '—';
        $s['online']           = isset($active[$s['name']]);
    }
    unset($s);
    usort($stats, fn($a,$b) => $b['playtime_seconds']-$a['playtime_seconds']);
    $result = array_values($stats);
    if ($logFile) @file_put_contents($cacheFile, json_encode(['mtime' => filemtime($logFile), 'data' => $result]));
    return $result;
}

// ============================================================
// CONSOLE / LOG
// ============================================================

// Gibt einen Ausschnitt der Server-Log-Zeilen zurück (aus Datei oder journalctl)
function get_log_lines(int $lines = 100, int $offset = 0): array {
    $offset   = max(0, $offset);
    $logFiles = get_log_files();
    $logFile  = null;
    foreach ($logFiles as $f) { if (file_exists($f)) { $logFile=$f; break; } }
    if (!$logFile) {
        // Fetch enough lines so incremental offset slicing works correctly
        $fetch = $offset > 0 ? min($offset + $lines, 5000) : $lines;
        $out = shell_exec('journalctl -u '.escapeshellarg(MC_SERVICE_NAME).' -n '.(int)$fetch.' --no-pager --output=short 2>/dev/null');
        if ($out) {
            $all   = array_values(array_filter(explode("\n", $out)));
            $total = count($all);
            $slice = $offset === 0 ? array_slice($all, -$lines) : array_slice($all, $offset);
            return ['lines' => $slice, 'total' => $total, 'source' => 'journalctl'];
        }
        return ['lines'=>[],'total'=>0,'source'=>'none'];
    }
    // Große Log-Dateien (>512 KB) nur via tail lesen statt komplett in den RAM
    if ($offset === 0 && filesize($logFile) > 512 * 1024) {
        $out = shell_exec('tail -n ' . (int)$lines . ' ' . escapeshellarg($logFile) . ' 2>/dev/null');
        if ($out !== null) {
            $result = array_values(array_filter(explode("\n", $out), fn($l) => $l !== ''));
            $total  = (int)trim(shell_exec('wc -l < ' . escapeshellarg($logFile)) ?: '0');
            return ['lines' => $result, 'total' => $total, 'source' => basename($logFile)];
        }
    }
    $all    = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
    $total  = count($all);
    $slice  = $offset > 0 ? array_slice($all, $offset) : array_slice($all, -$lines);
    return ['lines'=>$slice,'total'=>$total,'source'=>basename($logFile)];
}

// Sendet einen Befehl an die Server-Konsole via FIFO, screen, tmux oder stdin
function console_send(string $cmd): array {
    $cmd = trim($cmd);
    if ($cmd === '') return ['success'=>false,'message'=>'Leerer Befehl'];
    $fifo = MC_SERVER_DIR . '/server.stdin';
    if (file_exists($fifo) && filetype($fifo) === 'fifo') {
        $fp = @fopen($fifo, 'w');
        if ($fp !== false) { fwrite($fp, $cmd . "\n"); fclose($fp); return ['success'=>true,'message'=>'Befehl gesendet']; }
    }
    exec('screen -S minecraft -X stuff '.escapeshellarg($cmd."\n").' 2>&1',$out,$code);
    if ($code===0) return ['success'=>true,'message'=>'Befehl gesendet (screen)'];
    exec('tmux send-keys -t minecraft '.escapeshellarg($cmd).' Enter 2>&1',$out,$code);
    if ($code===0) return ['success'=>true,'message'=>'Befehl gesendet (tmux)'];
    $pidFile = MC_SERVER_DIR.'/server.pid';
    if (file_exists($pidFile)) { $pid=trim(file_get_contents($pidFile)); if(ctype_digit($pid)&&(int)$pid>0){$stdin="/proc/$pid/fd/0"; if(is_writable($stdin)){file_put_contents($stdin,$cmd."\n");return['success'=>true,'message'=>'Befehl gesendet (stdin)'];}}}
    return ['success'=>false,'message'=>'Kein aktiver screen/tmux'];
}

// ============================================================
// STATE & HELPERS
// ============================================================

// Liest den Panel-State (aktive Welt, Pack-Zuordnungen) aus der JSON-Datei
function get_state(): array {
    if (!file_exists(MC_STATE_FILE)) return ['active_world'=>null,'world_packs'=>[]];
    return json_decode(file_get_contents(MC_STATE_FILE),true)??['active_world'=>null,'world_packs'=>[]];
}
// Speichert den Panel-State als formatiertes JSON auf die Festplatte
function save_state(array $state): bool {
    $tmp = MC_STATE_FILE . '.tmp';
    if (file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT)) === false) return false;
    return rename($tmp, MC_STATE_FILE);
}
// Formatiert eine Byte-Zahl als lesbare Größenangabe (B/KB/MB/GB)
function format_bytes(int $bytes): string {
    if($bytes>=1073741824) return number_format($bytes/1073741824,2).' GB';
    if($bytes>=1048576)    return number_format($bytes/1048576,2).' MB';
    if($bytes>=1024)       return number_format($bytes/1024,2).' KB';
    return $bytes.' B';
}
// Formatiert Sekunden als lesbare Zeitangabe (z.B. "2h 15m" oder "45 Min")
function format_duration(int $seconds): string {
    if($seconds<=0) return '0 Min';
    $h=intdiv($seconds,3600); $m=intdiv($seconds%3600,60);
    return $h>0?"{$h}h {$m}m":"{$m} Min";
}
// Berechnet die Gesamtgröße eines Verzeichnisses rekursiv in Bytes
function dir_size(string $path): int {
    $size=0;
    try { foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS))as $f) $size+=$f->getSize(); }
    catch(\Exception $e){}
    return $size;
}
// Ersetzt ungültige Zeichen in einem Verzeichnisnamen durch Unterstriche
function sanitize_dirname(string $name): string { return preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $name); }
// Kopiert ein Verzeichnis rekursiv an einen Zielort
function copy_dir(string $src, string $dst): bool {
    if(!is_dir($dst) && !mkdir($dst,0755,true)) return false;
    foreach(scandir($src)as $file){if($file==='.'||$file==='..') continue; $s="$src/$file";$d="$dst/$file"; is_dir($s)?copy_dir($s,$d):copy($s,$d);}
    return true;
}
// Entpackt eine ZIP-Datei via ZipArchive oder unzip-Fallback
function extract_zip(string $file, string $dest): bool {
    if(!is_dir($dest) && !mkdir($dest,0755,true)) return false;
    if(class_exists('ZipArchive')){$zip=new ZipArchive();if($zip->open($file)===true){$zip->extractTo($dest);$zip->close();return true;}}
    exec('unzip -o '.escapeshellarg($file).' -d '.escapeshellarg($dest).' 2>&1',$out,$code);
    return $code===0;
}
