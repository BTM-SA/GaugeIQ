(() => {
    'use strict';

    const DB_NAME = 'gaugeiq-local-weather';
    const DB_VERSION = 1;
    const STORE_LOCATIONS = 'locations';
    const STORE_READINGS = 'readings';
    const SERVER_LOCATION_ID = 'server-current';
    const status = () => document.getElementById('localWeatherStatus');
    const locationSelect = () => document.getElementById('weatherLocationSelect');
    let databasePromise;

    function openDatabase() {
        if (!('indexedDB' in window)) {
            return Promise.reject(new Error('IndexedDB is not available in this browser.'));
        }
        if (databasePromise) return databasePromise;
        databasePromise = new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);
            request.onupgradeneeded = () => {
                const db = request.result;
                if (!db.objectStoreNames.contains(STORE_LOCATIONS)) {
                    db.createObjectStore(STORE_LOCATIONS, { keyPath: 'id' });
                }
                if (!db.objectStoreNames.contains(STORE_READINGS)) {
                    const readings = db.createObjectStore(STORE_READINGS, { keyPath: ['locationId', 'timestamp'] });
                    readings.createIndex('locationId', 'locationId', { unique: false });
                    readings.createIndex('timestamp', 'timestamp', { unique: false });
                }
            };
            request.onsuccess = () => {
                const db = request.result;
                db.onversionchange = () => db.close();
                resolve(db);
            };
            request.onerror = () => reject(request.error || new Error('Unable to open local weather storage.'));
            request.onblocked = () => reject(new Error('Local weather storage is busy in another GaugeIQ tab. Close the other tab and retry.'));
        });
        return databasePromise;
    }

    function transactionDone(tx) {
        return new Promise((resolve, reject) => {
            tx.oncomplete = () => resolve();
            tx.onabort = tx.onerror = () => reject(tx.error || new Error('Local weather storage operation failed.'));
        });
    }

    async function allLocations() {
        const db = await openDatabase();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_LOCATIONS, 'readonly');
            const request = tx.objectStore(STORE_LOCATIONS).getAll();
            request.onsuccess = () => resolve(request.result || []);
            request.onerror = () => reject(request.error);
        });
    }

    async function readingsFor(locationId, hours) {
        const db = await openDatabase();
        const rows = await new Promise((resolve, reject) => {
            const tx = db.transaction(STORE_READINGS, 'readonly');
            const request = tx.objectStore(STORE_READINGS).index('locationId').getAll(IDBKeyRange.only(locationId));
            request.onsuccess = () => resolve(request.result || []);
            request.onerror = () => reject(request.error);
        });
        if (hours === 'all') return rows.sort((a, b) => a.timestamp.localeCompare(b.timestamp));
        const cutoff = Date.now() - Math.max(1, Number(hours) || 24) * 3600000;
        return rows.filter(row => {
            const time = Date.parse(row.timestamp);
            return Number.isFinite(time) && time >= cutoff;
        }).sort((a, b) => a.timestamp.localeCompare(b.timestamp));
    }

    function parseCsv(text) {
        const rows = [];
        let row = [];
        let field = '';
        let quoted = false;
        for (let i = 0; i < text.length; i++) {
            const char = text[i];
            if (char === '"') {
                if (quoted && text[i + 1] === '"') {
                    field += '"';
                    i++;
                } else {
                    quoted = !quoted;
                }
            } else if (char === ',' && !quoted) {
                row.push(field);
                field = '';
            } else if ((char === '\n' || char === '\r') && !quoted) {
                if (char === '\r' && text[i + 1] === '\n') i++;
                row.push(field);
                if (row.some(value => value.trim() !== '')) rows.push(row);
                row = [];
                field = '';
            } else {
                field += char;
            }
        }
        if (field.length || row.length) {
            row.push(field);
            if (row.some(value => value.trim() !== '')) rows.push(row);
        }
        return rows;
    }

    function normalizeTimestamp(value) {
        if (typeof value === 'number') {
            const date = new Date(value < 100000000000 ? value * 1000 : value);
            return Number.isNaN(date.getTime()) ? null : date.toISOString();
        }
        const raw = String(value || '').trim();
        if (!raw) return null;
        const date = new Date(raw);
        return Number.isNaN(date.getTime()) ? null : date.toISOString();
    }

    function openMeteoTimestamp(value, offsetSeconds) {
        const raw = String(value || '').trim();
        if (!raw) return null;
        if (/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw)) return normalizeTimestamp(raw);
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (!match) return normalizeTimestamp(raw);
        const wallClockAsUtc = Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]), Number(match[6] || 0));
        const offset = Number(offsetSeconds);
        return new Date(wallClockAsUtc - (Number.isFinite(offset) ? offset : 0) * 1000).toISOString();
    }

    function localTimeInZoneToUtc(value, timeZone) {
        const raw = String(value || '').trim();
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?$/);
        if (!match) return normalizeTimestamp(raw);
        const desired = Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]), Number(match[6] || 0));
        try {
            const formatter = new Intl.DateTimeFormat('en-GB', {
                timeZone: timeZone || 'UTC', year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23'
            });
            let guess = desired;
            for (let attempt = 0; attempt < 3; attempt++) {
                const parts = Object.fromEntries(formatter.formatToParts(new Date(guess))
                    .filter(part => part.type !== 'literal').map(part => [part.type, Number(part.value)]));
                const represented = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second);
                const delta = desired - represented;
                guess += delta;
                if (delta === 0) break;
            }
            return new Date(guess).toISOString();
        } catch {
            return normalizeTimestamp(raw);
        }
    }

    function numberOrNull(value) {
        if (value === null || value === undefined || value === '') return null;
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    }

    function fromOpenMeteoJson(data) {
        if (data && data.format === 'gaugeiq-local-weather-backup' && Array.isArray(data.locations) && Array.isArray(data.readings)) {
            return { backup: data };
        }
        const hourly = data && data.hourly;
        if (!hourly || !Array.isArray(hourly.time)) {
            throw new Error('JSON format not recognized. Choose an Open-Meteo hourly JSON response or a GaugeIQ local backup.');
        }
        const n = hourly.time.length;
        const rows = [];
        for (let i = 0; i < n; i++) {
            const timestamp = localTimeInZoneToUtc(hourly.time[i], data.timezone || 'UTC');
            if (!timestamp) continue;
            rows.push({
                timestamp,
                temperature_c: numberOrNull(hourly.temperature_2m?.[i]),
                dew_point_c: numberOrNull(hourly.dew_point_2m?.[i]),
                pressure_hpa: numberOrNull(hourly.pressure_msl?.[i] ?? hourly.surface_pressure?.[i]),
                humidity_percent: numberOrNull(hourly.relative_humidity_2m?.[i]),
                wind_speed_kmh: numberOrNull(hourly.wind_speed_10m?.[i]),
                wind_direction_degrees: numberOrNull(hourly.wind_direction_10m?.[i]),
                rainfall_mm: numberOrNull(hourly.precipitation?.[i]),
                cloud_cover_percent: numberOrNull(hourly.cloud_cover?.[i]),
                weather_code: numberOrNull(hourly.weather_code?.[i]),
                source: 'open-meteo',
                observed_at: String(hourly.time[i])
            });
        }
        return {
            location: {
                name: data.location_name || data.name || 'Imported Open-Meteo location',
                latitude: numberOrNull(data.latitude),
                longitude: numberOrNull(data.longitude),
                timezone: data.timezone || 'UTC'
            },
            readings: rows
        };
    }

    function fromCsv(text, timezone) {
        const rows = parseCsv(text);
        if (rows.length < 2) throw new Error('The CSV file does not contain weather records.');
        const headers = rows[0].map(value => value.trim().replace(/^\uFEFF/, '').toLowerCase());
        const findColumn = names => headers.findIndex(header => names.includes(header));
        const timeIndex = findColumn(['time', 'timestamp', 'date', 'datetime']);
        if (timeIndex < 0) throw new Error('CSV must include a time column, as in an Open-Meteo hourly CSV.');
        const indices = {
            temperature_c: findColumn(['temperature_2m (°c)', 'temperature_2m (°c)', 'temperature_2m', 'temperature_c']),
            dew_point_c: findColumn(['dew_point_2m (°c)', 'dew_point_2m', 'dew_point_c']),
            pressure_hpa: findColumn(['pressure_msl (hpa)', 'pressure_msl', 'surface_pressure (hpa)', 'surface_pressure', 'pressure_hpa']),
            humidity_percent: findColumn(['relative_humidity_2m (%)', 'relative_humidity_2m', 'humidity_percent']),
            wind_speed_kmh: findColumn(['wind_speed_10m (km/h)', 'wind_speed_10m', 'wind_speed_kmh']),
            wind_direction_degrees: findColumn(['wind_direction_10m (°)', 'wind_direction_10m', 'wind_direction_degrees']),
            rainfall_mm: findColumn(['precipitation (mm)', 'precipitation', 'rainfall_mm']),
            cloud_cover_percent: findColumn(['cloud_cover (%)', 'cloud_cover', 'cloud_cover_percent']),
            weather_code: findColumn(['weather_code (wmo code)', 'weather_code', 'weathercode'])
        };
        const readings = [];
        for (const row of rows.slice(1)) {
            const timestamp = localTimeInZoneToUtc(row[timeIndex], timezone);
            if (!timestamp) continue;
            const reading = {
                timestamp,
                source: 'open-meteo-csv',
                observed_at: String(row[timeIndex] || '').trim()
            };
            Object.entries(indices).forEach(([key, index]) => {
                reading[key] = index >= 0 ? numberOrNull(row[index]) : null;
            });
            if (Object.values(reading).some(value => typeof value === 'number')) readings.push(reading);
        }
        if (!readings.length) throw new Error('No usable weather rows were found. Check that this is an Open-Meteo CSV with a time column.');
        return { location: { name: 'Imported Open-Meteo location', latitude: null, longitude: null, timezone: 'UTC' }, readings };
    }

    async function saveLocationAndReadings(location, readings, existingId) {
        if (!readings.length) throw new Error('No weather readings were found to import.');
        const db = await openDatabase();
        const id = existingId || ('local-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8));
        const name = String(location.name || 'Imported location').trim().slice(0, 100) || 'Imported location';
        const record = {
            id,
            name,
            latitude: numberOrNull(location.latitude),
            longitude: numberOrNull(location.longitude),
            timezone: String(location.timezone || 'UTC'),
            source: String(location.source || 'Open-Meteo import'),
            updatedAt: new Date().toISOString(),
            readingCount: readings.length
        };
        let tx = db.transaction(STORE_LOCATIONS, 'readwrite');
        tx.objectStore(STORE_LOCATIONS).put(record);
        await transactionDone(tx);

        const validReadings = readings.filter(item => item.timestamp);
        for (let start = 0; start < validReadings.length; start += 500) {
            tx = db.transaction(STORE_READINGS, 'readwrite');
            const store = tx.objectStore(STORE_READINGS);
            for (const item of validReadings.slice(start, start + 500)) {
                store.put({
                    locationId: id,
                    timestamp: item.timestamp,
                    observed_at: item.observed_at || item.timestamp,
                    temperature_c: numberOrNull(item.temperature_c),
                    dew_point_c: numberOrNull(item.dew_point_c),
                    pressure_hpa: numberOrNull(item.pressure_hpa),
                    humidity_percent: numberOrNull(item.humidity_percent),
                    wind_speed_kmh: numberOrNull(item.wind_speed_kmh),
                    wind_direction_degrees: numberOrNull(item.wind_direction_degrees),
                    rainfall_mm: numberOrNull(item.rainfall_mm),
                    cloud_cover_percent: numberOrNull(item.cloud_cover_percent),
                    weather_code: numberOrNull(item.weather_code),
                    source: item.source || record.source
                });
            }
            await transactionDone(tx);
        }
        return record;
    }


    function dateOnly(date) {
        return date.toISOString().slice(0, 10);
    }

    async function installHistoricalData() {
        const select = locationSelect();
        const rangeSelect = document.getElementById('localWeatherRange');
        const sourceSelect = document.getElementById('localWeatherSource');
        const fetchButton = document.getElementById('localWeatherFetch');
        if (!select || !rangeSelect || !sourceSelect) throw new Error('Historical-data controls are unavailable.');

        const selectedId = select.value;
        let locations = await allLocations();
        let location = locations.find(item => item.id === selectedId);
        if (selectedId === SERVER_LOCATION_ID) {
            const latitude = Number(select.dataset.serverLatitude);
            const longitude = Number(select.dataset.serverLongitude);
            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
                throw new Error('The server location coordinates are unavailable. Select or create a saved location with coordinates.');
            }
            location = {
                id: SERVER_LOCATION_ID,
                name: select.dataset.serverLocationName || 'Current GaugeIQ location',
                latitude,
                longitude,
                timezone: select.dataset.serverTimezone || 'auto'
            };
        }
        if (!location || !Number.isFinite(Number(location.latitude)) || !Number.isFinite(Number(location.longitude))) {
            throw new Error('This location needs valid latitude and longitude before historical data can be downloaded.');
        }

        const source = sourceSelect.value === 'weather' ? 'weather' : 'forecast';
        const range = rangeSelect.value;
        const now = new Date();
        const endDate = new Date(now.getTime() - 86400000);
        let startDate;
        if (range === 'all') {
            startDate = new Date(Date.UTC(1940, 0, 1));
        } else {
            const days = Math.max(1, Math.min(365, Number(range) || 365));
            startDate = new Date(endDate.getTime() - (days - 1) * 86400000);
        }
        if (source === 'forecast' && startDate < new Date(Date.UTC(2022, 0, 1))) {
            startDate = new Date(Date.UTC(2022, 0, 1));
        }
        if (startDate > endDate) throw new Error('There is no completed historical date in the selected range.');

        const endpoint = source === 'forecast'
            ? 'https://historical-forecast-api.open-meteo.com/v1/forecast'
            : 'https://archive-api.open-meteo.com/v1/archive';
        const params = new URLSearchParams({
            latitude: String(location.latitude),
            longitude: String(location.longitude),
            start_date: dateOnly(startDate),
            end_date: dateOnly(endDate),
            hourly: 'temperature_2m,dew_point_2m,apparent_temperature,pressure_msl,surface_pressure,relative_humidity_2m,wind_speed_10m,wind_direction_10m,precipitation,cloud_cover,weather_code',
            temperature_unit: 'celsius',
            wind_speed_unit: 'kmh',
            precipitation_unit: 'mm',
            timezone: location.timezone && location.timezone !== 'auto' ? location.timezone : 'auto'
        });
        fetchButton?.setAttribute('disabled', 'disabled');
        try {
            setStatus('Downloading ' + (source === 'forecast' ? 'Historical Forecast' : 'Historical Weather') +
                ' from ' + params.get('start_date') + ' to ' + params.get('end_date') + '…', false);
            const response = await fetch(endpoint + '?' + params.toString(), { cache: 'no-store' });
            let data;
            try { data = await response.json(); }
            catch { throw new Error('Open-Meteo returned an unreadable response. Please retry with a shorter range.'); }
            if (!response.ok || data.error) {
                throw new Error(data.reason || 'Open-Meteo returned HTTP ' + response.status + '. Try a shorter range or the other historical source.');
            }
            const parsed = fromOpenMeteoJson(data);
            if (!parsed.readings.length) throw new Error('Open-Meteo returned no hourly readings for this location and date range.');
            const targetName = String(location.name || 'GaugeIQ location');
            const savedLocation = {
                ...location,
                name: targetName,
                latitude: Number(location.latitude),
                longitude: Number(location.longitude),
                timezone: data.timezone || location.timezone || 'UTC',
                source: source === 'forecast' ? 'Open-Meteo Historical Forecast' : 'Open-Meteo Historical Weather'
            };
            let targetId = location.id;
            if (targetId === SERVER_LOCATION_ID) {
                const match = locations.find(item =>
                    Number.isFinite(Number(item.latitude)) && Number.isFinite(Number(item.longitude)) &&
                    Math.abs(Number(item.latitude) - savedLocation.latitude) < 0.001 &&
                    Math.abs(Number(item.longitude) - savedLocation.longitude) < 0.001
                );
                targetId = match?.id || null;
            }
            const saved = await saveLocationAndReadings(savedLocation, parsed.readings.map(row => ({
                ...row,
                source: savedLocation.source
            })), targetId);
            await refreshLocations(saved.id);
            if (window.GaugeIQLoadHistory) window.GaugeIQLoadHistory('all');
            setStatus('Historical data installed: ' + parsed.readings.length.toLocaleString() +
                ' hourly records for ' + saved.name + '. Matching timestamps are deduplicated; live server records remain unchanged.' +
                await storageSummary(), false);
        } finally {
            fetchButton?.removeAttribute('disabled');
        }
    }

    function setStatus(message, isError) {
        const element = status();
        if (!element) return;
        element.textContent = message;
        element.dataset.state = isError ? 'error' : 'ok';
    }

    function formatBytes(value) {
        if (!Number.isFinite(value) || value < 0) return 'unknown';
        if (value < 1024 * 1024) return Math.round(value / 1024) + ' KB';
        return (value / (1024 * 1024)).toFixed(1) + ' MB';
    }

    async function storageSummary() {
        if (!navigator.storage || typeof navigator.storage.estimate !== 'function') return '';
        try {
            const estimate = await navigator.storage.estimate();
            const usage = Number(estimate.usage);
            const quota = Number(estimate.quota);
            if (!Number.isFinite(usage) || !Number.isFinite(quota) || quota <= 0) return '';
            const percent = usage / quota;
            return ' Browser storage: ' + formatBytes(usage) + ' used; estimated origin quota ' + formatBytes(quota) + '.' +
                (percent >= 0.8 ? ' Storage is getting full; export a backup and check available device space.' : '');
        } catch {
            return '';
        }
    }

    function updateRangeButtons() {
        const localSelected = locationSelect()?.value && locationSelect().value !== SERVER_LOCATION_ID;
        document.querySelectorAll('[data-local-range="true"]').forEach(button => {
            button.hidden = !localSelected;
        });
    }

    async function refreshLocations(selectId) {
        const select = locationSelect();
        if (!select) return;
        const locations = await allLocations();
        const selected = selectId || select.value || SERVER_LOCATION_ID;
        select.replaceChildren();
        const serverOption = document.createElement('option');
        serverOption.value = SERVER_LOCATION_ID;
        serverOption.textContent = 'Current GaugeIQ location (server)';
        select.append(serverOption);
        locations.sort((a, b) => a.name.localeCompare(b.name)).forEach(location => {
            const option = document.createElement('option');
            option.value = location.id;
            const coords = Number.isFinite(location.latitude) && Number.isFinite(location.longitude)
                ? ' · ' + location.latitude.toFixed(3) + ', ' + location.longitude.toFixed(3)
                : '';
            option.textContent = location.name + coords;
            select.append(option);
        });
        select.value = [...select.options].some(option => option.value === selected) ? selected : SERVER_LOCATION_ID;
        updateRangeButtons();
    }

    function downloadJson(filename, data) {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.append(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }

    async function exportBackup() {
        setStatus('Preparing local weather backup…', false);
        const locations = await allLocations();
        const readings = [];
        for (const location of locations) {
            readings.push(...await readingsFor(location.id, 'all'));
        }
        downloadJson('gaugeiq-weather-backup-' + new Date().toISOString().slice(0, 10) + '.json', {
            format: 'gaugeiq-local-weather-backup',
            version: 1,
            exportedAt: new Date().toISOString(),
            locations,
            readings
        });
        setStatus('Backup exported: ' + locations.length + ' location(s), ' + readings.length + ' readings.' + await storageSummary(), false);
    }

    async function importFile(file) {
        if (!file) return;
        if (file.size > 100 * 1024 * 1024) {
            throw new Error('This file is over 100 MB. Split the dataset into smaller date ranges and import them separately.');
        }
        setStatus('Reading ' + file.name + '…', false);
        const text = await file.text();
        let parsed;
        if (/\.csv$/i.test(file.name) || file.type.includes('csv')) {
            const timezone = prompt('Timezone used by this CSV (for example, Africa/Johannesburg):', Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC');
            if (timezone === null) return;
            try { new Intl.DateTimeFormat('en-US', { timeZone: timezone.trim() || 'UTC' }); }
            catch { throw new Error('That timezone is not recognized. Use an IANA timezone such as Africa/Johannesburg.'); }
            parsed = fromCsv(text, timezone.trim() || 'UTC');
            parsed.location.timezone = timezone.trim() || 'UTC';
        } else {
            let data;
            try { data = JSON.parse(text); } catch { throw new Error('This file is not valid JSON.'); }
            parsed = fromOpenMeteoJson(data);
        }

        if (parsed.backup) {
            const backup = parsed.backup;
            if (backup.version !== 1) throw new Error('This GaugeIQ backup version is not supported.');
            if (!confirm('Restore ' + backup.locations.length + ' location(s) and ' + backup.readings.length + ' readings? Existing matching timestamps will be updated; other records will be kept.')) return;
            for (const location of backup.locations) {
                await saveLocationAndReadings(location, backup.readings.filter(row => row.locationId === location.id), location.id);
            }
            setStatus('Backup restored. ' + backup.locations.length + ' location(s) processed.' + await storageSummary(), false);
            await refreshLocations(backup.locations[0]?.id);
            const allRange = document.querySelector('.history-range-button[data-hours="all"]');
            if (allRange && !allRange.hidden) allRange.click();
            else if (window.GaugeIQLoadHistory) window.GaugeIQLoadHistory(24);
            return;
        }

        const defaultName = parsed.location.name || 'Imported Open-Meteo location';
        const name = prompt('Name this location (for example, East London):', defaultName);
        if (name === null) return;
        parsed.location.name = name.trim() || defaultName;
        parsed.location.source = 'Open-Meteo';
        const existing = (await allLocations()).find(location => location.name.toLocaleLowerCase() === parsed.location.name.toLocaleLowerCase());
        const existingId = existing && confirm('A local location named "' + existing.name + '" already exists. Add these readings to it and update matching timestamps? Choose Cancel to create a separate location.')
            ? existing.id
            : undefined;
        const saved = await saveLocationAndReadings(parsed.location, parsed.readings, existingId);
        await refreshLocations(saved.id);
        updateRangeButtons();
        const range = document.querySelector('.history-range-button[data-hours="all"]');
        if (range) range.click();
        else if (window.GaugeIQLoadHistory) window.GaugeIQLoadHistory('all');
        setStatus('Imported ' + parsed.readings.length + ' readings for ' + saved.name + ' into this device only.' + await storageSummary(), false);
    }

    async function requestPersistentStorage() {
        if (!navigator.storage || typeof navigator.storage.persist !== 'function') {
            setStatus('Local storage is available, but this browser cannot confirm persistent-storage protection.', false);
            return;
        }
        const alreadyPersistent = typeof navigator.storage.persisted === 'function' && await navigator.storage.persisted();
        if (alreadyPersistent) {
            setStatus('GaugeIQ local weather storage is marked persistent by this browser.', false);
            return;
        }
        const granted = await navigator.storage.persist();
        setStatus(granted
            ? 'The browser granted persistent storage for GaugeIQ.'
            : 'The browser did not grant persistent storage. Export backups regularly to protect your data.', !granted);
    }

    function setup() {
        const select = locationSelect();
        const fileInput = document.getElementById('localWeatherFile');
        const importButton = document.getElementById('localWeatherImport');
        const exportButton = document.getElementById('localWeatherExport');
        const persistButton = document.getElementById('localWeatherPersist');
        const fetchButton = document.getElementById('localWeatherFetch');
        if (!select || !fileInput || !importButton || !exportButton) return;

        select.addEventListener('change', () => {
            updateRangeButtons();
            document.querySelectorAll('.history-range-button').forEach(button => button.classList.remove('active'));
            document.querySelector('.history-range-button[data-hours="24"]')?.classList.add('active');
            if (window.GaugeIQLoadHistory) window.GaugeIQLoadHistory(24);
        });
        importButton.addEventListener('click', () => fileInput.click());
        fetchButton?.addEventListener('click', async () => {
            try { await installHistoricalData(); }
            catch (error) { setStatus(error instanceof Error ? error.message : 'Unable to download historical weather data.', true); }
        });
        fileInput.addEventListener('change', async () => {
            try { await importFile(fileInput.files?.[0]); }
            catch (error) { setStatus(error instanceof Error ? error.message : 'Unable to import this file.', true); }
            finally { fileInput.value = ''; }
        });
        exportButton.addEventListener('click', async () => {
            try { await exportBackup(); }
            catch (error) { setStatus(error instanceof Error ? error.message : 'Unable to export a backup.', true); }
        });
        persistButton?.addEventListener('click', async () => {
            try { await requestPersistentStorage(); }
            catch { setStatus('Unable to request persistent storage in this browser.', true); }
        });

        openDatabase()
            .then(() => refreshLocations())
            .then(async () => setStatus('Local weather database is ready. Imports and backups stay on this device unless you export them.' + await storageSummary(), false))
            .catch(error => {
                setStatus(error instanceof Error ? error.message : 'Local weather storage is unavailable.', true);
                importButton.disabled = true;
                exportButton.disabled = true;
                if (persistButton) persistButton.disabled = true;
            });
    }

    window.GaugeIQLocalWeather = {
        serverLocationId: SERVER_LOCATION_ID,
        readingsFor,
        allLocations,
        refreshLocations,
        setStatus,
        installHistoricalData
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setup);
    else setup();
})();