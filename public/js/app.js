const themeSelect = document.getElementById('themeSelect');
const themeMeta = document.getElementById('themeColorMeta');

function applyTheme(theme) {
    const root = document.documentElement;
    if (theme === 'system') {
        root.removeAttribute('data-theme');
    } else {
        root.dataset.theme = theme;
    }

    const dark = theme === 'dark'
        || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    if (themeMeta) {
        themeMeta.setAttribute('content', dark ? '#0b1120' : '#f3f4f6');
    }
}

const savedTheme = localStorage.getItem('gaugeiq-theme') || 'system';
if (themeSelect) {
    themeSelect.value = savedTheme;
    themeSelect.addEventListener('change', () => {
        const theme = themeSelect.value;
        localStorage.setItem('gaugeiq-theme', theme);
        applyTheme(theme);
    });
}

const colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
colorScheme.addEventListener?.('change', () => {
    if ((localStorage.getItem('gaugeiq-theme') || 'system') === 'system') {
        applyTheme('system');
    }
});

applyTheme(savedTheme);

const status = document.getElementById('status');
const notifyButton = document.getElementById('notifyButton');
const historyStatus = document.getElementById('historyStatus');

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

    const response = await fetch('../api/push-config.php', { cache: 'no-store' });
    if (!response.ok) throw new Error('GaugeIQ push notifications are not configured yet.');

    const { publicKey } = await response.json();
    const registration = await getRegistration();
    let subscription = await registration.pushManager.getSubscription();

    if (!subscription) {
        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: base64UrlToUint8Array(publicKey)
        });
    }

    const saveResponse = await fetch('../api/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription.toJSON())
    });

    if (!saveResponse.ok) throw new Error('GaugeIQ could not save this device for notifications.');

    status.textContent = 'Notifications are enabled for GaugeIQ.';
    notifyButton.innerHTML = '<span class="alerts-led" aria-hidden="true"></span><span>Alerts on</span>';
    notifyButton.classList.add('alerts-on');
    notifyButton.disabled = true;
}

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('service-worker.js').catch(() => {
        status.textContent = 'PWA service worker could not be registered.';
    });
}

async function showAlertsOnIfAlreadyEnabled() {
    if (!('Notification' in window) || !('PushManager' in window) || Notification.permission !== 'granted') return;
    try {
        const registration = await getRegistration();
        if (await registration.pushManager.getSubscription()) {
            notifyButton.innerHTML = '<span class="alerts-led" aria-hidden="true"></span><span>Alerts on</span>';
            notifyButton.classList.add('alerts-on');
            notifyButton.disabled = true;
        }
    } catch {
        // Leave the normal enable button available if the existing subscription cannot be checked.
    }
}

showAlertsOnIfAlreadyEnabled();

if (!('Notification' in window) || Notification.permission !== 'granted') {
                throw new Error('Enable GaugeIQ notifications first.');
            }

            const registration = await getRegistration();

            if (!registration.active) {
                throw new Error('GaugeIQ service worker is not active yet. Please tap Refresh and try again.');
            }

            await registration.showNotification('GaugeIQ test notification', {
                body: 'If you hear a sound, GaugeIQ notifications are working on this device.',
                tag: 'gaugeiq-test-' + Date.now(),
                renotify: true,
                silent: false,
                data: { url: './' }
            });

            status.textContent = 'Test notification sent. Check your notification center.';
        } catch (error) {
            status.textContent = error instanceof Error
                ? error.message
                : 'Unable to send the test notification.';
        }
    });
}

if (serverTestNotifyButton) {
    serverTestNotifyButton.addEventListener('click', async () => {
        serverTestNotifyButton.disabled = true;
        status.textContent = 'Running server push test…';
        try {
            const csrf = document.querySelector('meta[name="gaugeiq-csrf"]')?.content || '';
            if (!csrf) throw new Error('GaugeIQ security token is missing. Reload the app and try again.');

            const response = await fetch('../api/test-push.php', {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf },
                credentials: 'same-origin',
                cache: 'no-store'
            });

            const text = await response.text();
            let data = {};
            try { data = JSON.parse(text); } catch { data = { error: text || 'The server returned an invalid response.' }; }

            if (!response.ok) {
                throw new Error(data.error || ('Server push test failed (HTTP ' + response.status + ').'));
            }

            if (Array.isArray(data.results) && data.results.length) {
                const failures = data.results.filter(result => !result.ok);
                if (failures.length) {
                    const detail = failures.map(result =>
                        'Device ' + result.id + ': HTTP ' + (result.status ?? 'n/a') + ' — ' + result.reason
                    ).join(' | ');
                    throw new Error(detail);
                }
            }

            status.textContent = data.sent > 0
                ? 'Server push sent to ' + data.sent + ' device(s). Check your notification center.'
                : 'Server push ran, but there are no accepted subscribed devices.';
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Unable to run the server push test.';
        } finally {
            serverTestNotifyButton.disabled = false;
        }
    });
}

