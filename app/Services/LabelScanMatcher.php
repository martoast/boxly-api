<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Which customer a warehouse label names — CONFIDENT matches only (Alex, 2026-11-25): one single customer or nothing.
 *
 * The label's name ("BOXLY FABIOLA PEREZ", "FMG Gustavo Millán González") loses its accents and its Boxly / FMG prefix; what is
 * left must be at least two words, one of them a surname, and EVERY one of them must be in the customer's name ("ADELINA OROPEZA"
 * is "Adelina Oropeza Tapia"). One such customer → matched. Several → matched only when exactly one has that very name, or
 * when they all share one phone (one person, two accounts); otherwise ambiguous. Staff names (Boxly-bought orders: Velonie
 * Villalobos) → staff. Everything else → none. Measured on 839 real San Diego labels: 566 matched, 0 wrong in two samples of 25.
 *
 * A word is a surname when customers' names carry it after their first word more often than as their first word
 * ("Victor Hugo" alone names nobody for sure; "Oropeza", "Melo" do).
 */
class LabelScanMatcher
{
    const PREFIXES = ['BOXLY', 'NOXLY', 'FMG', 'FGM', 'FMS', 'BOX'];
    const NOISE = ['SUITE', 'STE', 'APT', 'CO', 'DE', 'DEL', 'LA', 'LAS', 'LOS', 'MX', 'USA'];
    const STAFF_WORDS = ['VELONIE', 'VILLALOBOS'];

    /** @var array<int, array{id:int, words:array<string,bool>, digits:string}> */
    private array $customers = [];
    /** @var array<string,bool> */
    private array $surnames = [];
    /** @var array<int, array<string,bool>> */
    private array $staff = [];

