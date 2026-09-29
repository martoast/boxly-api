<?php

namespace Tests\Unit;

use App\Models\CartItem;
use PHPUnit\Framework\TestCase;

class CartItemCleanTitleTest extends TestCase
{
    public function test_a_trailing_product_code_or_store_suffix_is_dropped(): void
    {
        $this->assertSame("Carhartt Women's Cuffed Rib Knit Beanie", CartItem::cleanTitle("Carhartt Women's Cuffed Rib Knit Beanie (105560)"));
        $this->assertSame("Women's Cuffed Rib Knit Beanie", CartItem::cleanTitle("Women's Cuffed Rib Knit Beanie | Carhartt"));
        $this->assertSame('Nike Air Max 90', CartItem::cleanTitle('Nike Air Max 90 [SKU DH4115-100]'));
        $this->assertSame('Carhartt Pocket Tee', CartItem::cleanTitle('Carhartt Pocket Tee Style #K87'));
        $this->assertSame('Adidas Samba OG', CartItem::cleanTitle('Adidas Samba OG (B75806)'));
    }

    public function test_sizes_years_colours_and_names_with_codes_are_kept(): void
    {
        foreach (['Apple iPhone 15 (128GB)', 'Apple iPad (2022)', 'Owala FreeSip (24oz)', 'Hoodie (Black)', 'Iconic K87 Pocket T-Shirt',
            'Gymshark Crest Joggers - Light Grey Marl', 'Stanley Quencher H2.0 FlowState Tumbler 40 oz', '(12345)'] as $title) {
            $this->assertSame($title, CartItem::cleanTitle($title));
        }
    }
}
