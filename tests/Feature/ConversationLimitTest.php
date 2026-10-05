<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Tests\LiveShoppingTestCase;

/**
 * A user keeps at most 10 chats (Alex 2026-10-05): a new chat deletes the least recently used one beyond the cap.
 *
 *   vendor/bin/phpunit tests/Feature/ConversationLimitTest.php
 */
class ConversationLimitTest extends LiveShoppingTestCase
{
    public function test_the_eleventh_chat_deletes_the_least_recently_used(): void
    {
        $owner = User::factory()->createQuietly();
        $other = User::factory()->createQuietly();
        $ids = [];
        for ($i = 0; $i < 10; $i++) {
            $c = Conversation::create(['user_id' => $owner->id, 'title' => "chat {$i}", 'last_message_at' => now()->subDays(20 - $i)]);
            $c->messages()->create(['role' => 'user', 'content' => ['parts' => [['type' => 'text', 'text' => "hola {$i}"]]]]);
            $ids[] = $c->id;
        }
        // chat 0 is the oldest by creation, but chat 1 is the least recently USED
        Conversation::whereKey($ids[0])->update(['last_message_at' => now()->subHour()]);
        $theirs = Conversation::create(['user_id' => $other->id, 'title' => 'otro', 'last_message_at' => now()->subYears(2)]);

        $new = $this->actingAs($owner)->postJson('/conversations', ['title' => 'nuevo'])->assertCreated()->json('data.id');

        $left = Conversation::where('user_id', $owner->id)->pluck('id')->all();
        $this->assertCount(10, $left);
        $this->assertContains($new, $left);
        $this->assertContains($ids[0], $left, 'a recently used chat stays, however old');
        $this->assertNotContains($ids[1], $left, 'the least recently used chat is deleted');
        $this->assertSame(0, \DB::table('conversation_messages')->where('conversation_id', $ids[1])->count(), 'its messages go with it');
        $this->assertNotNull(Conversation::find($theirs->id), 'another user\'s chats are never touched');
    }

    public function test_under_the_cap_nothing_is_deleted(): void
    {
        $owner = User::factory()->createQuietly();
        for ($i = 0; $i < 5; $i++) {
            Conversation::create(['user_id' => $owner->id, 'last_message_at' => now()->subDays($i)]);
        }
        $this->actingAs($owner)->postJson('/conversations', [])->assertCreated();
        $this->assertSame(6, Conversation::where('user_id', $owner->id)->count());
    }
}
