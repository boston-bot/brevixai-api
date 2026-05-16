<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * POST /api/auth/signup
     */
    public function signup(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:8',
            'companyName' => 'required|string',
            'firstName' => 'nullable|string',
            'lastName' => 'nullable|string',
            'tier' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // 1. Create Company
            $company = Company::create([
                'id' => (string) Str::uuid(),
                'name' => $request->input('companyName'),
            ]);

            // 2. Setup Subscription
            $tier = $request->input('tier') ?? 'starter';
            if ($tier === 'accounting-firm') {
                $tier = 'accounting';
            }

            Subscription::create([
                'company_id' => $company->id,
                'tier' => $tier,
                'status' => 'active',
            ]);

            // 3. Create User
            $user = User::create([
                'id' => (string) Str::uuid(),
                'email' => strtolower($request->input('email')),
                'password_hash' => Hash::make($request->input('password')),
                'first_name' => $request->input('firstName'),
                'last_name' => $request->input('lastName'),
                'company_id' => $company->id,
                'role' => 'owner',
            ]);

            DB::commit();

            // 4. Generate Sanctum Token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'firstName' => $user->first_name,
                    'lastName' => $user->last_name,
                    'companyId' => $user->company_id,
                    'role' => $user->role,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Internal server error', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', strtolower($request->input('email')))->first();

        if (!$user || !Hash::check($request->input('password'), $user->password_hash)) {
            return response()->json(['error' => 'Invalid email or password'], 401);
        }

        $user->update(['last_login_at' => now()]);

        // Revoke all existing tokens to enforce single session (optional, but matching old JWT behavior of replacing tokens)
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'firstName' => $user->first_name,
                'lastName' => $user->last_name,
                'companyId' => $user->company_id,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    /**
     * GET /api/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('company');

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'companyId' => $user->company_id,
            'companyName' => $user->company ? $user->company->name : null,
            'role' => $user->role,
            'createdAt' => $user->created_at,
            'hasCompletedOnboarding' => (bool) $user->has_completed_onboarding,
        ]);
    }

    /**
     * POST /api/auth/complete-onboarding
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->has_completed_onboarding = true;
        $user->save();

        return response()->json(['success' => true]);
    }
}
