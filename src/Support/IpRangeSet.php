<?php

declare(strict_types=1);

namespace Botect\Support;

use InvalidArgumentException;

/** Packed, merged inclusive IPv4-mapped intervals; membership uses binary search. */
final readonly class IpRangeSet
{
    private string $packed;

    /** @param list<array{0:string, 1:string}> $intervals */
    public function __construct(array $intervals, ?string $packed = null)
    {
        if ($packed !== null) {
            if (strlen($packed) % 32 !== 0) {
                throw new InvalidArgumentException('Invalid packed intervals.');
            }
            $this->packed = $packed;

            return;
        }
        usort($intervals, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
        $merged = [];
        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;
            if ($last >= 0 && (strcmp($start, $merged[$last][1]) <= 0 || $start === self::successor($merged[$last][1]))) {
                if (strcmp($end, $merged[$last][1]) > 0) {
                    $merged[$last][1] = $end;
                }
            } else {
                $merged[] = [$start, $end];
            }
        }
        $this->packed = implode('', array_map(static fn (array $bounds): string => $bounds[0].$bounds[1], $merged));
    }

    public function packed(): string
    {
        return $this->packed;
    }

    private static function successor(string $bound): ?string
    {
        for ($i = 15; $i >= 0; $i--) {
            if (ord($bound[$i]) !== 255) {
                $bound[$i] = chr(ord($bound[$i]) + 1);

                return $bound;
            }
            $bound[$i] = "\0";
        }

        return null;
    }

    /** Revalidate mapped IPv4 using its underlying address, too. */
    public static function publicAddress(string $ip): bool
    {
        try {
            $packed = self::address($ip);
            if (substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
                $ip = inet_ntop(substr($packed, 12));
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public static function address(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new InvalidArgumentException('Invalid address.');
        }

        return strlen($packed) === 4 ? str_repeat("\0", 10)."\xff\xff".$packed : $packed;
    }

    /** @return array{0:string, 1:string} */
    public static function cidr(string $cidr): array
    {
        if (! str_contains($cidr, '/')) {
            $ip = self::address($cidr);

            return [$ip, $ip];
        }
        if (! preg_match('~^([^/]+)/([0-9]{1,3})$~D', $cidr, $parts)) {
            throw new InvalidArgumentException('Invalid crawler CIDR.');
        }
        $ip = @inet_pton($parts[1]);
        $bits = (int) $parts[2];
        if ($ip === false || $bits > strlen($ip) * 8) {
            throw new InvalidArgumentException('Invalid crawler CIDR.');
        }
        if (strlen($ip) === 4) {
            $bits += 96;
        }
        $ip = self::address($parts[1]);
        $mask = '';
        for ($i = 0; $i < 16; $i++) {
            $n = max(0, min(8, $bits - $i * 8));
            $mask .= chr($n === 0 ? 0 : (255 << (8 - $n)) & 255);
        }

        return [$ip & $mask, ($ip & $mask) | ~$mask];
    }

    public function contains(string $ip): bool
    {
        $ip = self::address($ip);
        $low = 0;
        $high = intdiv(strlen($this->packed), 32) - 1;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            if (strcmp($ip, substr($this->packed, $middle * 32, 16)) < 0) {
                $high = $middle - 1;
            } elseif (strcmp($ip, substr($this->packed, $middle * 32 + 16, 16)) > 0) {
                $low = $middle + 1;
            } else {
                return true;
            }
        }

        return false;
    }

    public static function networkPrefix(string $ip): string
    {
        $packed = self::address($ip);
        $v4 = substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff";
        $address = $v4 ? inet_ntop(substr($packed, 12, 3)."\0") : inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8));

        return $address.($v4 ? '/24' : '/64');
    }
}
