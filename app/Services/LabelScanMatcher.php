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
            $this->customers[] = ['id' => (int) $c->id, 'words' => array_fill_keys($words, true), 'digits' => substr(preg_replace('/\D/', '', (string) $c->phone), -10)];
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
        return ['status' => 'ambiguous', 'user_id' => null];
    }
}
