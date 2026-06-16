<?php

namespace App\Mcp\Tools\Admin;

use App\Http\Controllers\AdminCustomerController;
use App\Http\Requests\AdminCreateUserRequest;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class AdminCreateCustomerTool extends AdminTool
{
    public function name(): string
    {
        return 'admin_create_customer';
    }

    public function description(): string
    {
        return 'Create a new customer account. Email and phone (E.164, e.g. +5215551234567) are required.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema->string('name')->description('Full name.')->required();
        $schema->string('email')->description('Email (must be unique).')->required();
        $schema->string('phone')->description('Phone in E.164, e.g. +5215551234567.')->required();
        $schema->string('user_type')->description('expat, business, or shopper (optional).');
        $schema->string('preferred_language')->description('es or en (optional).');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->guardAdmin(function () use ($arguments) {
            $form = $this->formRequest(AdminCreateUserRequest::class, [
                'name' => $arguments['name'] ?? null,
                'email' => $arguments['email'] ?? null,
                'phone' => $arguments['phone'] ?? null,
                'user_type' => $arguments['user_type'] ?? null,
                'preferred_language' => $arguments['preferred_language'] ?? null,
            ]);
            return $this->ok(app(AdminCustomerController::class)->store($form));
        });
    }
}
