<?php

declare(strict_types=1);

namespace App\Domains\Payments\Actions;

use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Payments\Models\Payment;
use App\Domains\Risk\Enums\RiskDecision;
use App\Domains\Risk\Models\RiskFlag;
use App\Domains\Risk\Support\RiskEvaluator;

/**
 * What happens once the order is fully paid (PSP capture, or credits covering everything — spec 9.2 "same
 * state machine"): capture-time risk rules decide between `paid` and `payment_review`.
 */
final class CapturePath
{
    public function __construct(
        private readonly RiskEvaluator $risk,
        private readonly OrderStateMachine $machine,
    ) {}

    public function afterCapture(Order $order, ?Payment $payment): Order
    {
        $result = $this->risk->atCapture($order, $payment);

        if ($result->decision !== RiskDecision::Pass) {
            RiskFlag::raise('order', $order->id, 'payment_review', $order->risk_score + $result->score, ['rules' => $result->rules()]);

            return $this->machine->sendToReview($order, $result->rules());
        }

        return $this->machine->markPaid($order);
    }
}
