document.addEventListener('DOMContentLoaded', async () => {
    const form = document.querySelector('form[action="admin-update.php"]');
    if (form) {
        form.addEventListener('submit', event => {
            if (!window.confirm('Install this verified GaugeIQ release now?')) {
                event.preventDefault();
            }
        });
    }

    const status = document.getElementById('adminUpdateStatus');
    const details = document.getElementById('adminUpdateDetails');
    if (!status || !details) return;

    try {
        const response = await fetch('api/update-check.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok) throw new Error();

        if (!data.update_available) {
            status.textContent = 'GaugeIQ is up to date (v' + data.current_version + ').';
            return;
        }

        if (!data.package_url || !data.checksum_url) {
            status.textContent = 'An update was found, but its release package or checksum is missing.';
            return;
        }

        document.getElementById('adminLatestVersion').textContent = 'Version ' + data.latest_version + ' is available.';
        document.getElementById('adminUpdateVersion').value = data.latest_version;
        document.getElementById('adminPackageUrl').value = data.package_url;
        document.getElementById('adminChecksumUrl').value = data.checksum_url;
        details.hidden = false;
        status.textContent = '';
    } catch {
        status.textContent = 'Unable to check for GaugeIQ updates.';
    }
});
