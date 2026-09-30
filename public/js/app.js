const status = document.getElementById('status');
const notifyButton = document.getElementById('notifyButton');

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js').catch(() => {
        status.textContent = 'PWA service worker could not be registered.';
    });
}

if (!('Notification' in window)) {
    notifyButton.disabled = true;
    notifyButton.textContent = 'Notifications unavailable';
} else {
    notifyButton.addEventListener('click', async () => {
        const permission = await Notification.requestPermission();

        if (permission === 'granted') {
            status.textContent = 'Notifications are enabled for GaugeIQ.';
            notifyButton.textContent = 'Alerts enabled';
            notifyButton.disabled = true;
        } else {
            status.textContent = 'Notification permission was not granted.';
        }
    });
}
