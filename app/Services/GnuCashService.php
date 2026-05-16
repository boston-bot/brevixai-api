<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SQLite3;

class GnuCashService
{
    public function getStatus(string $companyId): array
    {
        $latestImport = DB::table('gnucash_imports')
            ->where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->first();

        return [
            'hasData' => !is_null($latestImport),
            'latestImport' => $latestImport
        ];
    }

    public function purgeData(string $companyId): void
    {
        DB::transaction(function () use ($companyId) {
            DB::table('gnucash_transactions')->where('company_id', $companyId)->delete();
            DB::table('gnucash_accounts')->where('company_id', $companyId)->delete();
            DB::table('gnucash_imports')->where('company_id', $companyId)->delete();
        });
    }

    public function importFile(string $companyId, $file): array
    {
        $path = $file->getRealPath();
        $filename = $file->getClientOriginalName();
        
        // Detect format
        $isSqlite = $this->isSqlite($path);
        $format = $isSqlite ? 'sqlite' : 'csv';

        // 1. Create Import Record
        $importId = (string) Str::uuid();
        DB::table('gnucash_imports')->insert([
            'id' => $importId,
            'company_id' => $companyId,
            'filename' => $filename,
            'file_format' => $format,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        try {
            if ($isSqlite) {
                $result = $this->parseSqlite($companyId, $importId, $path);
            } else {
                $result = $this->parseCsv($companyId, $importId, $path);
            }

            // 2. Clear old data (Full Replace strategy)
            DB::transaction(function () use ($companyId, $importId, $result) {
                // Delete previous imports and their data (cascade will handle transactions/accounts)
                DB::table('gnucash_imports')
                    ->where('company_id', $companyId)
                    ->where('id', '!=', $importId)
                    ->delete();

                // Insert Accounts
                foreach ($result['accounts'] as $acc) {
                    DB::table('gnucash_accounts')->insert(array_merge($acc, [
                        'company_id' => $companyId,
                        'import_id' => $importId,
                        'created_at' => now(),
                    ]));
                }

                // Insert Transactions
                foreach (array_chunk($result['transactions'], 100) as $chunk) {
                    DB::table('gnucash_transactions')->insert(array_map(fn($tx) => array_merge($tx, [
                        'company_id' => $companyId,
                        'import_id' => $importId,
                        'synced_at' => now(),
                    ]), $chunk));
                }

                // Update Import Status
                DB::table('gnucash_imports')->where('id', $importId)->update([
                    'status' => 'completed',
                    'transaction_count' => count($result['transactions']),
                    'account_count' => count($result['accounts']),
                    'date_range_start' => $result['date_range_start'],
                    'date_range_end' => $result['date_range_end'],
                ]);
            });

            return [
                'success' => true,
                'transactionCount' => count($result['transactions']),
                'format' => $format
            ];

        } catch (\Exception $e) {
            DB::table('gnucash_imports')->where('id', $importId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function isSqlite(string $path): bool
    {
        $handle = fopen($path, 'rb');
        $header = fread($handle, 16);
        fclose($handle);
        return str_starts_with($header, "SQLite format 3");
    }

    private function parseSqlite(string $companyId, string $importId, string $path): array
    {
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        
        // 1. Fetch Accounts
        $accounts = [];
        $res = $db->query("SELECT guid, name, account_type FROM accounts");
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $accounts[$row['guid']] = [
                'id' => (string) Str::uuid(),
                'name' => $row['name'],
                'full_name' => $row['name'], // Simplification: in real GnuCash this is hierarchical
                'account_type' => $row['account_type'],
            ];
        }

        // 2. Fetch Transactions
        $transactions = [];
        $dateStart = null;
        $dateEnd = null;

        $sql = "
            SELECT 
                t.guid as tx_guid,
                t.post_date,
                t.description,
                s.guid as split_guid,
                s.memo,
                s.value_num,
                s.value_denom,
                s.account_guid
            FROM transactions t
            JOIN splits s ON t.guid = s.tx_guid
        ";
        
        $res = $db->query($sql);
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $amount = $row['value_num'] / ($row['value_denom'] ?: 1);
            $date = date('Y-m-d', strtotime($row['post_date']));

            if (!$dateStart || $date < $dateStart) $dateStart = $date;
            if (!$dateEnd || $date > $dateEnd) $dateEnd = $date;

            $transactions[] = [
                'id' => (string) Str::uuid(),
                'gnucash_guid' => $row['split_guid'],
                'transaction_date' => $date,
                'vendor_name' => $row['description'],
                'amount' => $amount,
                'type' => $amount < 0 ? 'expense' : 'income',
                'account_type' => $accounts[$row['account_guid']]['account_type'] ?? 'unknown',
                'memo' => $row['memo'],
                'account_id' => $accounts[$row['account_guid']]['id'] ?? null,
            ];
        }

        $db->close();

        return [
            'accounts' => array_values($accounts),
            'transactions' => $transactions,
            'date_range_start' => $dateStart,
            'date_range_end' => $dateEnd,
        ];
    }

    private function parseCsv(string $companyId, string $importId, string $path): array
    {
        // Simple CSV parser for GnuCash exports
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        
        $transactions = [];
        $accounts = []; // CSV usually doesn't provide a full COA, just the current account
        
        $dateStart = null;
        $dateEnd = null;

        while (($row = fgetcsv($handle)) !== FALSE) {
            // Mapping depends on GnuCash CSV export settings, but typically:
            // 0: Date, 2: Description, 3: Notes, 4: Memo, 6: Account Name, 8: Amount
            if (count($row) < 9) continue;

            $date = date('Y-m-d', strtotime($row[0]));
            $amount = floatval(str_replace(',', '', $row[8]));
            
            if (!$dateStart || $date < $dateStart) $dateStart = $date;
            if (!$dateEnd || $date > $dateEnd) $dateEnd = $date;

            $transactions[] = [
                'id' => (string) Str::uuid(),
                'transaction_date' => $date,
                'vendor_name' => $row[2],
                'amount' => $amount,
                'type' => $amount < 0 ? 'expense' : 'income',
                'account_type' => 'unknown',
                'memo' => $row[4] ?: $row[3],
                'account_id' => null, // We don't have a GUID for CSV splits
            ];
        }
        fclose($handle);

        return [
            'accounts' => [], // CSV import usually doesn't populate accounts table well
            'transactions' => $transactions,
            'date_range_start' => $dateStart,
            'date_range_end' => $dateEnd,
        ];
    }
}
