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

        return [
            'ean' => $row['ean'],
            'condition' => ['name' => 'NEW'],
            'onHoldByRetailer' => $stock <= 0,
            'fulfilment' => ['deliveryCode' => $deliveryCode],
            'pricing' => [
                'bundlePrices' => [[
                    'quantity' => 1,
                    'price' => $price,
                ]]
            ],
            'stock' => ['amount' => $stock],
        ];
    }
}
