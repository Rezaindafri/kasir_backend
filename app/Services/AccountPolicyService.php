<?php

namespace App\Services;

use App\Models\SystemSetting;

class AccountPolicyService
{
    public const PASSWORD_MIN_LENGTH_KEY = 'account_password_min_length';
    public const DEFAULT_PASSWORD_MIN_LENGTH = 6;

    public function passwordMinLength(): int
    {
        $value = SystemSetting::query()
            ->where('key', self::PASSWORD_MIN_LENGTH_KEY)
            ->value('value');

        return max(6, min(128, (int) ($value ?? self::DEFAULT_PASSWORD_MIN_LENGTH)));
    }

    public function setPasswordMinLength(int $length): int
    {
        $length = max(6, min(128, $length));
        SystemSetting::query()->updateOrCreate(
            ['key' => self::PASSWORD_MIN_LENGTH_KEY],
            ['value' => (string) $length],
        );

        return $length;
    }
}
