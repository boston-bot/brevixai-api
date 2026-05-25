<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $configs = DB::table('notification_configs')
            ->where('company_id', $companyId)
            ->get(['id', 'channel', 'config', 'events', 'enabled'])
            ->map(function (object $row): array {
                return [
                    'id'      => $row->id,
                    'channel' => $row->channel,
                    'config'  => json_decode((string) $row->config, true) ?? [],
                    'events'  => json_decode((string) $row->events, true) ?? [],
                    'enabled' => (bool) $row->enabled,
                ];
            });

        return response()->json(['notification_configs' => $configs]);
    }

    public function update(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'channel'         => 'required|string|in:slack,email,sms',
            'config'          => 'required|array',
            'config.webhook_url' => 'nullable|url',
            'config.email'    => 'nullable|email',
            'config.phone'    => 'nullable|string|max:20',
            'events'          => 'required|array',
            'events.*'        => 'string|in:alert_created,case_created,recommendation_approved',
            'enabled'         => 'required|boolean',
        ]);

        DB::table('notification_configs')->upsert(
            [
                'company_id' => $companyId,
                'channel'    => $validated['channel'],
                'config'     => json_encode($validated['config']),
                'events'     => json_encode($validated['events']),
                'enabled'    => $validated['enabled'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
            ['company_id', 'channel'],
            ['config', 'events', 'enabled', 'updated_at'],
        );

        return response()->json(['message' => 'Notification config saved.']);
    }
}
