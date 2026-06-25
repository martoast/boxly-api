<?php

namespace Database\Seeders;

use App\Models\StarterPrompt;
use Illuminate\Database\Seeder;

class StarterPromptsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['emoji' => '👟', 'title' => 'Tenis Nike para correr', 'prompt_text' => 'Tenis Nike para correr', 'image_query' => 'Nike running shoes women'],
            ['emoji' => '🥤', 'title' => 'Stanley Cups',            'prompt_text' => 'Stanley Cups',            'image_query' => 'Stanley cup tumbler'],
            ['emoji' => '🧴', 'title' => 'Skincare de Sephora',     'prompt_text' => 'Skincare de Sephora',     'image_query' => 'Sephora skincare set'],
            ['emoji' => '👜', 'title' => 'Bolsas Coach',            'prompt_text' => 'Bolsas Coach',            'image_query' => 'Coach handbag'],
            ['emoji' => '🎴', 'title' => 'Cartas Pokémon',          'prompt_text' => 'Cartas Pokémon',          'image_query' => 'Pokemon trading cards'],
            ['emoji' => '📦', 'title' => '¿Cómo funciona Boxly?',   'prompt_text' => '¿Cómo funciona Boxly?',   'image_query' => null],
        ];

        foreach ($defaults as $i => $d) {
            StarterPrompt::firstOrCreate(
                ['title' => $d['title']],
                array_merge($d, ['sort_order' => $i, 'is_active' => true])
            );
        }
    }
}
