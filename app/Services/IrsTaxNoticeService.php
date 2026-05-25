<?php

namespace App\Services;

use App\Support\ProfessionalServicesDisclaimer;
use RuntimeException;

class IrsTaxNoticeService
{
    private const NOTICE_EXCERPTS = [
        'CP2000' => 'IRS CP2000: Proposed changes to your tax return. The IRS has information that does not match what you reported. You have 60 days from the notice date to respond. Options: agree (sign and return), disagree (provide explanation), or request more time. Non-response results in an assessment.',
        'CP3219A' => 'IRS CP3219A: Statutory Notice of Deficiency (90-day letter). You have 90 days from the notice date (150 days if addressed outside the US) to file a petition with the US Tax Court. If you do not respond, the IRS will assess the proposed tax deficiency. This is a legal deadline — missing it forfeits your right to challenge the assessment in Tax Court without paying first.',
        '941'    => 'IRS Form 941: Employer\'s Quarterly Federal Tax Return. Delinquency or under-payment triggers a Trust Fund Recovery Penalty (TFRP) assessed personally against responsible officers. Deadlines are quarterly (April 30, July 31, October 31, January 31). Payroll tax failures attract compounding failure-to-deposit penalties (2%–15%).',
        '1099-K' => 'IRS 1099-K: Payment Card and Third-Party Network Transactions. Received by taxpayers who exceeded payment card or third-party network thresholds. May indicate unreported income; IRS may send CP2000 or other notices. Reconcile against business income records.',
        'CP501'  => 'IRS CP501: Balance due reminder. First notice that a balance is owed. Interest and penalties are accruing. Respond within 21 days to avoid escalation to CP503 and CP504.',
        'CP504'  => 'IRS CP504: Urgent — Intent to Levy. IRS intends to levy state tax refunds and may seize other assets. File Form 9465 (installment agreement) or pay in full to stop levy action. 30-day window from notice date.',
        'LT11'   => 'IRS LT11 (Letter 1058): Final Notice — Notice of Intent to Levy and Notice of Your Right to a Hearing. 30-day deadline to request a Collection Due Process (CDP) hearing. Missing this deadline loses your appeal rights.',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are a financial risk intelligence assistant analyzing IRS notices for a small business. Your role is to extract structured information from the notice text provided. You must always be factual, never speculate, and always append the professional services disclaimer.

You have knowledge of the following IRS notice types and their key characteristics:

%s

Analyze the provided notice text and return a JSON object with exactly these fields:
{
  "notice_type": string (e.g. "CP2000", "CP3219A", "941", "1099-K", "Unknown"),
  "deadline_days": integer or null (number of calendar days from notice date to respond; null if not determinable),
  "deadline_description": string (human-readable deadline, e.g. "60 days from notice date" or "90-day letter — Tax Court petition deadline"),
  "required_action": string (one clear sentence on what the taxpayer must do),
  "risk_level": string (one of: "critical", "high", "medium", "low"),
  "key_amount": number or null (dollar amount at issue, or null if not present),
  "summary": string (2-3 sentences summarizing the notice and its implications)
}

Risk level guidance:
- critical: statutory deadlines (90-day letter, levy notices) or payroll tax issues
- high: proposed tax changes, balance due with active penalties
- medium: informational mismatches, first-notice reminders
- low: informational returns with no immediate action required

Return only the JSON object with no additional text.
PROMPT;

    public function __construct(private readonly LlmService $llmService)
    {
    }

    /**
     * Parse and interpret an IRS notice from raw pasted text.
     *
     * @return array{
     *     notice_type: string,
     *     deadline_days: int|null,
     *     deadline_description: string,
     *     required_action: string,
     *     risk_level: string,
     *     key_amount: float|null,
     *     summary: string,
     *     disclaimer: string
     * }
     */
    public function interpretNotice(string $noticeText): array
    {
        $noticeText = trim($noticeText);
        if (strlen($noticeText) < 20) {
            throw new \InvalidArgumentException('Notice text is too short to interpret.');
        }

        $excerptBlock = collect(self::NOTICE_EXCERPTS)
            ->map(fn (string $text, string $key): string => "- {$key}: {$text}")
            ->implode("\n");

        $systemPrompt = sprintf(self::SYSTEM_PROMPT, $excerptBlock);

        try {
            $result = $this->llmService->completeJson(
                [['role' => 'user', 'content' => "Analyze this IRS notice:\n\n{$noticeText}"]],
                $systemPrompt,
                ['max_tokens' => 600]
            );
        } catch (RuntimeException $e) {
            throw new RuntimeException('Tax notice interpretation failed: ' . $e->getMessage(), 0, $e);
        }

        return [
            'notice_type'          => (string) ($result['notice_type'] ?? 'Unknown'),
            'deadline_days'        => isset($result['deadline_days']) && is_numeric($result['deadline_days'])
                                        ? (int) $result['deadline_days']
                                        : null,
            'deadline_description' => (string) ($result['deadline_description'] ?? 'See notice for deadline details.'),
            'required_action'      => (string) ($result['required_action'] ?? 'Review notice and consult a tax professional.'),
            'risk_level'           => $this->sanitizeRiskLevel((string) ($result['risk_level'] ?? 'medium')),
            'key_amount'           => isset($result['key_amount']) && is_numeric($result['key_amount'])
                                        ? (float) $result['key_amount']
                                        : null,
            'summary'              => (string) ($result['summary'] ?? ''),
            'disclaimer'           => ProfessionalServicesDisclaimer::TEXT,
        ];
    }

    private function sanitizeRiskLevel(string $level): string
    {
        return in_array($level, ['critical', 'high', 'medium', 'low'], true) ? $level : 'medium';
    }
}
