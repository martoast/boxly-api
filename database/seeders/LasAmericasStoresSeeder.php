<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seed the in-person store catalog for Las Americas Premium Outlets.
 *
 * Source list scraped from premiumoutlets.com/outlet/las-americas/stores
 * (2026-05-18) — filtered to physical-goods retail only. The mall has
 * 163 tenants total; food courts, restaurants, cafés, banks, telecom,
 * tax services, nail salons, postal, duty-free, vape, and similar
 * non-commerce tenants are explicitly excluded — Boxly only forwards
 * physical merchandise (apparel, footwear, accessories, jewelry,
 * watches, sunglasses, beauty, perfume, toys, electronics, household).
 * That leaves 126 stores in the picker.
 *
 * Logos are intentionally left null. Admin can upload via the existing
 * /app/admin/stores/{id}/edit page; the picker shows a colored
 * letter-circle for any store without a logo.
 *
 * Idempotent. updateOrCreate keyed on slug so reruns refresh names /
 * URLs without minting duplicates. Two defensive cleanups at the top:
 *  - prototype ghosts (the 20-brand placeholder list from the earlier
 *    seed): flip is_in_person_available=false
 *  - non-retail tenants (food, services, etc. that may have been added
 *    by a previous version of this seeder): same flip
 * Flag flips preserve PR<->store FK links — no data loss.
 *
 *     php artisan db:seed --class=LasAmericasStoresSeeder
 */
class LasAmericasStoresSeeder extends Seeder
{
    /** Prototype seed (2026-05-18) — slugs absent from the real directory. */
    private const PROTOTYPE_GHOSTS = [
        'michael-kors',
        'adidas-outlet',
        'polo-ralph-lauren',
        'levis-outlet',
        'under-armour',
        'kate-spade',
        'tory-burch',
        'columbia',
        'new-balance-factory',
        'skechers-outlet',
        'carters',
    ];

    /**
     * Non-retail tenants at Las Americas — food courts, restaurants,
     * cafés, banks, telecom, services, duty-free, vape, postal, etc.
     * Excluded from the picker; Boxly only forwards physical goods.
     */
    private const NON_RETAIL_NAMES = [
        'Achiote',
        'Al Chile',
        'Amerinails',
        'AT&T',
        'Border X Change',
        'Carl\'s Jr.',
        'Charleys Philly Steaks',
        'Churros El Tigre',
        'Cinnabon',
        'Daboba',
        'Deli & Go',
        'Famous Wok',
        'FiveO3 Pupusas',
        'Food Court',
        'H & R Block',
        'IHOP-International House of Pancakes',
        'Jamba Juice',
        'La Cucina',
        'Management Office',
        'McDonald\'s',
        'Nori Japan',
        'Panda Express',
        'Play Area',
        'Sbarro',
        'Seoul Street',
        'Snack Stop 2',
        'Spirit Postal',
        'Starbucks Coffee',
        'Sweet Munchies',
        'The Coffee Bean & Tea Leaf',
        'The TransFronterizo Institute',
        'The Vape Firm',
        'U.S. Bank',
        'UETA Club',
        'UETA Duty Free',
        'Wetzel\'s Pretzels',
        'Wetzel\'s Pretzels Kiosk',
    ];

