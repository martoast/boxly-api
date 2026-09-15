<?php

namespace Tests\Feature;

use App\Http\Controllers\CatalogController;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\LiveShoppingTestCase;

/**
 * A merchant that cannot ship to San Ysidro is not an option.
 *
 * Boxly's whole mechanism is a US address that receives the purchase, so a storefront
 * outside the US is not a cheaper version of the same offer — it is an offer the customer
 * cannot take. Google Shopping also returns "merchants" that are not shops at all.
 *
 * Every case below is a real merchant string SerpAPI returned to a real customer between
 * 2026-07-17 and 2026-09-15.
 *
 *   vendor/bin/phpunit tests/Feature/UnshippableMerchantTest.php
 */
class UnshippableMerchantTest extends LiveShoppingTestCase
{
    public static function unshippable(): array
    {
        return [
            'German retailer quoted for a Karl Lagerfeld tee' => ['ravir.de'],
            'German site quoted for New Balance'              => ['dielenparty.de'],
            'Japanese consultancy quoted for an Owala bottle' => ['management30.jp'],
            'New Zealand site quoted for an Owala bottle'     => ['onto-it.nz'],
            'a South African DAYCARE quoted for Gymshark'     => ['risingstardaycare.co.za'],
            'Basque ccTLD'                                    => ['denda.eus'],
            'UK'                                              => ['johnlewis.co.uk'],
            'Mexico — the country we import INTO'             => ['liverpool.com.mx'],
            'Canada is still not a US delivery'               => ['sportchek.ca'],
            'case is irrelevant'                              => ['RAVIR.DE'],
            'surrounding whitespace is irrelevant'            => ['  ravir.de  '],
        ];
    }

    #[DataProvider('unshippable')]
    public function test_a_non_us_storefront_never_reaches_the_shopper(string $merchant): void
    {
        $this->assertTrue(CatalogController::isUnshippableMerchant($merchant), $merchant);
    }

    public static function shippable(): array
    {
        return [
            'the overwhelming majority are plain names' => ['Amazon'],
            'a plain name says nothing about country'   => ['Red Tool Store'],
            'and neither does one with a dot in it'     => ['Ohio Power Tool'],
            'US .com'                                   => ['walmart.com'],
            'US .com with a subdomain'                  => ['shop.nike.com'],
            'a Shopify storefront'                      => ['younglabrands.myshopify.com'],
            'US supplier domain'                        => ['hdsupplysolutions.com'],
            'other gTLDs are not country codes'         => ['gymshark.store'],
            'nor is .shop'                              => ['owala.shop'],
            'empty is not a verdict'                    => [''],
        ];
    }

    #[DataProvider('shippable')]
    public function test_a_us_merchant_is_never_dropped(string $merchant): void
    {
        $this->assertFalse(CatalogController::isUnshippableMerchant($merchant), $merchant);
    }

    public function test_a_merchant_that_is_a_bare_country_word_is_not_matched(): void
    {
        // The rule is a TLD, not a substring: a shop can be called "Deutsche Optik" or sit on
        // "in-store.com" without being foreign.
        $this->assertFalse(CatalogController::isUnshippableMerchant('Deutsche Optik'));
        $this->assertFalse(CatalogController::isUnshippableMerchant('in-store.com'));
        $this->assertFalse(CatalogController::isUnshippableMerchant('Canada Goose'));
    }
}
