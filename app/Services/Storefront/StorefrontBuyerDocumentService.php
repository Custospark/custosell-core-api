<?php

declare(strict_types=1);

namespace App\Services\Storefront;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\Sale;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * B2C buyer document access — sale receipt / invoice for storefront orders only.
 */
class StorefrontBuyerDocumentService
{
    public function __construct(
        private readonly StorefrontService $storefront,
    ) {}

    public function saleForBuyer(int $buyerUserId, int $orderId): Sale
    {
        $order = $this->requireBuyerOrder($buyerUserId, $orderId);
        $sale = Sale::query()
            ->with(['business', 'customer', 'user', 'saleItems', 'payments'])
            ->where('order_id', $order->id)
            ->first();

        if (!$sale) {
            throw new NotFoundHttpException('Receipt is not available yet. The shop has not completed this order.');
        }

        return $sale;
    }

    public function invoiceForBuyer(int $buyerUserId, int $orderId): Invoice
    {
        $order = $this->requireBuyerOrder($buyerUserId, $orderId);
        $sale = Sale::query()->where('order_id', $order->id)->first();
        if (!$sale) {
            throw new NotFoundHttpException('Invoice is not available yet.');
        }

        $invoice = Invoice::query()
            ->with(['items', 'customer', 'payments', 'business', 'purchaseOrder'])
            ->where('sale_id', $sale->id)
            ->first();

        if (!$invoice) {
            throw new NotFoundHttpException('Invoice is not available yet. The shop has not invoiced this sale.');
        }

        return $invoice;
    }

    private function requireBuyerOrder(int $buyerUserId, int $orderId): Order
    {
        $order = $this->storefront->findBuyerOrder($buyerUserId, $orderId);
        if (!$order) {
            throw new NotFoundHttpException('Order not found.');
        }

        return $order;
    }
}
