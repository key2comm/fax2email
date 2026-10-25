<?php

namespace App\Support;

/**
 * Fax numbers are stored as digits with the country code (e.g. 15551234567).
 * 10-digit numbers are taken to be North American and get a leading 1.
 */
class PhoneNumber
{
    /**
     * @return string|null Digits with country code, or null if it can't be a phone number.
     */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/\D/', '', $input);
        if (strlen($digits) === 10) {
            $digits = '1'.$digits;
        }

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? $digits : null;
    }

    /**
     * A readable form, e.g. +1 (555) 123-4567.
     */
    public static function format(?string $digits): string
    {
        if ($digits === null || $digits === '') {
            return '';
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return sprintf('+1 (%s) %s-%s', substr($digits, 1, 3), substr($digits, 4, 3), substr($digits, 7));
        }

        return strlen($digits) > 7 ? "+{$digits}" : $digits;
    }
}
