<?php

namespace App\Support\Normalization;

final class EmailNormalizer
{
    /**
     * Return the first valid, lowercased e-mail address in the value.
     */
    public function normalize(?string $value): ?string
    {
        foreach (preg_split('/[;,\s]+/', trim((string) $value)) ?: [] as $candidate) {
            $candidate = (string) preg_replace('/^mailto:/i', '', $candidate);
            $candidate = mb_strtolower(explode('?', $candidate, 2)[0]);

            if ($candidate !== '' && mb_strlen($candidate) <= 255 && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return null;
    }
}
