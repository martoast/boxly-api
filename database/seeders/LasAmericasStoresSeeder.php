<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seed the in-person store catalog for Las Americas Premium Outlets.
 *
 * These are outlet-only brands the customer can pick from on the
 * /shop/in-person/stores step. Stores already in the Boxly Store catalog
 * (YoungLA, Chubbies, etc.) can be enabled for in-person shopping
 * separately via the admin UI by toggling is_in_person_available.
 *
 * Idempotent — runs on slug, skips existing rows.
 *
 *     php artisan db:seed --class=LasAmericasStoresSeeder
 */
class LasAmericasStoresSeeder extends Seeder
{
    public function run(): void
    {
        $brands = [
            ['name' => 'Coach Outlet',           'base_url' => 'https://www.coachoutlet.com'],
            ['name' => 'Michael Kors',           'base_url' => 'https://www.michaelkors.com'],
            ['name' => 'Nike Factory Store',     'base_url' => 'https://www.nike.com'],
            ['name' => 'Adidas Outlet',          'base_url' => 'https://www.adidas.com'],
            ['name' => 'Tommy Hilfiger',         'base_url' => 'https://usa.tommy.com'],
            ['name' => 'Calvin Klein',           'base_url' => 'https://www.calvinklein.us'],
            ['name' => 'Polo Ralph Lauren',      'base_url' => 'https://www.ralphlauren.com'],
            ["name" => "Levi's Outlet",          'base_url' => 'https://www.levi.com'],
            ['name' => 'Vans Outlet',            'base_url' => 'https://www.vans.com'],
            ['name' => 'Converse',               'base_url' => 'https://www.converse.com'],
            ['name' => 'Under Armour',           'base_url' => 'https://www.underarmour.com'],
            ['name' => 'Kate Spade',             'base_url' => 'https://www.katespade.com'],
            ['name' => 'Tory Burch',             'base_url' => 'https://www.toryburch.com'],
            ['name' => 'Guess Factory',          'base_url' => 'https://www.guessfactory.com'],
            ['name' => 'The North Face',         'base_url' => 'https://www.thenorthface.com'],
            ['name' => 'Columbia',               'base_url' => 'https://www.columbia.com'],
            ['name' => 'New Balance Factory',    'base_url' => 'https://www.newbalance.com'],
            ['name' => 'Skechers Outlet',        'base_url' => 'https://www.skechers.com'],
            ['name' => 'Sunglass Hut',           'base_url' => 'https://www.sunglasshut.com'],
            ['name' => "Carter's",               'base_url' => 'https://www.carters.com'],
        ];

        foreach ($brands as $i => $brand) {
            // Plain slug for the lookup so reruns find existing rows rather
            // than minting "-1", "-2" duplicates via generateUniqueSlug.
            Store::firstOrCreate(
                ['slug' => Str::slug($brand['name'])],
                [
                    'name'                   => $brand['name'],
                    'base_url'               => $brand['base_url'],
                    'is_active'              => true,
                    'show_on_landing'        => false,
                    'is_in_person_available' => true,
                    'sort_order'             => 100 + $i,
                ],
            );
        }
    }
}
