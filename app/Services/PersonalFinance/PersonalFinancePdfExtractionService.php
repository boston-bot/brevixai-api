<?php

namespace App\Services\PersonalFinance;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PersonalFinancePdfExtractionService
{
    public function statementDisk(): string
    {
        return (string) config('personal_finance.statement_disk', 'local');
    }

    public function statementDirectory(): string
    {
        return (string) config('personal_finance.statement_path', storage_path('personal'));
    }

    public function statementPrefix(): string
    {
        return trim((string) config('personal_finance.statement_prefix', ''), '/');
    }

    /**
     * @return array<int, string>
     */
    public function listStatementFiles(): array
    {
        if ($this->usesStorageDisk()) {
            $files = Storage::disk($this->statementDisk())->allFiles($this->statementPrefix());
            $files = array_filter($files, static fn (string $path): bool => str_ends_with(strtolower($path), '.pdf'));
            sort($files, SORT_NATURAL);

            return array_values($files);
        }

        $directory = $this->statementDirectory();
        if (! is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.pdf') ?: [];
        sort($files, SORT_NATURAL);

        return array_values($files);
    }

    public function filename(string $path): string
    {
        return basename($path);
    }

    public function sourceUri(string $path): string
    {
        if (! $this->usesStorageDisk()) {
            return $path;
        }

        $bucket = config("filesystems.disks.{$this->statementDisk()}.bucket");

        return $bucket ? "s3://{$bucket}/{$path}" : "{$this->statementDisk()}://{$path}";
    }

    public function sha256(string $path): string
    {
        if (! $this->usesStorageDisk()) {
            $realPath = $this->assertStatementPath($path);

            return hash_file('sha256', $realPath);
        }

        $stream = Storage::disk($this->statementDisk())->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read statement from {$this->sourceUri($path)}");
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    public function extractText(string $path): string
    {
        $realPath = $this->materializeStatementFile($path);
        $binary = (string) config('personal_finance.pdftotext_path', '/usr/local/bin/pdftotext');

        try {
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
        } finally {
            $this->cleanupMaterializedStatementFile($realPath);
        }
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

    private function usesStorageDisk(): bool
    {
        return $this->statementDisk() !== 'local';
    }

    private function materializeStatementFile(string $path): string
    {
        if (! $this->usesStorageDisk()) {
            return $this->assertStatementPath($path);
        }

        $stream = Storage::disk($this->statementDisk())->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read statement from {$this->sourceUri($path)}");
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'brevix-pf-statement-');
        if ($temporaryPath === false) {
            fclose($stream);
            throw new RuntimeException('Unable to create temporary statement file.');
        }

        $temporary = fopen($temporaryPath, 'wb');
        if (! is_resource($temporary)) {
            fclose($stream);
            unlink($temporaryPath);
            throw new RuntimeException('Unable to open temporary statement file.');
        }

        stream_copy_to_stream($stream, $temporary);
        fclose($stream);
        fclose($temporary);

        return $temporaryPath;
    }

    private function cleanupMaterializedStatementFile(string $path): void
    {
        if ($this->usesStorageDisk() && is_file($path)) {
            unlink($path);
        }
    }
}
