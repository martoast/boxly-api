<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Tools\BoxlyTool;
use Illuminate\Routing\Redirector;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Base for admin MCP tools. Same connect flow + token as customer tools, but
 * only exposed (by BoxlyServer::boot) when the connected user is an admin.
 * Each tool also guards on isAdmin() as defense in depth, and delegates to the
 * existing admin controllers so all validation/side-effects are reused.
 */
abstract class AdminTool extends BoxlyTool
{
    protected function guardAdmin(callable $fn): ToolResult
    {
        if (! optional($this->user())->isAdmin()) {
            return ToolResult::error('This tool requires an admin Boxly account.');
        }
        return $this->guard($fn);
    }

    /**
     * Build + validate a FormRequest from the current request merged with the
     * given data, so we can call controller methods that type-hint a FormRequest.
     */
    protected function formRequest(string $class, array $data)
    {
        $form = $class::createFrom(request());
        $form->merge($data);
        $form->setContainer(app())->setRedirector(app(Redirector::class));
        $form->validateResolved();

        return $form;
    }
}
