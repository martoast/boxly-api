<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\ProfileController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class GetProfileTool extends BoxlyTool
{
    public function name(): string
    {
        return 'get_profile';
    }

    public function description(): string
    {
        return 'Get the customer\'s profile and their Boxly US warehouse (casillero) shipping address — the address they use when shopping US stores.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guard(fn () => $this->ok(app(ProfileController::class)->show(request())));
    }
}
