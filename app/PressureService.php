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

        $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}&current=surface_pressure&timezone=auto";

        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'header' => "User-Agent: GaugeIQ/0.1\r\n",
            ],
        ]);

        $json = @file_get_contents($url, false, $context);
        if ($json === false) {
            throw new RuntimeException('Unable to retrieve pressure data.');
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $pressure = $data['current']['surface_pressure'] ?? null;

        if (!is_numeric($pressure)) {
            throw new RuntimeException('Pressure data was not returned by the weather service.');
        }

        return [
            'pressure_hpa' => (float)$pressure,
            'observed_at' => (string)($data['current']['time'] ?? gmdate('c')),
        ];
    }

    public function record(float $pressure, string $observedAt): ?float
    {
        $previous = $this->db->query(
            'SELECT pressure_hpa FROM pressure_readings ORDER BY id DESC LIMIT 1'
        )->fetchColumn();

        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'INSERT INTO pressure_readings (pressure_hpa, observed_at, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$pressure, $observedAt, $now]);

        return $previous === false ? null : (float)$previous;
    }

    public function thresholdExceeded(?float $previous, float $current): bool
    {
        if ($previous === null) {
            return false;
        }

        return abs($current - $previous) >= (float)$this->config['pressure']['threshold_hpa'];
    }
}
