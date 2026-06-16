<?php

namespace App\Mcp\Tools\Admin;

use App\Mcp\Tools\BoxlyTool;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
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
     *
     * $routeParams binds route-model parameters (e.g. ['order' => $order]) so
     * FormRequests that read $this->route('order') in rules()/passedValidation()
     * work — our MCP route has no such binding.
     */
    protected function formRequest(string $class, array $data, array $routeParams = [])
    {
        $form = $class::createFrom(request());
        $form->merge($data);
        $form->setContainer(app())->setRedirector(app(Redirector::class));

        if ($routeParams) {
            $route = new Route(['POST'], '/_mcp', []);
            $route->bind(request());
            foreach ($routeParams as $name => $value) {
                $route->setParameter($name, $value);
            }
            $form->setRouteResolver(fn () => $route);
        }

        $form->validateResolved();

        return $form;
    }
}
