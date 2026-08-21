<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketCommentReaction;
use App\Models\User;
use App\Services\AccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TicketCommentController extends Controller
{
    /**
     * List comments for a ticket.
     */
    public function index(Request $request, Ticket $ticket): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

        if (! in_array($ticket->unit_id, $accessibleIds)) {
            return response()->json(['message' => 'Ticket not accessible.'], 403);
        }

        $threaded = $request->boolean('threaded');
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
    public function store(Request $request, Ticket $ticket): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

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
        $mentions = $this->extractMentions($validated['body']);

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
    public function show(Request $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

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
    public function update(Request $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

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
    public function destroy(Request $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

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
    public function react(Request $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

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
    public function unreact(Request $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

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
    public function reactions(Request $request, Ticket $ticket, TicketComment $comment): JsonResponse
    {
        $accessibleIds = app(AccessService::class)->accessibleUnitIds($request->user());

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
            "WITH RECURSIVE cte AS (
                SELECT id, parent_id, 0 AS depth FROM ticket_comments WHERE id = ?
                UNION ALL
                SELECT tc.id, tc.parent_id, cte.depth + 1
                FROM ticket_comments tc
                INNER JOIN cte ON tc.id = cte.parent_id
            )
            SELECT MAX(depth) AS max_depth FROM cte",
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
        if (!preg_match('/^(https?|mailto|tel):/i', $url)) {
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
            fn($m) => '<a href="' . $this->sanitizeUrl($m[2]) . '" target="_blank" rel="noopener">' . $m[1] . '</a>',
            $html
        );
        $html = preg_replace('/^> (.+)$/m', '<blockquote>$1</blockquote>', $html);
        $html = preg_replace('/^- (.+)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);
        $html = nl2br($html);
        return $html;
    }

    /**
     * Extract @mentions from body.
     */
    private function extractMentions(string $body): array
    {
        preg_match_all('/@(\w+)/', $body, $matches);
        $usernames = array_unique($matches[1] ?? []);
        
        if (empty($usernames)) {
            return [];
        }

        return User::whereIn('n_code', $usernames)->pluck('id', 'n_code')->toArray();
    }

    /**
     * Notify mentioned users.
     */
    private function notifyMentions(array $mentions, TicketComment $comment, User $author): void
    {
        foreach ($mentions as $username => $userId) {
            if ($userId === $author->id) continue;

            \App\Services\NotificationService::send(
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
        \App\Services\NotificationService::send(
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
        if ($comment->user_id === $reactor->id) return;

        $emojiMap = [
            '+1' => '👍',
            '-1' => '👎',
            'heart' => '❤️',
            'tada' => '🎉',
            'rocket' => '🚀',
            'eyes' => '👀',
        ];

        $emoji = $emojiMap[$reaction] ?? $reaction;

        \App\Services\NotificationService::send(
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
