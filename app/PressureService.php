<?php
declare(strict_types=1);

final class PressureService
{
    public function __construct(private array $config, private PDO $db)
    {
    }

    public function fetchCurrent(): array
    {
        $lat = rawurlencode((string)$this->config['pressure']['latitude']);
        $lon = rawurlencode((string)$this->config['pressure']['longitude']);

        $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}"
            . "&current=surface_pressure,relative_humidity_2m,wind_speed_10m,wind_direction_10m"
            . "&wind_speed_unit=kmh&timezone=auto";

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: GaugeIQ/0.2\r\n",
            ],
        ]);

        $json = file_get_contents($url, false, $context);
        if ($json === false) {
            $error = error_get_last();
            $detail = is_array($error) && isset($error['message'])
                ? (string)$error['message']
                : 'No PHP stream error was reported.';

            $httpStatus = null;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d{3})/i', $header, $matches)) {
                        $httpStatus = $matches[1];
                        break;
                    }
                }
            }

            $status = $httpStatus === null ? '' : " HTTP status {$httpStatus}.";
            throw new RuntimeException(
                "Unable to retrieve weather data: {$detail}{$status}"
            );
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $current = $data['current'] ?? [];

        foreach ([
            'surface_pressure' => 'Pressure',
            'relative_humidity_2m' => 'Humidity',
            'wind_speed_10m' => 'Wind speed',
            'wind_direction_10m' => 'Wind direction',
        ] as $field => $label) {
            if (!isset($current[$field]) || !is_numeric($current[$field])) {
                throw new RuntimeException("{$label} data was not returned by the weather service.");
            }
        }

        return [
            'pressure_hpa' => (float)$current['surface_pressure'],
            'humidity_percent' => (float)$current['relative_humidity_2m'],
            'wind_speed_kmh' => (float)$current['wind_speed_10m'],
            'wind_direction_degrees' => (float)$current['wind_direction_10m'],
            'observed_at' => (string)($current['time'] ?? gmdate('c')),
        ];
    }

    public function record(array $current): ?array
    {
        $previous = $this->db->query(
            'SELECT pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees
             FROM gaugeiq_pressure_readings ORDER BY id DESC LIMIT 1'
        )->fetch();

        $stmt = $this->db->prepare(
            'INSERT INTO gaugeiq_pressure_readings
             (pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, observed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $current['pressure_hpa'],
            $current['humidity_percent'],
            $current['wind_speed_kmh'],
            $current['wind_direction_degrees'],
            $current['observed_at'],
            gmdate('c'),
        ]);

        return $previous ?: null;
    }

    public function pressureThresholdExceeded(?array $previous, float $current): bool
    {
        if ($previous === null) {
            return false;
        }

        return abs($current - (float)$previous['pressure_hpa'])
            >= (float)$this->config['pressure']['threshold_hpa'];
    }

    public function humidityThresholdExceeded(?array $previous, float $current): bool
    {
        if ($previous === null || empty($this->config['humidity']['enabled'])) {
            return false;
        }

        $previousValue = (float)$previous['humidity_percent'];
        $mode = (string)($this->config['humidity']['mode'] ?? 'change');
        $threshold = (float)($this->config['humidity']['threshold_percent'] ?? 10);

        return match ($mode) {
            'above' => $current >= $threshold && $previousValue < $threshold,
            'below' => $current <= $threshold && $previousValue > $threshold,
            'change' => abs($current - $previousValue) >= $threshold,
            default => false,
        };
    }

    public function windSpeedThresholdExceeded(?array $previous, float $current): bool
    {
        if ($previous === null || empty($this->config['wind']['enabled'])) {
            return false;
        }

        $mode = (string)($this->config['wind']['speed_mode'] ?? 'above');
        $threshold = (float)($this->config['wind']['speed_threshold_kmh'] ?? 40);

        return match ($mode) {
            'above' => $current >= $threshold && (float)$previous['wind_speed_kmh'] < $threshold,
            'below' => $current <= $threshold && (float)$previous['wind_speed_kmh'] > $threshold,
            'change' => abs($current - (float)$previous['wind_speed_kmh']) >= $threshold,
            default => false,
        };
    }

    public function windDirectionChanged(?array $previous, float $current): bool
    {
        if ($previous === null || empty($this->config['wind']['enabled'])) {
            return false;
        }

        $threshold = (float)($this->config['wind']['direction_change_degrees'] ?? 45);
        return $this->circularDifference(
            (float)$previous['wind_direction_degrees'],
            $current
        ) >= $threshold;
    }

    public function windDirectionMatches(float $current): bool
    {
        if (empty($this->config['wind']['enabled'])) {
            return false;
        }

        $directions = $this->config['wind']['specific_directions'] ?? [];
        if (!is_array($directions) || $directions === []) {
            return false;
        }

        foreach ($directions as $direction) {
            if ($this->circularDifference((float)$direction, $current) <= 22.5) {
                return true;
            }
        }

        return false;
    }

    public function circularDifference(float $a, float $b): float
    {
        $difference = abs(fmod($a - $b, 360.0));
        return min($difference, 360.0 - $difference);
    }

    public static function directionLabel(float $degrees): string
    {
        $labels = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'];
        return $labels[(int)round($degrees / 45) % 8];
    }
}
