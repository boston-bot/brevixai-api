<?php

namespace App\Services\FraudTesting;

use App\Models\Company;
use App\Models\FraudTesting\FraudScenarioSubmission;
use App\Models\Subscription;
use App\Models\Upload;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\BusinessProfileContextService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FraudScenarioProvisionService
{
    public function __construct(
        private readonly BusinessProfileContextService $profileContext,
    ) {}

    /**
     * Provision a test workspace from a fraud scenario's mock data.
     * Creates a fresh Company, links or creates a User, and seeds all mock transactions.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException if the scenario is missing mock data
     */
    public function provision(
        FraudScenarioSubmission $submission,
        ?string $email = null,
        ?string $password = null,
        ?User $workspaceMember = null,
        string $workspaceMemberRole = 'admin',
    ): array
    {
        if (! in_array($workspaceMemberRole, ['owner', 'admin'], true)) {
            throw new \RuntimeException('Workspace member role must be owner or admin.');
        }

        $mockCompany = $submission->mockCompany;
        if (! $mockCompany) {
            throw new \RuntimeException('Scenario has no mock company data.');
        }

        $mockTransactions = $submission->mockTransactions()->get();
        $partyNames = $submission->mockParties()->pluck('party_name', 'id');

        return DB::transaction(function () use ($submission, $mockCompany, $mockTransactions, $partyNames, $email, $password, $workspaceMember, $workspaceMemberRole): array {
            // forceFill bypasses $fillable so we can set the UUID id ourselves,
            // which is needed in environments where gen_random_uuid() isn't available.

            $companyId = (string) Str::uuid();
            $company = tap(new Company)->forceFill([
                'id' => $companyId,
                'name' => $mockCompany->company_name,
                'industry' => $mockCompany->industry,
                'entity_type' => $mockCompany->entity_type,
                'has_completed_onboarding' => true,
            ])->save();
            $company = Company::find($companyId);

            // Subscription — risk-advisory so all features are unlocked
            (new Subscription)->forceFill([
                'company_id' => $companyId,
                'tier' => 'risk-advisory',
                'status' => 'active',
            ])->save();

            $createdThrowawayUser = $workspaceMember === null;

            if ($createdThrowawayUser) {
                $password = $password ?? Str::random(16);
                $email = $email ?? ('fraud-test-' . Str::lower(Str::random(8)) . '@brevix-test.internal');
                $userId = (string) Str::uuid();

                tap(new User)->forceFill([
                    'id' => $userId,
                    'email' => $email,
                    'password_hash' => Hash::make($password),
                    'first_name' => 'Test',
                    'last_name' => 'User',
                    'company_id' => $companyId,
                    'role' => 'owner',
                    'is_verified' => true,
                ])->save();
                $user = User::find($userId);
            } else {
                $user = $workspaceMember;
            }

            // Business profile + workspace membership
            $uploadingUserId = (string) $user->id;
            $profile = $this->profileContext->createDefaultProfileForWorkspace($company, $user);
            $businessProfileId = $profile?->id ? (string) $profile->id : null;
            $businessProfileName = $profile?->name ? (string) $profile->name : null;

            if (! $createdThrowawayUser) {
                WorkspaceMembership::updateOrCreate(
                    ['company_id' => $companyId, 'user_id' => $user->id],
                    [
                        'role' => $workspaceMemberRole,
                        'scope' => 'workspace',
                        'granted_by' => $user->id,
                    ],
                );
            }

            // Synthetic upload record to satisfy the transactions foreign key
            $uploadId = (string) Str::uuid();
            tap(new Upload)->forceFill([
                'id' => $uploadId,
                'company_id' => $companyId,
                'business_profile_id' => $businessProfileId,
                'uploaded_by' => $uploadingUserId,
                'filename' => 'fraud-scenario-mock-data.csv',
                'original_filename' => $submission->title . ' (mock data).csv',
                'status' => 'promoted',
                'import_type' => 'transaction_ledger',
                'row_count' => $mockTransactions->count(),
                'uploaded_at' => now(),
                'promoted_at' => now(),
            ])->save();

            // Seed transactions using raw DB insert for performance and to avoid
            // fillable restrictions on the id column (DB default is gen_random_uuid()).
            $rows = $mockTransactions->map(fn ($mt) => [
                'id' => (string) Str::uuid(),
                'upload_id' => $uploadId,
                'company_id' => $companyId,
                'business_profile_id' => $businessProfileId,
                'txn_id' => $mt->external_transaction_id,
                'date' => $mt->transaction_date?->toDateString(),
                'vendor_customer' => $mt->party_id ? ($partyNames[$mt->party_id] ?? null) : null,
                'type' => $mt->transaction_type,
                'category' => $mt->account_category,
                'memo' => $mt->description,
                'amount' => $mt->amount,
                'anomaly_flag' => (int) $mt->is_fraudulent,
                'anomaly_reason' => $mt->fraud_pattern,
                'validation_status' => 'valid',
                'row_content_hash' => md5((string) $mt->id),
            ])->all();

            if (! empty($rows)) {
                DB::table('transactions')->insert($rows);
            }

            $result = [
                'company_name' => $mockCompany->company_name,
                'workspace_id' => $companyId,
                'workspace_name' => $mockCompany->company_name,
                'business_profile_id' => $businessProfileId,
                'business_profile_name' => $businessProfileName,
                'transaction_count' => count($rows),
                'scenario_id' => $submission->id,
                'scenario_title' => $submission->title,
            ];

            if ($createdThrowawayUser) {
                $result['email'] = $email;
                $result['password'] = $password;
            }

            return $result;
        });
    }
}
