import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

export function initEcho(overrideConfig = {}) {
  const config = window.__CONFIG__ || {};
  const host = overrideConfig.reverbHost || config.reverbHost || window.location.hostname;
  const port = overrideConfig.reverbPort || config.reverbPort || 8080;
  const scheme = overrideConfig.reverbScheme || config.reverbScheme || 'http';
  const key = overrideConfig.reverbAppKey || config.reverbAppKey || 'shot-creative-key';

  return new Echo({
    broadcaster: 'reverb',
    key: key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
  });
}
