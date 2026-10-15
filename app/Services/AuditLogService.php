<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogService
{
    /**
     * Record an immutable audit log entry.
     */
    public static function log(
        ?User $user,
        string $action,
        string $entityType,
        string|int $entityId,
        ?array $newValues = null,
        ?array $oldValues = null,
        ?string $ipAddress = null,
        ?int $affiliateId = null
    ): AuditLog {
        $businessId = app(\App\Tenancy\TenantContext::class)->getBusinessId();
        if (!$businessId && $user) {
            $businessId = $user->businesses()->first()?->id;
        }
        if (!$businessId && $affiliateId) {
            $businessId = \App\Models\Affiliate::withoutGlobalScopes()->find($affiliateId)?->business_id;
        }

        return AuditLog::create([
            'business_id' => $businessId,
            'user_id' => $user?->id,
            'affiliate_id' => $affiliateId ?? $user?->affiliate_id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string)$entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress ?? request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
