<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Ledger\Actions\PostOrderPayments;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Models\Payment;
use App\Domains\Risk\Enums\RiskDecision;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Risk\Support\RiskEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * What happens once the order is fully paid (PSP capture, or credits covering everything — spec 9.2 "same
 * state machine"): the PAYMENT_CAPTURED journal is posted (spec 6.1: on capture, before the risk outcome),
 * then capture-time risk rules decide between `paid` and `payment_review` — all in one transaction.
 */
final class CapturePath
{
    public function __construct(
        private readonly RiskEvaluator $risk,
        private readonly OrderStateMachine $machine,
        private readonly PostOrderPayments $ledger,
    ) {}

    public function afterCapture(Order $order, ?Payment $payment): Order
    {
        return DB::transaction(function () use ($order, $payment): Order {
            $this->ledger->captured($order);
            $result = $this->risk->atCapture($order, $payment);

            if ($result->decision !== RiskDecision::Pass) {
                RiskFlag::raise('order', $order->id, 'payment_review', $order->risk_score + $result->score, ['rules' => $result->rules()]);

                return $this->machine->sendToReview($order, $result->rules());
            }

            return $this->machine->markPaid($order);
        });
    }
}
