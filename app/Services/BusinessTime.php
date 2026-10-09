<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/** Calendar/reporting time is WIB; persisted timestamps remain UTC. */
final class BusinessTime
{
    public const TIMEZONE = 'Asia/Jakarta';

    public static function now(): Carbon
    {
        return Carbon::now(self::TIMEZONE);
    }

    public static function local($instant): Carbon
    {
        return Carbon::parse($instant)->setTimezone(self::TIMEZONE);
    }

    public static function utc($instant): Carbon
    {
        return Carbon::parse($instant)->utc();
    }

    public static function startOfDay($date): Carbon
    {
        $local = is_string($date) ? Carbon::parse($date, self::TIMEZONE) : self::local($date);
        return $local->setTimezone(self::TIMEZONE)->startOfDay()->utc();
    }

    public static function endOfDay($date): Carbon
    {
        $local = is_string($date) ? Carbon::parse($date, self::TIMEZONE) : self::local($date);
        return $local->setTimezone(self::TIMEZONE)->endOfDay()->utc();
    }
}
