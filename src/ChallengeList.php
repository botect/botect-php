<?php

declare(strict_types=1);

namespace Botect;

use Botect\Support\IpRangeSet;
use UnexpectedValueException;

final class ChallengeList
{
    private ?IpRangeSet $crawlers = null;

    private array $trusted = [];

    private array $shapes;

    private function __construct(private array $data, private ?\Closure $catalogueLoader = null)
    {
        $this->shapes = array_fill_keys($data['header_shapes'], true);
        if ($catalogueLoader !== null) {
            return;
        }
        $this->crawlers = new IpRangeSet(array_map(IpRangeSet::cidr(...), $data['crawler_cidrs']));
        $groups = [];
        foreach ($data['trusted_ranges'] as $range) {
            $groups[$range['expires_at'] ?? 'forever'][] = [hex2bin($range['start']), hex2bin($range['end'])];
        }
        $this->trusted = array_map(static fn (array $ranges): IpRangeSet => new IpRangeSet($ranges), $groups);
    }

    /** The returned snapshot always includes its exact, complete catalogue. */
    public static function fromArray(array $data, ?self $previous = null, ?int $now = null): self
    {
        $now ??= time();
        $invalid = static function (): never {
            throw new UnexpectedValueException('Invalid Botect challenge list.');
        };
        if (($data['schema_version'] ?? null) !== 1 || ! is_bool($data['enabled'] ?? null)
            || ! in_array($data['status'] ?? null, ['ok', 'disabled', 'no_source', 'stale_source', 'unavailable'], true)
            || ! is_string($data['crawler_directory_version'] ?? null)
            || ! in_array($data['crawler_catalogue'] ?? null, ['full', 'unchanged'], true)) {
            $invalid();
        }
        foreach (['generated_at', 'refresh_after', 'expires_at'] as $key) {
            if (! is_int($data[$key] ?? null) || $data[$key] < 0) {
                $invalid();
            }
        }
        if ($data['generated_at'] > $now + 30 || $data['refresh_after'] !== $data['generated_at'] + 300
            || $data['expires_at'] < $data['generated_at'] || $data['expires_at'] > $data['generated_at'] + 1800) {
            $invalid();
        }
        foreach (['header_shapes', 'ranges', 'trusted_ranges'] as $key) {
            if (! is_array($data[$key] ?? null) || ! array_is_list($data[$key])) {
                $invalid();
            }
        }
        foreach (['source_run_id', 'source_window_end'] as $key) {
            if (! array_key_exists($key, $data) || ($data[$key] !== null && (! is_int($data[$key]) || $data[$key] < 0))) {
                $invalid();
            }
        }
        if ($data['enabled']) {
            if ($data['status'] !== 'ok' || ! is_int($data['source_run_id']) || $data['source_run_id'] < 1
                || ! is_int($data['source_window_end']) || $data['source_window_end'] > $data['generated_at']
                || $data['expires_at'] <= $data['generated_at']) {
                $invalid();
            }
        } elseif ($data['status'] === 'ok' || $data['source_run_id'] !== null || $data['source_window_end'] !== null
            || $data['expires_at'] !== $data['generated_at'] || $data['header_shapes'] !== [] || $data['trusted_ranges'] !== []
            || $data['crawler_catalogue'] !== 'full' || ($data['crawler_cidrs'] ?? null) !== []) {
            $invalid();
        }
        foreach ($data['header_shapes'] as $shape) {
            if (! is_string($shape) || ! preg_match('/^[a-f0-9]{32}$/D', $shape)) {
                $invalid();
            }
        }
        if ($data['crawler_catalogue'] === 'unchanged') {
            if ($previous === null || $previous->version() !== $data['crawler_directory_version'] || array_key_exists('crawler_cidrs', $data)) {
                $invalid();
            }
            $data['crawler_cidrs'] = $previous->toArray()['crawler_cidrs'];
        }
        if (! is_array($data['crawler_cidrs'] ?? null) || ! array_is_list($data['crawler_cidrs'])) {
            $invalid();
        }
        foreach ($data['crawler_cidrs'] as $cidr) {
            if (! is_string($cidr)) {
                $invalid();
            }
            IpRangeSet::cidr($cidr);
        }
        foreach (['header_shapes', 'crawler_cidrs'] as $key) {
            $sorted = array_values(array_unique($data[$key]));
            sort($sorted, SORT_STRING);
            if ($sorted !== $data[$key]) {
                $invalid();
            }
        }
        foreach ($data['trusted_ranges'] as $range) {
            if (! is_array($range) || ! is_string($range['start'] ?? null) || ! is_string($range['end'] ?? null)
                || ! preg_match('/^[a-f0-9]{32}$/D', $range['start']) || ! preg_match('/^[a-f0-9]{32}$/D', $range['end'])
                || strcmp($range['start'], $range['end']) > 0 || ! array_key_exists('expires_at', $range)
                || ($range['expires_at'] !== null && (! is_int($range['expires_at']) || $range['expires_at'] < 0))) {
                $invalid();
            }
        }
        $data['crawler_catalogue'] = 'full';

        return new self($data);
    }

