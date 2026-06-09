<?php
// ============================================================
// Minecraft Bedrock Admin Panel — Konfiguration
// github.com/Ronny-1979/mcadmin
// ============================================================

// ── Systemzeitzone ─────────────────────────────────────────────
// Genau das, was `date` in der Shell zeigt — keine Überschreibung.
// Muss VOR allen date()-Aufrufen stehen (index.php, handler.php, cron.php).
$_tz = trim((string)@file_get_contents('/etc/timezone'));
if ($_tz === '') {
    $_tz = trim((string)@shell_exec('timedatectl show --property=Timezone --value 2>/dev/null'));
}
if ($_tz === '') $_tz = 'UTC';
date_default_timezone_set($_tz);
unset($_tz);

// ── Server-Pfade ─────────────────────────────────────────────
define('MC_SERVER_DIR',      '/opt/minecraft-bedrock');
define('MC_WORLDS_DIR',      MC_SERVER_DIR . '/worlds');
define('MC_PACKS_BEHAVIOR_DIR', MC_SERVER_DIR . '/behavior_packs');
define('MC_PACKS_RESOURCE_DIR', MC_SERVER_DIR . '/resource_packs');
define('MC_SERVER_EXECUTABLE',  MC_SERVER_DIR . '/bedrock_server');
define('MC_SERVICE_NAME',    'minecraft-bedrock');
define('MC_LOG_FILE',        MC_SERVER_DIR . '/logs/latest.log');
define('MC_PROPERTIES_FILE', MC_SERVER_DIR . '/server.properties');
define('MC_WHITELIST_FILE',  MC_SERVER_DIR . '/whitelist.json');
define('MC_PERMISSIONS_FILE',MC_SERVER_DIR . '/permissions.json');

// ── Panel-Pfade ───────────────────────────────────────────────
define('MC_BACKUP_DIR',      __DIR__ . '/backups');
define('MC_UPLOAD_DIR',      __DIR__ . '/uploads');
define('MC_STATE_FILE',      __DIR__ . '/mcadmin_state.json');
define('MC_VERSION_CACHE_FILE', __DIR__ . '/version_cache.json');
define('MC_SETTINGS_FILE',   __DIR__ . '/mcadmin_settings.json');
define('MC_PANEL_VERSION_FILE',  __DIR__ . '/.mcadmin_version');
define('MC_PANEL_UPDATE_SCRIPT', '/usr/local/sbin/mcadmin-panel-update.sh');
define('MC_PANEL_UPDATE_CACHE',  __DIR__ . '/panel_version_cache.json');

// ── Versions-Cache ────────────────────────────────────────────
define('MC_VERSION_CACHE_TTL', 900);

// ── Backup-Limit ──────────────────────────────────────────────
define('MAX_BACKUP_COUNT', 20);

// ── Standard-Zugangsdaten (werden in mcadmin_settings.json überschrieben) ──
define('DEFAULT_ADMIN_USER', 'admin');
define('DEFAULT_ADMIN_PASS', 'admin');

// ── Verzeichnisse anlegen ─────────────────────────────────────
foreach ([MC_BACKUP_DIR, MC_UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0755, true);
}

// ── Einstellungen laden ───────────────────────────────────────
// Lädt mcadmin_settings.json und gibt sie mit Standard-Werten zusammengeführt zurück
function load_settings(): array {
    $defaults = [
        'admin_user'              => DEFAULT_ADMIN_USER,
        'admin_pass_hash'         => password_hash(DEFAULT_ADMIN_PASS, PASSWORD_DEFAULT),
        'discord_webhook'         => '',
        'discord_events'          => [
            'server_start'   => true,
            'server_stop'    => true,
            'player_join'    => true,
            'player_leave'   => false,
            'player_kick'    => true,
            'backup_created' => false,
            'server_update'     => true,
            'update_available' => true,
            'world_switch'      => false,
        ],
        'backup_max_count'        => MAX_BACKUP_COUNT,
        'backup_schedule_enabled' => false,
        'backup_schedule_time'    => '03:00',
        'backup_last_date'        => '',
        'restart_schedule_enabled'    => false,
        'restart_schedule_time'       => '06:00',
        'restart_last_date'           => '',
        'update_check_enabled'    => false,
        'update_check_time'       => '04:00',
        'update_check_last_date'  => '',
        'update_check_last_mc_version' => '',
        'update_check_last_panel_sha'  => '',
        'auto_install_mc'              => false,
        'auto_install_panel'           => false,
    ];
    if (file_exists(MC_SETTINGS_FILE)) {
        $s = json_decode(file_get_contents(MC_SETTINGS_FILE), true);
        if (is_array($s)) return array_merge($defaults, $s);
    }
    return $defaults;
}

// Speichert die Einstellungen als formatiertes JSON in mcadmin_settings.json
function save_settings(array $s): bool {
    $tmp = MC_SETTINGS_FILE . '.tmp';
    if (file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT)) === false) return false;
    return rename($tmp, MC_SETTINGS_FILE);
}

// State
if (!file_exists(MC_STATE_FILE)) {
    file_put_contents(MC_STATE_FILE, json_encode(
        ['active_world' => null, 'world_packs' => []], JSON_PRETTY_PRINT
    ));
}
