<?php
declare(strict_types=1);

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

final class PushService
{
    public function __construct(private array $config, private PDO $db)
    {
    }

    public function send(string $title, string $body): int
    {
        $publicKey = trim((string)$this->config['push']['public_key']);
        $privateKey = trim((string)$this->config['push']['private_key']);
        $subject = trim((string)$this->config['push']['subject']);

        if ($publicKey === '' || $privateKey === '' || $subject === '') {
            throw new RuntimeException('VAPID credentials are not configured.');
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => rtrim((string)$this->config['app']['base_url'], '/') . '/',
        ], JSON_THROW_ON_ERROR);

        $subscriptions = $this->db->query(
            'SELECT id, subscription_json FROM push_subscriptions ORDER BY id ASC'
        )->fetchAll();

        $sent = 0;

        foreach ($subscriptions as $row) {
            $subscription = Subscription::create(
                json_decode((string)$row['subscription_json'], true, 512, JSON_THROW_ON_ERROR)
            );

            $report = $webPush->sendOneNotification($subscription, $payload);

            if ($report->isSuccess()) {
                $sent++;
                continue;
            }

            $status = $report->getResponse()?->getStatusCode();
            if ($status === 404 || $status === 410) {
                $delete = $this->db->prepare('DELETE FROM push_subscriptions WHERE id = ?');
                $delete->execute([(int)$row['id']]);
            }
        }

        return $sent;
    }
}
