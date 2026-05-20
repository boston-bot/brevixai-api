<?php

namespace App\Services\PersonalFinance;

use Carbon\CarbonImmutable;

class ChaseStatementParser
{
    private const INFLOW_SECTIONS = [
        'deposit',
        'addition',
        'credit',
        'electronic deposits',
    ];

    private const OUTFLOW_SECTIONS = [
        'withdrawal',
        'debit',
        'checks paid',
        'fees',
        'service fees',
        'electronic withdrawals',
        'atm',
    ];

    /**
     * @return array{
     *     statement_date: string|null,
     *     period_start: string|null,
     *     period_end: string|null,
     *     account_last4: string|null,
     *     transactions: array<int, array<string, mixed>>,
     *     warnings: array<int, string>,
     *     metadata: array<string, mixed>
     * }
     */
    public function parse(string $text, string $filename): array
    {
        $warnings = [];
        $period = $this->parsePeriod($text, $filename);
        $periodEnd = $period['period_end'] ? CarbonImmutable::parse($period['period_end']) : null;
        $accountLast4 = $this->parseAccountLast4($text, $filename);

        $transactions = [];
        $currentSection = 'unknown';
        $currentBlock = null;
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $line) {
            $normalized = $this->normalizeLine($line);
            if ($normalized === '') {
                continue;
            }

            if ($this->isSectionHeading($normalized)) {
                if ($currentBlock !== null) {
                    $this->flushBlock($transactions, $warnings, $currentBlock, $periodEnd);
                    $currentBlock = null;
                }

                $currentSection = $normalized;

                continue;
            }

            if ($this->startsWithDate($normalized)) {
                if ($currentBlock !== null) {
                    $this->flushBlock($transactions, $warnings, $currentBlock, $periodEnd);
                }

                $currentBlock = [
                    'section' => $currentSection,
                    'lines' => [$normalized],
                ];

                continue;
            }

            if ($currentBlock !== null && ! $this->looksLikeTableHeader($normalized)) {
                $currentBlock['lines'][] = $normalized;
            }
        }

        if ($currentBlock !== null) {
            $this->flushBlock($transactions, $warnings, $currentBlock, $periodEnd);
        }

        if ($transactions === []) {
            $warnings[] = 'No transaction rows were parsed from the statement text.';
        }

