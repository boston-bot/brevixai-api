<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use App\Models\ChatUsageDaily;
use App\Models\RexPendingAction;
use App\Models\AuditCase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Exception;

class ChatService
{
    private const DAILY_QUOTA = [
        'starter' => 0,
        'growth' => 50,
        'risk-advisory' => 200,
        'accounting' => 200,
        'accounting-firm' => 200,
    ];

    public function checkAndIncrementQuota(string $companyId): array
    {
        $sub = DB::table('subscriptions')->where('company_id', $companyId)->where('status', 'active')->first();
        $tier = $sub ? $sub->tier : 'starter';
        $limit = self::DAILY_QUOTA[$tier] ?? 0;

        if ($limit === 0) {
            throw new Exception("Rex is not available on your current plan", 403);
        }

        $today = Carbon::today()->toDateString();

        $usage = ChatUsageDaily::firstOrCreate(
            ['company_id' => $companyId, 'date' => $today],
            ['message_count' => 0]
        );

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

    public function getUsage(string $companyId): array
    {
        $sub = DB::table('subscriptions')->where('company_id', $companyId)->where('status', 'active')->first();
        $tier = $sub ? $sub->tier : 'starter';
        $limit = self::DAILY_QUOTA[$tier] ?? 0;

        $today = Carbon::today()->toDateString();
        $usage = ChatUsageDaily::where('company_id', $companyId)->where('date', $today)->first();
        $used = $usage ? $usage->message_count : 0;

        return [
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
        ];
    }

    public function createSession(string $companyId, string $userId, ?string $title): ChatSession
    {
        return ChatSession::create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'title' => $title ?: 'New Chat with Rex',
        ]);
    }

    public function listSessions(string $companyId): array
    {
        $sessions = DB::table('chat_sessions as cs')
            ->where('cs.company_id', $companyId)
            ->orderBy('cs.updated_at', 'desc')
            ->limit(20)
            ->select(
                'cs.id', 'cs.title', 'cs.created_at', 'cs.updated_at',
                DB::raw("(SELECT content FROM chat_messages WHERE session_id = cs.id ORDER BY created_at DESC LIMIT 1) AS last_message")
            )
            ->get();

        return $sessions->toArray();
    }

    public function getSession(string $companyId, string $sessionId): ?array
    {
        $session = ChatSession::where('id', $sessionId)->where('company_id', $companyId)->first();
        if (!$session) return null;

        $messages = ChatMessage::where('session_id', $sessionId)->orderBy('created_at', 'asc')->get();

        $sessionArray = $session->toArray();
        $sessionArray['messages'] = $messages->toArray();
        return $sessionArray;
    }

    public function deleteSession(string $companyId, string $sessionId): bool
    {
        $session = ChatSession::where('id', $sessionId)->where('company_id', $companyId)->first();
        if (!$session) return false;

        $session->delete();
        return true;
    }

    public function logUserMessage(string $companyId, string $sessionId, string $content): void
    {
        ChatMessage::create([
            'session_id' => $sessionId,
            'company_id' => $companyId,
            'role' => 'user',
            'content' => $content,
        ]);

        ChatSession::where('id', $sessionId)->update(['updated_at' => now()]);
    }

    public function logAssistantMessage(string $companyId, string $sessionId, string $content, ?array $structuredPayload): void
    {
        ChatMessage::create([
            'session_id' => $sessionId,
            'company_id' => $companyId,
            'role' => 'assistant',
            'content' => $content,
            'structured_payload' => $structuredPayload,
        ]);

        ChatSession::where('id', $sessionId)->update(['updated_at' => now()]);
    }

    public function getWorkspace(string $companyId, string $sessionId): array
    {
        $messages = ChatMessage::where('session_id', $sessionId)
            ->where('company_id', $companyId)
            ->where('role', 'assistant')
            ->orderBy('created_at')
            ->get();

        $artifacts = [];
        foreach ($messages as $message) {
            $payload = $message->structured_payload ?? [];
            foreach (($payload['artifacts'] ?? []) as $artifact) {
                $artifacts[$artifact['id'] ?? uniqid('artifact-')] = $artifact;
            }
        }

        $actions = RexPendingAction::where('session_id', $sessionId)
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->get();

        return [
            'artifacts' => array_values($artifacts),
            'actions' => $actions,
        ];
    }

    public function confirmAction(string $companyId, string $userId, string $sessionId, string $actionId): array
    {
        $action = RexPendingAction::where('id', $actionId)
            ->where('session_id', $sessionId)
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->first();

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

    public function rejectAction(string $companyId, string $userId, string $sessionId, string $actionId): array
    {
        $action = RexPendingAction::where('id', $actionId)
            ->where('session_id', $sessionId)
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->first();

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
