<?php

declare(strict_types=1);

namespace Passkeys\Service;

class AaguidLabelResolver
{
    /**
     * @var array<string, string>|null
     */
    private static ?array $map = null;

    /**
     * @param string|null $aaguidBinary 16-byte raw AAGUID
     *
     * @return string|null
     */
    public function labelFor(?string $aaguidBinary): ?string
    {
        if (!$aaguidBinary || strlen($aaguidBinary) !== 16) {
            return null;
        }
        $hex = bin2hex($aaguidBinary);
        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );

        return self::map()[$uuid] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function map(): array
    {
        if (self::$map === null) {
            $path = dirname(__DIR__, 2) . '/resources/aaguid-map.json';
            $contents = (string)file_get_contents($path);
            /** @var array<string, string> $decoded */
            $decoded = json_decode($contents, true) ?? [];
            self::$map = $decoded;
        }

        return self::$map;
    }
}
