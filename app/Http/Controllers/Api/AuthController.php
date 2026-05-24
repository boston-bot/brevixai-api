<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PasswordReset;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

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
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            report($e);

            return response()->json(['error' => 'Unable to create account. Please try again later.'], 500);
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

        if (! $user || ! Hash::check($request->input('password'), $user->password_hash)) {
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
        $company = $user->company;

        return response()->json([
            'id' => $user->id,
            'email' => $user->email,
            'firstName' => $user->first_name,
            'lastName' => $user->last_name,
            'companyId' => $user->company_id,
            'companyName' => $company ? $company->name : null,
            'role' => $user->role,
            'createdAt' => $user->created_at,
            'hasCompletedOnboarding' => (bool) ($company?->has_completed_onboarding ?? false),
        ]);
    }

    /**
     * POST /api/auth/forgot-password
     * Always returns a generic success response to prevent account enumeration.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', strtolower($request->input('email')))->first();

        if ($user) {
            PasswordReset::where('user_id', $user->id)->delete();

            $rawToken = Str::random(64);

            PasswordReset::create([
                'user_id' => $user->id,
                'token' => hash('sha256', $rawToken),
                'expires_at' => now()->addHour(),
                'used' => false,
            ]);
        }

        return response()->json(['message' => 'If an account with that email exists, a password reset link has been sent.']);
    }

    /**
     * POST /api/auth/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|min:8',
        ]);

        $record = PasswordReset::where('token', hash('sha256', $request->input('token')))
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            return response()->json(['error' => 'Invalid or expired password reset token.'], 422);
        }

        $user = $record->user;

        if (! $user) {
            return response()->json(['error' => 'Invalid or expired password reset token.'], 422);
        }

        DB::transaction(function () use ($user, $record, $request): void {
            $user->update(['password_hash' => Hash::make($request->input('password'))]);
            $record->update(['used' => true]);
            $user->tokens()->delete();
        });

        return response()->json(['message' => 'Password has been reset successfully.']);
    }

    /**
     * POST /api/auth/complete-onboarding
     */
    public function completeOnboarding(Request $request): JsonResponse
    {
        $company = $request->user()->company;

        if (! $company) {
            return response()->json(['error' => 'User is not associated with a company'], 422);
        }

        $company->has_completed_onboarding = true;
        $company->save();

        return response()->json(['success' => true]);
    }
}
