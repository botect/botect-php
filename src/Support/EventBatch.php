<?php

declare(strict_types=1);

namespace Botect\Support;

use DateTimeImmutable;
use InvalidArgumentException;

final class EventBatch
{
    private const NUMBERS = [
        'mouse_entropy' => 1, 'scroll_velocity' => 1000000, 'visibility_changes' => 10000,
        'first_input_delay_ms' => 86400000, 'total_ms' => 86400000, 'visible_ms' => 86400000,
        'mouse_moves' => 1000000, 'pointer_moves' => 1000000, 'scroll_events' => 1000000,
        'click_events' => 100000, 'key_events' => 100000, 'scroll_max_depth_pct' => 100,
        'max_touch_points' => 100, 'coarse_pointer' => 1, 'time_to_first_input_ms' => 86400000,
    ];

    private const BOOLEANS = ['js_passed', 'webdriver', 'headless', 'headless_ua', 'zero_viewport', 'missing_plugins', 'navigator_anomaly'];

    /** @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    public static function validate(array $body): array
    {
        if (array_diff(array_keys($body), ['events', 'session_token', 'site_key']) !== [] || ! is_array($body['events'] ?? null) || ! array_is_list($body['events']) || count($body['events']) < 1 || count($body['events']) > 100) {
            throw new InvalidArgumentException('Invalid event batch.');
        }
        $events = [];
        foreach ($body['events'] as $event) {
            if (! is_array($event) || array_diff(array_keys($event), ['request_id', 'type', 'received_at', 'payload']) !== []
                || ! is_string($event['request_id'] ?? null) || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $event['request_id'])
                || ! in_array($event['type'] ?? null, ['mouse', 'scroll', 'visibility', 'first_input', 'js_probe', 'page', 'snapshot'], true)
                || ! is_string($event['received_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $event['received_at'])) {
                throw new InvalidArgumentException('Invalid event.');
            }
            new DateTimeImmutable($event['received_at']);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (is_array($dateErrors) && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) {
                throw new InvalidArgumentException('Invalid event timestamp.');
            }
            $payload = $event['payload'] ?? [];
            if (! is_array($payload) || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 65536) {
                throw new InvalidArgumentException('Invalid event payload.');
            }
            foreach ($payload as $key => $value) {
                $valid = match (true) {
                    array_key_exists($key, self::NUMBERS) => $value === null || ((is_int($value) || ($key === 'mouse_entropy' && is_float($value))) && is_finite((float) $value) && $value >= 0 && $value <= self::NUMBERS[$key]),
                    in_array($key, self::BOOLEANS, true) => $value === null || is_bool($value),
                    $key === 'collector_version' => $value === null || (is_string($value) && preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,3}$/D', $value)),
                    $key === 'page_path_hash' => $value === null || (is_string($value) && preg_match('/^[0-9a-f]{1,32}$/D', $value)),
                    $key === 'page_path_shape' => $value === null || (is_string($value) && strlen($value) <= 64 && preg_match('~^/[A-Za-z0-9/._{}-]*$~D', $value)),
                    $key === 'pointer_types' => is_array($value) && array_is_list($value) && count($value) <= 3 && count(array_filter($value, static fn ($item): bool => in_array($item, ['mouse', 'touch', 'pen'], true))) === count($value),
                    $key === 'languages' => is_array($value) && array_is_list($value) && count($value) <= 10 && count(array_filter($value, static fn ($item): bool => is_string($item) && preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8}){0,2}$/D', $item))) === count($value),
                    $key === 'plugins' => $value === [],
                    default => false,
                };
                if (! $valid) {
                    throw new InvalidArgumentException('Unsupported or invalid signal.');
                }
            }
            // A path shape is a convenience, not evidence worth retaining raw segments for.
            unset($payload['page_path_shape']);
            $events[] = ['request_id' => $event['request_id'], 'type' => $event['type'], 'received_at' => $event['received_at'], 'payload' => $payload];
        }

        return $events;
    }
}
