<?php

namespace App\Services;

use App\Models\OnboardingAnswer;
use App\Models\OnboardingSession;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OnboardingSessionService
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PAUSED = 'paused';

    public const STEP_INTENT = 'intent';

    public const STEP_BUSINESS_CONTEXT = 'business_context';

    public const STEP_EVIDENCE_CHECKLIST = 'evidence_checklist';

    public const STEP_CONNECT_OR_UPLOAD = 'connect_or_upload';

    public const STEP_FIRST_SNAPSHOT = 'first_snapshot';

    public const STEP_ACTION_PLAN = 'action_plan';

    public const SCOPE_STANDARD = 'standard';

    public const SCOPE_LIMITED = 'scope_limited';

    /** @return list<string> */
    public function allowedStatuses(): array
    {
        return [
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_PAUSED,
        ];
    }

    /** @return list<string> */
    public function allowedSteps(): array
    {
        return [
            self::STEP_INTENT,
            self::STEP_BUSINESS_CONTEXT,
            self::STEP_EVIDENCE_CHECKLIST,
            self::STEP_CONNECT_OR_UPLOAD,
            self::STEP_FIRST_SNAPSHOT,
            self::STEP_ACTION_PLAN,
        ];
    }

    /** @return list<string> */
    public function allowedScopeModes(): array
    {
        return [
            self::SCOPE_STANDARD,
            self::SCOPE_LIMITED,
        ];
    }

    public function getOrCreate(BusinessProfileContext $context, User $user): OnboardingSession
    {
        $query = OnboardingSession::where('company_id', $context->companyId)
            ->where('status', '!=', self::STATUS_COMPLETED);

        if ($context->businessProfileId && Schema::hasColumn('onboarding_sessions', 'business_profile_id')) {
            $query->where('business_profile_id', $context->businessProfileId);
        } else {
            $query->whereNull('business_profile_id');
        }

        $session = $query->orderByDesc('created_at')->first();
        if ($session) {
            return $session;
        }

        return OnboardingSession::create([
            'company_id' => $context->companyId,
            'business_profile_id' => $context->businessProfileId,
            'created_by' => $user->id,
            'status' => self::STATUS_IN_PROGRESS,
            'current_step' => self::STEP_INTENT,
            'scope_mode' => self::SCOPE_STANDARD,
            'business_context' => [],
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(OnboardingSession $session, array $payload, User $user): OnboardingSession
    {
        return DB::transaction(function () use ($session, $payload, $user): OnboardingSession {
            $attributes = [];

            if (array_key_exists('primaryIntent', $payload)) {
                $attributes['primary_intent'] = EvidenceRequirementService::normalizeIntent(
                    $payload['primaryIntent'] !== null ? (string) $payload['primaryIntent'] : null
                );
            }

            if (array_key_exists('currentStep', $payload)) {
                $attributes['current_step'] = $payload['currentStep'];
            }

            if (array_key_exists('status', $payload)) {
                $attributes['status'] = $payload['status'];
                if ($payload['status'] === self::STATUS_COMPLETED && ! $session->completed_at) {
                    $attributes['completed_at'] = now();
                }
            }

            if (array_key_exists('scopeMode', $payload)) {
                $attributes['scope_mode'] = $payload['scopeMode'];
            }

            $reviewPeriod = Arr::get($payload, 'reviewPeriod', []);
            if (array_key_exists('reviewPeriodStart', $payload) || array_key_exists('start', (array) $reviewPeriod)) {
                $attributes['review_period_start'] = $payload['reviewPeriodStart'] ?? Arr::get($reviewPeriod, 'start');
            }
            if (array_key_exists('reviewPeriodEnd', $payload) || array_key_exists('end', (array) $reviewPeriod)) {
                $attributes['review_period_end'] = $payload['reviewPeriodEnd'] ?? Arr::get($reviewPeriod, 'end');
            }

            if (array_key_exists('businessContext', $payload) && is_array($payload['businessContext'])) {
                $businessContext = array_merge(
                    $session->business_context ?: [],
                    $this->cleanBusinessContext($payload['businessContext'])
                );
                $attributes['business_context'] = $businessContext;

                $this->upsertAnswers($session, $businessContext, $user);
            }

            if (($payload['scopeAcknowledged'] ?? false) === true) {
                $attributes['scope_acknowledged_at'] = now();
                $attributes['scope_mode'] = self::SCOPE_LIMITED;
            }

            if ($attributes !== []) {
                $session->fill($attributes);
                $session->save();
            }

            return $session->refresh();
        });
    }

    /**
     * @param  array<string, mixed>|null  $readiness
     * @return array<string, mixed>
     */
    public function contract(OnboardingSession $session, ?array $readiness = null): array
    {
        return [
            'id' => (string) $session->id,
            'companyId' => (string) $session->company_id,
            'businessProfileId' => $session->business_profile_id ? (string) $session->business_profile_id : null,
            'status' => (string) $session->status,
            'primaryIntent' => $session->primary_intent,
            'currentStep' => (string) $session->current_step,
            'reviewPeriod' => [
                'start' => $session->review_period_start?->toDateString(),
                'end' => $session->review_period_end?->toDateString(),
            ],
            'scopeMode' => (string) $session->scope_mode,
            'scopeAcknowledgedAt' => $session->scope_acknowledged_at?->toISOString(),
            'businessContext' => $session->business_context ?: [],
            'dataReadiness' => $readiness,
            'completedAt' => $session->completed_at?->toISOString(),
            'createdAt' => $session->created_at?->toISOString(),
            'updatedAt' => $session->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $businessContext
     * @return array<string, mixed>
     */
    private function cleanBusinessContext(array $businessContext): array
    {
        $allowedKeys = [
            'organizationType',
            'industryOrActivity',
            'entityType',
            'fiscalYearStart',
            'approximateAnnualSpend',
            'accountingSystem',
            'bankAccountCount',
            'authorizedSignerCount',
            'bookAccessCount',
            'checksUsed',
            'employeesChanged',
            'contractorsChanged',
            'vendorsChanged',
            'volunteersChanged',
            'statedConcernSummary',
            'priorActionsTaken',
            'noticeType',
            'noticeDeadline',
            'taxPeriod',
        ];

        $clean = [];
        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $businessContext)) {
                continue;
            }

            $value = $businessContext[$key];
            if (is_string($value)) {
                $value = trim(substr($value, 0, 2000));
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $businessContext
     */
    private function upsertAnswers(OnboardingSession $session, array $businessContext, User $user): void
    {
        foreach ($businessContext as $key => $value) {
            OnboardingAnswer::updateOrCreate(
                [
                    'onboarding_session_id' => $session->id,
                    'answer_key' => $key,
                ],
                [
                    'company_id' => $session->company_id,
                    'business_profile_id' => $session->business_profile_id,
                    'answered_by' => $user->id,
                    'answer_value' => ['value' => $value],
                ],
            );
        }
    }
}
