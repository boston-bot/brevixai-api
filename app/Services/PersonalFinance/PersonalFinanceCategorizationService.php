<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalFinanceRule;
use App\Models\PersonalFinanceTransaction;
use Illuminate\Support\Collection;

class PersonalFinanceCategorizationService
{
    private const DEFAULT_RULES = [
        ['rule_type' => 'income_source', 'name' => 'Payroll and direct deposit income', 'pattern' => 'PAYROLL|DIRECT DEP|DIRECT DEPOSIT|ACH CREDIT|PPD ID|SALARY', 'target_value' => PersonalFinanceTransaction::PERSON_UNKNOWN, 'priority' => 10, 'metadata' => ['category' => 'income']],
        ['rule_type' => 'exclusion', 'name' => 'Internal transfers', 'pattern' => 'TRANSFER TO|TRANSFER FROM|ONLINE TRANSFER|EXTERNAL TRANSFER|SAVINGS TRANSFER', 'target_value' => 'transfer', 'priority' => 20, 'metadata' => ['person_scope' => PersonalFinanceTransaction::PERSON_EXCLUDED]],
        ['rule_type' => 'category', 'name' => 'Opaque credit card payments', 'pattern' => 'CHASE CREDIT CRD|CARDMEMBER|AMEX|AMERICAN EXPRESS|CITI CARD|CAPITAL ONE|DISCOVER E-PAY|BARCLAYS|SYNCHRONY', 'target_value' => 'credit_card_payment', 'priority' => 30, 'metadata' => ['adjustable' => true]],
        ['rule_type' => 'category', 'name' => 'Housing', 'pattern' => 'MORTGAGE|RENT|APARTMENT|PROPERTY MGMT|PROPERTY MANAGEMENT|HOA', 'target_value' => 'housing', 'priority' => 40, 'metadata' => ['person_scope' => PersonalFinanceTransaction::PERSON_SHARED, 'adjustable' => false]],
        ['rule_type' => 'category', 'name' => 'Groceries', 'pattern' => 'KROGER|ALDI|WHOLE FOODS|WHOLEFDS|TRADER JOE|COSTCO|SAM.?S CLUB|WALMART|TARGET|MEIJER|PUBLIX|GROCERY', 'target_value' => 'groceries', 'priority' => 50, 'metadata' => ['person_scope' => PersonalFinanceTransaction::PERSON_SHARED, 'adjustable' => true]],
        ['rule_type' => 'category', 'name' => 'Dining', 'pattern' => 'RESTAURANT|MCDONALD|STARBUCKS|CHICK-FIL-A|CHICK FIL A|TACO|PIZZA|DOORDASH|UBER EATS|GRUBHUB|CAFE|DINING', 'target_value' => 'dining', 'priority' => 60, 'metadata' => ['adjustable' => true]],
        ['rule_type' => 'category', 'name' => 'Utilities and telecom', 'pattern' => 'ELECTRIC|WATER|GAS BILL|UTILITY|COMCAST|XFINITY|AT&T|ATT\\*|VERIZON|T-MOBILE|TMOBILE|INTERNET', 'target_value' => 'utilities', 'priority' => 70, 'metadata' => ['person_scope' => PersonalFinanceTransaction::PERSON_SHARED, 'adjustable' => false]],
        ['rule_type' => 'category', 'name' => 'Subscriptions and software', 'pattern' => 'NETFLIX|SPOTIFY|APPLE.COM/BILL|GOOGLE\\*|AMAZON PRIME|HULU|DISNEY|OPENAI|ADOBE|MICROSOFT|ICLOUD|PATREON', 'target_value' => 'subscriptions', 'priority' => 80, 'metadata' => ['adjustable' => true]],
        ['rule_type' => 'category', 'name' => 'Transportation', 'pattern' => 'SHELL|BP#|BP |EXXON|CHEVRON|MOBIL|UBER|LYFT|PARKING|TOLL|FUEL|GAS STATION', 'target_value' => 'transportation', 'priority' => 90, 'metadata' => ['adjustable' => true]],
        ['rule_type' => 'category', 'name' => 'Healthcare', 'pattern' => 'PHARMACY|CVS|WALGREENS|DOCTOR|DENTAL|MEDICAL|HOSPITAL|INSURANCE|HEALTH', 'target_value' => 'healthcare', 'priority' => 100, 'metadata' => ['adjustable' => false]],
        ['rule_type' => 'category', 'name' => 'Shopping', 'pattern' => 'AMAZON|ETSY|EBAY|BEST BUY|HOME DEPOT|LOWE.?S|SHOP|STORE', 'target_value' => 'shopping', 'priority' => 110, 'metadata' => ['adjustable' => true]],
        ['rule_type' => 'category', 'name' => 'Cash and ATM', 'pattern' => 'ATM WITHDRAWAL|CASH WITHDRAWAL|NON-CHASE ATM', 'target_value' => 'cash_atm', 'priority' => 120, 'metadata' => ['adjustable' => true]],
        ['rule_type' => 'category', 'name' => 'Bank fees', 'pattern' => 'MONTHLY SERVICE FEE|OVERDRAFT|RETURNED ITEM|NON-CHASE ATM FEE|FEE', 'target_value' => 'fees', 'priority' => 130, 'metadata' => ['adjustable' => true]],
    ];

