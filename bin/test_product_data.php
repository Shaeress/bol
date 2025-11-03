#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;
use App\Bol\BolClient;
use App\DB\PdoFactory;

// Load environment variables
if (file_exists(__DIR__ . '/../.env')) {
    (new Dotenv())->load(__DIR__ . '/../.env');
}

$ean = '5400977000013';

echo "BOL Product Data Test for EAN: {$ean}\n";
echo str_repeat("=", 50) . "\n\n";

try {
    $bol = new BolClient();
    
    echo "1. Check our local staging data:\n";
    echo str_repeat("-", 30) . "\n";
    
    try {
        $pdo = PdoFactory::make();
        $stmt = $pdo->prepare('SELECT * FROM bol_stg_offers WHERE ean = ? LIMIT 1');
        $stmt->execute([$ean]);
        $localData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($localData) {
            echo "✅ Found local staging data:\n";
            foreach ($localData as $key => $value) {
                echo "  {$key}: " . json_encode($value) . "\n";
            }
        } else {
            echo "❌ No local staging data found for EAN {$ean}\n";
        }
    } catch (\Throwable $e) {
        echo "❌ Local data check failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n2. Check our offer mapping:\n";
    echo str_repeat("-", 30) . "\n";
    
    try {
        $stmt = $pdo->prepare('SELECT * FROM bol_offer_map WHERE ean = ? LIMIT 1');
        $stmt->execute([$ean]);
        $mapData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($mapData) {
            echo "✅ Found offer mapping:\n";
            foreach ($mapData as $key => $value) {
                echo "  {$key}: " . json_encode($value) . "\n";
            }
        } else {
            echo "❌ No offer mapping found for EAN {$ean}\n";
        }
    } catch (\Throwable $e) {
        echo "❌ Mapping check failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n3. Check BOL inventory for this EAN:\n";
    echo str_repeat("-", 30) . "\n";
    
    try {
        $response = $bol->request('GET', '/retailer/inventory', [
            'headers' => [
                'Accept' => 'application/vnd.retailer.v10+json',
            ],
            'query' => ['ean' => $ean]
        ]);
        $inventoryData = json_decode((string) $response->getBody(), true);
        
        if (empty($inventoryData)) {
            echo "❌ No BOL inventory found for EAN {$ean}\n";
        } else {
            echo "✅ BOL inventory data:\n";
            echo json_encode($inventoryData, JSON_PRETTY_PRINT) . "\n";
        }
    } catch (\Throwable $e) {
        echo "❌ BOL inventory check failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n4. Check if we have any offers at all:\n";
    echo str_repeat("-", 30) . "\n";
    
    try {
        $response = $bol->request('GET', '/retailer/inventory', [
            'headers' => [
                'Accept' => 'application/vnd.retailer.v10+json',
            ]
        ]);
        $allInventory = json_decode((string) $response->getBody(), true);
        
        echo "✅ Total inventory items: " . count($allInventory) . "\n";
        
        if (!empty($allInventory)) {
            echo "Sample inventory items:\n";
            foreach (array_slice($allInventory, 0, 3) as $item) {
                echo "  - EAN: " . ($item['ean'] ?? 'N/A') . 
                     " | Stock: " . ($item['amount'] ?? 'N/A') . 
                     " | NCK: " . ($item['nck'] ?? 'N/A') . "\n";
            }
        }
    } catch (\Throwable $e) {
        echo "❌ Total inventory check failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("=", 50) . "\n";
    echo "SUMMARY for EAN {$ean}:\n";
    echo "• Local staging: " . (isset($localData) && $localData ? "✅ Found" : "❌ Not found") . "\n";
    echo "• Offer mapping: " . (isset($mapData) && $mapData ? "✅ Found" : "❌ Not found") . "\n"; 
    echo "• BOL inventory: " . (isset($inventoryData) && !empty($inventoryData) ? "✅ Found" : "❌ Not found") . "\n";
    
} catch (\Throwable $e) {
    echo "❌ Error initializing: " . $e->getMessage() . "\n";
}

echo "\nTest completed at: " . date('Y-m-d H:i:s') . "\n";