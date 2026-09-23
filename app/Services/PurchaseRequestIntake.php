<?php

namespace App\Services;

use App\Mail\PurchaseRequestCreated;
use App\Mail\PurchaseRequestCreatedTeamNotification;
use App\Models\Conversation;
use App\Models\PurchaseRequest;
use App\Models\PurchaseRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Creating a customer purchase request — the one path shared by the classic
 * "paste links" form (PurchaseRequestController::store) and the Boxly cart
 * (CartController::finalize), so both produce the same pending_review ticket,
 * the same item rows and the same two emails.
 *
 * Transactions stay with the caller: store() also uploads per-item files inside
 * its transaction, and finalize() flips the cart in the same one.
 */
class PurchaseRequestIntake
{
    /**
     * Create the PR and its items. Returns null when every row was blank (no
     * name and no link) — the caller rolls back and answers 422.
     *
     * $afterItem(PurchaseRequestItem $item, int|string $index, PurchaseRequest $pr): bool
     * lets the caller attach an uploaded image; returning true means "handled",
     * which skips re-hosting product_image_url.
     */
    public function create(
        User $user,
        array $items,
        ?int $conversationId = null,
        string $currency = 'usd',
        ?string $customerNotes = null,
        ?callable $afterItem = null,
    ): ?PurchaseRequest {
        // Only link a chat this customer actually owns.
        if ($conversationId && ! Conversation::where('id', $conversationId)->where('user_id', $user->id)->exists()) {
            $conversationId = null;
        }

        $pr = PurchaseRequest::create([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'request_number' => PurchaseRequest::generateRequestNumber(),
            'status' => PurchaseRequest::STATUS_PENDING_REVIEW,
            'currency' => $currency,
            'customer_notes' => $customerNotes,
        ]);

        $createdItems = 0;

        foreach ($items as $index => $itemData) {
            // Handle options: If sent via FormData, it might be a JSON string or an array
            $options = null;
            if (isset($itemData['options'])) {
                $options = is_string($itemData['options'])
                    ? json_decode($itemData['options'], true)
                    : $itemData['options'];
            }

            // A link or a name — one of the two. Anything else is an empty row
            // the paste UI left behind, and silently dropping it is right:
            // failing the whole request over a blank line would lose the
            // customer's entire list.
            $name = $this->itemName($itemData);
            if ($name === null) {
                continue;
            }

            $item = PurchaseRequestItem::create([
                'purchase_request_id' => $pr->id,
                'product_name' => $name,
                'product_url' => $this->cleanUrl($itemData['product_url'] ?? null),
                'product_image_url' => $this->cleanUrl($itemData['product_image_url'] ?? null) ?: null,
                // Null until the cart is built and the REAL price is written.
                'price' => $itemData['price'] ?? null,
                'quantity' => $itemData['quantity'],
                'options' => $options,
                'notes' => $itemData['notes'] ?? null,
            ]);
            $createdItems++;

            $handled = $afterItem ? (bool) $afterItem($item, $index, $pr) : false;
            if (! $handled && ! empty($itemData['product_image_url'])) {
                // No uploaded file — re-host the provided image URL to our
                // bucket so it's permanent (source thumbnails can expire).
                $this->rehostItemImage($item, $item->product_image_url, $user, $pr);
            }
        }

        return $createdItems === 0 ? null : $pr;
    }

    /** Customer confirmation + shopping-team alert. Call after commit. */
    public function notifyCreated(PurchaseRequest $pr, User $user): void
    {
        Log::info('Purchase Request created', ['id' => $pr->id, 'user_id' => $user->id]);

        // Customer confirmation
        try {
            Mail::to($user)->queue(new PurchaseRequestCreated($pr));
            Log::info('Purchase Request confirmation email queued for ' . $user->email);
        } catch (\Exception $e) {
            Log::error('Failed to queue purchase request email: ' . $e->getMessage());
        }

        // Internal alert to the shopping team — Velonie can review and quote
        // right away. Admins excluded (they have the dashboard).
        try {
            $pr->load(['items', 'user']);
            $teamEmails = User::query()
                ->where('role', User::ROLE_EMPLOYEE)
                ->where('team', User::TEAM_SHOPPING)
                ->pluck('email')
                ->all();
            if (! empty($teamEmails)) {
                Mail::to($teamEmails)->queue(new PurchaseRequestCreatedTeamNotification($pr));
            }
        } catch (\Exception $e) {
            Log::error('Failed to queue PR-created team notification: ' . $e->getMessage());
        }
    }