if (!('Notification' in window) || !('PushManager' in window)) {
    notifyButton.disabled = true;
    notifyButton.textContent = 'Notifications unavailable';
} else {
    notifyButton.addEventListener('click', async () => {
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error('Notification permission was not granted.');
            await enablePushNotifications();
        } catch (error) {
            status.textContent = error instanceof Error ? error.message : 'Unable to enable notifications.';
        }
    });
}

function formatChartTime(value, hours) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    if (hours >= 168) {
        return date.toLocaleDateString([], { day: 'numeric', month: 'short' });
    }

    return date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
}

function drawChart(canvas, values, unit, decimals = 1, hours = 24) {
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const width = canvas.clientWidth || 600;
    const height = canvas.clientHeight || 220;
    canvas.width = width * dpr;
    canvas.height = height * dpr;
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, width, height);

    const grouped = new Map();
    values.forEach(item => {
        if (!Number.isFinite(item.value)) return;
        const timestamp = new Date(item.time).getTime();
        const key = Number.isFinite(timestamp) ? timestamp : 'invalid-' + grouped.size;
        if (!grouped.has(key)) grouped.set(key, []);
        grouped.get(key).push(item.value);
    });

    const valid = [...grouped.entries()]
        .map(([timestamp, readings]) => ({
            value: readings.reduce((sum, value) => sum + value, 0) / readings.length,
            time: Number.isFinite(timestamp) ? new Date(timestamp).toISOString() : values.find(item => Number.isFinite(item.value))?.time
        }))
        .sort((a, b) => new Date(a.time).getTime() - new Date(b.time).getTime());
    if (valid.length === 0) {
        ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--muted');
        ctx.font = '14px system-ui';
        ctx.fillText('No history recorded yet.', 16, 32);
        return;
    }

    const styles = getComputedStyle(document.documentElement);
    const text = styles.getPropertyValue('--muted').trim();
    const line = styles.getPropertyValue('--accent').trim();
    const border = styles.getPropertyValue('--border').trim();
    const min = Math.min(...valid.map(item => item.value));
    const max = Math.max(...valid.map(item => item.value));
    const range = max - min || 1;
    const pad = { top: 18, right: 14, bottom: 34, left: 14 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    ctx.strokeStyle = border;
    ctx.lineWidth = 1;
    ctx.beginPath();
    ctx.moveTo(pad.left, pad.top + plotH);
    ctx.lineTo(width - pad.right, pad.top + plotH);
    ctx.stroke();

    ctx.strokeStyle = line;
    ctx.lineWidth = 2.5;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    ctx.beginPath();

    const firstTime = new Date(valid[0].time).getTime();
    const lastTime = new Date(valid[valid.length - 1].time).getTime();
    const timeRange = lastTime - firstTime || 1;

    const points = valid.map(item => {
        const timestamp = new Date(item.time).getTime();
        return {
            x: valid.length === 1
                ? pad.left + (plotW / 2)
                : pad.left + ((timestamp - firstTime) / timeRange) * plotW,
            y: pad.top + (1 - ((item.value - min) / range)) * plotH
        };
    });

    if (points.length > 1) {
        ctx.moveTo(points[0].x, points[0].y);

        // Smooth each transition independently between two real readings.
        // Equal readings remain perfectly horizontal; changing readings are
        // connected with a simple cubic curve that always reaches both points.
        for (let index = 0; index < points.length - 1; index += 1) {
            const current = points[index];
            const next = points[index + 1];
            const dx = next.x - current.x;

            if (Math.abs(next.y - current.y) < 0.001) {
                ctx.lineTo(next.x, next.y);
                continue;
            }

            const curve = dx / 3;
            ctx.bezierCurveTo(
                current.x + curve,
                current.y,
                next.x - curve,
                next.y,
                next.x,
                next.y
            );
        }
        ctx.stroke();
    }
    if (valid.length === 1) {
        const item = valid[0];
        const x = pad.left + (plotW / 2);
        const y = pad.top + (1 - ((item.value - min) / range)) * plotH;
        ctx.fillStyle = line;
        ctx.beginPath();
        ctx.arc(x, y, 5, 0, Math.PI * 2);
        ctx.fill();
    }

    ctx.fillStyle = text;
    ctx.font = '12px system-ui';
    ctx.fillText(max.toFixed(decimals) + unit, pad.left, 13);
    ctx.fillText(min.toFixed(decimals) + unit, pad.left, height - 4);

    const first = valid[0];
    const last = valid[valid.length - 1];
    const firstLabel = formatChartTime(first.time, hours);
    const lastLabel = formatChartTime(last.time, hours);
    ctx.fillText(firstLabel, pad.left, height - 17);
    const lastWidth = ctx.measureText(lastLabel).width;
    ctx.fillText(lastLabel, width - pad.right - lastWidth, height - 17);
}

