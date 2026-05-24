<?php

namespace App\Support;

final class CorsAllowedOrigins
{
    private const LOCAL_ENVIRONMENTS = ['local', 'development', 'testing'];

    private const LOCAL_ORIGINS = [
        'http://localhost:8081',
        'http://localhost:19006',
        'http://127.0.0.1:8081',
        'http://127.0.0.1:19006',
    ];

    /**
     * @return list<string>
     */
    public static function fromEnvironment(?string $appEnvironment, ?string $frontendUrl, ?string $configuredOrigins): array
    {
        $origins = [
            ...self::parseOrigins($frontendUrl),
            ...self::parseOrigins($configuredOrigins),
        ];

        if (in_array((string) $appEnvironment, self::LOCAL_ENVIRONMENTS, true)) {
            $origins = [
                ...$origins,
                ...self::LOCAL_ORIGINS,
            ];
        }

        return array_values(array_unique(array_map(
            static fn (string $origin): string => rtrim($origin, '/'),
            array_filter($origins)
        )));
    }

    /**
     * @return list<string>
     */
    private static function parseOrigins(?string $origins): array
    {
        if ($origins === null || trim($origins) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            explode(',', $origins)
        )));
    }
}
