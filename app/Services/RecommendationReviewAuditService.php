<?php

namespace App\Services;

use App\Models\RecommendationReviewEvent;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class RecommendationReviewAuditService
{
    /** @var array<int, string> */
    private const RECOMMENDATION_TYPES = [
        RecommendationReviewEvent::TYPE_ALERT,
        RecommendationReviewEvent::TYPE_CASE,
    ];

    /** @var array<int, string> */
    private const EVENT_TYPES = [
        RecommendationReviewEvent::EVENT_CREATED,
        RecommendationReviewEvent::EVENT_VIEWED,
        RecommendationReviewEvent::EVENT_APPROVED,
        RecommendationReviewEvent::EVENT_DISMISSED,
        RecommendationReviewEvent::EVENT_EXPIRED,
    ];

    /** @var array<int, string> */
    private const ACTOR_TYPES = [
        RecommendationReviewEvent::ACTOR_USER,
        RecommendationReviewEvent::ACTOR_SYSTEM,
        RecommendationReviewEvent::ACTOR_AGENT,
    ];

    /** @var array<int, string> */
    private const SENSITIVE_METADATA_KEYS = [
        'evidence',
        'supporting_evidence',
        'raw_evidence',
        'transaction_details',
        'raw_payload',
        'review_note',
        'payload',
    ];

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        string $companyId,
        string $recommendationType,
        string $recommendationId,
        string $eventType,
        string $actorType,
        ?string $actorId = null,
        ?array $metadata = null,
    ): ?RecommendationReviewEvent {
        $this->validate($recommendationType, $eventType, $actorType);

        if (! Schema::hasTable('recommendation_review_events')) {
            return null;
        }

        return RecommendationReviewEvent::create([
            'company_id' => $companyId,
            'recommendation_type' => $recommendationType,
            'recommendation_id' => $recommendationId,
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event_metadata' => $metadata === null ? null : $this->redactSensitiveMetadata($metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function history(string $companyId, string $recommendationType, string $recommendationId): array
    {
        if (! Schema::hasTable('recommendation_review_events')) {
            return [];
        }

        return RecommendationReviewEvent::where('company_id', $companyId)
            ->where('recommendation_type', $recommendationType)
            ->where('recommendation_id', $recommendationId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (RecommendationReviewEvent $event): array => [
                'id' => $event->id,
                'recommendation_type' => $event->recommendation_type,
                'recommendation_id' => $event->recommendation_id,
                'event_type' => $event->event_type,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'event_metadata' => $this->sanitizeStoredMetadata($event->event_metadata),
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function validate(string $recommendationType, string $eventType, string $actorType): void
    {
        if (! in_array($recommendationType, self::RECOMMENDATION_TYPES, true)) {
            throw new InvalidArgumentException('Invalid recommendation review event recommendation type.');
        }

        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid recommendation review event type.');
        }

        if (! in_array($actorType, self::ACTOR_TYPES, true)) {
            throw new InvalidArgumentException('Invalid recommendation review event actor type.');
        }

        if (in_array($eventType, [RecommendationReviewEvent::EVENT_APPROVED, RecommendationReviewEvent::EVENT_DISMISSED], true)
            && $actorType !== RecommendationReviewEvent::ACTOR_USER) {
            throw new InvalidArgumentException('Recommendation approval and dismissal events must be performed by a user.');
        }

        if ($actorType === RecommendationReviewEvent::ACTOR_AGENT
            && ! in_array($eventType, [RecommendationReviewEvent::EVENT_CREATED, RecommendationReviewEvent::EVENT_VIEWED], true)) {
            throw new InvalidArgumentException('Agent actors can only generate or read recommendation events.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function redactSensitiveMetadata(array $metadata): array
    {
        $redacted = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_METADATA_KEYS, true)) {
                continue;
            }

            $redacted[$key] = is_array($value) ? $this->redactSensitiveMetadata($value) : $value;
        }

        return $redacted;
    }

    private function sanitizeStoredMetadata(mixed $metadata): ?array
    {
        if ($metadata === null || $metadata === '') {
            return null;
        }

        if (is_array($metadata)) {
            return $this->redactSensitiveMetadata($metadata);
        }

        if (! is_string($metadata)) {
            return null;
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $this->redactSensitiveMetadata($decoded) : null;
    }
}
