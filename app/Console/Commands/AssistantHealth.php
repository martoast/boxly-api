<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Is boxly.mx's AI search answering? (Alex, 2026-11-09: every 6 hours, an email when it is not.)
 *
 * Why this exists: from ~2026-11-06 every AI search message failed — the model provider's prepaid credits ran out — and nobody noticed
 * for three days; shoppers got "Algo salió mal al enviar tu mensaje" and left. One short message to the live assistant route: it is
 * healthy when the stream carries a reply (text or a tool call) and no error event. Anything else — an HTTP error, a streamed
 * {"type":"error"} (the provider's own message, e.g. "Your prepayment credits are depleted"), an empty answer, a timeout — emails the
 * alert address with what came back. It mails again on every failing run, and once more when the assistant answers again.
 */
class AssistantHealth extends Command
{
    protected $signature = 'boxly:assistant-health {--dry-run : Check and report, never email}';

    protected $description = 'Send one message to the live AI search assistant and email the alert address if it does not answer';

    private const FAILING_KEY = 'assistant_health:failing_since';

    public function handle(): int
    {
        $url = (string) config('services.assistant_health.url');
        $to = (string) config('services.assistant_health.email');
        $started = microtime(true);
        [$ok, $detail] = $this->probe($url);
        $ms = (int) round((microtime(true) - $started) * 1000);
        $failingSince = Cache::get(self::FAILING_KEY);

        if ($ok) {
            $this->info("assistant ok in {$ms} ms: {$detail}");
            if ($failingSince) {
                Cache::forget(self::FAILING_KEY);
                $this->mail($to, '✅ Boxly AI search is answering again', "The AI search assistant at {$url} answers again ({$ms} ms).\n\nIt had been failing since {$failingSince} (UTC).\n\nReply: {$detail}");
            }
            return self::SUCCESS;
        }

        $since = $failingSince ?: now()->toDateTimeString();
        Cache::put(self::FAILING_KEY, $since, now()->addDays(30));
        Log::error('assistant-health: the AI search assistant is not answering', ['url' => $url, 'detail' => $detail, 'ms' => $ms]);
        $this->error("assistant FAILING after {$ms} ms: {$detail}");
        $this->mail($to, '🚨 Boxly AI search is NOT answering', "The AI search assistant at {$url} did not answer a test message.\n\nWhat came back ({$ms} ms):\n{$detail}\n\nFailing since: {$since} (UTC). Shoppers see \"Algo salió mal al enviar tu mensaje\" until it is fixed.\n\nCheck the model provider's account and key (server/utils/aiProvider.ts in boxly-lead picks it: OpenAI first).");
        return self::FAILURE;
    }

    /** [healthy, what came back] for one message to the assistant route. */
    private function probe(string $url): array
    {
        try {
            // health: true — the app answers it like any message but never logs it as a guest's question in the admin
            $res = Http::timeout(60)->acceptJson()->post($url, [
                'health' => true,
                'messages' => [['id' => 'health-' . now()->timestamp, 'role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hola']]]],
            ]);
        } catch (\Throwable $e) {
            return [false, 'no answer: ' . mb_substr($e->getMessage(), 0, 300)];
        }
        $body = (string) $res->body();
        if (! $res->successful()) {
            return [false, "HTTP {$res->status()}: " . mb_substr($body, 0, 500)];
        }
        if (preg_match('/"type"\s*:\s*"error"[^}]*?"errorText"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $body, $m) || str_contains($body, '"type":"error"')) {
            return [false, 'the assistant streamed an error: ' . (isset($m[1]) ? stripcslashes($m[1]) : mb_substr($body, 0, 500))];
        }
        if (preg_match('/"type"\s*:\s*"(?:text-delta|tool-input-available|tool-input-start)"/', $body)) {
            $text = '';
            if (preg_match_all('/"type"\s*:\s*"text-delta"[^}]*?"delta"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $body, $all)) $text = stripcslashes(implode('', $all[1]));
            return [true, $text !== '' ? mb_substr($text, 0, 200) : 'a tool call'];
        }
        return [false, 'no reply in the stream: ' . mb_substr($body, 0, 500)];
    }

    private function mail(string $to, string $subject, string $text): void
    {
        if ($this->option('dry-run') || $to === '') {
            $this->line("(no email) {$subject}");
            return;
        }
        try {
            Mail::raw($text, fn ($m) => $m->to($to)->subject($subject));
        } catch (\Throwable $e) {
            Log::error('assistant-health: the alert email could not be sent', ['error' => $e->getMessage()]);
            $this->error('the alert email could not be sent: ' . $e->getMessage());
        }
    }
}
