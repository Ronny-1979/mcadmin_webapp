<?php
// Automatische Aufgaben — wird minütlich per cron aufgerufen.
// - tägliches Backup zur konfigurierten Uhrzeit
// - tägliche Update-Prüfung für Minecraft Bedrock + Panel zur konfigurierten Uhrzeit
require_once __DIR__ . '/config.php';   // setzt date_default_timezone_set() bereits
require_once __DIR__ . '/includes/functions.php';

$now   = date('H:i');
$today = date('Y-m-d');

// ------------------------------------------------------------
// Automatisches Backup
// ------------------------------------------------------------
$s = load_settings();
if (!empty($s['backup_schedule_enabled'])) {
    $time     = $s['backup_schedule_time'] ?? '03:00';
    $lastDate = $s['backup_last_date'] ?? '';

    $zeitOk  = $now >= $time          ? 'ja' : 'nein';
    $datumOk = $lastDate !== $today   ? 'ja' : 'nein';
    echo date('Y-m-d H:i:s') . " [backup-schedule] Zeit:{$now}>={$time}={$zeitOk}, DatumFrei={$datumOk} (letztes:{$lastDate})\n";

    if ($now >= $time && $lastDate !== $today) {
        // Datum VOR dem Backup setzen — verhindert, dass ein zweiter Cron-Lauf
        // ein weiteres Backup startet, während das erste noch läuft (Race Condition).
        $s['backup_last_date'] = $today;
        save_settings($s);

        $result = create_backup('auto');
        if ($result['success']) {
            echo date('Y-m-d H:i:s') . " Backup erstellt: {$result['filename']}\n";
        } else {
            echo date('Y-m-d H:i:s') . " Backup fehlgeschlagen: {$result['message']}\n";
        }
    }
}

// ------------------------------------------------------------
// Automatischer Server-Neustart
// ------------------------------------------------------------
$s = load_settings();
if (!empty($s['restart_schedule_enabled'])) {
    $time     = $s['restart_schedule_time'] ?? '06:00';
    $lastDate = $s['restart_last_date'] ?? '';

    $zeitOk  = $now >= $time          ? 'ja' : 'nein';
    $datumOk = $lastDate !== $today   ? 'ja' : 'nein';
    echo date('Y-m-d H:i:s') . " [restart-schedule] Zeit:{$now}>={$time}={$zeitOk}, DatumFrei={$datumOk} (letztes:{$lastDate})\n";

    if ($now >= $time && $lastDate !== $today) {
        server_restart();
        $s = load_settings();
        $s['restart_last_date'] = $today;
        save_settings($s);
        echo date('Y-m-d H:i:s') . " Auto-Restart durchgeführt\n";
    }
}

// ------------------------------------------------------------
// Automatische Update-Prüfung
// ------------------------------------------------------------
$s = load_settings();
if (!empty($s['update_check_enabled'])) {
    $time     = $s['update_check_time'] ?? '04:00';
    $lastDate = $s['update_check_last_date'] ?? '';

    $zeitOk  = $now >= $time          ? 'ja' : 'nein';
    $datumOk = $lastDate !== $today   ? 'ja' : 'nein';
    echo date('Y-m-d H:i:s') . " [update-check] Zeit:{$now}>={$time}={$zeitOk}, DatumFrei={$datumOk} (letztes:{$lastDate})\n";

    if ($now >= $time && $lastDate !== $today) {
        $result = run_update_availability_check(true);

        $s = load_settings();
        $s['update_check_last_date'] = $today;
        save_settings($s);

        $mc = $result['minecraft'] ?? [];
        $pa = $result['panel'] ?? [];
        $sent = implode(',', $result['notifications_sent'] ?? []);

        echo date('Y-m-d H:i:s')
            . ' Update-Prüfung: Minecraft '
            . ($mc['current'] ?? '?') . ' → ' . ($mc['latest'] ?? '?')
            . ', Panel ' . ($pa['current'] ?? '?') . ' → ' . ($pa['latest'] ?? '?')
            . ($sent ? " | Discord: {$sent}" : '')
            . "\n";

        // Auto-Install Minecraft
        $s = load_settings();
        if (!empty($s['auto_install_mc']) && !empty($result['minecraft']['update_available'])) {
            $mcVer = $result['minecraft']['latest'];
            $inst  = run_update_async($mcVer);
            echo date('Y-m-d H:i:s') . " [auto-install-mc] Gestartet: {$mcVer} → " . ($inst['success'] ? 'OK' : ($inst['message'] ?? 'Fehler')) . "\n";
        }

        // Auto-Install Panel
        if (!empty($s['auto_install_panel']) && !empty($result['panel']['update_available'])) {
            $inst = run_panel_update_async();
            echo date('Y-m-d H:i:s') . " [auto-install-panel] Gestartet → " . ($inst['success'] ? 'OK' : ($inst['message'] ?? 'Fehler')) . "\n";
        }
    }
}

// Heartbeat: immer am Ende loggen, damit man sieht dass der Cron läuft
// (auch wenn keine Aktion ausgeführt wurde)
echo date('Y-m-d H:i:s') . " [cron] Lauf OK (TZ=" . date_default_timezone_get() . ")\n";