    public function run(): void
    {
        $ghosts = array_merge(
            self::PROTOTYPE_GHOSTS,
            array_map(fn ($n) => Str::slug($n), self::NON_RETAIL_NAMES),
        );

        DB::table('stores')
            ->whereIn('slug', $ghosts)
            ->where('is_in_person_available', true)
            ->update(['is_in_person_available' => false]);

        $brands = [
            ['name' => '7 for All Mankind', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/7-for-all-mankind'],
            ['name' => 'Abercrombie & Fitch Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/abercrombie-fitch-outlet'],
            ['name' => 'Abercrombie Kids', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/abercrombie-kids'],
            ['name' => 'adidas Outlet Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/adidas-outlet-store'],
            ['name' => 'Aerie', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/aerie'],
            ['name' => 'Aeropostale', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/aeropostale'],
            ['name' => 'ALDO Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/aldo-outlet'],
            ['name' => 'American Eagle', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/american-eagle'],
            ['name' => 'Ann Taylor Factory Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/ann-taylor-factory-store'],
            ['name' => 'Ariat', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/ariat'],
            ['name' => 'Armani Exchange Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/armani-exchange-outlet'],
            ['name' => 'Banana Republic Factory Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/banana-republic-factory-store'],
            ['name' => 'Banter by Piercing Pagoda', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/banter-by-piercing-pagoda'],
            ['name' => 'Bath & Body Works', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/bath-body-works'],
            ['name' => 'Beautiqe', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/beautiqe'],
            ['name' => 'Bohemian Corner', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/bohemian-corner'],
            ['name' => 'BOSS Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/boss-outlet'],
            ['name' => 'BoxLunch', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/boxlunch'],
            ['name' => 'Brooks Brothers Factory Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/brooks-brothers-factory-store'],
            ['name' => 'Calvin Klein', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/calvin-klein'],
            ['name' => 'Centenario Jewelry and Coin Shop', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/centenario-jewelry-and-coin-shop'],
            ['name' => 'Chico\'s Off The Rack', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/chicos-off-the-rack'],
            ['name' => 'Chrono Toys', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/chrono-toys'],
            ['name' => 'City Kicks', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/city-kicks'],
            ['name' => 'Claire\'s', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/claires'],
            ['name' => 'Clarks', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/clarks'],
            ['name' => 'Coach Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/coach-outlet'],
            ['name' => 'Cole Haan Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/cole-haan-outlet'],
            ['name' => 'Columbia Factory Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/columbia-factory-store'],
            ['name' => 'Converse', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/converse'],
            ['name' => 'Crocs', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/crocs'],
            ['name' => 'Daniel\'s Jewelers', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/daniels-jewelers'],
            ['name' => 'Disney Store Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/disney-store-outlet'],
            ['name' => 'Don Roberto Jewelers', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/don-roberto-jewelers'],
            ['name' => 'Elegante Menswear', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/elegante-menswear'],
            ['name' => 'Eleganza Women’s Fashion', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/eleganza-womens-fashion'],
            ['name' => 'Express Factory Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/express-factory-outlet'],
            ['name' => 'Fabletics', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/fabletics'],
            ['name' => 'Foot Locker', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/foot-locker'],
            ['name' => 'Gadget Station', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/gadget-station'],
            ['name' => 'GameStop', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/gamestop'],
            ['name' => 'Gap Factory', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/gap-factory'],
            ['name' => 'Grupo Concordia', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/grupo-concordia'],
            ['name' => 'GUESS Accessories', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/guess-accessories'],
            ['name' => 'GUESS Factory', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/guess-factory'],
            ['name' => 'H & M', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/h-m'],
            ['name' => 'Hollister Co.', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/hollister-co'],
            ['name' => 'Hot Topic', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/hot-topic'],
            ['name' => 'HUGO Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/hugo-outlet'],
            ['name' => 'Hurley', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/hurley'],
            ['name' => 'Inspire Shoes', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/inspire-shoes'],
            ['name' => 'J.Crew Factory', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/jcrew-factory'],
            ['name' => 'J.Crew Factory Crewcuts', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/jcrew-factory-crewcuts'],
            ['name' => 'Janie and Jack', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/janie-and-jack'],
            ['name' => 'JD', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/jd'],
            ['name' => 'Journeys', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/journeys'],
            ['name' => 'Journeys Kidz', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/journeys-kidz'],
            ['name' => 'Karl Lagerfeld Paris', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/karl-lagerfeld-paris'],
            ['name' => 'Kate Spade New York Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/kate-spade-new-york-outlet'],
            ['name' => 'Kays Jewelers Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/kays-jewelers-outlet'],
            ['name' => 'Kipling', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/kipling'],
            ['name' => 'La Fragancia', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/la-fragancia'],
            ['name' => 'LACOSTE Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/lacoste-outlet'],
            ['name' => 'Last Chance Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/last-chance-store'],
            ['name' => 'Levi\'s® Outlet Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/levis-outlet-store'],
            ['name' => 'Lids', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/lids'],
            ['name' => 'Lids 2nd Location', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/lids-2nd-location'],
            ['name' => 'LOFT Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/loft-outlet'],
            ['name' => 'Luggage Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/luggage-outlet'],
            ['name' => 'lululemon-pop up', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/lululemon-pop-up'],
            ['name' => 'Manga Spot', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/manga-spot'],
            ['name' => 'Marc Jacobs', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/marc-jacobs'],
            ['name' => 'MAUBER JEWELRY', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/mauber-jewelry'],
            ['name' => 'Men\'s District', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/mens-district'],
            ['name' => 'Michael Kors Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/michael-kors-outlet'],
            ['name' => 'MINISO', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/miniso'],
            ['name' => 'Movado', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/movado'],
            ['name' => 'Nautica', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/nautica'],
            ['name' => 'New Balance Factory Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/new-balance-factory-store'],
            ['name' => 'NIKE Factory Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/nike-factory-store'],
            ['name' => 'O Neill', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/o-neill'],
            ['name' => 'Oakley', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/oakley'],
            ['name' => 'Old Navy Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/old-navy-outlet'],
            ['name' => 'OndadeMar', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/ondademar'],
            ['name' => 'Original Penguin', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/original-penguin'],
            ['name' => 'Pandora', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/pandora'],
            ['name' => 'Perry Ellis', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/perry-ellis'],
            ['name' => 'Polo Ralph Lauren Factory Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/polo-ralph-lauren-factory-store'],
            ['name' => 'Psycho Bunny', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/psycho-bunny'],
            ['name' => 'Puma Kids', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/puma-kids'],
            ['name' => 'PUMA Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/puma-outlet'],
            ['name' => 'Racing Miami', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/racing-miami'],
            ['name' => 'Rack Room Shoes', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/rack-room-shoes'],
            ['name' => 'Samsonite', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/samsonite'],
            ['name' => 'SD Jewelers', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/sd-jewelers'],
            ['name' => 'Shoe Palace', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/shoe-palace'],
            ['name' => 'Signature Perfume', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/signature-perfume'],
            ['name' => 'Skechers', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/skechers'],
            ['name' => 'Sports Treasures', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/sports-treasures'],
            ['name' => 'Steve Madden', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/steve-madden'],
            ['name' => 'Sunglass Hut', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/sunglass-hut'],
            ['name' => 'Sunglass Hut (Plaza)', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/sunglass-hut-plaza'],
            ['name' => 'Sunglass Hut International & Watch Stop', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/sunglass-hut-international-watch-stop'],
            ['name' => 'Sunglass Plus #1', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/sunglass-plus-1'],
            ['name' => 'Swarovski', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/swarovski'],
            ['name' => 'Tactical', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/tactical'],
            ['name' => 'The Children\'s Place', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/the-childrens-place'],
            ['name' => 'The Cosmetics Company Store', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/the-cosmetics-company-store'],
            ['name' => 'The North Face', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/the-north-face'],
            ['name' => 'The Uniform Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/the-uniform-outlet'],
            ['name' => 'Tillys', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/tillys'],
            ['name' => 'Timberland', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/timberland'],
            ['name' => 'Tommy Hilfiger', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/tommy-hilfiger'],
            ['name' => 'True Religion Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/true-religion-outlet'],
            ['name' => 'U.S. Cosmetica', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/us-cosmetica'],
            ['name' => 'U.S. Polo Assn.', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/us-polo-assn'],
            ['name' => 'U.S. Sportswear', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/us-sportswear'],
            ['name' => 'Under Armour Factory House', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/under-armour-factory-house'],
            ['name' => 'Vans Outlet', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/vans-outlet'],
            ['name' => 'Victoria\'s Secret', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/victorias-secret'],
            ['name' => 'Vilebrequin', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/vilebrequin'],
            ['name' => 'Vitamin World', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/vitamin-world'],
            ['name' => 'Watch Station International', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/watch-station-international'],
            ['name' => 'Windsor', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/windsor'],
            ['name' => 'Zadig & Voltaire', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/zadig-voltaire'],
            ['name' => 'Zumiez', 'base_url' => 'https://www.premiumoutlets.com/outlet/las-americas/stores/zumiez'],
        ];

        foreach ($brands as $i => $brand) {
            Store::updateOrCreate(
                ['slug' => Str::slug($brand['name'])],
                [
                    'name'                   => $brand['name'],
                    'base_url'               => $brand['base_url'],
                    'is_active'              => true,
                    'is_in_person_available' => true,
                    'sort_order'             => 100 + $i,
                ],
            );
        }
    }
}
