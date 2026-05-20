<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalFinanceAnalysisRun;
use App\Models\PersonalFinanceExport;
use App\Models\PersonalFinanceTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;

class PersonalFinanceExportService
{
    public function __construct(
        private readonly PersonalFinanceAnalyticsService $analyticsService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{bytes: string, filename: string, contentType: string, export: PersonalFinanceExport}
     */
    public function generate(string $companyId, string $userId, string $format, array $filters = [], bool $includeTransactions = false): array
    {
        $summary = $this->analyticsService->summary($companyId, $filters);
        $transactions = $includeTransactions
            ? $this->analyticsService->transactionQuery($companyId, $filters)->orderBy('posted_date')->get()
            : collect();

        $analysisRun = PersonalFinanceAnalysisRun::create([
            'company_id' => $companyId,
            'from_date' => $filters['from'] ?? null,
            'to_date' => $filters['to'] ?? null,
            'summary' => $summary,
            'warnings' => $summary['warnings'] ?? [],
            'generated_at' => now(),
        ]);

        $payload = [
            'summary' => $summary,
            'transactions' => $transactions,
            'includeTransactions' => $includeTransactions,
        ];

        if ($format === PersonalFinanceExport::FORMAT_PDF) {
            $bytes = Pdf::loadView('reports.personal-finance-summary-pdf', $payload)->output();
            $contentType = 'application/pdf';
        } elseif ($format === PersonalFinanceExport::FORMAT_DOCX) {
            $bytes = $this->generateDocx($summary, $transactions->all(), $includeTransactions);
            $contentType = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        } else {
            throw new RuntimeException('Unsupported export format.');
        }

        $filename = 'personal-finance-summary-'.now()->format('Y-m-d').'.'.$format;
        $reportHash = hash('sha256', $bytes);

        $export = PersonalFinanceExport::create([
            'company_id' => $companyId,
            'analysis_run_id' => $analysisRun->id,
            'generated_by_user_id' => $userId,
            'format' => $format,
            'filename' => $filename,
            'report_hash' => $reportHash,
            'filters' => array_merge($filters, ['includeTransactions' => $includeTransactions]),
            'generated_at' => now(),
        ]);

        return [
            'bytes' => $bytes,
            'filename' => $filename,
            'contentType' => $contentType,
            'export' => $export,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     * @param  array<int, PersonalFinanceTransaction>  $transactions
     */
    private function generateDocx(array $summary, array $transactions, bool $includeTransactions): string
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);

        $section = $phpWord->addSection();
        $section->addTitle('Personal Finance Summary', 1);
        $section->addText('Generated: '.$summary['generatedAt']);
        $section->addText('This is cash-flow analysis for the Chase account only, not a complete household accounting report.');

        $section->addTitle('Totals', 2);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);
        foreach ($summary['totals'] as $label => $value) {
            $table->addRow();
            $table->addCell(3500)->addText($this->label($label));
            $table->addCell(2500)->addText('$'.number_format((float) $value, 2));
        }

        $section->addTitle('Top Spending Categories', 2);
        $this->addSimpleRows($section, ['Category', 'Amount', 'Count'], array_map(fn (array $row): array => [
            $row['category'],
            '$'.number_format((float) $row['amount'], 2),
            (string) $row['count'],
        ], $summary['topCategories']));

        $section->addTitle('Recommendations', 2);
        foreach ($summary['recommendations'] as $recommendation) {
            $section->addListItem($recommendation);
        }

        if (! empty($summary['warnings'])) {
            $section->addTitle('Warnings', 2);
            foreach ($summary['warnings'] as $warning) {
                $section->addListItem($warning);
            }
        }

        if ($includeTransactions) {
            $section->addTitle('Transactions', 2);
            $rows = array_map(fn (PersonalFinanceTransaction $transaction): array => [
                $transaction->posted_date->toDateString(),
                $transaction->description,
                $transaction->category,
                $transaction->person_scope,
                '$'.number_format((float) $transaction->amount, 2),
            ], $transactions);
            $this->addSimpleRows($section, ['Date', 'Description', 'Category', 'Person', 'Amount'], $rows);
        }

        $directory = (string) config('personal_finance.export_path');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create personal finance export directory.');
        }

        $path = $directory.DIRECTORY_SEPARATOR.'personal-finance-'.uniqid('', true).'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        $bytes = file_get_contents($path);
        @unlink($path);

        if ($bytes === false) {
            throw new RuntimeException('Unable to read generated DOCX export.');
        }

        return $bytes;
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function addSimpleRows(mixed $section, array $headers, array $rows): void
    {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80]);
        $table->addRow();
        foreach ($headers as $header) {
            $table->addCell(2500)->addText($header, ['bold' => true]);
        }

        foreach ($rows as $row) {
            $table->addRow();
            foreach ($row as $cell) {
                $table->addCell(2500)->addText($cell);
            }
        }
    }

    private function label(string $value): string
    {
        return ucwords(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?? $value));
    }
}
