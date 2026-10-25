<?php

namespace App\Services;

class ReferralCodeGenerator
{
    private const string Alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    public function generate(): string
    {
        $characters = '';
        $lastIndex = strlen(self::Alphabet) - 1;

        for ($position = 0; $position < 6; $position++) {
            $characters .= self::Alphabet[random_int(0, $lastIndex)];
        }

        return 'REF-'.$characters;
    }
}
