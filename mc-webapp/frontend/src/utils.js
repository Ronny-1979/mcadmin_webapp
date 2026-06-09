// ── API-Hilfsfunktion ──────────────────────────────────────────
export async function api(method, url, body = null, isFile = false) {
  const opts = {
    method,
    credentials: 'include',
  };
  if (body && !isFile) {
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify(body);
  } else if (isFile) {
    opts.body = body; // FormData
  }
  const res = await fetch(url, opts);
  if (res.status === 401) {
    window.location.href = '/login';
    return null;
  }
  return res.json();
}

export const get  = (url)        => api('GET',    url);
export const post = (url, body)  => api('POST',   url, body);
export const del  = (url)        => api('DELETE', url);
export const upload = (url, fd)  => api('POST',   url, fd, true);

// ── Format-Helfer ──────────────────────────────────────────────
export function formatBytes(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1048576) return `${(bytes/1024).toFixed(1)} KB`;
  return `${(bytes/1048576).toFixed(1)} MB`;
}

export function formatDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleString('de-DE');
}

export function formatUptime(isoStart) {
  if (!isoStart) return '—';
  const diff = Math.floor((Date.now() - new Date(isoStart)) / 1000);
  const h = Math.floor(diff / 3600);
  const m = Math.floor((diff % 3600) / 60);
  const s = diff % 60;
  return `${h}h ${m}m ${s}s`;
}

// ── Log-Zeilen klassifizieren ──────────────────────────────────
export function logClass(line) {
  const l = line.toLowerCase();
  if (l.includes('player connected')  || l.includes('joined the game'))  return 'log-join';
  if (l.includes('player disconnected')|| l.includes('left the game'))   return 'log-leave';
  if (l.includes('[error]')           || l.includes('error:'))           return 'log-error';
  if (l.includes('[warn')             || l.includes('warning'))          return 'log-warn';
  if (l.includes('<')                 && l.includes('>'))                return 'log-chat';
  if (l.includes('running command')   || l.includes('issued server'))    return 'log-cmd';
  return 'log-info';
}
