// ============================================================
// mc-webapp — Konfiguration
// Hier alle Pfade und Einstellungen anpassen
// ============================================================

export const config = {
  // Server-Port (mcadmin läuft auf 80, daher 3001)
  port: 3001,

  // JWT Secret — bitte in Produktion ändern!
  jwtSecret: 'mc-webapp-secret-bitte-aendern',
  jwtExpiry: '24h',

  // Minecraft Bedrock Server
  mc: {
    serverDir:      '/opt/minecraft-bedrock',
    worldsDir:      '/opt/minecraft-bedrock/worlds',
    behaviorDir:    '/opt/minecraft-bedrock/behavior_packs',
    resourceDir:    '/opt/minecraft-bedrock/resource_packs',
    executable:     '/opt/minecraft-bedrock/bedrock_server',
    serviceName:    'minecraft-bedrock',
    propertiesFile: '/opt/minecraft-bedrock/server.properties',
    whitelistFile:  '/opt/minecraft-bedrock/whitelist.json',
    permissionsFile:'/opt/minecraft-bedrock/permissions.json',
    fifo:           '/opt/minecraft-bedrock/server.stdin',
    logFiles: [
      '/opt/minecraft-bedrock/logs/latest.log',
      '/opt/minecraft-bedrock/server.log',
      '/opt/minecraft-bedrock/logs/server.log',
    ],
  },

  // Panel-Daten (teilt Dateien mit mcadmin PHP-Panel)
  panel: {
    settingsFile: '/var/www/html/mcadmin/mcadmin_settings.json',
    stateFile:    '/var/www/html/mcadmin/mcadmin_state.json',
    backupDir:    '/var/www/html/mcadmin/backups',
    uploadDir:    '/tmp/mc-webapp-uploads',
    maxBackups:   20,
    // PHP-Bridge: interne API-Adresse des PHP-Panels
    phpApiUrl:   'http://127.0.0.1/mcadmin/api/handler.php',
    phpLoginUrl: 'http://127.0.0.1/mcadmin/index.php',
  },

  // Standard-Login (wird aus mcadmin_settings.json überschrieben)
  defaultUser: 'admin',
  defaultPass: 'admin',
};
