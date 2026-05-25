<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\ChatUsageDaily;
use App\Models\RexPendingAction;
use App\Models\AuditCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Exception;

class ChatService
{
    public function __construct(private readonly PlanPolicyService $planPolicy) {}

    public function checkAndIncrementQuota(string $companyId, ?string $businessProfileId = null): array
    {
        $limit = $this->planPolicy->dailyChatLimit($companyId);

        if ($limit === 0) {
            throw new Exception("Rex is not available on your current plan", 403);
        }

        $today = Carbon::today()->toDateString();

        $keys = ['company_id' => $companyId, 'date' => $today];
        if ($businessProfileId && Schema::hasColumn('chat_usage_daily', 'business_profile_id')) {
            $keys['business_profile_id'] = $businessProfileId;
        }

        $usage = ChatUsageDaily::firstOrCreate($keys, ['message_count' => 0]);

        if ($usage->message_count >= $limit) {
            throw new Exception("Daily message limit of {$limit} reached. Resets at midnight.", 429);
        }

        $usage->increment('message_count');

        return [
            'allowed' => true,
            'limit' => $limit,
            'used' => $usage->message_count,
        ];
    }

    public function getUsage(string $companyId, ?string $businessProfileId = null): array
    {
        $limit = $this->planPolicy->dailyChatLimit($companyId);

        $today = Carbon::today()->toDateString();
        $usageQuery = ChatUsageDaily::where('company_id', $companyId)->where('date', $today);
        if ($businessProfileId && Schema::hasColumn('chat_usage_daily', 'business_profile_id')) {
            $usageQuery->where('business_profile_id', $businessProfileId);
        }
        $usage = $usageQuery->first();
        $used = $usage ? $usage->message_count : 0;

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    public function createSession(string $companyId, string $userId, ?string $title, ?string $businessProfileId = null): ChatSession
    {
        $attributes = [
            'company_id' => $companyId,
            'user_id' => $userId,
            'title' => $title ?: 'New Chat with Rex',
        ];

        if ($businessProfileId && Schema::hasColumn('chat_sessions', 'business_profile_id')) {
            $attributes['business_profile_id'] = $businessProfileId;
        }

        return ChatSession::create($attributes);
    }

    public function listSessions(string $companyId, ?string $businessProfileId = null): array
    {
        $sessions = DB::table('chat_sessions as cs')
            ->where('cs.company_id', $companyId);

        if ($businessProfileId && Schema::hasColumn('chat_sessions', 'business_profile_id')) {
            $sessions->where('cs.business_profile_id', $businessProfileId);
        }

        $sessions = $sessions->orderBy('cs.updated_at', 'desc')
            ->limit(20)
            ->select(
                'cs.id', 'cs.title', 'cs.created_at', 'cs.updated_at',
                DB::raw("(SELECT content FROM chat_messages WHERE session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_message")
            )
            ->get();

        return $sessions->toArray();
    }

    public function getSession(string $companyId, string $sessionId, ?string $businessProfileId = null): ?array
    {
        $query = ChatSession::where('id', $sessionId)->where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('chat_sessions', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }
        $session = $query->first();
        if (!$session) return null;

        $messages = ChatMessage::where('session_id', $sessionId)->orderBy('created_at', 'asc')->get();

        $sessionArray = $session->toArray();
        $sessionArray['messages'] = $messages->toArray();
        return $sessionArray;
    }

    public function deleteSession(string $companyId, string $sessionId, ?string $businessProfileId = null): bool
    {
        $query = ChatSession::where('id', $sessionId)->where('company_id', $companyId);
        if ($businessProfileId && Schema::hasColumn('chat_sessions', 'business_profile_id')) {
            $query->where('business_profile_id', $businessProfileId);
        }
        $session = $query->first();
        if (!$session) return false;

        $session->delete();
        return true;
    }

    public function logUserMessage(string $companyId, string $sessionId, string $content, ?string $businessProfileId = null): void
    {
        $attributes = [
            'session_id' => $sessionId,
            'company_id' => $companyId,
            'role' => 'user',
            'content' => $content,
        ];

        if ($businessProfileId && Schema::hasColumn('chat_messages', 'business_profile_id')) {
            $attributes['business_profile_id'] = $businessProfileId;
        }

        ChatMessage::create($attributes);

        ChatSession::where('id', $sessionId)->update(['updated_at' => now()]);
    }

    public function logAssistantMessage(string $companyId, string $sessionId, string $content, ?array $structuredPayload, ?string $businessProfileId = null): void
    {
        $attributes = [
            'session_id' => $sessionId,
            'company_id' => $companyId,
            'role' => 'assistant',
            'content' => $content,
            'structured_payload' => $structuredPayload,
        ];

        if ($businessProfileId && Schema::hasColumn('chat_messages', 'business_profile_id')) {
            $attributes['business_profile_id'] = $businessProfileId;
        }

        ChatMessage::create($attributes);

        ChatSession::where('id', $sessionId)->update(['updated_at' => now()]);
    }

    public function getWorkspace(string $companyId, string $sessionId, ?string $businessProfileId = null): array
    {
        $messages = ChatMessage::where('session_id', $sessionId)
            ->where('company_id', $companyId)
            ->where('role', 'assistant');

        if ($businessProfileId && Schema::hasColumn('chat_messages', 'business_profile_id')) {
            $messages->where('business_profile_id', $businessProfileId);
        }

        $messages = $messages->orderBy('created_at')->get();

        $artifacts = [];
        foreach ($messages as $message) {
            $payload = $message->structured_payload ?? [];
            foreach (($payload['artifacts'] ?? []) as $artifact) {
                $artifacts[$artifact['id'] ?? uniqid('artifact-')] = $artifact;
            }
        }

        $actions = RexPendingAction::where('session_id', $sessionId)
            ->where('company_id', $companyId)
            ->where('status', 'pending');

        if ($businessProfileId && Schema::hasColumn('rex_pending_actions', 'business_profile_id')) {
            $actions->where('business_profile_id', $businessProfileId);
        }

        $actions = $actions->get();

        return [
            'artifacts' => array_values($artifacts),
            'actions' => $actions,
        ];
    }

    public function confirmAction(string $companyId, string $userId, string $sessionId, string $actionId, ?string $businessProfileId = null): array
    {
        $action = RexPendingAction::where('id', $actionId)
            ->where('session_id', $sessionId)
            ->where('company_id', $companyId)
            ->where('status', 'pending');

        if ($businessProfileId && Schema::hasColumn('rex_pending_actions', 'business_profile_id')) {
            $action->where('business_profile_id', $businessProfileId);
        }

        $action = $action->first();

        if (!$action) {
            throw new Exception("Action not found or already resolved", 404);
        }

        $executionResult = [];

        if ($action->action_type === 'create_case') {
            $preview = $action->preview;
            $case = app(CaseService::class)->create($companyId, $userId, [
                'title' => $preview['title'] ?? 'Investigation',
                'description' => $preview['description'] ?? '',
                'severity' => $preview['severity'] ?? 'warning',
                'alert_ids' => $preview['alertIds'] ?? [],
                'transaction_ids' => $preview['transactionIds'] ?? [],
            ]);
            $executionResult['caseId'] = $case->id;

            DB::table('audit_logs')->insert([
                'company_id' => $companyId,
                'user_id' => $userId,
                'action' => 'confirm_action',
                'resource_type' => 'case',
                'resource_id' => $case->id,
                'metadata' => json_encode(['actionId' => $actionId, 'sessionId' => $sessionId, 'actionType' => 'create_case']),
                'created_at' => now(),
            ]);
        }

        $action->update([
            'status' => 'confirmed',
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);

        return ['success' => true, 'actionId' => $actionId, 'result' => $executionResult];
    }

    public function rejectAction(string $companyId, string $userId, string $sessionId, string $actionId, ?string $businessProfileId = null): array
    {
        $action = RexPendingAction::where('id', $actionId)
            ->where('session_id', $sessionId)
            ->where('company_id', $companyId)
            ->where('status', 'pending');

        if ($businessProfileId && Schema::hasColumn('rex_pending_actions', 'business_profile_id')) {
            $action->where('business_profile_id', $businessProfileId);
        }

        $action = $action->first();

        if (!$action) {
            throw new Exception("Action not found or already resolved", 404);
        }

        $action->update([
            'status' => 'rejected',
            'confirmed_by' => $userId,
            'confirmed_at' => now(),
        ]);

        return ['success' => true, 'actionId' => $actionId, 'status' => 'rejected'];
    }
}