    /**
     * Download a product image URL and store it permanently in our Spaces
     * bucket, then point the item's image_url at it. Best-effort: on any failure
     * the item keeps its original product_image_url. Only runs at PR creation.
     */
    public function rehostItemImage(PurchaseRequestItem $item, string $sourceUrl, $user, PurchaseRequest $pr): void
    {
        try {
            $res = \Illuminate\Support\Facades\Http::timeout(20)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; BoxlyBot/1.0)'])
                ->get($sourceUrl);
            if (! $res->successful() || ! $res->body()) {
                return;
            }
            $body = $res->body();
            $type = strtolower((string) $res->header('Content-Type'));
            if (! str_contains($type, 'image')) {
                return; // not an image
            }
            $ext = match (true) {
                str_contains($type, 'png')  => 'png',
                str_contains($type, 'webp') => 'webp',
                str_contains($type, 'gif')  => 'gif',
                default                     => 'jpg',
            };

            $userName = Str::slug($user->name);
            $path = "users/{$userName}-{$user->id}/requests/{$pr->request_number}/items/{$item->id}/image-" . time() . ".{$ext}";

            Storage::disk('spaces')->put($path, $body, 'public');
            $url = config('filesystems.disks.spaces.url') . '/' . $path;

            $item->update([
                'image_path'      => $path,
                'image_filename'  => basename($path),
                'image_mime_type' => $type,
                'image_size'      => strlen($body),
                'image_url'       => $url, // permanent, hosted by us (display prefers this)
            ]);
        } catch (\Throwable $e) {
            Log::warning('PR item image re-host failed: ' . $e->getMessage());
        }
    }

    /**
     * A readable product name for an item the customer pasted as a bare link.
     *
     * Product URLs almost always carry the product name as a slug, so we can get
     * something human WITHOUT a network call — which is the whole point: the old
     * create page scraped every URL just to fill this field, and the customer paid
     * for it in seconds of spinner.
     *
     *   .../shop/monchhichi-classic-fruit-plushie-keychain?color=040
     *        -> "Monchhichi Classic Fruit Plushie Keychain"
     *   .../p/starbucks-pumpkin-spice-light-roast-ground-coffee-11oz/-/A-53409621
     *        -> "Starbucks Pumpkin Spice Light Roast Ground Coffee 11oz"
     *
     * We walk the path backwards and take the last segment that reads like words,
     * skipping numeric ids ("17795600806", "A-53409621") and routing noise
     * ("p", "dp", "ip", "shop", "products"). If nothing qualifies we fall back to
     * the host, which is still better than showing a raw URL in the admin list.
     * A background job enriches these with the real title later, on our time.
     */
    private function nameFromUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        $segments = array_values(array_filter(
            explode('/', (string) parse_url($url, PHP_URL_PATH)),
            fn ($s) => $s !== '',
        ));

        $skip = ['p', 'dp', 'ip', 'gp', 'shop', 'product', 'products', 'item', 'items', 'pd', 'prod'];
        foreach (array_reverse($segments) as $segment) {
            $slug = preg_replace('/\.(html?|aspx?|php|htm)$/i', '', $segment);
            if (in_array(strtolower($slug), $skip, true)) {
                continue;
            }
            // Needs a real word in it — filters out ids like "A-53409621".
            if (! preg_match('/[a-z]{3,}/i', $slug)) {
                continue;
            }
            $pretty = trim(preg_replace('/\s+/', ' ', str_replace(['-', '_', '+'], ' ', $slug)));
            if ($pretty === '') {
                continue;
            }

            return mb_substr(mb_convert_case($pretty, MB_CASE_TITLE, 'UTF-8'), 0, 255);
        }

        return $host !== '' ? mb_substr($host, 0, 255) : null;
    }

    /**
     * The name to store for an item: what the customer typed, else the slug from
     * their link. Returns null when there is neither — the caller skips those.
     */
    public function itemName(array $itemData): ?string
    {
        $given = trim((string) ($itemData['product_name'] ?? ''));
        if ($given !== '') {
            return mb_substr($given, 0, 255);
        }

        return $this->nameFromUrl($itemData['product_url'] ?? null);
    }

    /**
     * A product/image URL exactly as we should store and render it: trimmed,
     * with raw spaces percent-encoded. Google Shopping links from SerpAPI carry
     * a literal space ("...&q=Nikon Z30&prds=...") which is invalid in an href —
     * some mail clients cut the link there, so "Ver producto" dies. Everything
     * else is left byte-for-byte, so an already-encoded link is untouched.
     */
    public function cleanUrl(?string $url): string
    {
        return str_replace(' ', '%20', trim((string) $url));
    }
}