    public function ensureDefaultRules(string $companyId): void
    {
        foreach (self::DEFAULT_RULES as $rule) {
            PersonalFinanceRule::firstOrCreate(
                [
                    'company_id' => $companyId,
                    'rule_type' => $rule['rule_type'],
                    'name' => $rule['name'],
                ],
                [
                    'match_field' => 'description',
                    'pattern' => $rule['pattern'],
                    'target_value' => $rule['target_value'],
                    'priority' => $rule['priority'],
                    'is_active' => true,
                    'metadata' => $rule['metadata'],
                ],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    public function categorizeParsedTransaction(string $companyId, array $parsed): array
    {
        $description = (string) $parsed['description'];
        $normalizedMerchant = $this->normalizeMerchant($description);
        $direction = (string) $parsed['direction'];
        $category = $direction === PersonalFinanceTransaction::DIRECTION_INFLOW ? 'income' : 'uncategorized';
        $personScope = PersonalFinanceTransaction::PERSON_UNKNOWN;
        $confidence = 55;

        foreach ($this->activeRules($companyId) as $rule) {
            $fieldValue = $this->fieldValue($rule, $description, $normalizedMerchant, $category);
            if (! $this->matches($rule, $fieldValue)) {
                continue;
            }

            $metadata = $rule->metadata ?? [];

            if ($rule->rule_type === PersonalFinanceRule::TYPE_MERCHANT && $rule->target_value) {
                $normalizedMerchant = $rule->target_value;
                $confidence = max($confidence, 80);

                continue;
            }

            if ($rule->rule_type === PersonalFinanceRule::TYPE_EXCLUSION) {
                $category = $rule->target_value ?: 'transfer';
                $personScope = $metadata['person_scope'] ?? PersonalFinanceTransaction::PERSON_EXCLUDED;
                $confidence = max($confidence, 85);

                continue;
            }

            if ($rule->rule_type === PersonalFinanceRule::TYPE_INCOME_SOURCE && $direction === PersonalFinanceTransaction::DIRECTION_INFLOW) {
                $category = $metadata['category'] ?? 'income';
                $personScope = $rule->target_value ?: $personScope;
                $confidence = max($confidence, 80);

                continue;
            }

            if ($rule->rule_type === PersonalFinanceRule::TYPE_CATEGORY && $direction === PersonalFinanceTransaction::DIRECTION_OUTFLOW) {
                $category = $rule->target_value ?: $category;
                if (! empty($metadata['person_scope'])) {
                    $personScope = $metadata['person_scope'];
                }
                $confidence = max($confidence, 75);

                continue;
            }

            if ($rule->rule_type === PersonalFinanceRule::TYPE_PERSON) {
                $personScope = $rule->target_value ?: $personScope;
                $confidence = max($confidence, 80);
            }
        }

        return [
            'normalized_merchant' => $normalizedMerchant,
            'category' => $category,
            'person_scope' => $personScope,
            'recurring_key' => $this->recurringKey($direction, $category, $normalizedMerchant, $personScope),
            'confidence' => min(99, $confidence),
        ];
    }

    public function reclassifyTransaction(PersonalFinanceTransaction $transaction): void
    {
        $classification = $this->categorizeParsedTransaction($transaction->company_id, [
            'description' => $transaction->description,
            'direction' => $transaction->direction,
        ]);

        $transaction->update($classification);
    }

    /**
     * @return array<string, mixed>
     */
    public function categoryMetadata(string $companyId, string $category): array
    {
        $rule = PersonalFinanceRule::where('company_id', $companyId)
            ->where('rule_type', PersonalFinanceRule::TYPE_CATEGORY)
            ->where('target_value', $category)
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();

        return $rule?->metadata ?? [];
    }

    /**
     * @return Collection<int, PersonalFinanceRule>
     */
    private function activeRules(string $companyId): Collection
    {
        return PersonalFinanceRule::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();
    }

    private function fieldValue(PersonalFinanceRule $rule, string $description, string $normalizedMerchant, string $category): string
    {
        return match ($rule->match_field) {
            'merchant', 'normalized_merchant' => $normalizedMerchant,
            'category' => $category,
            default => $description,
        };
    }

    private function matches(PersonalFinanceRule $rule, string $value): bool
    {
        $metadata = $rule->metadata ?? [];
        $pattern = (string) $rule->pattern;

        if (($metadata['is_regex'] ?? true) === false) {
            return stripos($value, $pattern) !== false;
        }

        return @preg_match('~'.str_replace('~', '\~', $pattern).'~i', $value) === 1;
    }

    private function normalizeMerchant(string $description): string
    {
        $merchant = strtoupper($description);
        $merchant = preg_replace('/\b(POS|ACH|WEB|ONLINE|RECURRING|PURCHASE|DEBIT|CARD|CHECKCARD|WITHDRAWAL|PAYMENT)\b/', ' ', $merchant) ?? $merchant;
        $merchant = preg_replace('/\b\d{4,}\b/', ' ', $merchant) ?? $merchant;
        $merchant = preg_replace('/[#*][A-Z0-9-]+/', ' ', $merchant) ?? $merchant;
        $merchant = trim(preg_replace('/\s+/', ' ', $merchant) ?? $merchant);

        if ($merchant === '') {
            $merchant = strtoupper(trim($description));
        }

        return mb_substr($merchant, 0, 120);
    }

    private function recurringKey(string $direction, string $category, string $normalizedMerchant, string $personScope): ?string
    {
        if ($direction !== PersonalFinanceTransaction::DIRECTION_OUTFLOW || $personScope === PersonalFinanceTransaction::PERSON_EXCLUDED) {
            return null;
        }

        return strtolower($category.'|'.$normalizedMerchant);
    }
}