    /** Only locally published, previously validated metadata enters this path. */
    public static function fromStored(array $metadata, \Closure $catalogueLoader): self
    {
        if (($metadata['storage_version'] ?? null) !== 1 || ! is_string($metadata['catalogue_id'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/D', $metadata['catalogue_id']) || ! is_array($metadata['header_shapes'] ?? null)
            || ! is_bool($metadata['enabled'] ?? null) || ! is_int($metadata['expires_at'] ?? null) || ! is_int($metadata['refresh_after'] ?? null)) {
            throw new UnexpectedValueException('Invalid stored challenge metadata.');
        }

        return new self($metadata, $catalogueLoader);
    }

    public function metadata(string $catalogueId): array
    {
        $data = $this->data;
        unset($data['crawler_cidrs'], $data['trusted_ranges']);

        return $data + ['storage_version' => 1, 'catalogue_id' => $catalogueId];
    }

    /** Publish full catalogue and compiled intervals before exposing their immutable ID. */
    public function catalogue(): array
    {
        $this->loadCatalogue();

        return ['crawler_cidrs' => $this->data['crawler_cidrs'], 'trusted_ranges' => $this->data['trusted_ranges'],
            'packed_crawlers' => base64_encode($this->crawlers->packed()),
            'packed_trusted' => array_map(static fn (IpRangeSet $ranges): string => base64_encode($ranges->packed()), $this->trusted)];
    }

    private function loadCatalogue(): void
    {
        if ($this->crawlers !== null) {
            return;
        }
        $catalogue = ($this->catalogueLoader)();
        if (! is_array($catalogue) || ! is_array($catalogue['crawler_cidrs'] ?? null) || ! is_array($catalogue['trusted_ranges'] ?? null)
            || ! is_string($catalogue['packed_crawlers'] ?? null) || ! is_array($catalogue['packed_trusted'] ?? null)) {
            throw new UnexpectedValueException('Unavailable stored challenge catalogue.');
        }
        $decode = static function (string $value): IpRangeSet {
            $packed = base64_decode($value, true);
            if ($packed === false) {
                throw new UnexpectedValueException('Invalid packed catalogue.');
            }

            return new IpRangeSet([], $packed);
        };
        $crawlers = $decode($catalogue['packed_crawlers']);
        $trusted = array_map($decode, $catalogue['packed_trusted']);
        $this->data['crawler_cidrs'] = $catalogue['crawler_cidrs'];
        $this->data['trusted_ranges'] = $catalogue['trusted_ranges'];
        $this->crawlers = $crawlers;
        $this->trusted = $trusted;
    }

    public function toArray(): array
    {
        $this->loadCatalogue();
        $data = $this->data;
        unset($data['storage_version'], $data['catalogue_id']);

        return $data;
    }

    public function version(): string
    {
        return $this->data['crawler_directory_version'];
    }

    public function usable(?int $now = null): bool
    {
        return $this->data['enabled'] && $this->data['expires_at'] > ($now ?? time());
    }

    public function refreshDue(?int $now = null): bool
    {
        return $this->data['refresh_after'] <= ($now ?? time());
    }

    public function hasShape(string $shape): bool
    {
        return isset($this->shapes[$shape]);
    }

    public function exempt(string $ip, ?int $now = null): bool
    {
        $this->loadCatalogue();
        if ($this->crawlers->contains($ip)) {
            return true;
        }
        foreach ($this->trusted as $expiry => $ranges) {
            if (($expiry === 'forever' || $expiry > ($now ?? time())) && $ranges->contains($ip)) {
                return true;
            }
        }

        return false;
    }
}
