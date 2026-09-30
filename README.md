# GaugeIQ

GaugeIQ is a mobile-first Progressive Web App for monitoring atmospheric pressure and sending notifications when pressure changes beyond a user-defined threshold.

## Architecture

- PHP 8.2+ backend
- SQLite by default for simple hosting
- Open-Meteo weather data for atmospheric pressure
- Web Push subscriptions for browser notifications
- Server-side cron job for reliable background checking
- Installable PWA for iPhone Safari

## First setup

1. Copy `config/config.example.php` to `config/local.php`.
2. Set the application's public base URL.
3. Run `php database/migrate.php`.
4. Configure the weather location and cron job.
5. Serve the `public/` directory over HTTPS.
6. Open GaugeIQ in Safari on iPhone, add it to the Home Screen, and enable notifications.

No API key is required for the initial Open-Meteo integration.

## Cron

Run the pressure checker at an interval such as every 15 minutes:

`php /path/to/GaugeIQ/cron/check-pressure.php`

The checker records the latest pressure and sends a push notification when the configured change threshold is crossed.

## Project status

Initial foundation. Web Push credential generation/configuration and production push delivery will be completed in the next milestone.
