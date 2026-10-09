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


const windGauge = document.querySelector('.wind-gauge');
const windCompassStatus = document.getElementById('windCompassStatus');
const windCompassButton = document.getElementById('windCompassButton');

const WIND_COMPASS_STORAGE_KEY = 'gaugeiq-compass-enabled';
const WIND_COMPASS_SMOOTHING = 0.18;
const WIND_COMPASS_NORTH_BUFFER = 6;

const storedWindCompassState = localStorage.getItem(WIND_COMPASS_STORAGE_KEY);
const windCompassCanRequestPermission = typeof DeviceOrientationEvent !== 'undefined'
    && typeof DeviceOrientationEvent.requestPermission === 'function';
let windCompassEnabled = storedWindCompassState !== null
    ? storedWindCompassState === 'true'
    : !windCompassCanRequestPermission;
let windCompassListening = false;
let windCompassHeading = null;
let windCompassTargetHeading = null;
let windCompassRotation = null;
let windCompassAnimationFrame = null;

function normalizeCompassHeading(value) {
    const heading = Number(value);
    if (!Number.isFinite(heading)) return null;
    return ((heading % 360) + 360) % 360;
}

function shortestCompassDelta(from, to) {
    return ((to - from + 540) % 360) - 180;
}

function compassDirectionLabel(degrees) {
    const directions = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
    return directions[Math.round(degrees / 45) % 8];
}

function applyWindCompassHeading(heading) {
    if (!windGauge || !Number.isFinite(heading) || !windCompassEnabled) return;

    const normalized = normalizeCompassHeading(heading);
    if (normalized === null) return;

    windCompassTargetHeading = normalized;

    if (windCompassHeading === null) {
        windCompassHeading = normalized;
        windCompassRotation = normalized;
    }

    if (windCompassAnimationFrame === null) {
        const animate = () => {
            if (!windCompassEnabled || windCompassHeading === null || windCompassTargetHeading === null) {
                windCompassAnimationFrame = null;
                return;
            }

            const delta = shortestCompassDelta(windCompassHeading, windCompassTargetHeading);

            // Keep the displayed heading normalized, but keep the actual
            // compass rotation unwrapped. This prevents a transition such as
            // 5° -> 340° from taking the long way around the circle.
            const nearNorth =
                (windCompassHeading <= WIND_COMPASS_NORTH_BUFFER || windCompassHeading >= 360 - WIND_COMPASS_NORTH_BUFFER) &&
                (windCompassTargetHeading <= WIND_COMPASS_NORTH_BUFFER || windCompassTargetHeading >= 360 - WIND_COMPASS_NORTH_BUFFER);

            const smoothing = nearNorth ? 0.10 : WIND_COMPASS_SMOOTHING;
            const step = delta * smoothing;
            windCompassHeading = normalizeCompassHeading(windCompassHeading + step);
            windCompassRotation += step;

            if (Math.abs(delta) < 0.08) {
                const correction = shortestCompassDelta(windCompassHeading, windCompassTargetHeading);
                windCompassHeading = windCompassTargetHeading;
                windCompassRotation += correction;
            }

            // Rotate the compass disc and its wind indicators together.
            // The disc turns opposite the device heading; the wind indicator
            // keeps its geographic bearing inside that rotating disc.
            const compassDisc = windGauge.querySelector('.wind-compass-orientation');
            if (compassDisc) {
                // Rotate one SVG group for the dial, tick marks, speed segments,
                // cardinal labels and both wind pointers. The pointers' fixed
                // SVG bearing transform is applied inside this rotating group.
                compassDisc.style.transform = 'rotate(' + (-windCompassRotation).toFixed(2) + 'deg)';
            }

            if (windCompassStatus) {
                windCompassStatus.textContent = 'Heading ' + Math.round(windCompassHeading) + '° · ' + compassDirectionLabel(windCompassHeading);
            }

            windCompassAnimationFrame = requestAnimationFrame(animate);
        };

        windCompassAnimationFrame = requestAnimationFrame(animate);
    }
}

