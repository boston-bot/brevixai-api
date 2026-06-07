<?php

namespace App\Services\FraudTesting;

use App\Models\FraudTesting\FraudGenerationRun;
use App\Models\FraudTesting\FraudMockCompany;
use App\Models\FraudTesting\FraudMockParty;
use App\Models\FraudTesting\FraudMockTransaction;
use App\Models\FraudTesting\FraudScenarioSubmission;
use Illuminate\Support\Str;

class FraudScenarioMockDataService
{
    public function saveMockData(FraudScenarioSubmission $submission, array $data): FraudMockCompany
    {
        FraudMockTransaction::where('scenario_submission_id', $submission->id)->delete();
        FraudMockParty::where('scenario_submission_id', $submission->id)->delete();
        FraudMockCompany::where('scenario_submission_id', $submission->id)->delete();

        $companyData = $data['mock_company'] ?? [];
        $mockCompany = FraudMockCompany::create([
            'id' => (string) Str::uuid(),
            'scenario_submission_id' => $submission->id,
            'company_name' => $companyData['company_name'] ?? 'Unknown Company',
            'industry' => $companyData['industry'] ?? null,
            'entity_type' => $companyData['entity_type'] ?? null,
            'annual_revenue' => $companyData['annual_revenue'] ?? null,
            'employee_count' => $companyData['employee_count'] ?? null,
            'vendor_count' => $companyData['vendor_count'] ?? null,
            'customer_count' => $companyData['customer_count'] ?? null,
            'months_of_activity' => $companyData['months_of_activity'] ?? null,
            'profile_payload' => $companyData,
        ]);

        $partyIdMap = [];
        foreach ($data['parties'] ?? [] as $partyData) {
            $party = FraudMockParty::create([
                'id' => (string) Str::uuid(),
                'scenario_submission_id' => $submission->id,
                'mock_company_id' => $mockCompany->id,
                'external_party_id' => $partyData['external_party_id'] ?? null,
                'party_type' => $partyData['party_type'] ?? 'Unknown',
                'party_name' => $partyData['party_name'] ?? '',
                'role' => $partyData['role'] ?? null,
                'is_fraud_actor' => $partyData['is_fraud_actor'] ?? false,
                'is_related_party' => $partyData['is_related_party'] ?? false,
                'attributes' => $partyData['attributes'] ?? null,
            ]);
            if ($partyData['external_party_id'] ?? null) {
                $partyIdMap[$partyData['external_party_id']] = $party->id;
            }
        }

        foreach ($data['transactions'] ?? [] as $txData) {
            $externalPartyId = $txData['external_party_id'] ?? null;
            FraudMockTransaction::create([
                'id' => (string) Str::uuid(),
                'scenario_submission_id' => $submission->id,
                'mock_company_id' => $mockCompany->id,
                'external_transaction_id' => $txData['external_transaction_id'] ?? null,
                'transaction_type' => $txData['transaction_type'] ?? 'Unknown',
                'transaction_date' => $txData['transaction_date'] ?? null,
                'amount' => $txData['amount'] ?? null,
                'party_id' => $externalPartyId ? ($partyIdMap[$externalPartyId] ?? null) : null,
                'account_category' => $txData['account_category'] ?? null,
                'description' => $txData['description'] ?? null,
                'is_fraudulent' => $txData['is_fraudulent'] ?? false,
                'fraud_pattern' => $txData['fraud_pattern'] ?? null,
                'expected_brevix_signal' => $txData['expected_brevix_signal'] ?? null,
                'payload' => $txData['payload'] ?? null,
            ]);
        }

        $submission->update(['mock_data_status' => FraudScenarioSubmission::MOCK_DATA_STATUS_COMPLETED]);

        FraudGenerationRun::where('scenario_submission_id', $submission->id)
            ->where('run_type', FraudGenerationRun::RUN_TYPE_MOCK_DATA_GENERATION)
            ->where('status', FraudGenerationRun::STATUS_RUNNING)
            ->update(['status' => FraudGenerationRun::STATUS_COMPLETED, 'completed_at' => now()]);

        return $mockCompany->fresh();
    }
}
