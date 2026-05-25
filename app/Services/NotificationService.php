<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /**
     * Fan-out an alert-created notification to all enabled channels for the company.
     */
    public function notifyOnAlertCreated(Alert $alert, Company $company): void
    {
        $configs = DB::table('notification_configs')
            ->where('company_id', $company->id)
            ->where('enabled', true)
            ->whereRaw("events @> ?", [json_encode(['alert_created'])])
            ->get();

        foreach ($configs as $config) {
            $channelConfig = json_decode((string) $config->config, true) ?? [];

            try {
                match ((string) $config->channel) {
                    'slack' => $this->sendSlackNotification(
                        (string) ($channelConfig['webhook_url'] ?? ''),
                        $this->alertSlackMessage($alert, $company),
                        ['alert_id' => $alert->id, 'severity' => $alert->severity],
                    ),
                    'email' => $this->sendEmailNotification(
                        (string) ($channelConfig['email'] ?? ''),
                        "New Alert: {$alert->title}",
                        ['alert' => $alert, 'company' => $company],
                    ),
                    default => null,
                };
            } catch (Throwable $e) {
                Log::warning('notification.dispatch_failed', [
                    'channel'    => $config->channel,
                    'company_id' => $company->id,
                    'alert_id'   => $alert->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * POST a message to a Slack incoming webhook.
     */
    public function sendSlackNotification(string $webhookUrl, string $message, array $context = []): void
    {
        if (! $webhookUrl) {
            return;
        }

        $response = Http::timeout(10)->post($webhookUrl, [
            'text' => $message,
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => $message],
                ],
            ],
        ]);

        if (! $response->successful()) {
            Log::warning('notification.slack_failed', [
                'status' => $response->status(),
                ...$context,
            ]);
        }
    }

    /**
     * Send an email notification using Laravel's mail system.
     */
    public function sendEmailNotification(string $email, string $subject, array $data = []): void
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        // Use Laravel mail with a simple text mailable — no Mailable class required
        \Illuminate\Support\Facades\Mail::raw(
            $this->plainTextEmailBody($subject, $data),
            function ($message) use ($email, $subject): void {
                $message->to($email)->subject($subject);
            }
        );
    }

    private function alertSlackMessage(Alert $alert, Company $company): string
    {
        $emoji = match ($alert->severity) {
            'critical' => ':red_circle:',
            'high'     => ':large_orange_circle:',
            'medium'   => ':large_yellow_circle:',
            default    => ':white_circle:',
        };

        return "{$emoji} *[{$alert->severity}] {$alert->title}*\n"
            . "Company: {$company->name}\n"
            . ($alert->detail ? "Detail: {$alert->detail}" : '');
    }

    private function plainTextEmailBody(string $subject, array $data): string
    {
        $alert = $data['alert'] ?? null;
        $company = $data['company'] ?? null;

        if ($alert && $company) {
            return implode("\n", [
                $subject,
                '',
                "Company: {$company->name}",
                "Severity: {$alert->severity}",
                $alert->detail ? "Detail: {$alert->detail}" : '',
                '',
                'Review this alert in the Brevix AI dashboard.',
            ]);
        }

        return $subject;
    }
}
