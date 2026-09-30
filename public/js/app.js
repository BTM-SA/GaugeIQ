const status = document.getElementById('status');
const notifyButton = document.getElementById('notifyButton');

async function getRegistration() {
    if (!('serviceWorker' in navigator)) {
        throw new Error('Service workers are unavailable.');
    }

    return navigator.serviceWorker.ready;
}

function base64UrlToUint8Array(base64UrlData) {
    const padding = '='.repeat((4 - (base64UrlData.length % 4)) % 4);
    const base64 = (base64UrlData + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = atob(base64);
    return Uint8Array.from([...rawData].map(char => char.charCodeAt(0)));
}

async function enablePushNotifications() {
    if (!('PushManager' in window) || !('Notification' in window)) {
        throw new Error('This browser does not support Web Push notifications.');
    }

    if (Notification.permission === 'denied') {
        throw new Error('Notifications are blocked for GaugeIQ. Enable them in iPhone Settings.');
    }

    const response = await fetch('api/push-config.php', { cache: 'no-store' });
    if (!response.ok) {
        throw new Error('GaugeIQ push notifications are not configured yet.');
    }

    const { publicKey } = await response.json();
    const registration = await getRegistration();

    let subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToUint8Array(publicKey)
        });
    }

    const saveResponse = await fetch('api/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription.toJSON())
    });

    if (!saveResponse.ok) {
        throw new Error('GaugeIQ could not save this device for notifications.');
    }

    status.textContent = 'Notifications are enabled for GaugeIQ.';
    notifyButton.textContent = 'Alerts enabled';
    notifyButton.disabled = true;

    const test = document.createElement('button');
    test.type = 'button';
    test.className = 'secondary test-button';
    test.textContent = 'Send test alert';

    test.addEventListener('click', async () => {
        try {
            test.disabled = true;

            const response = await fetch('api/test-push.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });

            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.error || 'The test notification failed.');
            }

            status.textContent = result.sent > 0
                ? 'Test notification sent.'
                : 'No notification devices are registered yet.';
        } catch (error) {
            status.textContent = error instanceof Error
                ? error.message
                : 'Test notification failed.';
        } finally {
            test.disabled = false;
        }
    });

    document.querySelector('.shell').appendChild(test);
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js').catch(() => {
        status.textContent = 'PWA service worker could not be registered.';
    });
}

if (!('Notification' in window) || !('PushManager' in window)) {
    notifyButton.disabled = true;
    notifyButton.textContent = 'Notifications unavailable';
} else {
    notifyButton.addEventListener('click', async () => {
        try {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                throw new Error('Notification permission was not granted.');
            }

            await enablePushNotifications();
        } catch (error) {
            status.textContent = error instanceof Error
                ? error.message
                : 'Unable to enable notifications.';
        }
    });
}