function handleWindDeviceOrientation(event) {
    if (!windCompassEnabled) return;

    let heading = null;

    if (Number.isFinite(event.webkitCompassHeading)) {
        heading = event.webkitCompassHeading;
    } else if (Number.isFinite(event.alpha)) {
        // Standard orientation fallback. Absolute alpha is measured clockwise
        // from the device reference frame, so invert it to obtain a compass
        // heading when no native compass heading is exposed.
        heading = 360 - event.alpha;
    }

    if (heading !== null) {
        applyWindCompassHeading(heading);
    }
}

function updateWindCompassButton() {
    if (!windCompassButton) return;

    windCompassButton.textContent = windCompassEnabled ? 'Turn compass off' : 'Turn compass on';
    windCompassButton.setAttribute('aria-pressed', windCompassEnabled ? 'true' : 'false');
}

function disableWindCompass() {
    windCompassEnabled = false;
    localStorage.setItem(WIND_COMPASS_STORAGE_KEY, 'false');

    if (windCompassListening) {
        window.removeEventListener('deviceorientation', handleWindDeviceOrientation, true);
        window.removeEventListener('deviceorientationabsolute', handleWindDeviceOrientation, true);
        windCompassListening = false;
    }

    if (windCompassAnimationFrame !== null) {
        cancelAnimationFrame(windCompassAnimationFrame);
        windCompassAnimationFrame = null;
    }

    windCompassTargetHeading = null;
    windCompassHeading = null;
    windCompassRotation = null;

    if (windCompassStatus) windCompassStatus.textContent = 'Compass off';
    updateWindCompassButton();
}

async function enableWindCompass() {
    if (!('DeviceOrientationEvent' in window)) {
        if (windCompassStatus) windCompassStatus.textContent = 'Compass unavailable';
        if (windCompassButton) windCompassButton.disabled = true;
        return;
    }

    try {
        if (typeof DeviceOrientationEvent.requestPermission === 'function') {
            const permission = await DeviceOrientationEvent.requestPermission(true);
            if (permission !== 'granted') {
                throw new Error('Compass permission was not granted.');
            }
        }

        windCompassEnabled = true;
        localStorage.setItem(WIND_COMPASS_STORAGE_KEY, 'true');

        if (!windCompassListening) {
            window.addEventListener('deviceorientation', handleWindDeviceOrientation, true);
            window.addEventListener('deviceorientationabsolute', handleWindDeviceOrientation, true);
            windCompassListening = true;
        }

        updateWindCompassButton();
        if (windCompassStatus) windCompassStatus.textContent = 'Finding heading…';
    } catch (error) {
        if (windCompassStatus) {
            windCompassStatus.textContent = error instanceof Error ? error.message : 'Compass unavailable';
        }
    }
}

