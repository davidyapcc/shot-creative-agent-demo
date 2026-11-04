import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

export const DEFAULT_REVERB_PORT = 8080;
export const DEFAULT_REVERB_SCHEME = 'http';

export function initEcho(overrideConfig = {})
{
  const config = window.__CONFIG__ || {};
  const host = overrideConfig.reverbHost || config.reverbHost || window.location.hostname;
  const port = overrideConfig.reverbPort || config.reverbPort || DEFAULT_REVERB_PORT;
  const scheme = overrideConfig.reverbScheme || config.reverbScheme || DEFAULT_REVERB_SCHEME;
  const key = overrideConfig.reverbAppKey || config.reverbAppKey || '';

  // Throw so a missing key fails at startup instead of opening a dead socket.
  if (!key)
  {
    throw new Error('Reverb app key is not configured.');
  }

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
