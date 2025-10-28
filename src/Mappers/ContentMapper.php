<?php
declare(strict_types=1);

namespace App\Mappers;

final class ContentMapper
{
    public static function fromRow(array $row): array
    {
        $attributes = json_decode($row['attributes'], true) ?: [];
        $assetsRaw  = json_decode($row['assets_raw'], true) ?: [];

        // Bol attributes format
        $attributeList = [];
        foreach ($attributes as $key => $val) {
            if ($val === null || $val === '') continue;
            $attributeList[] = [
                'id' => (string)$key,
                'values' => [['value' => (string)$val]],
            ];
        }

        // Bol assets format
        $assetList = [];
        foreach ($assetsRaw as $typeKey => $assetGroup) {
            foreach ($assetGroup as $asset) {
                $assetList[] = [
                    'type' => $asset['type'] ?? 'IMAGE',
                    'imageType' => $asset['imageType'] ?? 'Packshot',
                    'resources' => $asset['resources'] ?? [],
                ];
            }
        }

        return [
            'ean' => $row['ean'],
            'language' => $row['language'],
            'attributes' => $attributeList,
            'assets' => $assetList,
        ];
    }
}
