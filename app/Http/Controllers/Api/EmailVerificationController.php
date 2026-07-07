<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * GET /api/auth/verify-email/{id}/{hash}
     *
     * Landing target for the signed link in the verification email. The user
     * may not be logged in on the device that opens the link, so this route
     * is signed rather than authenticated, and redirects to the frontend.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/');

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect($frontendUrl.'/email-verified?status=invalid');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect($frontendUrl.'/email-verified?status=already-verified');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return redirect($frontendUrl.'/email-verified?status=success');
    }

    /**
     * POST /api/auth/resend-verification
     */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email is already verified.']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['message' => 'Verification email sent.']);
    }
}
