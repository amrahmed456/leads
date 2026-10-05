<?php

namespace App\Support\Normalization;

use App\Enums\Country;

final class PhoneNormalizer
{
    /**
     * Normalize a phone number to E.164 (e.g. +966501234567) where possible.
     *
     * A value holding several numbers ("050...; 011...") yields the first one
     * that can be normalized. If none can, but the value still looks like a
     * phone number, its digits are kept so the information is not lost.
     */
    public function normalize(?string $value, ?Country $country = null): ?string
    {
        $value = Text::asciiDigits(trim((string) $value));

        if ($value === '') {
            return null;
        }

        $fallback = null;

        foreach (preg_split('/[;,|\/\n]+/', $value) ?: [] as $candidate) {
            $candidate = $this->stripExtension($candidate);

            $normalized = $this->toE164($candidate, $country);

            if ($normalized !== null) {
                return $normalized;
            }

            $fallback ??= $this->digitsOnly($candidate);
        }

        return $fallback;
    }

    private function toE164(string $candidate, ?Country $country): ?string
    {
        $international = str_starts_with(ltrim($candidate), '+');
        $digits = (string) preg_replace('/\D+/', '', $candidate);

        if ($digits === '') {
            return null;
        }

        if (! $international && str_starts_with($digits, '00')) {
            $international = true;
            $digits = substr($digits, 2);
        }

        if ($international) {
            return $this->fromInternational($digits);
        }

        if ($country === null) {
            return null;
        }

        $code = $country->callingCode();
        $trunk = $country->trunkPrefix();

        $nationalCandidates = [$digits];

        // Calling code written without "+" or "00", e.g. 966501234567.
        if (str_starts_with($digits, $code)) {
            $nationalCandidates[] = substr($digits, strlen($code));
        }

        // Local format with trunk prefix, e.g. 0501234567.
        if ($trunk !== null && str_starts_with($digits, $trunk)) {
            $nationalCandidates[] = substr($digits, strlen($trunk));
        }

        foreach (array_reverse($nationalCandidates) as $national) {
            if (preg_match($country->nationalNumberPattern(), $national)) {
                return '+'.$code.$national;
            }
        }

        return null;
    }

    private function fromInternational(string $digits): ?string
    {
        foreach (Country::cases() as $country) {
            $code = $country->callingCode();

            if (! str_starts_with($digits, $code)) {
                continue;
            }

            $national = substr($digits, strlen($code));

            // "+966 (0)50..." — a trunk prefix that should not be dialled internationally.
            $trunk = $country->trunkPrefix();
            if ($trunk !== null && str_starts_with($national, $trunk)) {
                $national = substr($national, strlen($trunk));
            }

            return preg_match($country->nationalNumberPattern(), $national) ? '+'.$code.$national : null;
        }

        // Other countries: accept any plausible E.164 length.
        $length = strlen($digits);

        return $length >= 8 && $length <= 15 && $digits[0] !== '0' ? '+'.$digits : null;
    }

    private function stripExtension(string $candidate): string
    {
        return (string) preg_replace('/\s*(?:ext\.?|extension|x|#)\s*\d{1,6}\s*$/i', '', trim($candidate));
    }

    private function digitsOnly(string $candidate): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $candidate);
        $length = strlen($digits);

        if ($length < 6 || $length > 15) {
            return null;
        }

        return (str_starts_with(ltrim($candidate), '+') ? '+' : '').$digits;
    }
}
