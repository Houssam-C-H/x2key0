<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Actions;

use App\Domains\Delivery\Models\Delivery;
use App\Domains\Delivery\Models\DeliveryEvidence;
use App\Domains\Delivery\Models\ServiceMilestone;
use App\Domains\Identity\Models\User;
use App\Domains\Offers\Enums\DeliveryMethod;
use App\Domains\Orders\Enums\ActorType;
use App\Domains\Orders\Enums\SellerSubstatus;
use App\Domains\Orders\Exceptions\CheckoutException;
use App\Domains\Orders\Models\Order;
use App\Domains\Orders\StateMachines\OrderStateMachine;
use App\Domains\Storage\Contracts\SecretVault;
use App\Domains\Storage\Enums\StorageItemType;
use App\Domains\Storage\Support\StoragePayload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use SensitiveParameter;

/**
 * Manual delivery by the seller (spec 8.2), per delivery method:
 *
 * - digital_key / code / file: one code (or text) per unit, sealed;
 * - account_transfer: one account per unit (`login|password|email|email_password|recovery_codes|notes`), sealed;
 * - manual_top_up / send_gift: a note (transaction or gift id) is required, a screenshot is optional;
 * - service: progress milestones (`milestone()`), delivered when they reach 100 %.
 *
 * Secrets never reach notes, logs or the timeline; evidence files go to the private disk with their SHA-256.
 */
final class DeliverOrder
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly SecretVault $vault,
    ) {}

    public function handle(Order $order, User $seller, int $units, #[SensitiveParameter] string $secrets, ?string $note, ?UploadedFile $evidence): Order
    {
        $order->loadMissing('item.offer');
        $method = $order->item->offer->delivery_method;
        $note = $note === null ? null : mb_substr(trim($note), 0, 1000);

        if ($method === DeliveryMethod::Service) {
            throw CheckoutException::because('order.error.use_milestones');
        }

        $sealed = [];
        if (in_array($method, [DeliveryMethod::DigitalKey, DeliveryMethod::Code, DeliveryMethod::File, DeliveryMethod::AccountTransfer], true)) {
            $type = $method === DeliveryMethod::AccountTransfer ? StorageItemType::Account : StorageItemType::Text;
            $parsed = StoragePayload::parse($type, $secrets);
            if ($parsed['invalid'] > 0 || count($parsed['items']) !== $units) {
                throw CheckoutException::because('order.error.secrets_count', ['count' => $units], 'secrets');
            }
            foreach ($parsed['items'] as $item) {
                $sealed[] = ['kind' => $method === DeliveryMethod::AccountTransfer ? 'account' : 'key', 'sealed' => $this->vault->seal($item['plaintext'])];
            }
        } elseif ($note === null || $note === '') {
            throw CheckoutException::because('order.error.note_required', field: 'note');
        }

        return DB::transaction(function () use ($order, $seller, $units, $method, $note, $sealed, $evidence): Order {
            $delivery = Delivery::query()->forceCreate([
                'order_item_id' => $order->item->id,
                'quantity' => $units,
                'method' => $method->value,
                'delivered_by_type' => 'seller',
                'delivered_by_id' => $seller->id,
                'note' => $note,
            ]);

            foreach ($sealed as $secret) {
                DB::table('delivery_secrets')->insert([
                    'delivery_id' => $delivery->id,
                    'kind' => $secret['kind'],
                    'ciphertext' => $secret['sealed']->ciphertext,
                    'dek_wrapped' => $secret['sealed']->wrappedKey,
                    'created_at' => now(),
                ]);
            }

            if ($evidence !== null) {
                $path = $evidence->store("evidence/{$order->public_id}", 'local');
                DeliveryEvidence::query()->forceCreate([
                    'delivery_id' => $delivery->id,
                    'file_path' => (string) $path,
                    'mime' => (string) $evidence->getMimeType(),
                    'size' => (int) $evidence->getSize(),
                    'sha256' => hash_file('sha256', $evidence->getRealPath()),
                    'scan_status' => 'pending',
                    'uploaded_by' => $seller->id,
                ]);
            }

            return $this->machine->recordDelivery($order, $units, ActorType::Seller, $seller, ['delivery_id' => $delivery->id]);
        });
    }

    /** Service orders (boosting): cumulative progress; 100 % delivers every remaining unit. */
    public function milestone(Order $order, User $seller, string $title, int $percent, ?string $note): Order
    {
        $order->loadMissing('item.offer');

        if ($order->item->offer->delivery_method !== DeliveryMethod::Service) {
            throw CheckoutException::because('order.error.not_service');
        }

        $max = ServiceMilestone::query()->where('order_item_id', $order->item->id)->max('percent');
        $last = is_numeric($max) ? (int) $max : 0;
        if ($percent <= $last || $percent > 100) {
            throw CheckoutException::because('order.error.milestone_percent', ['last' => $last], 'percent');
        }

        return DB::transaction(function () use ($order, $seller, $title, $percent, $note): Order {
            ServiceMilestone::query()->forceCreate([
                'order_item_id' => $order->item->id,
                'title' => mb_substr(trim($title), 0, 160),
                'percent' => $percent,
                'status' => 'done',
                'note' => $note === null ? null : mb_substr(trim($note), 0, 1000),
            ]);

            if ($percent === 100) {
                return $this->machine->recordDelivery($order, $order->item->remaining(), ActorType::Seller, $seller, ['milestone' => $percent]);
            }

            $order->refresh();
            if ($order->seller_substatus === SellerSubstatus::New || $order->seller_substatus === SellerSubstatus::Restocking) {
                return $this->machine->setSubstatus($order, SellerSubstatus::InTrading, ActorType::Seller, $seller);
            }

            return $order;
        });
    }

    /** Evidence files are private: served only through an authorised controller. */
    public static function evidencePath(DeliveryEvidence $evidence): string
    {
        return Storage::disk('local')->path($evidence->file_path);
    }
}
