# GaugeIQ

GaugeIQ is a mobile-first Progressive Web App for monitoring atmospheric pressure and sending notifications when pressure changes beyond a user-defined threshold.

## Architecture

- PHP 8.2+ backend
- SQLite or MySQL/MariaDB storage
- Open-Meteo weather data for atmospheric pressure
- Web Push subscriptions for browser notifications
- VAPID authentication for server-to-device push
- Server-side cron job for reliable background checking
- Installable PWA for iPhone Safari

## First setup

1. Copy `config/config.example.php` to `config/local.php`.
2. Set the application's public base URL.
3. Set the pressure location and alert threshold.
4. Run `composer install --no-dev --optimize-autoloader`.
5. Run `php database/migrate.php`.
6. Generate VAPID credentials with `php bin/generate-vapid.php`.
7. Put the generated public and private keys into `config/local.php`.
8. Set the VAPID subject to a stable `mailto:` address or HTTPS URL.
9. Serve only the `public/` directory as the website document root and use HTTPS.
10. Run `php bin/check-requirements.php`.
11. Install GaugeIQ on the iPhone Home Screen.
12. Open GaugeIQ and tap **Enable alerts**.
13. Configure the cron job to run `php /path/to/GaugeIQ/cron/check-pressure.php` every 15 minutes.

The VAPID keys must be generated once and kept unchanged. Never commit `config/local.php` or the private VAPID key.

For a detailed cPanel deployment, see [docs/CPANEL.md](docs/CPANEL.md).

## iPhone notifications

On iPhone and iPad, Web Push is supported for web apps that have been added to the Home Screen. Notification permission must be requested from a direct user interaction such as tapping GaugeIQ's alert button.

## Cron alerts

GaugeIQ stores pressure readings and compares changes against the configured threshold. Once an alert is sent, that pressure becomes the notification baseline, preventing repeated alerts every cron run while pressure remains within the same movement range.

Expired push subscriptions are removed automatically when the push service reports them as gone.

## Testing push notifications

After an iPhone has enabled alerts, test the real server-to-device push path from the server:

```bash
php bin/test-push.php
```

GaugeIQ intentionally does not expose a public test-push endpoint. A public endpoint could allow an anonymous visitor to trigger notifications to every registered device.

## Server requirements

The current Web Push library requires PHP 8.2+ plus the `curl`, `mbstring`, and `openssl` extensions. SQLite support through `pdo_sqlite` is also required. `bcmath` or `gmp` can improve performance but are optional.

## Security model

The application is designed so that the project root is not the web root. Only `public/` should be publicly served. Configuration, application classes, cron scripts, Composer dependencies, and the SQLite database remain outside the public document root.

The public web root also sends basic browser security headers and disables directory listings.

## Easy installation

A first-run installer is available at `/install/`. It can create the initial configuration, initialize the database, generate VAPID credentials, and set the monitoring location.

The installer supports:

- SQLite for a simple personal installation
- An existing MySQL/MariaDB database
- Browser location permission for easy coordinate setup
- Initial pressure, humidity, and wind monitoring choices

The installer locks itself after successful installation.

For production release packages, Composer dependencies should be bundled so a non-technical user does not need to run Composer.

## Weather monitoring

GaugeIQ records:

- Atmospheric pressure
- Relative humidity
- Wind speed
- Wind direction

Alerts can be configured independently. Wind monitoring supports speed thresholds, direction-change thresholds, and specific compass directions. Direction calculations use degrees and correctly handle the 0°/360° boundary.

## Project status

Foundation, Web Push delivery, SQLite/MySQL database support, first-run installation, humidity monitoring, wind speed monitoring, and wind direction monitoring are implemented. The next milestone can add the full visual settings screen, pressure/weather history, charts, dark mode, and a richer mobile dashboard.
