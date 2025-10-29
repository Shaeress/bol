<?php
declare(strict_types=1);

namespace App\Mappers;

final class OfferMapper
{
    public static function fromRow(array $row): array
    {
        $price = (float)$row['price'];
        $stock = max(0, (int)$row['stock']);
        $deliveryCode = $row['delivery_code'] ?? '1-8d';
        $onHoldByRetailer = $row['on_hold_by_retailer'] ?? ($stock <= 0);
        if (!$onHoldByRetailer && $stock <= 0) {
            $onHoldByRetailer = true;
        }

        return [
            'ean' => $row['ean'],
            'condition' => ['name' => 'NEW'],
            'onHoldByRetailer' => $onHoldByRetailer,
            'fulfilment' => [
                'method' => 'FBR',
                'deliveryCode' => $deliveryCode
            ],
            'pricing' => [
                'bundlePrices' => [[
                    'quantity' => 1,
                    'unitPrice' => $price,
                ]]
            ],
            'stock' => ['amount' => $stock, 'managedByRetailer' => true],
        ];
    }
}
