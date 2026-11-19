<?php

namespace App\Services;

use App\Models\Affiliate;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ManagerPinVerificationService
{
    public const MAX_ATTEMPTS = 5;
    public const DECAY_SECONDS = 300; // 5 minutes

    /**
     * Verify a Manager override PIN with strict business + branch role scoping and rate limiting.
     *
     * Rules:
     * - Candidate must be active (`is_active = true`) and have a non-null `manager_override_pin`.
     * - Candidate must belong to `$businessId` with role `owner` or `admin` (via `business_members`,
     *   or legacy `users.role IN ('owner', 'admin')` within the same business when no `business_members` rows exist).
     * - If role is `owner`, they can authorize any branch in the business.
     * - If role is `admin`, they must either be assigned to `$branchId` (`users.affiliate_id = $branchId` or
     *   `branch_members.affiliate_id = $branchId`), or have no branch restriction (`users.affiliate_id IS NULL`
     *   and zero `branch_members` rows).
     * - Applies `RateLimiter` (5 attempts per 5 minutes per user/branch).
     *
     * @throws HttpResponseException (429) on brute-force lockout
     * @throws ValidationException (422) on missing/invalid PIN
     */
    public static function verify(
        User $actor,
        ?string $pin,
        ?int $businessId,
        ?int $branchId,
        string $missingPinMessage = 'Otorisasi PIN Manager diperlukan untuk tindakan ini.',
        ?string $ipAddress = null,
        string $auditContext = 'ManagerOverride'
    ): User {
        if (empty($pin)) {
            throw ValidationException::withMessages([
                'manager_pin' => [$missingPinMessage],
            ]);
        }

        if (!$businessId && $branchId) {
            $branch = Affiliate::withoutGlobalScopes()->find($branchId);
            $businessId = $branch?->business_id ? (int) $branch->business_id : null;
        }

        $throttleKey = sprintf(
            'manager_pin_verify:biz_%d:branch_%d:user_%s',
            $businessId ?? 0,
            $branchId ?? 0,
            (string) $actor->id
        );

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            AuditLogService::log(
                $actor,
                'security.pin_brute_force_lockout',
                $auditContext,
                'LOCKOUT',
                ['seconds_remaining' => $seconds, 'ip' => $ipAddress],
                null,
                $ipAddress,
                $branchId
            );

            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Terlalu banyak percobaan PIN Manager yang salah. Akses PIN dikunci sementara. Silakan coba lagi dalam ' . max(1, (int) ceil($seconds / 60)) . ' menit.',
                'code' => 'PIN_RATE_LIMITED',
                'retry_after' => $seconds,
            ], 429));
        }

        $candidatesQuery = User::query()
            ->where('is_active', true)
            ->whereNotNull('manager_override_pin')
            ->with(['businessMembers' => function ($q) use ($businessId) {
                $q->where('is_active', true);
                if ($businessId) {
                    $q->where('business_id', $businessId);
                }
            }, 'businessMembers.branchMembers']);

        if ($businessId) {
            $candidatesQuery->where(function ($outer) use ($businessId, $branchId) {
                // Standard multi-tenant membership with explicit owner/admin role + branch scope
                $outer->whereHas('businessMembers', function ($bm) use ($businessId, $branchId) {
                    $bm->where('business_id', $businessId)
                        ->where('is_active', true)
                        ->where(function ($roleQ) use ($branchId) {
                            $roleQ->where('role', 'owner')
                                ->orWhere(function ($adminQ) use ($branchId) {
                                    $adminQ->where('role', 'admin');
                                    if ($branchId) {
                                        $adminQ->where(function ($branchScope) use ($branchId) {
                                            $branchScope->whereHas('user', fn ($uq) => $uq->where('affiliate_id', $branchId))
                                                ->orWhereHas('branchMembers', fn ($brm) => $brm->where('affiliate_id', $branchId))
                                                ->orWhere(function ($unrestricted) {
                                                    $unrestricted->whereHas('user', fn ($uq) => $uq->whereNull('affiliate_id'))
                                                        ->whereDoesntHave('branchMembers');
                                                });
                                        });
                                    }
                                });
                        });
                })
                // Legacy fallback: direct user role owner/admin when no business_members entry exists
                ->orWhere(function ($legacy) use ($businessId, $branchId) {
                    $legacy->whereDoesntHave('businessMembers', fn ($bm) => $bm->where('business_id', $businessId))
                        ->whereIn('role', ['owner', 'admin'])
                        ->where(function ($bizScope) use ($businessId, $branchId) {
                            $bizScope->where('role', 'owner')
                                ->whereHas('ownedBusinesses', fn ($ob) => $ob->where('id', $businessId));
                            if ($branchId) {
                                $bizScope->orWhere(function ($adminBranch) use ($businessId, $branchId) {
                                    $adminBranch->where('affiliate_id', $branchId)
                                        ->whereHas('affiliate', fn ($aq) => $aq->where('business_id', $businessId));
                                });
                            }
                        });
                });
            });
        } else {
            $candidatesQuery->where(function ($q) use ($branchId) {
                $q->where('role', 'owner')
                    ->orWhere(function ($adminQ) use ($branchId) {
                        $adminQ->where('role', 'admin');
                        if ($branchId) {
                            $adminQ->where(function ($bs) use ($branchId) {
                                $bs->where('affiliate_id', $branchId)->orWhereNull('affiliate_id');
                            });
                        }
                    });
            });
        }

        $candidates = $candidatesQuery->get();

        $matchingManager = $candidates->first(function (User $mgr) use ($pin) {
            return password_verify($pin, (string) $mgr->manager_override_pin);
        });

        if (!$matchingManager) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            $remaining = max(0, self::MAX_ATTEMPTS - RateLimiter::attempts($throttleKey));
            $suffix = $remaining > 0
                ? " Sisa percobaan: {$remaining} kali."
                : ' Akses PIN dikunci sementara selama 5 menit.';

            throw ValidationException::withMessages([
                'manager_pin' => ['PIN Manager Cabang tidak valid.' . $suffix],
            ]);
        }

        RateLimiter::clear($throttleKey);

        return $matchingManager;
    }
}
