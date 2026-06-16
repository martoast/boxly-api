<?php

namespace App\Mcp\Tools;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Base for Boxly customer MCP tools. Each tool delegates to the existing
 * authenticated customer controllers so validation, scoping and side-effects
 * (emails, Stripe, etc.) are reused — never reimplemented.
 *
 * The MCP route is behind auth:sanctum, so request()->user() is the customer
 * whose token the AI presented. All data access is scoped to that user.
 */
abstract class BoxlyTool extends Tool
{
    protected function user()
    {
        return request()->user();
    }

    /**
     * Merge tool arguments into the request, dropping nulls so controller
     * defaults (e.g. $request->input('year', now()->year)) still apply for
     * omitted arguments.
     */
    protected function mergeInput(array $data): void
    {
        request()->merge(array_filter($data, fn ($v) => $v !== null));
    }

    /**
     * Normalize a controller JsonResponse (or array) into a ToolResult.
     * Unwraps the common { success, data } envelope.
     */
    protected function ok($response): ToolResult
    {
        $data = is_array($response) ? $response : $response->getData(true);
        if (is_array($data) && array_key_exists('data', $data)) {
            $data = $data['data'];
        }
        return ToolResult::json(is_array($data) ? $data : ['result' => $data]);
    }

    /**
     * Run the tool body with uniform error handling.
     */
    protected function guard(callable $fn): ToolResult
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            return ToolResult::error('Invalid input: ' . json_encode($e->errors()));
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }
}
