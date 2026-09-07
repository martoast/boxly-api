<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Tests\LiveShoppingTestCase;

/**
 * Per-chat rolling memory (phase 2): the context read, the versioned summary write,
 * and the slimmed addMessages response.
 *
 * Builds on LiveShoppingTestCase because it is the one base in this repo that can
 * run the REAL users + conversations migrations on the test connection (the full
 * chain contains raw MySQL that SQLite cannot execute); the new columns come from
 * the real phase-2 migration file on top of that.
 *
 *   vendor/bin/phpunit tests/Feature/ConversationContextTest.php
 */
class ConversationContextTest extends LiveShoppingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', [
            '--path'  => 'database/migrations/2026_09_07_000000_add_running_summary_to_conversations_table.php',
            '--force' => true,
        ]);
    }

    /** A thread with N alternating user/assistant rows; returns [conversation, ids]. */
    private function thread(User $owner, int $rows): array
    {
        $c = Conversation::create(['user_id' => $owner->id, 'title' => 'hilo']);
        $ids = [];
        for ($i = 0; $i < $rows; $i++) {
            $role = $i % 2 === 0 ? 'user' : 'assistant';
            $ids[] = $c->messages()->create(['role' => $role, 'content' => ['parts' => [['type' => 'text', 'text' => "m{$i}"]]]])->id;
        }
        return [$c, $ids];
    }

    // ── context (read) ────────────────────────────────────────────────────

    public function test_context_is_owner_scoped(): void
    {
        [$c] = $this->thread(User::factory()->createQuietly(), 2);
        $other = User::factory()->createQuietly();

        $this->actingAs($other)->getJson("/conversations/{$c->id}/context")->assertStatus(403);
        $this->actingAs($other)->patchJson("/conversations/{$c->id}/summary", ['running_summary' => 'x', 'summary_upto_message_id' => 1, 'base_version' => 0])->assertStatus(403);
    }

    public function test_fresh_thread_has_no_summary_and_everything_is_unsummarized(): void
    {
        $owner = User::factory()->createQuietly();
        [$c, $ids] = $this->thread($owner, 4);

        $this->actingAs($owner)
            ->getJson("/conversations/{$c->id}/context")
            ->assertOk()
            ->assertJsonPath('data.running_summary', null)
            ->assertJsonPath('data.summary_upto_message_id', null)
            ->assertJsonPath('data.summary_version', 0)
            ->assertJsonPath('data.last_message_id', end($ids))
            ->assertJsonPath('data.unsummarized', 4)
            ->assertJsonPath('data.to_fold', []); // no window asked → nothing to fold
    }

    public function test_to_fold_excludes_the_trailing_window_rows(): void
    {
        $owner = User::factory()->createQuietly();
        [$c, $ids] = $this->thread($owner, 12);

        $r = $this->actingAs($owner)->getJson("/conversations/{$c->id}/context?window=8")->assertOk();
        $fold = $r->json('data.to_fold');

        // 12 rows, none summarized, 8 stay verbatim → the first 4 are foldable, ascending.
        $this->assertSame(array_slice($ids, 0, 4), array_column($fold, 'id'));
        $this->assertSame('user', $fold[0]['role']);
        $this->assertSame('m0', $fold[0]['content']['parts'][0]['text']);
    }

    public function test_to_fold_is_empty_when_the_window_still_covers_everything(): void
    {
        $owner = User::factory()->createQuietly();
        [$c] = $this->thread($owner, 6);

        $this->actingAs($owner)->getJson("/conversations/{$c->id}/context?window=8")
            ->assertOk()->assertJsonPath('data.unsummarized', 6)->assertJsonPath('data.to_fold', []);
    }

    public function test_to_fold_starts_after_summary_upto(): void
    {
        $owner = User::factory()->createQuietly();
        [$c, $ids] = $this->thread($owner, 14);
        $c->update(['running_summary' => 'resumen', 'summary_upto_message_id' => $ids[3], 'summary_version' => 2]);

        $r = $this->actingAs($owner)->getJson("/conversations/{$c->id}/context?window=8")->assertOk()
            ->assertJsonPath('data.running_summary', 'resumen')
            ->assertJsonPath('data.summary_version', 2)
            ->assertJsonPath('data.unsummarized', 10);
        // rows 4..13 are unsummarized; the last 8 (6..13) stay verbatim → fold 4 and 5.
        $this->assertSame([$ids[4], $ids[5]], array_column($r->json('data.to_fold'), 'id'));
    }

    // ── summary (write) ───────────────────────────────────────────────────

    public function test_update_summary_happy_path_bumps_the_version(): void
    {
        $owner = User::factory()->createQuietly();
        [$c, $ids] = $this->thread($owner, 6);

        $this->actingAs($owner)
            ->patchJson("/conversations/{$c->id}/summary", ['running_summary' => 'Busca tenis para correr.', 'summary_upto_message_id' => $ids[1], 'base_version' => 0])
            ->assertOk()
            ->assertJsonPath('data.summary_version', 1)
            ->assertJsonPath('data.summary_upto_message_id', $ids[1]);

        $c->refresh();
        $this->assertSame('Busca tenis para correr.', $c->running_summary);
        $this->assertSame($ids[1], $c->summary_upto_message_id);
        $this->assertSame(1, $c->summary_version);
        $this->assertNotNull($c->summary_updated_at);

        // The read side now reports only the rows after upto as unsummarized.
        $this->actingAs($owner)->getJson("/conversations/{$c->id}/context")
            ->assertJsonPath('data.unsummarized', 4)->assertJsonPath('data.summary_version', 1);
    }

    public function test_stale_base_version_is_a_409_and_changes_nothing(): void
    {
        $owner = User::factory()->createQuietly();
        [$c, $ids] = $this->thread($owner, 6);
        $c->update(['running_summary' => 'v1', 'summary_upto_message_id' => $ids[1], 'summary_version' => 1]);

        $this->actingAs($owner)
            ->patchJson("/conversations/{$c->id}/summary", ['running_summary' => 'stale', 'summary_upto_message_id' => $ids[3], 'base_version' => 0])
            ->assertStatus(409)
            ->assertJsonPath('error', 'version_conflict')
            ->assertJsonPath('summary_version', 1);

        $c->refresh();
        $this->assertSame('v1', $c->running_summary);
        $this->assertSame($ids[1], $c->summary_upto_message_id);
    }

    public function test_upto_must_belong_to_the_conversation_and_never_move_backwards(): void
    {
        $owner = User::factory()->createQuietly();
        [$c, $ids] = $this->thread($owner, 6);
        [$otherConv, $otherIds] = $this->thread($owner, 2);
        $c->update(['summary_upto_message_id' => $ids[3], 'summary_version' => 1]);

        $this->actingAs($owner)
            ->patchJson("/conversations/{$c->id}/summary", ['running_summary' => 'x', 'summary_upto_message_id' => $otherIds[1], 'base_version' => 1])
            ->assertStatus(422)->assertJsonPath('error', 'upto_not_in_conversation');

        $this->actingAs($owner)
            ->patchJson("/conversations/{$c->id}/summary", ['running_summary' => 'x', 'summary_upto_message_id' => $ids[1], 'base_version' => 1])
            ->assertStatus(422)->assertJsonPath('error', 'upto_moves_backwards');
    }

    public function test_summary_is_validated(): void
    {
        $owner = User::factory()->createQuietly();
        [$c, $ids] = $this->thread($owner, 2);

        $this->actingAs($owner)->patchJson("/conversations/{$c->id}/summary", ['running_summary' => str_repeat('x', 2501), 'summary_upto_message_id' => $ids[1], 'base_version' => 0])
            ->assertStatus(422);
        $this->actingAs($owner)->patchJson("/conversations/{$c->id}/summary", ['running_summary' => 'ok', 'base_version' => 0])
            ->assertStatus(422);
    }

    // ── addMessages response ──────────────────────────────────────────────

    public function test_add_messages_returns_the_inserted_ids_not_the_whole_thread(): void
    {
        $owner = User::factory()->createQuietly();
        [$c, $ids] = $this->thread($owner, 4);

        $r = $this->actingAs($owner)->postJson("/conversations/{$c->id}/messages", ['messages' => [
            ['role' => 'user', 'content' => ['parts' => [['type' => 'text', 'text' => 'hola']]]],
            ['role' => 'assistant', 'content' => ['parts' => [['type' => 'text', 'text' => 'qué tal']]]],
        ]])->assertOk();

        $inserted = $r->json('data.inserted_ids');
        $this->assertCount(2, $inserted);
        $this->assertSame(end($ids) + 1, $inserted[0]);
        $this->assertSame(max($inserted), $r->json('data.last_message_id'));
        $this->assertArrayNotHasKey('messages', $r->json('data'));
        $this->assertSame(6, $c->messages()->count());
    }
}
