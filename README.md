# GaugeIQ

GaugeIQ is a mobile-first Progressive Web App for monitoring atmospheric pressure and sending notifications when pressure changes beyond a user-defined threshold.

## Architecture

- PHP 8.2+ backend
- SQLite by default for simple hosting
- Open-Meteo weather data for atmospheric pressure
- Web Push subscriptions for browser notifications
- VAPID authentication for server-to-device push
- Server-side cron job for reliable background checking
- Installable PWA for iPhone Safari

## First setup

1. Copy `config/config.example.php` to `config/local.php`.
2. Set the application's public base URL.
3. Set the pressure location and alert threshold.
4. Run `composer install`.
5. Run `php database/migrate.php`.
6. Generate VAPID credentials with `php bin/generate-vapid.php`.
7. Put the generated public and private keys into `config/local.php`.
8. Set the VAPID subject to a stable `mailto:` address or HTTPS URL.
9. Serve only the `public/` directory as the website document root and use HTTPS.
10. Install GaugeIQ on the iPhone Home Screen.
11. Open GaugeIQ and tap **Enable alerts**.
12. Configure the cron job to run `php /path/to/GaugeIQ/cron/check-pressure.php` every 15 minutes.

The VAPID keys must be generated once and kept unchanged. Never commit `config/local.php` or the private VAPID key.

## iPhone notifications

On iPhone and iPad, Web Push is supported for web apps that have been added to the Home Screen. Notification permission must be requested from a direct user interaction such as tapping GaugeIQ's alert button.

## Cron alerts

GaugeIQ stores pressure readings and compares changes against the configured threshold. Once an alert is sent, that pressure becomes the notification baseline, preventing repeated alerts every cron run while pressure remains within the same movement range.

Expired push subscriptions are removed automatically when the push service reports them as gone.

## Server requirements

The current Web Push library requires PHP 8.2+ plus the `curl`, `mbstring`, and `openssl` extensions. `bcmath` or `gmp` can improve performance but are optional.

## Project status

Foundation and Web Push notification layer are implemented. The next milestone can add pressure history, charts, configurable settings, and a richer mobile dashboard.