    public function __construct(?iterable $customers = null, ?iterable $staff = null)
    {
        $customers ??= User::where('role', User::ROLE_CUSTOMER)->get(['id', 'name', 'phone']);
        $staff ??= User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_EMPLOYEE])->get(['id', 'name']);
        $first = [];
        $later = [];
        foreach ($customers as $c) {
            $words = self::words((string) $c->name);
            if (! $words) {
                continue;
            }
            $first[$words[0]] = ($first[$words[0]] ?? 0) + 1;
            foreach (array_slice($words, 1) as $w) {
                $later[$w] = ($later[$w] ?? 0) + 1;
            }
            $this->customers[] = ['id' => (int) $c->id, 'words' => array_fill_keys($words, true), 'digits' => substr(preg_replace('/\D/', '', (string) $c->phone), -10),
                // the first (paternal) surname: second-to-last of 3+ words ("José Fernando GARCÍA Menéndez"), the last of two
                'paternal' => count($words) >= 3 ? $words[count($words) - 2] : (count($words) === 2 ? $words[1] : null)];
        }
        foreach (array_keys($first + $later) as $w) {
            if (($later[$w] ?? 0) > ($first[$w] ?? 0)) {
                $this->surnames[$w] = true;
            }
        }
        foreach ($staff as $s) {
            $words = self::words((string) $s->name);
            if (count($words) >= 2) {
                $this->staff[] = array_fill_keys($words, true);
            }
        }
    }

    /** Upper-case ASCII words of a name, accents gone, one-letter and filler words dropped. */
    public static function words(string $name): array
    {
        $ascii = strtoupper(Str::ascii($name));
        $out = [];
        foreach (preg_split('/[^A-Z]+/', $ascii) ?: [] as $w) {
            if (strlen($w) >= 2 && ! in_array($w, self::NOISE, true)) {
                $out[] = $w;
            }
        }
        return $out;
    }

    /** ['status' => matched|ambiguous|none|staff, 'user_id' => int|null] for one label name. */
    public function match(?string $recipientName): array
    {
        $strict = $this->strictMatch($recipientName);
        if (in_array($strict['status'], ['matched', 'staff'], true)) {
            return $strict;
        }
        return $this->typoMatch($recipientName) ?? $strict;
    }

    /**
     * LABELS WITH TYPOS (Suite 2026-11-25, half of a day unmatched: "MARIADNY PEA CASTRO" is Mariady Fernanda Peña Castro,
     * "Kenia Joel Rosales Macedo" is Kenia Joselin, "juan raul" is the account "Juan raul"). Still one customer or nothing. A
     * customer is a candidate when (a) the label is exactly their name, or, with at least one surname matching exactly, (b) the
     * label has 3+ words and every other word is a near-typo of one of theirs (edit distance <= 25%), or (c) 3+ words match
     * exactly and ONE stray word is left, the first name among the exact ones ("LUZ ANGEL SOLANO" is not Luis Angel Solano).
     * Measured on 859 San Diego labels: +33 matches, every one the same person. Null when no candidate.
     */
    private function typoMatch(?string $recipientName): ?array
    {
        $words = self::words((string) $recipientName);
        if ($words && ! in_array($words[0], self::PREFIXES, true) && strlen($words[0]) >= 4 && self::similarity($words[0], 'BOXLY') >= 0.8) {
            array_shift($words); // "EOXLY", a misread prefix
        }
        $words = array_values(array_diff($words, self::PREFIXES));
        $label = array_fill_keys($words, true);
        if (count($label) < 2 || array_intersect_key($label, array_fill_keys(self::STAFF_WORDS, true))) {
            return null;
        }
        $hits = [];
        foreach ($this->customers as $c) {
            $exact = array_intersect_key($label, $c['words']);
            if (count($exact) === count($label) && count($label) === count($c['words'])) {
                $hits[] = $c;
                continue;
            }
            if (! array_intersect_key($exact, $this->surnames)) {
                continue;
            }
            $miss = array_keys(array_diff_key($label, $c['words']));
            $typos = count($label) >= 3 && ! array_filter($miss, fn ($w) => ! self::nearWord($w, array_keys($c['words'])));
            $oneStray = count($exact) >= 3 && count($miss) === 1 && isset($c['words'][$words[0]]);
            if ($typos || $oneStray) {
                $hits[] = $c;
            }
        }
        if (count($hits) === 1) {
            return ['status' => 'matched', 'user_id' => $hits[0]['id']];
        }
        if (! $hits) {
            return null;
        }
        $phones = array_unique(array_column($hits, 'digits'));
        if (count($phones) === 1 && strlen($phones[0]) === 10) {
            return ['status' => 'matched', 'user_id' => max(array_column($hits, 'id'))];
        }
        return $this->firstSurnameTie($hits, $words ?? array_keys($label));
    }

    /**
     * THE LAST TIE (Alex 2026-11-25, "BOXLY FERNANDO GARCIA" is José Fernando García Menéndez, not Fernando Antonio Guevara
     * García): a label is "first name + FIRST surname", so when exactly one candidate's first (paternal) surname is the label's
     * last word, it is that one. Otherwise ambiguous.
     */
    private function firstSurnameTie(array $hits, array $labelWords): array
    {
        $last = $labelWords ? end($labelWords) : null;
        $pick = array_values(array_filter($hits, fn ($c) => $last !== null && $c['paternal'] === $last));
        return count($pick) === 1 ? ['status' => 'matched', 'user_id' => $pick[0]['id']] : ['status' => 'ambiguous', 'user_id' => null];
    }

    private static function similarity(string $a, string $b): float
    {
        return 1 - levenshtein($a, $b) / max(strlen($a), strlen($b), 1);
    }

    private static function nearWord(string $w, array $customerWords): bool
    {
        if (strlen($w) < 3) {
            return false;
        }
        foreach ($customerWords as $c) {
            if (self::similarity($w, $c) >= 0.75) {
                return true;
            }
        }
        return false;
    }

    /** The strict pass: every label word in the customer's name, a surname among them (see the class comment). */
    private function strictMatch(?string $recipientName): array
    {
        $label = array_fill_keys(array_diff(self::words((string) $recipientName), self::PREFIXES), true);
        if (array_intersect_key($label, array_fill_keys(self::STAFF_WORDS, true))) {
            return ['status' => 'staff', 'user_id' => null];
        }
        foreach ($this->staff as $staffWords) {
            if (count($label) >= 2 && ! array_diff_key($label, $staffWords)) {
                return ['status' => 'staff', 'user_id' => null];
            }
        }
        if (count($label) < 2 || ! array_intersect_key($label, $this->surnames)) {
            return ['status' => 'none', 'user_id' => null];
        }
        $hits = array_values(array_filter($this->customers, fn ($c) => ! array_diff_key($label, $c['words'])));
        if (count($hits) === 1) {
            return ['status' => 'matched', 'user_id' => $hits[0]['id']];
        }
        if (! $hits) {
            return ['status' => 'none', 'user_id' => null];
        }
        $exact = array_values(array_filter($hits, fn ($c) => count($c['words']) === count($label)));
        if (count($exact) === 1) {
            return ['status' => 'matched', 'user_id' => $exact[0]['id']];
        }
        $phones = array_unique(array_column($hits, 'digits'));
        if (count($phones) === 1 && strlen($phones[0]) === 10) {
            return ['status' => 'matched', 'user_id' => max(array_column($hits, 'id'))];
        }
        return $this->firstSurnameTie($hits, $words ?? array_keys($label));
    }
}
