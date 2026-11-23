<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Identity\Models\User;
use App\Domains\Orders\Models\Order;
use App\Domains\Tickets\Actions\TicketDesk;
use App\Domains\Tickets\Enums\TicketStatus;
use App\Domains\Tickets\Enums\TicketTopic;
use App\Domains\Tickets\Exceptions\TicketException;
use App\Domains\Tickets\Models\Ticket;
use App\Domains\Tickets\Models\TicketAttachment;
use App\Domains\Tickets\Models\TicketMessage;
use App\Shared\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Service tickets for users (spec 1.9 / Part 11 / Part 20): list with All · Pending · Awaiting Reply · Closed and
 * counts, new ticket with topic follow-up forms and an optional order, thread with reply / close / reopen /
 * rating, attachments. Only the ticket owner sees a ticket; internal staff notes never leave the admin.
 */
final class TicketController
{
    public function index(Request $request): Response
    {
        $user = self::user($request);
        $status = is_string($s = $request->query('status')) ? TicketStatus::tryFrom($s) : null;
        $base = Ticket::query()->where('user_id', $user->id);

        $counts = ['all' => (clone $base)->count()];
        foreach (TicketStatus::cases() as $case) {
            $counts[$case->value] = (clone $base)->where('status', $case->value)->count();
        }

        $tickets = (clone $base)->with('order')->when($status !== null, fn (Builder $q) => $q->where('status', $status?->value))
            ->orderByDesc('last_reply_at')->orderByDesc('id')->paginate(20)->withQueryString();

        return Inertia::render('Tickets/Index', [
            'status' => $status?->value,
            'counts' => $counts,
            'tickets' => [
                'data' => collect($tickets->items())->map(static fn (Ticket $t): array => [
                    'id' => $t->public_id,
                    'href' => $t->path(),
                    'topic' => $t->topic->value,
                    'subject' => $t->subject,
                    'order' => $t->order?->number,
                    'status' => $t->status->value,
                    'createdAt' => $t->created_at->toIso8601String(),
                    'lastReplyAt' => $t->last_reply_at?->toIso8601String(),
                ])->all(),
                'page' => $tickets->currentPage(),
                'lastPage' => $tickets->lastPage(),
            ],
            'emails' => self::emails(),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = self::user($request);
        $orderId = is_string($o = $request->query('order')) ? $o : null;

        $orders = Order::query()->whereNotNull('paid_at')
            ->where(fn (Builder $q) => $q->where('buyer_id', $user->id)->orWhereHas('seller', fn (Builder $s) => $s->where('user_id', $user->id)))
            ->latest('id')->limit(50)->get(['id', 'public_id', 'number']);

        return Inertia::render('Tickets/Create', [
            'topics' => array_map(static fn (TicketTopic $t): array => ['value' => $t->value, 'fields' => array_map(
                static fn (string $key, array $rule): array => ['key' => $key, 'required' => $rule[0], 'max' => $rule[1]],
                array_keys($t->fields()), $t->fields(),
            )], TicketTopic::cases()),
            'orders' => $orders->map(static fn (Order $o): array => ['id' => $o->public_id, 'number' => $o->number])->all(),
            'selectedOrder' => $orders->firstWhere('public_id', $orderId)?->public_id,
            'maxFiles' => TicketDesk::MAX_FILES,
            'emails' => self::emails(),
        ]);
    }

    public function store(Request $request, TicketDesk $desk): RedirectResponse
    {
        $user = self::user($request);
        $topic = TicketTopic::tryFrom($request->string('topic')->toString());

        $rules = [
            'topic' => ['required', Rule::in(TicketTopic::values())],
            'subject' => ['required', 'string', 'min:5', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'order' => ['nullable', 'string', 'size:26'],
            'files' => ['array', 'max:'.TicketDesk::MAX_FILES],
            'files.*' => TicketDesk::FILE_RULES,
        ];
        foreach ($topic?->fields() ?? [] as $key => [$required, $max]) {
            $rules["form.{$key}"] = [$required ? 'required' : 'nullable', 'string', "max:{$max}"];
        }
        $request->validate($rules);

        $order = null;
        if ($request->filled('order')) {
            $order = Order::query()->where('public_id', $request->string('order')->toString())->first();
            if ($order === null) {
                return back()->withErrors(['order' => __('ticket.error.order')]);
            }
        }

        $form = [];
        foreach (array_keys($topic?->fields() ?? []) as $key) {
            $value = $request->input("form.{$key}");
            if (is_string($value) && trim($value) !== '') {
                $form[$key] = trim($value);
            }
        }

        try {
            $ticket = $desk->open($user, [
                'topic' => $topic ?? TicketTopic::Other,
                'subject' => $request->string('subject')->toString(),
                'body' => $request->string('body')->toString(),
                'order_id' => $order?->id,
                'form_data' => $form,
            ], self::files($request));
        } catch (TicketException $e) {
            return back()->withErrors(['ticket' => $e->getMessage()]);
        }

        return redirect($ticket->path())->with('success', __('ticket.created'));
    }

    public function show(Request $request, Settings $settings, string $publicId): Response
    {
        $ticket = $this->own($request, $publicId);
        $ticket->load(['messages.attachments', 'order', 'dispute']);
        $locale = app()->getLocale();
        $reopenUntil = $ticket->closed_at?->copy()->addDays($settings->int('ticket_reopen_days'));

        return Inertia::render('Tickets/Show', [
            'ticket' => [
                'id' => $ticket->public_id,
                'href' => $ticket->path(),
                'topic' => $ticket->topic->value,
                'subject' => $ticket->subject,
                'status' => $ticket->status->value,
                'form' => $ticket->form_data ?? [],
                'order' => $ticket->order === null ? null : ['number' => $ticket->order->number, 'href' => $ticket->order->buyer_id === $ticket->user_id ? "/buy/orders/{$ticket->order->public_id}" : "/seller/orders/{$ticket->order->public_id}"],
                'dispute' => $ticket->dispute?->path(),
                'createdAt' => $ticket->created_at->toIso8601String(),
                'closedAt' => $ticket->closed_at?->toIso8601String(),
                'satisfaction' => $ticket->satisfaction,
                'messages' => $ticket->messages->filter(static fn (TicketMessage $m): bool => ! $m->is_internal)->sortBy('id')->map(static fn (TicketMessage $m): array => [
                    'id' => $m->id,
                    'role' => $m->author_role,
                    'body' => $m->author_role === 'system' ? self::systemText($m, $locale) : $m->body,
                    'at' => $m->created_at->toIso8601String(),
                    'files' => $m->attachments->map(static fn (TicketAttachment $a): array => [
                        'name' => $a->original_name,
                        'size' => $a->size,
                        'href' => "/tickets/{$m->ticket_id}/files/{$a->id}",
                    ])->values()->all(),
                ])->values()->all(),
            ],
            'can' => [
                'reply' => $ticket->status !== TicketStatus::Closed,
                'close' => $ticket->status !== TicketStatus::Closed,
                'reopen' => $ticket->status === TicketStatus::Closed && $ticket->dispute_id === null && $reopenUntil !== null && $reopenUntil->isFuture(),
                'rate' => $ticket->status === TicketStatus::Closed && $ticket->satisfaction === null,
            ],
            'reopenUntil' => $reopenUntil?->toIso8601String(),
            'maxFiles' => TicketDesk::MAX_FILES,
        ]);
    }

    public function reply(Request $request, TicketDesk $desk, string $publicId): RedirectResponse
    {
        $request->validate([
            'body' => ['nullable', 'string', 'max:5000', 'required_without:files'],
            'files' => ['array', 'max:'.TicketDesk::MAX_FILES],
            'files.*' => TicketDesk::FILE_RULES,
        ]);

        return $this->run($request, $publicId, fn (Ticket $t, User $u) => $desk->userReply($t, $u, $request->string('body')->toString(), self::files($request)), 'ticket.replied');
    }

    public function close(Request $request, TicketDesk $desk, string $publicId): RedirectResponse
    {
        return $this->run($request, $publicId, fn (Ticket $t, User $u) => $desk->close($t, 'user', $u), 'ticket.closed');
    }

    public function reopen(Request $request, TicketDesk $desk, string $publicId): RedirectResponse
    {
        return $this->run($request, $publicId, fn (Ticket $t, User $u) => $desk->reopen($t, $u), 'ticket.reopened');
    }

    public function rate(Request $request, TicketDesk $desk, string $publicId): RedirectResponse
    {
        $request->validate(['score' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:500']]);

        return $this->run($request, $publicId, fn (Ticket $t, User $u) => $desk->rate($t, $u, $request->integer('score'), $request->string('comment')->toString()), 'ticket.rated');
    }

    public function file(Request $request, int $ticket, int $attachment): StreamedResponse
    {
        $user = self::user($request);
        $file = TicketAttachment::query()->whereKey($attachment)->where('ticket_id', $ticket)
            ->whereHas('ticket', fn (Builder $q) => $q->where('user_id', $user->id))
            ->whereHas('message', fn (Builder $q) => $q->where('is_internal', false))
            ->first();
        abort_if($file === null, 404);

        return Storage::disk('local')->response($file->file_path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  callable(Ticket, User): mixed  $action
     */
    private function run(Request $request, string $publicId, callable $action, string $success): RedirectResponse
    {
        $ticket = $this->own($request, $publicId);

        try {
            $action($ticket, self::user($request));
        } catch (TicketException $e) {
            return back()->withErrors(['ticket' => $e->getMessage()]);
        }

        return back()->with('success', __($success));
    }

    private function own(Request $request, string $publicId): Ticket
    {
        $ticket = Ticket::query()->where('public_id', $publicId)->where('user_id', self::user($request)->id)->first();
        abort_if($ticket === null, 404);

        return $ticket;
    }

    private static function systemText(TicketMessage $m, string $locale): string
    {
        $key = is_string($m->meta['key'] ?? null) ? $m->meta['key'] : $m->body;
        $text = __($key, [], $locale);

        return is_string($text) ? $text : $key;
    }

    /**
     * @return array{merchant: string, customer: string, dispute: string}
     */
    private static function emails(): array
    {
        return [
            'merchant' => config()->string('x2key.support.merchant_email'),
            'customer' => config()->string('x2key.support.customer_email'),
            'dispute' => config()->string('x2key.support.dispute_email'),
        ];
    }

    /**
     * @return list<UploadedFile>
     */
    private static function files(Request $request): array
    {
        $files = $request->file('files');

        return is_array($files) ? array_values($files) : [];
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