async function loadHistory(hours = 24) {
    if (!historyStatus) return;

    historyStatus.textContent = 'Loading history…';

    try {
        const response = await fetch('../api/history.php?hours=' + encodeURIComponent(hours), { cache: 'no-store' });
        if (!response.ok) throw new Error('History unavailable.');

        const data = await response.json();
        const readings = Array.isArray(data.readings) ? data.readings : [];

        const charts = [
            ['pressureChart', readings.map(r => ({ value: Number(r.pressure_hpa), time: r.observed_at })), ' hPa', 1],
            ['humidityChart', readings.map(r => ({ value: Number(r.humidity_percent), time: r.observed_at })), '%', 0],
            ['windChart', readings.map(r => ({ value: Number(r.wind_speed_kmh), time: r.observed_at })), ' km/h', 1]
        ];

        charts.forEach(([id, values, unit, decimals]) => {
            const canvas = document.getElementById(id);
            if (canvas) drawChart(canvas, values, unit, decimals, hours);
        });

        const rangeLabel = hours === 168 ? '7 days' : hours + ' hours';
        historyStatus.textContent = readings.length
            ? 'Last ' + rangeLabel + ' · ' + readings.length + ' readings'
            : 'No readings have been recorded yet.';

        const title = document.getElementById('historyTitle');
        if (title) title.textContent = 'History · ' + rangeLabel;
    } catch {
        historyStatus.textContent = 'Historical readings are currently unavailable.';
    }
}

document.querySelectorAll('.history-range-button').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('.history-range-button').forEach(item => item.classList.remove('active'));
        button.classList.add('active');
        loadHistory(Number(button.dataset.hours));
    });
});

loadHistory();

async function checkForGaugeIQUpdate() {
    const notice = document.getElementById('updateNotice');
    const title = document.getElementById('updateNoticeTitle');
    const message = document.getElementById('updateNoticeText');
    const link = document.getElementById('updateNoticeLink');
    if (!notice || !title || !message || !link) return;

    try {
        const response = await fetch('../api/update-check.php', { cache: 'no-store' });
        if (!response.ok) return;

        const data = await response.json();
        if (!data.update_available) return;

        title.textContent = 'GaugeIQ update available';
        message.textContent = 'Version ' + data.latest_version + ' is available.';
        link.href = data.release_url || '#';
        notice.hidden = false;
    } catch {
        // Update checks are optional and must never interrupt the dashboard.
    }
}

checkForGaugeIQUpdate();

const cronCopyButton = document.getElementById('cronCopyButton');
const cronCommand = document.getElementById('cronCommand');
const cronCopyStatus = document.getElementById('cronCopyStatus');
if (cronCopyButton && cronCommand) {
    cronCopyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(cronCommand.textContent.trim());
            cronCopyStatus.textContent = 'Command copied. Paste it into cPanel Cron Jobs.';
            cronCopyButton.textContent = 'Copied';
            setTimeout(() => { cronCopyButton.textContent = 'Copy command'; }, 1800);
        } catch {
            cronCopyStatus.textContent = 'Copy failed. Select the command and copy it manually.';
        }
    });
}


let gaugeIqHiddenAt = null;

function refreshGaugeIqWhenReturning() {
    if (gaugeIqHiddenAt === null) return;
    const hiddenFor = Date.now() - gaugeIqHiddenAt;
    gaugeIqHiddenAt = null;
    if (hiddenFor >= 60 * 1000) {
        window.location.reload();
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        gaugeIqHiddenAt = Date.now();
    } else if (document.visibilityState === 'visible') {
        refreshGaugeIqWhenReturning();
    }
});

window.addEventListener('pageshow', () => {
    refreshGaugeIqWhenReturning();
});
