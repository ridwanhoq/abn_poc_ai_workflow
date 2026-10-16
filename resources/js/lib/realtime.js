import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { reactive } from 'vue';

/**
 * Optional WebSocket updates via Laravel Reverb. Enabled only when
 * VITE_REVERB_APP_KEY is set at build time; otherwise every caller keeps
 * polling. Events carry ids/status only - callers re-fetch through the API.
 */
export const realtime = reactive({ enabled: false, connected: false });

let echo = null;

function client() {
    if (echo || !import.meta.env.VITE_REVERB_APP_KEY) {
        return echo;
    }

    window.Pusher = Pusher;
    const scheme = import.meta.env.VITE_REVERB_SCHEME || 'https';

    echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST || window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
        forceTLS: scheme === 'https',
        enabledTransports: scheme === 'https' ? ['wss'] : ['ws'],
        // Channel auth goes through the session + XSRF cookie like the REST API.
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                axios
                    .post(
                        '/broadcasting/auth',
                        { socket_id: socketId, channel_name: channel.name },
                        { withXSRFToken: true },
                    )
                    .then((response) => callback(null, response.data))
                    .catch((error) => callback(error, null));
            },
        }),
    });

    realtime.enabled = true;
    echo.connector.pusher.connection.bind('state_change', ({ current }) => {
        realtime.connected = current === 'connected';
    });

    return echo;
}

/**
 * Subscribe to "execution.updated" on a private channel. Returns an unsubscribe function
 * (a no-op when realtime is disabled).
 */
export function onExecutionUpdated(channel, callback) {
    const instance = client();

    if (!instance) {
        return () => {};
    }

    instance.private(channel).listen('.execution.updated', callback);

    return () => instance.leave(channel);
}
