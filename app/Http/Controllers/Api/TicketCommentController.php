<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UnitScopedRequest;
use App\Jobs\SendNotificationJob;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCommentReaction;
use App\Models\User;
use App\Rules\PerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TicketCommentController extends Controller
{
    /**
     * Issue #863: hard cap on how many `@handle`s one comment may resolve to.
     *
     * A body is capped at `max:10000` characters and every valid handle is
     * 11 characters (`@` + a 10-digit n_code, `\w+` stops at the next
     * non-word character so no separator is needed), so ONE maximum-length
     * comment fits 909 distinct handles — measured, not estimated. Without a
     * bound that becomes 909 `IN` values and 909 queued `SendNotificationJob`s
     * from a single request on a route gated only by `create_ticket`, which
     * the lowest role holds.
     *
     * The cap is applied to the MATCHED HANDLES, before the database lookup,
     * not to the notifications actually created: the amplification is the
     * 909-element `whereIn` plus one job per match, and unmatched handles are
     * the majority of any spam attempt, so counting only the recipients that
     * resolved would let unknown names consume the whole quota.
     */
    public const MAX_MENTIONS = 20;

    /**
     * List comments for a ticket.
     */
    public function index(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        $threaded = $request->boolean('threaded');
        // Issue #894: see UnitController::index(). 422 instead of a silent one-sided
        // clamp; `min()` kept as defence in depth.
        $request->validate(['per_page' => ['sometimes', new PerPage(100)]]);
        $perPage = min($request->integer('per_page', 20), 100);

        $query = $ticket->comments()
            ->with(['user:id,n_code', 'reactions'])
            ->whereNull('parent_id')
            ->latest();

        if ($threaded) {
            $comments = $query->with('children.user', 'children.reactions')
                ->paginate($perPage);
        } else {
            $comments = $query->paginate($perPage);
        }

        return response()->json([
            'data' => $comments->items(),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    /**
     * Create a new comment on a ticket.
     */
    public function store(UnitScopedRequest $request, Ticket $ticket): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:10000',
            'parent_id' => 'nullable|exists:ticket_comments,id',
        ]);

        // If parent_id provided, verify it belongs to same ticket
        if ($validated['parent_id'] ?? null) {
            $parent = TicketComment::find($validated['parent_id']);
            if (! $parent || $parent->ticket_id !== $ticket->id) {
                return response()->json(['message' => 'Invalid parent comment.'], 422);
            }
            // Limit thread depth to 3
            $depth = $this->getThreadDepth($parent);
            if ($depth >= 3) {
                return response()->json(['message' => 'Maximum thread depth reached (3).'], 422);
            }
        }

        // Process @mentions and markdown
        $bodyHtml = $this->processMarkdown($validated['body']);
        $mentions = $this->extractMentions($validated['body'], $accessibleIds);

        $comment = TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'body' => $validated['body'],
            'body_html' => $bodyHtml,
            'is_system' => false,
        ]);

        // Notify mentioned users
        if (! empty($mentions)) {
            $this->notifyMentions($mentions, $comment, $request->user());
        }

        // Notify parent comment author (if reply)
        if ($comment->parent_id) {
            $parentComment = TicketComment::find($comment->parent_id);
            if ($parentComment && $parentComment->user_id !== $request->user()->id) {
                $this->notifyReply($parentComment, $comment, $request->user());
            }
        }

        return response()->json([
            'success' => true,
            'data' => $comment->load(['user:id,n_code', 'reactions']),
        ], 201);
    }

    /**
     * Show a single comment.
     */
    public function show(UnitScopedRequest $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($ticket->unit_id, $accessibleIds) || $comment->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'Comment not accessible.'], 403);
        }

        return response()->json([
            'data' => $comment->load(['user:id,n_code', 'reactions', 'children.user:id,n_code']),
        ]);
    }

    /**
     * Update a comment (author only, within 15 minutes).
     */
    public function update(UnitScopedRequest $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($ticket->unit_id, $accessibleIds) || $comment->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'Comment not accessible.'], 403);
        }

        if (! $comment->canBeEditedBy($request->user())) {
            return response()->json(['message' => 'Cannot edit this comment.'], 403);
        }

        $validated = $request->validate([
            'body' => 'required|string|max:10000',
        ]);

        $comment->update([
            'body' => $validated['body'],
            'body_html' => $this->processMarkdown($validated['body']),
        ]);

        return response()->json([
            'success' => true,
            'data' => $comment->fresh()->load(['user:id,n_code', 'reactions']),
        ]);
    }

    /**
     * Soft delete a comment (author or admin).
     */
    public function destroy(UnitScopedRequest $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($ticket->unit_id, $accessibleIds) || $comment->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'Comment not accessible.'], 403);
        }

        if (! $comment->canBeDeletedBy($request->user())) {
            return response()->json(['message' => 'Cannot delete this comment.'], 403);
        }

        $comment->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Add a reaction to a comment.
     */
    public function react(UnitScopedRequest $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($ticket->unit_id, $accessibleIds) || $comment->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'Comment not accessible.'], 403);
        }

        $validated = $request->validate([
            'reaction' => 'required|string|in:+1,-1,heart,tada,rocket,eyes',
        ]);

        $reaction = TicketCommentReaction::firstOrCreate([
            'comment_id' => $comment->id,
            'user_id' => $request->user()->id,
            'reaction' => $validated['reaction'],
        ]);

        // Notify comment author (batched - could be improved with a job)
        if ($comment->user_id !== $request->user()->id) {
            $this->notifyReaction($comment, $request->user(), $validated['reaction']);
        }

        return response()->json([
            'success' => true,
            'data' => $reaction,
        ]);
    }

    /**
     * Remove a reaction from a comment.
     */
    public function unreact(UnitScopedRequest $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($ticket->unit_id, $accessibleIds) || $comment->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'Comment not accessible.'], 403);
        }

        $validated = $request->validate([
            'reaction' => 'required|string|in:+1,-1,heart,tada,rocket,eyes',
        ]);

        TicketCommentReaction::where([
            'comment_id' => $comment->id,
            'user_id' => $request->user()->id,
            'reaction' => $validated['reaction'],
        ])->delete();

        return response()->json(['success' => true]);
    }

    /**
     * List reactions on a comment with counts.
     */
    public function reactions(UnitScopedRequest $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = $request->accessibleIds();

        if (! in_array($ticket->unit_id, $accessibleIds) || $comment->ticket_id !== $ticket->id) {
            return response()->json(['message' => 'Comment not accessible.'], 403);
        }

        $reactions = $comment->reactions()
            ->with('user:id,n_code')
            ->get()
            ->groupBy('reaction')
            ->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'users' => $group->map->user,
                ];
            });

        return response()->json([
            'data' => $reactions,
        ]);
    }

    /**
     * Calculate thread depth in a single query (recursive CTE) — Issue #392.
     */
    private function getThreadDepth(TicketComment $comment): int
    {
        $depth = DB::selectOne(
            'WITH RECURSIVE cte AS (
                SELECT id, parent_id, 0 AS depth FROM ticket_comments WHERE id = ?
                UNION ALL
                SELECT tc.id, tc.parent_id, cte.depth + 1
                FROM ticket_comments tc
                INNER JOIN cte ON tc.id = cte.parent_id
            )
            SELECT MAX(depth) AS max_depth FROM cte',
            [$comment->id]
        );

        return (int) ($depth->max_depth ?? 0);
    }

    /**
     * Sanitize URL by blocking dangerous protocols and escaping attribute-breaking characters.
     * Fixes Issue #458: XSS via unquoted HTML event attributes in URLs (e.g., `onmouseover=alert(1)`).
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        $dangerousProtocols = ['javascript:', 'data:', 'vbscript:', 'file:', 'about:'];

        foreach ($dangerousProtocols as $proto) {
            if (stripos($url, $proto) === 0) {
                return '#';
            }
        }

        // Only allow http, https, mailto, tel
        if (! preg_match('/^(https?|mailto|tel):/i', $url)) {
            return '#';
        }

        // Escape any remaining dangerous chars in the URL value (e.g., spaces, quotes, angle brackets)
        return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Process markdown to HTML (simple implementation).
     */
    private function processMarkdown(string $body): string
    {
        // Basic markdown processing - in production use a proper parser like league/commonmark
        $html = e($body);
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        $html = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $html);
        $html = preg_replace('/`(.+?)`/', '<code>$1</code>', $html);
        $html = preg_replace_callback(
            '/\[(.+?)\]\((.+?)\)/',
            // Issue #425: URL goes through sanitizeUrl() which strips any character that could
            // break out of the href="..." attribute (quotes, angle brackets, control chars).
            // Note: $body is e()-escaped before this, so this is an extra defense-in-depth layer.
            fn ($m) => '<a href="'.$this->sanitizeUrl($m[2]).'" target="_blank" rel="noopener">'.$m[1].'</a>',
            $html
        );
        $html = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $html);
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);
        $html = nl2br($html);

        return $html;
    }

    /**
     * Extract @mentions from body, capped and unit-scoped (issue #863).
     *
     * Two independent bounds, both applied BEFORE the lookup so neither the
     * `whereIn` nor the job count can be inflated by the other:
     *
     * 1. `MAX_MENTIONS` on the matched handles — the amplification is a
     *    909-value `IN` list, not the notifications, so the cap sits here.
     * 2. The author's own `accessibleUnitIds()` — a mention can only reach a user
     *    whose linked person sits in a unit the author can see, which is the same
     *    contract every other user-visible query in the app follows. A target the
     *    author cannot read must not be able to receive a notification about a
     *    comment they will never see.
     *
     * The unit predicate is an UNCONDITIONAL `whereIn`, never `when($ids, …)` or
     * `! empty($ids)`: `accessibleUnitIds()` is legitimately `[]` for an account
     * with no `user_units` row and no `person.u_id`, and `[]` must compile to
     * `0 = 1` (zero recipients), not to "no restriction". A NULL `persons.u_id`
     * never matches a `whereIn`, so a unit-less person is in scope of nothing —
     * also fail-closed, and deliberate.
     *
     * @param  array<int>  $accessibleIds
     * @return array<string, int> n_code => user id
     */
    private function extractMentions(string $body, array $accessibleIds): array
    {
        preg_match_all('/@(\w+)/', $body, $matches);

        // `true` preserves the n_code keys notifyMentions() re-emits as the
        // notification body, and `array_slice` bounds the `whereIn` below.
        $usernames = array_slice(array_unique($matches[1] ?? []), 0, self::MAX_MENTIONS, true);

        if ($usernames === []) {
            return [];
        }

        return User::query()
            // The IN-filter lives inside a `where()` closure on purpose: a
            // top-level `whereIn()` re-types the chain to Query\Builder (no
            // larastan), which makes the `whereHas()` below unresolvable.
            ->where(fn ($userQuery) => $userQuery->whereIn('n_code', $usernames))
            ->whereHas('person', fn ($personQuery) => $personQuery->whereIn('u_id', $accessibleIds))
            ->pluck('id', 'n_code')
            ->all();
    }

    /**
     * Notify mentioned users.
     */
    private function notifyMentions(array $mentions, TicketComment $comment, User $author): void
    {
        foreach ($mentions as $username => $userId) {
            if ($userId === $author->id) {
                continue;
            }

            SendNotificationJob::dispatch(
                $userId,
                'mention',
                "شما در یک نظر به تیکت {$comment->ticket->ticket_code} منشن شدید",
                'منشن در نظر',
                'at-sign',
                'text-blue-500',
                route('tickets.inbox', $comment->ticket_id)
            );
        }
    }

    /**
     * Notify parent comment author of reply.
     */
    private function notifyReply(TicketComment $parentComment, TicketComment $reply, User $author): void
    {
        SendNotificationJob::dispatch(
            $parentComment->user_id,
            'reply',
            "{$author->n_code} به نظر شما در تیکت {$reply->ticket->ticket_code} پاسخ داد",
            'پاسخ به نظر',
            'message-circle',
            'text-green-500',
            route('tickets.inbox', $reply->ticket_id)
        );
    }

    /**
     * Notify comment author of reaction.
     */
    private function notifyReaction(TicketComment $comment, User $reactor, string $reaction): void
    {
        if ($comment->user_id === $reactor->id) {
            return;
        }

        $emojiMap = [
            '+1' => '👍',
            '-1' => '👎',
            'heart' => '❤️',
            'tada' => '🎉',
            'rocket' => '🚀',
            'eyes' => '👀',
        ];

        $emoji = $emojiMap[$reaction] ?? $reaction;

        SendNotificationJob::dispatch(
            $comment->user_id,
            'reaction',
            "{$reactor->n_code} واکنش {$emoji} را به نظر شما در تیکت {$comment->ticket->ticket_code} اضافه کرد",
            'واکنش جدید',
            'smile',
            'text-yellow-500',
            route('tickets.inbox', $comment->ticket_id)
        );
    }
}
