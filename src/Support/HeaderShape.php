<?php

declare(strict_types=1);

namespace Botect\Support;

final class HeaderShape
{
    private const HEADERS = ['accept', 'accept-language', 'accept-encoding', 'cache-control', 'connection', 'cookie', 'host', 'pragma', 'referer', 'sec-ch-ua', 'sec-ch-ua-mobile', 'sec-ch-ua-platform', 'sec-fetch-dest', 'sec-fetch-mode', 'sec-fetch-site', 'sec-fetch-user', 'upgrade-insecure-requests', 'user-agent'];

    /** @param list<string> $names
     * @return list<string>
     */
    public static function normalize(array $names): array
    {
        $headers = [];
        foreach ($names as $name) {
            $name = strtolower($name);
            if (in_array($name, self::HEADERS, true) && ! in_array($name, $headers, true)) {
                $headers[] = $name;
            }
        }

        return $headers;
    }

    /** @param list<string> $names */
    public static function hash(array $names): string
    {
        return substr(hash('sha256', implode(',', self::normalize($names))), 0, 32);
    }
}