        return [
            'statement_date' => $period['statement_date'],
            'period_start' => $period['period_start'],
            'period_end' => $period['period_end'],
            'account_last4' => $accountLast4,
            'transactions' => $transactions,
            'warnings' => array_values(array_unique($warnings)),
            'metadata' => [
                'parser' => 'chase_statement_v1',
                'source_filename' => $filename,
            ],
        ];
    }

    /**
     * @param  array{section: string, lines: array<int, string>}  $block
     * @param  array<int, array<string, mixed>>  $transactions
     * @param  array<int, string>  $warnings
     */
    private function flushBlock(array &$transactions, array &$warnings, array $block, ?CarbonImmutable $periodEnd): void
    {
        $row = $this->parseTransactionBlock($block, $periodEnd);
        if ($row === null) {
            $warnings[] = 'Skipped an unrecognized transaction row in section '.$block['section'].'.';

            return;
        }

        $transactions[] = $row;
    }

    /**
     * @param  array{section: string, lines: array<int, string>}  $block
     * @return array<string, mixed>|null
     */
    private function parseTransactionBlock(array $block, ?CarbonImmutable $periodEnd): ?array
    {
        $text = trim(implode(' ', $block['lines']));
        if (! preg_match('/^(\d{1,2}\/\d{1,2}(?:\/\d{2,4})?)\s+(.*)$/', $text, $dateMatch)) {
            return null;
        }

        $postedDate = $this->parsePostedDate($dateMatch[1], $periodEnd);
        $remainder = trim($dateMatch[2]);

        if (! preg_match_all('/(?:-?\$?\d{1,3}(?:,\d{3})*|\$?\d+)\.\d{2}|\(\$?(?:\d{1,3}(?:,\d{3})*|\d+)\.\d{2}\)/', $remainder, $moneyMatches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $amountIndex = count($moneyMatches[0]) >= 2 ? count($moneyMatches[0]) - 2 : count($moneyMatches[0]) - 1;
        [$amountToken, $amountOffset] = $moneyMatches[0][$amountIndex];
        $description = trim(substr($remainder, 0, $amountOffset));

        if ($description === '' || $this->looksLikeTableHeader($description)) {
            return null;
        }

        $rawAmount = $this->parseMoney($amountToken);
        $direction = $this->directionFor($rawAmount, $block['section'], $description);
        $signedAmount = $direction === 'outflow' ? -abs($rawAmount) : abs($rawAmount);

        return [
            'posted_date' => $postedDate,
            'description' => $this->cleanDescription($description),
            'amount' => round($signedAmount, 2),
            'direction' => $direction,
            'source_section' => $block['section'],
            'raw_row' => $text,
        ];
    }

    /**
     * @return array{statement_date: string|null, period_start: string|null, period_end: string|null}
     */
    private function parsePeriod(string $text, string $filename): array
    {
        if (preg_match('/([A-Z][a-z]+)\s+(\d{1,2}),\s+(\d{4})\s+(?:through|to|-)\s+([A-Z][a-z]+)\s+(\d{1,2}),\s+(\d{4})/', $text, $match)) {
            $start = CarbonImmutable::parse("{$match[1]} {$match[2]}, {$match[3]}");
            $end = CarbonImmutable::parse("{$match[4]} {$match[5]}, {$match[6]}");

            return [
                'statement_date' => $end->toDateString(),
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
            ];
        }

        if (preg_match('/(\d{4})(\d{2})(\d{2})/', $filename, $match)) {
            $end = CarbonImmutable::createSafe((int) $match[1], (int) $match[2], (int) $match[3]);
            if ($end !== null) {
                return [
                    'statement_date' => $end->toDateString(),
                    'period_start' => $end->startOfMonth()->toDateString(),
                    'period_end' => $end->toDateString(),
                ];
            }
        }

        return [
            'statement_date' => null,
            'period_start' => null,
            'period_end' => null,
        ];
    }

    private function parseAccountLast4(string $text, string $filename): ?string
    {
        if (preg_match('/Account(?:\s+Number)?\D+(\d{4})\b/i', $text, $match)) {
            return $match[1];
        }

        if (preg_match('/statements-(\d{4})/i', $filename, $match)) {
            return $match[1];
        }

        return null;
    }

    private function parsePostedDate(string $rawDate, ?CarbonImmutable $periodEnd): string
    {
        $parts = explode('/', $rawDate);
        $month = (int) $parts[0];
        $day = (int) $parts[1];

        if (isset($parts[2])) {
            $year = (int) $parts[2];
            $year = $year < 100 ? 2000 + $year : $year;
        } else {
            $year = $periodEnd?->year ?? (int) now()->year;
            if ($periodEnd && $month > $periodEnd->month && $periodEnd->month <= 2) {
                $year--;
            }
        }

        return CarbonImmutable::createSafe($year, $month, $day)?->toDateString()
            ?? CarbonImmutable::create($year, $month, 1)->toDateString();
    }

    private function directionFor(float $amount, string $section, string $description): string
    {
        if ($amount < 0) {
            return 'outflow';
        }

        $sectionLower = strtolower($section);

        foreach (self::OUTFLOW_SECTIONS as $needle) {
            if (str_contains($sectionLower, $needle)) {
                return 'outflow';
            }
        }

        foreach (self::INFLOW_SECTIONS as $needle) {
            if (str_contains($sectionLower, $needle)) {
                return 'inflow';
            }
        }

        return preg_match('/payroll|direct dep|deposit|ach credit/i', $description) ? 'inflow' : 'outflow';
    }

    private function parseMoney(string $value): float
    {
        $negative = str_starts_with($value, '-') || str_contains($value, '(');
        $number = (float) str_replace(['$', ',', '-', '(', ')'], '', $value);

        return $negative ? -$number : $number;
    }

    private function cleanDescription(string $description): string
    {
        return trim(preg_replace('/\s+/', ' ', $description) ?? $description);
    }

    private function normalizeLine(string $line): string
    {
        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }

    private function startsWithDate(string $line): bool
    {
        return (bool) preg_match('/^\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\s+/', $line);
    }

    private function isSectionHeading(string $line): bool
    {
        $lower = strtolower($line);

        if (strlen($line) > 90 || $this->startsWithDate($line)) {
            return false;
        }

        return str_contains($lower, 'deposits and additions')
            || str_contains($lower, 'electronic deposits')
            || str_contains($lower, 'electronic withdrawals')
            || str_contains($lower, 'atm & debit card withdrawals')
            || str_contains($lower, 'checks paid')
            || str_contains($lower, 'fees')
            || str_contains($lower, 'withdrawals')
            || str_contains($lower, 'deposits');
    }

    private function looksLikeTableHeader(string $line): bool
    {
        return (bool) preg_match('/\b(date|description|amount|balance|transaction detail)\b/i', $line);
    }
}
