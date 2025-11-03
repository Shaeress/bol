<?php
declare(strict_types=1);

namespace App\Mappers;

final class OfferMapper
{
    public static function fromRow(array $row): array
    {
        $price = (float)$row['price'];
        
        // Calculate stock based on use_vdv_stock setting
        $baseStock = max(0, (int)$row['stock']);
        $vdvStock = max(0, (int)$row['vdv_stock']);
        $useVdvStock = (int)($row['use_vdv_stock']);
        
        if ($useVdvStock === 1) {
            $stock = $baseStock + $vdvStock;
        } else {
            $stock = $baseStock;
        }
        
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
    
    public static function formCoreHash(array $offerPayload): string
    {
        $wantCore = [
            'onHoldByRetailer' => (bool)($offerPayload['onHoldByRetailer'] ?? false),
            'fulfilment'       => [
                'method' => 'FBR',
                'deliveryCode' => $offerPayload['fulfilment']['deliveryCode'] ?? '1-2 weken',
            ],
        ];
        return hash('sha256', json_encode($wantCore));
    }
}
