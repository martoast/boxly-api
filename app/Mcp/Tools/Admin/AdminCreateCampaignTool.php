<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminCampaignController;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminCreateCampaignTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_create_campaign';
    }

    public function description(): string
    {
        return 'Create an email campaign as a DRAFT (it is never sent automatically — sending is done separately after review). Plain Mexican Spanish, short subject, single CTA.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('name')->description('Internal campaign name.')->required();
        $schema->string('subject')->description('Email subject (keep short).')->required();
        $schema->string('body')->description('Email body (plain text).')->required();
        $schema->string('audience')->description('all, has_orders, no_orders, active_orders, expat, business, shopper.')->required();
        $schema->string('link_url')->description('Optional CTA link URL.');
        $schema->string('link_text')->description('Optional CTA button text.');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $this->mergeInput([
                'name' => $arguments['name'] ?? null,
                'subject' => $arguments['subject'] ?? null,
                'body' => $arguments['body'] ?? null,
                'audience' => $arguments['audience'] ?? null,
                'link_url' => $arguments['link_url'] ?? null,
                'link_text' => $arguments['link_text'] ?? null,
            ]);
            return $this->ok(app(AdminCampaignController::class)->store(request()));
        });
    }
}
