<?php
declare(strict_types=1);

final class AlertRuleService
{
    public function __construct(private PDO $db)
    {
    }

    public function all(bool $enabledOnly = false): array
    {
        $sql = 'SELECT * FROM gaugeiq_alert_rules';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY id ASC';

        return $this->db->query($sql)->fetchAll();
    }

    public function create(
        string $name,
        string $metric,
        string $conditionType,
        array $configuration,
        int $cooldownMinutes = 60
    ): int {
        $this->validate($metric, $conditionType, $configuration);

        $now = gmdate('c');
        $stmt = $this->db->prepare(
            'INSERT INTO gaugeiq_alert_rules
             (name, metric, condition_type, configuration_json, enabled, cooldown_minutes, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)'
        );
        $stmt->execute([
            trim($name) ?: 'GaugeIQ alert',
            $metric,
            $conditionType,
            json_encode($configuration, JSON_THROW_ON_ERROR),
            max(0, $cooldownMinutes),
            $now,
            $now,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(
        int $id,
        string $name,
        string $metric,
        string $conditionType,
        array $configuration,
        bool $enabled,
        int $cooldownMinutes
    ): void {
        $this->validate($metric, $conditionType, $configuration);

        $stmt = $this->db->prepare(
            'UPDATE gaugeiq_alert_rules
             SET name = ?, metric = ?, condition_type = ?, configuration_json = ?,
                 enabled = ?, cooldown_minutes = ?, updated_at = ?
             WHERE id = ?'
        );
        $stmt->execute([
            trim($name) ?: 'GaugeIQ alert',
            $metric,
            $conditionType,
            json_encode($configuration, JSON_THROW_ON_ERROR),
            $enabled ? 1 : 0,
            max(0, $cooldownMinutes),
            gmdate('c'),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM gaugeiq_alert_rules WHERE id = ?');
        $stmt->execute([$id]);

        $stmt = $this->db->prepare('DELETE FROM gaugeiq_alert_events WHERE rule_id = ?');
        $stmt->execute([$id]);
    }

    public function evaluate(array $previous, array $current): array
    {
        $matches = [];

        foreach ($this->all(true) as $rule) {
            $config = json_decode((string)$rule['configuration_json'], true);
            if (!is_array($config)) {
                continue;
            }

            if (!$this->cooldownExpired($rule)) {
                continue;
            }

            $result = $this->matches(
                (string)$rule['metric'],
                (string)$rule['condition_type'],
                $config,
                $previous,
                $current
            );

            if ($result === null) {
                continue;
            }

            $matches[] = [
                'id' => (int)$rule['id'],
                'name' => (string)$rule['name'],
                'message' => $result,
            ];
        }

        return $matches;
    }

    public function evaluateWeatherChange(): array
    {
        $matches = [];

        foreach ($this->all(true) as $rule) {
            if ((string)$rule['metric'] !== 'weather_change' || !$this->cooldownExpired($rule)) {
                continue;
            }

            $config = json_decode((string)$rule['configuration_json'], true);
            if (!is_array($config)) {
                continue;
            }

            $result = $this->weatherChange((string)$rule['condition_type'], $config);
            if ($result === null) {
                continue;
            }

            $matches[] = [
                'id' => (int)$rule['id'],
                'name' => (string)$rule['name'],
                'message' => $result,
            ];
        }

        return $matches;
    }

    public function markTriggered(int $ruleId, string $message, string $observedAt): void
    {
        $now = gmdate('c');

        $stmt = $this->db->prepare(
            'UPDATE gaugeiq_alert_rules SET last_triggered_at = ?, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $now, $ruleId]);

        $stmt = $this->db->prepare(
            'INSERT INTO gaugeiq_alert_events (rule_id, observed_at, message, created_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$ruleId, $observedAt, $message, $now]);
    }

    private function cooldownExpired(array $rule): bool
    {
        if (empty($rule['last_triggered_at'])) {
            return true;
        }

        $last = strtotime((string)$rule['last_triggered_at']);
        if ($last === false) {
            return true;
        }

        return time() - $last >= ((int)$rule['cooldown_minutes'] * 60);
    }

    private function matches(
        string $metric,
        string $type,
        array $config,
        array $previous,
        array $current
    ): ?string {
        return match ($metric) {
            'pressure' => $this->pressure($type, $config, $previous, $current),
            'humidity' => $this->humidity($type, $config, $previous, $current),
            'wind_speed' => $this->windSpeed($type, $config, $previous, $current),
            'wind_direction' => $this->windDirection($type, $config, $previous, $current),
            'wind' => $this->combinedWind($type, $config, $previous, $current),
            'weather_change' => $this->weatherChange($type, $config),
            default => null,
        };
    }

    private function pressure(string $type, array $c, array $p, array $n): ?string
    {
        $value = (float)$n['pressure_hpa'];
        $old = (float)$p['pressure_hpa'];
        $threshold = (float)($c['value'] ?? 0);

        if ($type === 'change' && abs($value - $old) >= $threshold) {
            return sprintf('Pressure changed by %.1f hPa to %.1f hPa.', abs($value - $old), $value);
        }
        if ($type === 'above' && $value >= $threshold && $old < $threshold) {
            return sprintf('Pressure rose above %.1f hPa to %.1f hPa.', $threshold, $value);
        }
        if ($type === 'below' && $value <= $threshold && $old > $threshold) {
            return sprintf('Pressure fell below %.1f hPa to %.1f hPa.', $threshold, $value);
        }

        return null;
    }

    private function humidity(string $type, array $c, array $p, array $n): ?string
    {
        $value = (float)$n['humidity_percent'];
        $old = (float)$p['humidity_percent'];
        $threshold = (float)($c['value'] ?? 0);

        if ($type === 'change' && abs($value - $old) >= $threshold) {
            return sprintf('Humidity changed by %.0f%% to %.0f%%.', abs($value - $old), $value);
        }
        if ($type === 'above' && $value >= $threshold && $old < $threshold) {
            return sprintf('Humidity rose above %.0f%% to %.0f%%.', $threshold, $value);
        }
        if ($type === 'below' && $value <= $threshold && $old > $threshold) {
            return sprintf('Humidity fell below %.0f%% to %.0f%%.', $threshold, $value);
        }

        return null;
    }

    private function windSpeed(string $type, array $c, array $p, array $n): ?string
    {
        $value = (float)$n['wind_speed_kmh'];
        $old = (float)$p['wind_speed_kmh'];
        $threshold = (float)($c['value'] ?? 0);

        if ($type === 'change' && abs($value - $old) >= $threshold) {
            return sprintf('Wind speed changed by %.1f km/h to %.1f km/h.', abs($value - $old), $value);
        }
        if ($type === 'above' && $value >= $threshold && $old < $threshold) {
            return sprintf('Wind speed rose above %.1f km/h to %.1f km/h.', $threshold, $value);
        }
        if ($type === 'below' && $value <= $threshold && $old > $threshold) {
            return sprintf('Wind speed fell below %.1f km/h to %.1f km/h.', $threshold, $value);
        }

        return null;
    }

    private function windDirection(string $type, array $c, array $p, array $n): ?string
    {
        $value = (float)$n['wind_direction_degrees'];
        $old = (float)$p['wind_direction_degrees'];
        $difference = $this->directionDifference($old, $value);

        if ($type === 'change' && $difference >= (float)($c['degrees'] ?? 0)) {
            return sprintf('Wind direction changed by %.0f° to %s.', $difference, PressureService::directionLabel($value));
        }

        if ($type === 'specific') {
            $target = (float)($c['degrees'] ?? 0);
            if ($this->directionDifference($target, $value) <= 22.5 &&
                $this->directionDifference($target, $old) > 22.5) {
                return sprintf('Wind is now coming from %s.', PressureService::directionLabel($value));
            }
        }

        return null;
    }

    private function weatherChange(string $type, array $c): ?string
    {
        if ($type !== 'above') {
            return null;
        }

        $threshold = max(1.0, min(10.0, (float)($c['value'] ?? 0)));
        $since = gmdate('c', time() - (6 * 3600));
        $stmt = $this->db->prepare(
            'SELECT temperature_c, dew_point_c, pressure_hpa, humidity_percent, wind_speed_kmh, wind_direction_degrees, observed_at, created_at
             FROM gaugeiq_pressure_readings
             WHERE created_at >= ?
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute([$since]);
        $readings = $stmt->fetchAll();

        $score = $this->weatherChangeScore($readings);
        if ($score === null || $score < $threshold) {
            return null;
        }

        return sprintf('Weather change score reached %d/10.', $score);
    }

    private function weatherChangeScore(array $readings): ?int
    {
        $valid = [];
        foreach ($readings as $item) {
            $time = strtotime((string)($item['created_at'] ?? $item['observed_at'] ?? ''));
            if ($time === false) {
                continue;
            }
            $valid[] = [
                'temperature' => (float)$item['temperature_c'],
                'dewPoint' => (float)$item['dew_point_c'],
                'pressure' => (float)$item['pressure_hpa'],
                'humidity' => (float)$item['humidity_percent'],
                'wind' => (float)$item['wind_speed_kmh'],
                'direction' => (float)$item['wind_direction_degrees'],
                'time' => $time,
            ];
        }

        if (count($valid) < 3) {
            return null;
        }

        $first = $valid[0];
        $last = $valid[count($valid) - 1];
        $hours = max(0.5, ($last['time'] - $first['time']) / 3600);
        $score = 0;

        $pressureDelta = $last['pressure'] - $first['pressure'];
        $pressureRate = abs($pressureDelta) / $hours;
        if ($pressureRate >= 0.5) $score += $pressureRate >= 1.0 ? 2 : 1;
        if ($pressureRate >= 1.5) $score += 1;

        $humidityDelta = $last['humidity'] - $first['humidity'];
        $humidityChange = abs($humidityDelta);
        if ($humidityChange >= 4) $score += $humidityChange >= 8 ? 2 : 1;

        $temperatureDelta = $last['temperature'] - $first['temperature'];
        $temperatureChange = abs($temperatureDelta);
        if ($temperatureChange >= 1) $score += $temperatureChange >= 2 ? 2 : 1;

        $windDelta = $last['wind'] - $first['wind'];
        $windChange = abs($windDelta);
        if ($windChange >= 8) $score += $windChange >= 15 ? 2 : 1;

        $directionDifference = abs(fmod($first['direction'] - $last['direction'], 360.0));
        $directionDifference = min($directionDifference, 360.0 - $directionDifference);
        if ($directionDifference >= 30) $score += $directionDifference >= 60 ? 2 : 1;

        $firstSpread = $first['temperature'] - $first['dewPoint'];
        $lastSpread = $last['temperature'] - $last['dewPoint'];
        if (($lastSpread - $firstSpread) <= -1.5) {
            $score += 1;
        }

        return min(10, $score);
    }

    private function combinedWind(string $type, array $c, array $p, array $n): ?string
    {
        if ($type !== 'speed_and_direction') {
            return null;
        }

        $speed = (float)$n['wind_speed_kmh'];
        $oldSpeed = (float)$p['wind_speed_kmh'];
        $minSpeed = (float)($c['speed_min'] ?? 0);
        $from = (float)($c['direction_from'] ?? 0);
        $to = (float)($c['direction_to'] ?? 360);

        $speedMatch = $speed >= $minSpeed;
        $directionMatch = $this->inDirectionRange((float)$n['wind_direction_degrees'], $from, $to);
        $oldMatch = $oldSpeed >= $minSpeed &&
            $this->inDirectionRange((float)$p['wind_direction_degrees'], $from, $to);

        if ($speedMatch && $directionMatch && !$oldMatch) {
            return sprintf(
                'Wind is %.1f km/h from %s.',
                $speed,
                PressureService::directionLabel((float)$n['wind_direction_degrees'])
            );
        }

        return null;
    }

    private function inDirectionRange(float $value, float $from, float $to): bool
    {
        $value = fmod($value + 360.0, 360.0);
        $from = fmod($from + 360.0, 360.0);
        $to = fmod($to + 360.0, 360.0);

        return $from <= $to
            ? $value >= $from && $value <= $to
            : $value >= $from || $value <= $to;
    }

    private function directionDifference(float $a, float $b): float
    {
        $difference = abs(fmod($a - $b, 360.0));
        return min($difference, 360.0 - $difference);
    }

    private function validate(string $metric, string $type, array $configuration): void
    {
        $allowedMetrics = ['pressure', 'humidity', 'wind_speed', 'wind_direction', 'wind', 'weather_change'];
        $allowedTypes = ['change', 'above', 'below', 'specific', 'speed_and_direction'];

        if (!in_array($metric, $allowedMetrics, true) || !in_array($type, $allowedTypes, true)) {
            throw new InvalidArgumentException('Unsupported GaugeIQ alert rule.');
        }

        if ($configuration === []) {
            throw new InvalidArgumentException('Alert rule configuration cannot be empty.');
        }

        if ($metric === 'weather_change' && ($type !== 'above' || !isset($configuration['value']))) {
            throw new InvalidArgumentException('Weather change alerts require a score threshold.');
        }
    }
}
