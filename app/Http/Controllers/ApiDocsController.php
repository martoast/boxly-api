<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * GET /me/api-docs — the routes the signed-in user's API key can call, generated
 * from the live route table so it can never go stale (Alex 2026-10-01: "without
 * it, [the AI] is running blind").
 *
 * Admins get the admin group and the shopping group (they can call both); a
 * shopping manager gets the shopping group. Each route carries its method, path,
 * a one-line summary (the method's doc comment, else its name in words) and the
 * body fields when they can be read: a FormRequest's rules, or the keys of the
 * controller's own inline validate([...]). `?format=md` returns Markdown, for
 * pasting into an AI or for the AI to fetch itself with its key.
 */
class ApiDocsController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $gates = $user->isAdmin() ? ['admin', 'shopping'] : ['shopping'];

        $groups = [];
        foreach (Route::getRoutes() as $route) {
            $gate = collect($gates)->first(fn ($g) => in_array($g, $route->gatherMiddleware(), true));
            if (! $gate) {
                continue;
            }
            $path = '/' . ltrim($route->uri(), '/');
            $segments = explode('/', trim($path, '/'));
            $area = $segments[1] ?? $segments[0];
            [$summary, $fields] = $this->describe($route->getActionName(), $path);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $groups["{$gate}/{$area}"][] = ['method' => $method, 'path' => $path, 'summary' => $summary, 'fields' => $fields];
            }
        }
        ksort($groups);

        $data = [
            'base_url' => $request->getSchemeAndHttpHost(),
            'auth' => 'Send "Authorization: Bearer <your API key>" and "Accept: application/json" on every request. A key can do exactly what its owner can do in the app.',
            'notes' => [
                'Routes are served at the root: no /api prefix.',
                'Responses are JSON, usually {"success": true, "data": ...}.',
                'A missing or wrong field answers 422 with the field and the reason.',
                'Path parts in {braces} are ids (e.g. /admin/orders/{order} → /admin/orders/123).',
            ],
            'count' => array_sum(array_map('count', $groups)),
            'groups' => collect($groups)->map(fn ($routes, $key) => ['area' => $key, 'routes' => $routes])->values(),
        ];

        if ($request->query('format') === 'md') {
            return response($this->markdown($data), 200, ['Content-Type' => 'text/markdown; charset=UTF-8']);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** [summary, fields] for a "Controller@method" action. */
    private function describe(string $action, string $path): array
    {
        if (! str_contains($action, '@')) {
            return [Str::headline(last(explode('/', $path))), []];
        }
        [$class, $method] = explode('@', $action);
        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\Throwable) {
            return [Str::headline($method), []];
        }

        $summary = $this->docSummary($ref->getDocComment() ?: '')
            ?: Str::headline($method) . ' (' . Str::headline(Str::beforeLast(class_basename($class), 'Controller')) . ')';

        return [$summary, $this->formRequestFields($ref) ?: $this->inlineFields($ref)];
    }

    /** The doc comment's first paragraph, one line, without @tags. */
    private function docSummary(string $doc): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $doc) as $line) {
            $line = trim(preg_replace('#^\s*(/\*\*|\*/|\*)\s?#', '', $line));
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line === '' && $lines) {
                break;
            }
            if ($line !== '' && $line !== '/') {
                $lines[] = $line;
            }
        }

        return Str::limit(implode(' ', $lines), 240);
    }

    /** Rules of a FormRequest the method type-hints (best effort: rules() may need the live request). */
    private function formRequestFields(\ReflectionMethod $ref): array
    {
        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin() && is_subclass_of($type->getName(), FormRequest::class)) {
                try {
                    return $this->flatRules((new ($type->getName()))->rules());
                } catch (\Throwable) {
                    return [];
                }
            }
        }

        return [];
    }

    /** Keys and rule strings of the first validate([...]) / Validator::make(..., [...]) in the method's own source. */
    private function inlineFields(\ReflectionMethod $ref): array
    {
        $file = $ref->getFileName();
        if (! $file || ! is_readable($file)) {
            return [];
        }
        $src = implode('', array_slice(file($file), $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        if (! preg_match('/(?:->validate\(|Validator::make\([^\[]*)\s*\[/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        // The array literal: from its "[" to the matching "]".
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $end = null;
        for ($i = $start, $n = strlen($src); $i < $n; $i++) {
            $depth += $src[$i] === '[' ? 1 : ($src[$i] === ']' ? -1 : 0);
            if ($depth === 0) {
                $end = $i;
                break;
            }
        }
        if ($end === null) {
            return [];
        }
        preg_match_all("/['\"]([\\w.*]+)['\"]\\s*=>\\s*(?:['\"]([^'\"]*)['\"]|\\[)/", substr($src, $start + 1, $end - $start - 1), $pairs, PREG_SET_ORDER);
        $fields = [];
        foreach ($pairs as $p) {
            $fields[$p[1]] = $p[2] ?? '';
        }

        return $fields;
    }

    private function flatRules(array $rules): array
    {
        return collect($rules)->map(fn ($r) => is_array($r) ? implode('|', array_filter($r, 'is_string')) : (is_string($r) ? $r : ''))->all();
    }

    private function markdown(array $d): string
    {
        $out = ["# Boxly API — the routes your key can call", '', "Base URL: {$d['base_url']}", '', $d['auth'], ''];
        foreach ($d['notes'] as $note) {
            $out[] = "- {$note}";
        }
        $out[] = '';
        $out[] = "{$d['count']} routes.";
        foreach ($d['groups'] as $g) {
            $out[] = '';
            $out[] = "## {$g['area']}";
            foreach ($g['routes'] as $r) {
                $out[] = "- `{$r['method']} {$r['path']}` — {$r['summary']}";
                foreach ($r['fields'] as $name => $rules) {
                    $out[] = "    - `{$name}`" . ($rules !== '' ? ": {$rules}" : '');
                }
            }
        }

        return implode("\n", $out) . "\n";
    }
}