if (windGauge) {
    const initialCompassDisc = windGauge?.querySelector('.wind-compass-orientation');
    if (initialCompassDisc) initialCompassDisc.style.transform = 'rotate(0deg)';

    if (windCompassButton) {
        windCompassButton.addEventListener('click', async () => {
            if (windCompassEnabled) {
                disableWindCompass();
                return;
            }

            await enableWindCompass();
        });
        updateWindCompassButton();
    }

    if (windCompassEnabled &&
        typeof DeviceOrientationEvent !== 'undefined' &&
        typeof DeviceOrientationEvent.requestPermission !== 'function') {
        enableWindCompass();
    } else if (!windCompassEnabled && windCompassStatus) {
        windCompassStatus.textContent = 'Compass off';
    }
}

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

        // Connect real readings with a smooth curve without horizontal
        // plateaus at either endpoint. The control points follow the slope
        // through neighboring readings so the line begins and ends moving
        // naturally instead of pausing horizontally before changing value.
        for (let index = 0; index < points.length - 1; index += 1) {
            const current = points[index];
            const next = points[index + 1];

            if (Math.abs(next.y - current.y) < 0.001) {
                ctx.lineTo(next.x, next.y);
                continue;
            }

            const previous = points[index - 1] || current;
            const following = points[index + 2] || next;

            const incomingSlope = (next.y - previous.y) / Math.max(1, next.x - previous.x);
            const outgoingSlope = (following.y - current.y) / Math.max(1, following.x - current.x);
            const handle = (next.x - current.x) / 3;

            const cp1 = {
                x: current.x + handle,
                y: current.y + incomingSlope * handle
            };
            const cp2 = {
                x: next.x - handle,
                y: next.y - outgoingSlope * handle
            };

            ctx.bezierCurveTo(
                cp1.x,
                cp1.y,
                cp2.x,
                cp2.y,
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

function weatherChangeDirectionLabel(delta, unit, positive = 'increased', negative = 'decreased') {
    if (Math.abs(delta) < 0.05) return 'held steady';
    return delta > 0
        ? positive + ' by ' + Math.abs(delta).toFixed(unit === '°C' ? 1 : 1) + unit
        : negative + ' by ' + Math.abs(delta).toFixed(unit === '°C' ? 1 : 1) + unit;
}

function weatherConditionsStatus(score) {
    if (!Number.isFinite(score)) return { arrow: '→', label: 'Steady' };
    if (score >= 7) return { arrow: '↑', label: 'Worsening' };
    if (score <= 3) return { arrow: '↓', label: 'Improving' };
    return { arrow: '→', label: 'Steady' };
}

function weatherChangeScore(readings) {
    const valid = readings
        .map(item => ({
            temperature: Number(item.temperature_c),
            dewPoint: Number(item.dew_point_c),
            pressure: Number(item.pressure_hpa),
            humidity: Number(item.humidity_percent),
            wind: Number(item.wind_speed_kmh),
            direction: Number(item.wind_direction_degrees),
            rain: Number(item.rainfall_mm),
            cloud: Number(item.cloud_cover_percent),
            weatherCode: Number(item.weather_code),
            time: new Date(item.created_at || item.observed_at).getTime()
        }))
        .filter(item => Number.isFinite(item.time))
        .sort((a, b) => a.time - b.time);

    if (valid.length < 3) {
        return { score: null, summary: 'Gathering more readings…', reasons: [] };
    }

    const first = valid[0];
    const last = valid[valid.length - 1];
    const hours = Math.max(0.5, (last.time - first.time) / 3600000);
    const reasons = [];
    let score = 0;

    const pressureDelta = last.pressure - first.pressure;
    const pressureRate = Math.abs(pressureDelta) / hours;
    if (pressureRate >= 0.5) score += pressureRate >= 1.0 ? 2 : 1;
    if (pressureRate >= 1.5) score += 1;
    if (pressureRate >= 0.75) {
        reasons.push('Air pressure ' + (pressureDelta < 0 ? 'has fallen' : 'has risen') + ' by ' + Math.abs(pressureDelta).toFixed(1) + ' hPa over the last ' + hours.toFixed(1) + ' hours.');
    }

    const humidityDelta = last.humidity - first.humidity;
    const humidityChange = Math.abs(humidityDelta);
    if (humidityChange >= 4) score += humidityChange >= 8 ? 2 : 1;
    if (humidityChange >= 4) {
        reasons.push('Humidity ' + (humidityDelta < 0 ? 'has dropped' : 'has increased') + ' by ' + humidityChange.toFixed(0) + ' percentage points.');
    }

    const temperatureDelta = last.temperature - first.temperature;
    const temperatureChange = Math.abs(temperatureDelta);
    if (temperatureChange >= 1) score += temperatureChange >= 2 ? 2 : 1;
    if (temperatureChange >= 1) {
        reasons.push('Temperature ' + (temperatureDelta < 0 ? 'has cooled' : 'has warmed') + ' by ' + temperatureChange.toFixed(1) + '°C.');
    }

    const windDelta = last.wind - first.wind;
    const windChange = Math.abs(windDelta);
    if (windChange >= 8) score += windChange >= 15 ? 2 : 1;
    if (windChange >= 8) {
        reasons.push('Wind speed ' + (windDelta < 0 ? 'has eased' : 'has increased') + ' by ' + windChange.toFixed(1) + ' km/h.');
    }

    const circularDifference = (a, b) => {
        const diff = Math.abs(((a - b) % 360 + 360) % 360);
        return Math.min(diff, 360 - diff);
    };
    const directionChange = circularDifference(first.direction, last.direction);
    if (directionChange >= 30) score += directionChange >= 60 ? 2 : 1;
    if (directionChange >= 30) {
        reasons.push('Wind direction has shifted by about ' + Math.round(directionChange) + '°.');
    }

    const firstSpread = first.temperature - first.dewPoint;
    const lastSpread = last.temperature - last.dewPoint;
    const spreadDelta = lastSpread - firstSpread;
    if (Number.isFinite(firstSpread) && Number.isFinite(lastSpread) && spreadDelta <= -1.5) {
        score += 1;
        reasons.push('The temperature/dew-point gap is narrowing, indicating rising near-surface moisture.');
    }

    if (Number.isFinite(first.rain) && Number.isFinite(last.rain)) {
        const rainDelta = last.rain - first.rain;
        if ((first.rain < 0.2 && last.rain >= 0.2) || rainDelta >= 1.0) {
            score += 2;
            reasons.push('Rainfall has started or increased noticeably.');
        } else if (rainDelta >= 0.2) {
            score += 1;
            reasons.push('Rainfall is increasing.');
        }
    }

    if (Number.isFinite(first.cloud) && Number.isFinite(last.cloud)) {
        const cloudChange = Math.abs(last.cloud - first.cloud);
        if (cloudChange >= 40) {
            score += 2;
            reasons.push('Cloud cover has changed by about ' + Math.round(cloudChange) + ' percentage points.');
        } else if (cloudChange >= 20) {
            score += 1;
            reasons.push('Cloud cover has changed by about ' + Math.round(cloudChange) + ' percentage points.');
        }
    }

    if (Number.isFinite(first.weatherCode) && Number.isFinite(last.weatherCode) && first.weatherCode !== last.weatherCode) {
        const severity = code => {
            if ([95, 96, 99].includes(code)) return 5;
            if ([65, 67, 75, 82, 86].includes(code)) return 4;
            if ([61, 63, 66, 71, 73, 77, 80, 81, 85].includes(code)) return 3;
            if ([45, 48, 51, 53, 55, 56, 57].includes(code)) return 2;
            if ([2, 3].includes(code)) return 1;
            return 0;
        };
        const severityChange = Math.abs(severity(last.weatherCode) - severity(first.weatherCode));
        score += severityChange >= 2 ? 2 : 1;
        reasons.push('The reported weather condition has changed.');
    }

    score = Math.min(10, score);

    let summary;
    if (score >= 9) summary = 'Very high chance of a noticeable weather change.';
    else if (score >= 7) summary = 'High chance of a noticeable weather change.';
    else if (score >= 5) summary = 'Moderate chance of a noticeable weather change.';
    else if (score >= 3) summary = 'Some signs of a weather change developing.';
    else summary = 'Conditions look relatively stable right now.';

    return { score, summary, reasons: reasons.slice(0, 4) };
}

async function loadWeatherChange() {
    const scoreElement = document.getElementById('weatherChangeScore');
    const summaryElement = document.getElementById('weatherChangeSummary');
    const reasonsElement = document.getElementById('weatherChangeReasons');
    if (!scoreElement || !summaryElement || !reasonsElement) return;

    try {
        const response = await fetch('../api/history.php?hours=6', { cache: 'no-store' });
        if (!response.ok) throw new Error('Unable to load recent readings.');
        const data = await response.json();
        const result = weatherChangeScore(Array.isArray(data.readings) ? data.readings : []);

        if (result.score === null) {
            scoreElement.textContent = '—';
            summaryElement.textContent = result.summary;
            reasonsElement.innerHTML = '<div class="weather-change-reason">GaugeIQ needs a few more readings before it can estimate how quickly conditions are changing.</div>';
            return;
        }

        scoreElement.textContent = String(result.score);
        summaryElement.textContent = result.summary;
        const conditionsElement = document.getElementById('weatherConditions');
        if (conditionsElement) {
            const conditions = weatherConditionsStatus(result.score);
            conditionsElement.innerHTML = 'Conditions: <b>' + conditions.arrow + ' ' + conditions.label + '</b>';
        }
        reasonsElement.innerHTML = result.reasons.length
            ? result.reasons.map(reason => '<div class="weather-change-reason">' + reason + '</div>').join('')
            : '<div class="weather-change-reason">Pressure, temperature, humidity and wind have not changed significantly in the recent readings.</div>';

        const scoreClass = result.score >= 7 ? 'high' : result.score >= 5 ? 'moderate' : 'low';
        scoreElement.dataset.level = scoreClass;
        const stabilityFill = document.getElementById('weatherStabilityFill');
        const stabilityTrack = stabilityFill?.closest('.temperature-stability-track');
        if (stabilityFill) {
            const stabilityScore = 10 - result.score;
            stabilityFill.style.width = (stabilityScore * 10) + '%';

            const alertThreshold = Number(stabilityTrack?.dataset.weatherChangeAlertThreshold);
            const warningActive = Number.isFinite(alertThreshold)
                && result.score >= alertThreshold;

            stabilityFill.classList.toggle('warning', warningActive);
            stabilityTrack?.classList.toggle('warning', warningActive);
        }
    } catch {
        scoreElement.textContent = '—';
        summaryElement.textContent = 'Weather change indicator unavailable.';
        reasonsElement.innerHTML = '';
        const conditionsElement = document.getElementById('weatherConditions');
        if (conditionsElement) conditionsElement.innerHTML = 'Conditions: <b>→ Steady</b>';
    }
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
loadWeatherChange();

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


const deviceViewButton = document.getElementById('deviceViewButton');
const deviceList = document.getElementById('deviceList');

function formatDeviceDate(value) {
    if (!value) return 'Not recorded';
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
}

async function getCurrentPushEndpointHash() {
    try {
        if (!('PushManager' in window)) return null;
        const registration = await getRegistration();
        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) return null;
        const bytes = new TextEncoder().encode(subscription.endpoint);
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        return [...new Uint8Array(digest)].map(b => b.toString(16).padStart(2, '0')).join('');
    } catch {
        return null;
    }
}

async function loadDeviceList() {
    deviceList.innerHTML = '<p class="muted">Loading devices…</p>';
    try {
        const response = await fetch('../api/push-devices.php', { cache: 'no-store' });
        if (!response.ok) throw new Error('Unable to load registered devices.');
        const data = await response.json();
        const currentHash = await getCurrentPushEndpointHash();
        const devices = Array.isArray(data.devices) ? data.devices : [];

        if (!devices.length) {
            deviceList.innerHTML = '<p class="muted">No registered notification devices.</p>';
            return;
        }

        deviceList.innerHTML = devices.map(device => {
            const current = currentHash && currentHash === device.endpoint_hash;
            const pushStatus = device.last_push_status === 'sent'
                ? 'Last notification accepted by push service'
                : device.last_push_status === 'failed'
                    ? 'Last notification failed: ' + (device.last_push_error || 'unknown error')
                    : 'No notification delivery attempt recorded yet';

            return '<div class="device-item">' +
                '<div class="device-item-heading"><strong>' + device.device + (current ? ' · This device' : '') + '</strong><button type="button" class="device-delete" data-device-id="' + device.id + '">Delete</button></div>' +
                '<div class="device-details"><span>Browser</span><strong>' + device.browser + '</strong><span>Registered</span><strong>' + formatDeviceDate(device.created_at) + '</strong><span>Last seen</span><strong>' + formatDeviceDate(device.last_seen_at || device.updated_at) + '</strong><span>Delivery</span><strong>' + pushStatus + '</strong></div>' +
                '</div>';
        }).join('');

        deviceList.querySelectorAll('.device-delete').forEach(button => {
            button.addEventListener('click', async () => {
                if (!window.confirm('Delete this notification device?')) return;
                try {
                    const csrf = document.querySelector('input[name="csrf"]')?.value || '';
                    const body = new URLSearchParams({ id: button.dataset.deviceId, csrf });
                    const response = await fetch('../api/push-devices.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body });
                    if (!response.ok) throw new Error('Unable to delete the device.');
                    await loadDeviceList();
                    status.textContent = 'Notification device removed.';
                } catch (error) {
                    status.textContent = error instanceof Error ? error.message : 'Unable to delete the device.';
                }
            });
        });
    } catch (error) {
        deviceList.innerHTML = '<p class="muted">' + (error instanceof Error ? error.message : 'Unable to load devices.') + '</p>';
    }
}

if (deviceViewButton && deviceList) {
    deviceViewButton.addEventListener('click', async () => {
        const open = deviceList.hidden;
        deviceList.hidden = !open;
        deviceViewButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        deviceViewButton.textContent = open ? 'Hide' : 'View';
        if (open) await loadDeviceList();
    });
}
