<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalFinanceBudgetProfile;
use App\Models\PersonalFinanceStatementImport;
use App\Models\PersonalFinanceTransaction;
use Illuminate\Support\Facades\DB;
use Throwable;

class PersonalFinanceImportService
{
    public function __construct(
        private readonly PersonalFinancePdfExtractionService $pdfExtractionService,
        private readonly ChaseStatementParser $parser,
        private readonly PersonalFinanceCategorizationService $categorizationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(string $companyId): array
    {
        $files = $this->pdfExtractionService->listStatementFiles();
        $lastImport = PersonalFinanceStatementImport::where('company_id', $companyId)
            ->orderByDesc('updated_at')
            ->first();

        return [
            'enabled' => (bool) config('personal_finance.enabled'),
            'localOnly' => true,
            'statementDirectory' => basename($this->pdfExtractionService->statementDirectory()),
            'pdfCount' => count($files),
            'importedStatementCount' => PersonalFinanceStatementImport::where('company_id', $companyId)->count(),
            'transactionCount' => PersonalFinanceTransaction::where('company_id', $companyId)->count(),
            'dateRange' => [
                'from' => PersonalFinanceTransaction::where('company_id', $companyId)->min('posted_date'),
                'to' => PersonalFinanceTransaction::where('company_id', $companyId)->max('posted_date'),
            ],
            'lastImport' => $lastImport ? [
                'id' => $lastImport->id,
                'filename' => $lastImport->source_filename,
                'status' => $lastImport->status,
                'transactionCount' => $lastImport->transaction_count,
                'updatedAt' => $lastImport->updated_at?->toIso8601String(),
                'warnings' => $lastImport->warnings ?? [],
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $companyId, string $userId, bool $force = false, bool $reclassify = false): array
    {
        $this->categorizationService->ensureDefaultRules($companyId);
        $this->ensureBudgetProfile($companyId);

        if ($reclassify) {
            return $this->reclassify($companyId);
        }

        $results = [
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'transactionCount' => 0,
            'statements' => [],
        ];

        foreach ($this->pdfExtractionService->listStatementFiles() as $path) {
            $results['processed']++;
            $filename = basename($path);
            $sha256 = hash_file('sha256', $path);

            $existing = PersonalFinanceStatementImport::where('company_id', $companyId)
                ->where('sha256', $sha256)
                ->first();

            if ($existing && ! $force) {
                $results['skipped']++;
                $results['statements'][] = [
                    'filename' => $filename,
                    'status' => 'skipped',
                    'transactionCount' => $existing->transaction_count,
                ];

                continue;
            }

            try {
                $text = $this->pdfExtractionService->extractText($path);
                $parsed = $this->parser->parse($text, $filename);
                $statement = $this->persistParsedStatement($companyId, $userId, $path, $sha256, $parsed, $existing);

                $results['imported']++;
                $results['transactionCount'] += $statement->transaction_count;
                $results['statements'][] = [
                    'id' => $statement->id,
                    'filename' => $filename,
                    'status' => $statement->status,
                    'transactionCount' => $statement->transaction_count,
                    'warnings' => $statement->warnings ?? [],
                ];
            } catch (Throwable $e) {
                $results['failed']++;
                $statement = $existing ?: new PersonalFinanceStatementImport([
                    'company_id' => $companyId,
                    'imported_by_user_id' => $userId,
                    'source_filename' => $filename,
                    'source_path' => $path,
                    'sha256' => $sha256,
                ]);

                $statement->fill([
                    'status' => 'failed',
                    'warnings' => ['Import failed: '.$e->getMessage()],
                    'transaction_count' => 0,
                ]);
                $statement->save();

                $results['statements'][] = [
                    'id' => $statement->id,
                    'filename' => $filename,
                    'status' => 'failed',
                    'transactionCount' => 0,
                    'warnings' => $statement->warnings,
                ];
            }
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function persistParsedStatement(
        string $companyId,
        string $userId,
        string $path,
        string $sha256,
        array $parsed,
        ?PersonalFinanceStatementImport $existing,
    ): PersonalFinanceStatementImport {
        return DB::transaction(function () use ($companyId, $userId, $path, $sha256, $parsed, $existing): PersonalFinanceStatementImport {
            $statement = $existing ?: new PersonalFinanceStatementImport;

            if ($statement->exists) {
                $statement->transactions()->delete();
            }

            $statement->fill([
                'company_id' => $companyId,
                'imported_by_user_id' => $userId,
                'source_filename' => basename($path),
                'source_path' => $path,
                'sha256' => $sha256,
                'statement_date' => $parsed['statement_date'],
                'period_start' => $parsed['period_start'],
                'period_end' => $parsed['period_end'],
                'account_last4' => $parsed['account_last4'],
                'status' => 'imported',
                'transaction_count' => count($parsed['transactions']),
                'warnings' => $parsed['warnings'],
                'metadata' => $parsed['metadata'],
            ]);
            $statement->save();

            foreach ($parsed['transactions'] as $row) {
                $classification = $this->categorizationService->categorizeParsedTransaction($companyId, $row);

                PersonalFinanceTransaction::create(array_merge($classification, [
                    'company_id' => $companyId,
                    'statement_import_id' => $statement->id,
                    'posted_date' => $row['posted_date'],
                    'description' => $row['description'],
                    'amount' => $row['amount'],
                    'direction' => $row['direction'],
                    'source_section' => $row['source_section'] ?? null,
                    'raw_payload' => [
                        'source_row' => $row['raw_row'] ?? null,
                    ],
                ]));
            }

            return $statement->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function reclassify(string $companyId): array
    {
        $count = 0;
        PersonalFinanceTransaction::where('company_id', $companyId)
            ->orderBy('posted_date')
            ->chunkById(200, function ($transactions) use (&$count): void {
                foreach ($transactions as $transaction) {
                    $this->categorizationService->reclassifyTransaction($transaction);
                    $count++;
                }
            });

        return [
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'transactionCount' => $count,
            'reclassified' => $count,
            'statements' => [],
        ];
    }

    private function ensureBudgetProfile(string $companyId): PersonalFinanceBudgetProfile
    {
        return PersonalFinanceBudgetProfile::firstOrCreate(
            ['company_id' => $companyId],
            [
                'name' => 'Default',
                'person_a_label' => 'Person A',
                'person_b_label' => 'Person B',
                'person_a_monthly_allowance' => 0,
                'person_b_monthly_allowance' => 0,
                'shared_monthly_cap' => null,
                'opaque_card_payment_cap' => null,
                'catch_up_target_amount' => null,
                'category_caps' => [],
                'metadata' => [],
            ],
        );
    }
}
