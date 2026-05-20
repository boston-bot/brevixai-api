<?php

namespace App\Services\PersonalFinance;

use App\Models\PersonalFinanceRule;
use App\Models\PersonalFinanceTransaction;
use Illuminate\Support\Collection;

class PersonalFinanceCategorizationService
{
    private const DEFAULT_RULES = [
        // ── Income detection (priority 5–15) ────────────────────────────
        ['rule_type' => 'income_source', 'name' => 'Federal government salary', 'pattern' => 'AGRI TREAS.*FED SAL|TREAS.*FED SAL|FED SALARY', 'target_value' => PersonalFinanceTransaction::PERSON_UNKNOWN, 'priority' => 5, 'metadata' => ['category' => 'income']],
        ['rule_type' => 'income_source', 'name' => 'Employer payroll', 'pattern' => 'RESERVE COMMUNIC.*PAYROLL|PAYROLL|DIRECT DEP|DIRECT DEPOSIT|SALARY', 'target_value' => PersonalFinanceTransaction::PERSON_UNKNOWN, 'priority' => 6, 'metadata' => ['category' => 'income']],
        ['rule_type' => 'income_source', 'name' => 'Mobile check deposit', 'pattern' => 'REMOTE.*DEPOSIT|MOBILE DEPOSIT', 'target_value' => PersonalFinanceTransaction::PERSON_UNKNOWN, 'priority' => 7, 'metadata' => ['category' => 'income']],
        ['rule_type' => 'income_source', 'name' => 'Other deposits', 'pattern' => 'DEPOSIT\s+\d|ACH CREDIT', 'target_value' => PersonalFinanceTransaction::PERSON_UNKNOWN, 'priority' => 8, 'metadata' => ['category' => 'income']],
        ['rule_type' => 'income_source', 'name' => 'Interest income', 'pattern' => '^INTEREST\s+PAYMENT$|INTEREST PAYMENT', 'target_value' => PersonalFinanceTransaction::PERSON_UNKNOWN, 'priority' => 9, 'metadata' => ['category' => 'income']],
        ['rule_type' => 'income_source', 'name' => 'Venmo and cashout income', 'pattern' => 'VENMO CASHOUT', 'target_value' => PersonalFinanceTransaction::PERSON_UNKNOWN, 'priority' => 10, 'metadata' => ['category' => 'income']],
        ['rule_type' => 'income_source', 'name' => 'Real-time transfer received', 'pattern' => 'REAL TIME TRANSFER RECD|PAYMENT RECEIVED|APPLE CASH INST XFER', 'target_value' => PersonalFinanceTransaction::PERSON_UNKNOWN, 'priority' => 11, 'metadata' => ['category' => 'income']],

        // ── Exclusions (priority 20–25) ──────────────────────────────────
        ['rule_type' => 'exclusion', 'name' => 'Internal transfers', 'pattern' => 'TRANSFER TO CHK|TRANSFER FROM CHK|ONLINE TRANSFER FROM|ONLINE TRANSFER TO|EXTERNAL TRANSFER|SAVINGS TRANSFER', 'target_value' => 'transfer', 'priority' => 20, 'metadata' => ['person_scope' => PersonalFinanceTransaction::PERSON_EXCLUDED]],

        // ── Investments (new category, priority 28) ─────────────────────
        ['rule_type' => 'category', 'name' => 'Investments', 'pattern' => 'VANGUARD|FUNDRISE|ACORNS|FIDELITY|SCHWAB|BETTERMENT|WEALTHFRONT|ROBINHOOD', 'target_value' => 'investment', 'priority' => 28, 'metadata' => ['adjustable' => true]],

        // ── Debt payments (new category, priority 29) ───────────────────
        ['rule_type' => 'category', 'name' => 'Debt and loan payments', 'pattern' => 'AFFIRM.*PAY|AFFIRM\.COM|GOLDMAN SACHS.*COLLECTION|SOFI BANK.*PL|PENTAGON FEDERAL|ADVS ED SERV|STUDNTLOAN|FNB RIVER FALLS|MBFS.*PAY|REV LETSREV|CHECK\s+#', 'target_value' => 'debt_payment', 'priority' => 29, 'metadata' => ['adjustable' => true]],

        // ── Credit card payments (priority 30) ──────────────────────────
        ['rule_type' => 'category', 'name' => 'Credit card payments', 'pattern' => 'CHASE CREDIT CRD|CARDMEMBER|AMEX EPAYMENT|AMERICAN EXPRESS|CITI CARD|CAPITAL ONE|DISCOVER E-PAY|BARCLAYS|SYNCHRONY|BK OF AMER.*MC.*PMT|BK OF AMER.*ONLINE PMT', 'target_value' => 'credit_card_payment', 'priority' => 30, 'metadata' => ['adjustable' => true]],

        // ── Housing (priority 35) ───────────────────────────────────────
        ['rule_type' => 'category', 'name' => 'Housing', 'pattern' => 'PENNYMAC|NSM DBAMR.COOPER|MR\.?COOPER|MORTGAGE|RENT|APARTMENT|PROPERTY MGMT|PROPERTY MANAGEMENT|HOA|DELAUNE ESTATES', 'target_value' => 'housing', 'priority' => 35, 'metadata' => ['person_scope' => PersonalFinanceTransaction::PERSON_SHARED, 'adjustable' => false]],

        // ── Utilities and telecom (priority 40) ─────────────────────────
        ['rule_type' => 'category', 'name' => 'Utilities and telecom', 'pattern' => 'CITY OF.*UTILI|ENTERGY|ATMOS ENERGY|CLECO|ELECTRIC|WATER BILL|GAS BILL|UTILITY|COMCAST|XFINITY|AT&T|ATT\\*|VERIZON|T-MOBILE|TMOBILE|INTERNET|ACI.*ENTERGY', 'target_value' => 'utilities', 'priority' => 40, 'metadata' => ['person_scope' => PersonalFinanceTransaction::PERSON_SHARED, 'adjustable' => false]],

        // ── Groceries (priority 50) ─────────────────────────────────────
        ['rule_type' => 'category', 'name' => 'Groceries', 'pattern' => 'ROUSES|LAMENDOLA|KROGER|ALDI|WHOLE FOODS|WHOLEFDS|TRADER JOE|COSTCO|SAM.?S CLUB|WALMART|WAL-MART|WM SUPERCENTER|TARGET|MEIJER|PUBLIX|GROCERY|ALEXANDER.?S\s+(HERITAGE|HARVEST|HIGHLAND)', 'target_value' => 'groceries', 'priority' => 50, 'metadata' => ['person_scope' => PersonalFinanceTransaction::PERSON_SHARED, 'adjustable' => true]],

        // ── Dining (priority 60) ────────────────────────────────────────
        ['rule_type' => 'category', 'name' => 'Dining', 'pattern' => 'NAVARRE|NOLA MIA|EL PASO OF|MIKE ANDERSON|TST\s|TAILS\s|ARMK LSU|RESTAURANT|MCDONALD|STARBUCKS|CHICK.?FIL.?A|TACO|PIZZA|DOORDASH|UBER EATS|GRUBHUB|CAFE|DINING|SEAFOOD|WAFFLE|POPEYE|RAISIN.?CANES|ZAXBY|SONIC|WENDY|SUBWAY|FIREHOUSE|JIMMY JOHN|PANERA|CHIPOTLE|WING|SMOOTHIE|JUICE|BOBA|PASTRIES', 'target_value' => 'dining', 'priority' => 60, 'metadata' => ['adjustable' => true]],

        // ── Subscriptions and software (priority 70) ────────────────────
        ['rule_type' => 'category', 'name' => 'Subscriptions and software', 'pattern' => 'NETFLIX|SPOTIFY|APPLE\.COM/BILL|GOOGLE\\*|AMAZON PRIME|HULU|DISNEY|OPENAI|ADOBE|MICROSOFT|ICLOUD|PATREON|PARAMOUNT|PARAMNTPLUS|PAYPAL\s*\*NETFLIX|PP\*APPLE|YOUTUBE|HBO|PEACOCK|CHATGPT', 'target_value' => 'subscriptions', 'priority' => 70, 'metadata' => ['adjustable' => true]],

        // ── Donations and charity (new category, priority 75) ───────────
        ['rule_type' => 'category', 'name' => 'Donations and charity', 'pattern' => 'SHRINERSHOS|ALSACSTJUDE|ST\.?\s*JUDE|FOLDS\s*HONOR|AARP|CHARITY|DONATION|UNITED WAY|RED CROSS|SALVATION ARMY|GOODWILL', 'target_value' => 'donations', 'priority' => 75, 'metadata' => ['adjustable' => true]],

        // ── Transportation (priority 80) ────────────────────────────────
        ['rule_type' => 'category', 'name' => 'Transportation', 'pattern' => 'MURPHY EXPRESS|CIRCLE K|RACETRAC|SHELL|BP#|BP\s|EXXON|CHEVRON|MOBIL|UBER\s|LYFT|PARKING|TOLL|FUEL|GAS STATION|TEXACO|VALERO|MARATHON|CASEY|PILOT|LOVES\s|BUCCEE|QT\s', 'target_value' => 'transportation', 'priority' => 80, 'metadata' => ['adjustable' => true]],

        // ── Healthcare (priority 85) ────────────────────────────────────
        ['rule_type' => 'category', 'name' => 'Healthcare', 'pattern' => 'BRGMC|PHARMACY|CVS|WALGREENS|DOCTOR|DENTAL|MEDICAL|HOSPITAL|INSURANCE|HEALTH|CLINIC|URGENT|LABCORP|QUEST DIAG', 'target_value' => 'healthcare', 'priority' => 85, 'metadata' => ['adjustable' => false]],

        // ── Peer-to-peer payments (priority 88) ─────────────────────────
        ['rule_type' => 'category', 'name' => 'Peer-to-peer payments', 'pattern' => 'VENMO\s+PAYMENT|ZELLE\s+PAYMENT\s+TO|ZELLE\s+TO|CASHAPP|APPLE\s+CASH', 'target_value' => 'p2p_payment', 'priority' => 88, 'metadata' => ['adjustable' => true]],

        // ── Shopping (priority 90) ──────────────────────────────────────
        ['rule_type' => 'category', 'name' => 'Shopping', 'pattern' => 'AMAZON|TEMU|SHEIN|ETSY|EBAY|BEST BUY|HOME DEPOT|LOWE.?S|HOBBYLOBBY|HOBBY LOBBY|PETCO|PETSMART|DOLLAR GENERAL|DOLLAR TREE|FIVE BELOW|BARNESNOBLE|BARNES.?NOBLE|TOTAL WINE|LOUISIANA NURSER|BATH.?BODY', 'target_value' => 'shopping', 'priority' => 90, 'metadata' => ['adjustable' => true]],

        // ── Cash and ATM (priority 100) ─────────────────────────────────
        ['rule_type' => 'category', 'name' => 'Cash and ATM', 'pattern' => 'ATM WITHDRAWAL|CASH WITHDRAWAL|NON-CHASE ATM', 'target_value' => 'cash_atm', 'priority' => 100, 'metadata' => ['adjustable' => true]],

        // ── Bank fees (priority 110) ────────────────────────────────────
        ['rule_type' => 'category', 'name' => 'Bank fees', 'pattern' => 'MONTHLY SERVICE FEE|OVERDRAFT|RETURNED ITEM|NON-CHASE ATM FEE|SERVICE CHARGE', 'target_value' => 'fees', 'priority' => 110, 'metadata' => ['adjustable' => true]],
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
