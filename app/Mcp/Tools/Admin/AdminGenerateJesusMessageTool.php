<?php

namespace App\Mcp\Tools\Admin;

use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Formats the Spanish delivery message for Jesus (the FedEx courier).
 * Pure string formatting — mirrors cli/commands/jesus-message.js. No API call.
 */
class AdminGenerateJesusMessageTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_generate_jesus_message';
    }

    public function description(): string
    {
        return 'Generate the formatted Spanish hand-off message for Jesus (FedEx courier) for a shipped/consolidated box. Provide the customer name + address and the box dimensions (LxWxH cm) and weight (kg). Use after consolidating an order.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('name')->description('Customer name.')->required();
        $schema->string('phone')->description('Customer phone (optional).');
        $schema->string('address')->description('Delivery address (optional).');
        $schema->string('dims')->description('Box dimensions LxWxH in cm, e.g. 40x30x20.');
        $schema->number('weight')->description('Box weight in kg.');
        $schema->raw('boxes', [
            'type' => 'array',
            'description' => 'For multiple boxes: [{name, dims, weight}].',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'dims' => ['type' => 'string', 'description' => 'LxWxH cm'],
                    'weight' => ['type' => 'number'],
                ],
            ],
        ]);
        $schema->string('contents')->description('Package contents description.');
        $schema->string('shipping')->description('air (default) or ground.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $name = trim($arguments['name'] ?? '');
            if ($name === '') {
                return ToolResult::error('name is required.');
            }
            $shipping = ($arguments['shipping'] ?? 'air') === 'ground' ? 'Terrestre FedEx' : 'Avión FedEx';
            $contents = $arguments['contents'] ?? '[pendiente]';

            $boxes = $arguments['boxes'] ?? null;
            if (! $boxes && (! empty($arguments['dims']) || ! empty($arguments['weight']))) {
                $boxes = [['dims' => $arguments['dims'] ?? null, 'weight' => $arguments['weight'] ?? null]];
            }

            $msg = "Nombre: {$name}\n";
            if (! empty($arguments['phone'])) {
                $msg .= "    •    Teléfono: {$arguments['phone']}\n";
            }
            if (! empty($arguments['address'])) {
                $msg .= "    •    Dirección: {$arguments['address']}\n";
            }

            if (is_array($boxes) && count($boxes) > 1) {
                $total = 0.0;
                foreach ($boxes as $i => $box) {
                    $label = $box['name'] ?? ('Caja ' . ($i + 1));
                    $msg .= '    •    Caja ' . ($i + 1) . ": {$label}\n";
                    if ($d = $this->parseDims($box['dims'] ?? null)) {
                        $msg .= "         Dimensiones: {$d}\n";
                    }
                    if (! empty($box['weight'])) {
                        $msg .= "         Peso: {$box['weight']} kg\n";
                        $total += (float) $box['weight'];
                    }
                }
                if ($total > 0) {
                    $msg .= '    •    Peso Total: ' . number_format($total, 1) . " kg\n";
                }
            } else {
                $single = is_array($boxes) ? ($boxes[0] ?? []) : [];
                if ($d = $this->parseDims($single['dims'] ?? null)) {
                    $msg .= "    •    Dimensiones: {$d}\n";
                }
                if (! empty($single['weight'])) {
                    $msg .= "    •    Peso: {$single['weight']} kg\n";
                }
            }

            $msg .= "    •    Contenido: {$contents}\n";
            $msg .= "    •    Envío: {$shipping}";

            return ToolResult::text($msg);
        });
    }

    private function parseDims(?string $str): ?string
    {
        if (! $str) {
            return null;
        }
        $parts = preg_split('/[xX,\s]+/', trim($str), -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) !== 3) {
            return null;
        }
        return "{$parts[0]} × {$parts[1]} × {$parts[2]} cm";
    }
}
