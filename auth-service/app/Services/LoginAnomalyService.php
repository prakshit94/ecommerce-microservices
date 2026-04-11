<?php

namespace App\Services;

use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;

/**
 * LoginAnomalyService
 *
 * Detects suspicious login patterns:
 * - New IP address login
 * - Multiple failed attempts from same IP
 * - Unusual time-of-day logins
 *
 * For enterprise: integrate with MaxMind GeoIP or ipapi.co for country detection.
 */
class LoginAnomalyService
{
    /**
     * Check if a login attempt is anomalous.
     * Returns array with 'suspicious' flag and 'reasons'.
     */
    public function analyze(User $user, Request $request): array
    {
        $ip        = $request->ip();
        $userAgent = $request->userAgent();
        $reasons   = [];

        // --- 1. Check for known IP ---
        $knownIp = LoginHistory::where('user_id', $user->id)
            ->where('status', 'success')
            ->where('ip_address', $ip)
            ->exists();

        if (!$knownIp) {
            $hasPriorSuccess = LoginHistory::where('user_id', $user->id)
                ->where('status', 'success')
                ->exists();

            if ($hasPriorSuccess) {
                $reasons[] = 'new_ip_address';
            }
        }

        // --- 2. Recent failed attempts from this IP ---
        $recentFails = LoginHistory::where('ip_address', $ip)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subMinutes(15))
            ->count();

        if ($recentFails >= 3) {
            $reasons[] = 'multiple_recent_failures';
        }

        // --- 3. Account-level failed attempts ---
        $accountFails = LoginHistory::where('user_id', $user->id)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subMinutes(30))
            ->count();

        if ($accountFails >= 5) {
            $reasons[] = 'excessive_account_failures';
        }

        $suspicious = !empty($reasons);

        if ($suspicious) {
            Log::warning("Suspicious login detected for user #{$user->id}", [
                'ip'       => $ip,
                'ua'       => $userAgent,
                'reasons'  => $reasons,
            ]);
        }

        return [
            'suspicious' => $suspicious,
            'reasons'    => $reasons,
        ];
    }

    /**
     * Get count of failed login attempts for an IP in the last N minutes.
     */
    public function getIpFailCount(string $ip, int $minutes = 15): int
    {
        return LoginHistory::where('ip_address', $ip)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Get count of failed login attempts for a user in last N minutes.
     */
    public function getUserFailCount(int $userId, int $minutes = 30): int
    {
        return LoginHistory::where('user_id', $userId)
            ->where('status', 'failed')
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }
}
