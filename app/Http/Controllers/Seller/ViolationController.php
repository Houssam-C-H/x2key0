<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seller;

use App\Domains\Identity\Models\User;
use App\Domains\Violations\Actions\DecideViolation;
use App\Domains\Violations\Enums\ViolationStatus;
use App\Domains\Violations\Exceptions\ViolationException;
use App\Domains\Violations\Models\Violation;
use App\Domains\Violations\Models\ViolationRule;
use App\Http\Controllers\Seller\Concerns\ResolvesSeller;
use App\Shared\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Violation Center (spec 1.8 / 17.2): counters (Pending · Under Appeal · Processed), filters by rule and status,
 * active points and the thresholds, appeal within `violation_appeal_days`.
 */
final class ViolationController
{
    use ResolvesSeller;

    public function index(Request $request, Settings $settings): Response
    {
        $profile = $this->seller($request);
        $locale = app()->getLocale();
        $status = is_string($s = $request->query('status')) ? ViolationStatus::tryFrom($s) : null;
        $rule = is_string($r = $request->query('rule')) ? $r : null;

        $base = Violation::query()->where('seller_profile_id', $profile->id);
        $rows = (clone $base)->with(['rule', 'order'])
            ->when($status !== null, fn ($q) => $q->where('status', $status?->value))
            ->when($rule !== null, fn ($q) => $q->whereHas('rule', fn ($w) => $w->where('code', $rule)))
            ->latest('id')->limit(200)->get();

        return Inertia::render('Seller/Violations', [
            'counts' => [
                'open' => (clone $base)->where('status', ViolationStatus::Open->value)->count(),
                'appealed' => (clone $base)->where('status', ViolationStatus::Appealed->value)->count(),
                'processed' => (clone $base)->whereIn('status', [ViolationStatus::Upheld->value, ViolationStatus::Revoked->value])->count(),
            ],
            'activePoints' => (int) (clone $base)->active()->sum('points'),
            'thresholds' => ['suspend' => $settings->int('violation_points_store_suspend'), 'ban' => $settings->int('violation_points_ban')],
            'filters' => ['status' => $status?->value, 'rule' => $rule],
            'rules' => ViolationRule::query()->orderBy('code')->get()->map(fn (ViolationRule $r): array => ['code' => $r->code, 'name' => $r->translate('name', $locale)])->all(),
            'rows' => $rows->map(fn (Violation $v): array => [
                'id' => $v->public_id,
                'rule' => $v->rule->translate('name', $locale),
                'code' => $v->rule->code,
                'penalty' => $v->rule->penalty->value,
                'points' => $v->points,
                'details' => $v->details,
                'order' => $v->order === null ? null : ['number' => $v->order->number, 'href' => "/seller/orders/{$v->order->public_id}"],
                'status' => $v->status->value,
                'canAppeal' => $v->canAppeal(),
                'appealDueAt' => $v->appeal_due_at->toIso8601String(),
                'appealText' => $v->appeal_text,
                'decisionNote' => $v->decision_note,
                'expiresAt' => $v->expires_at->toIso8601String(),
                'createdAt' => $v->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    public function appeal(Request $request, DecideViolation $decide, string $publicId): RedirectResponse
    {
        $profile = $this->seller($request);
        $request->validate(['text' => ['required', 'string', 'min:10', 'max:2000']]);
        $violation = Violation::query()->where('public_id', $publicId)->where('seller_profile_id', $profile->id)->first();
        abort_if($violation === null, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $decide->appeal($violation, $user, $request->string('text')->toString());
        } catch (ViolationException $e) {
            return back()->withErrors(['appeal' => $e->getMessage()]);
        }

        return back()->with('success', __('violation.appealed'));
    }
}
