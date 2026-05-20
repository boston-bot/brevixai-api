<?php

namespace App\Services\PersonalFinance;

use RuntimeException;

class PersonalFinancePdfExtractionService
{
    public function statementDirectory(): string
    {
        return (string) config('personal_finance.statement_path', storage_path('personal'));
    }

    /**
     * @return array<int, string>
     */
    public function listStatementFiles(): array
    {
        $directory = $this->statementDirectory();
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.pdf') ?: [];
        sort($files, SORT_NATURAL);

        return array_values($files);
    }

    public function extractText(string $path): string
    {
        $realPath = $this->assertStatementPath($path);
        $binary = (string) config('personal_finance.pdftotext_path', '/usr/local/bin/pdftotext');

        if (! is_file($binary) || ! is_executable($binary)) {
            throw new RuntimeException("pdftotext binary is not executable at {$binary}");
        }

        $command = sprintf(
            '%s -layout -nopgbrk %s -',
            escapeshellarg($binary),
            escapeshellarg($realPath),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start pdftotext process.');
        }

        fclose($pipes[0]);
        $text = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('pdftotext failed: '.trim((string) $error));
        }

        return mb_convert_encoding((string) $text, 'UTF-8', 'UTF-8');
    }

    public function assertStatementPath(string $path): string
    {
        $base = realpath($this->statementDirectory());
        $realPath = realpath($path);

        if ($base === false || $realPath === false) {
            throw new RuntimeException('Statement path does not exist.');
        }

        $base = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (! str_starts_with($realPath, $base)) {
            throw new RuntimeException('Statement path is outside the configured personal finance directory.');
        }

        return $realPath;
    }
}
