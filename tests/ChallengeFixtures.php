<?php

declare(strict_types=1);
use Botect\ChallengeList;
use Botect\Configuration;
use Botect\Contracts\EnforcementStore;

function sdkChallengeListData(array $changes = [], ?int $now = null): array
{
    $now ??= time();

    return array_replace(['schema_version' => 1, 'enabled' => true, 'status' => 'ok', 'generated_at' => $now,
        'refresh_after' => $now + 300, 'expires_at' => $now + 1800, 'source_run_id' => 158, 'source_window_end' => $now,
        'header_shapes' => ['c0d7f5f1a627cd7a2a285ebaf4a1ad3c'], 'ranges' => [], 'crawler_directory_version' => '42',
        'crawler_catalogue' => 'full', 'crawler_cidrs' => [], 'trusted_ranges' => []], $changes);
}

function sdkSeedEnforcement(EnforcementStore $store, Configuration $configuration, array $snapshot): void
{
    $list = ChallengeList::fromArray($snapshot);
    $id = hash('sha256', $configuration->enforcementScope().'|'.json_encode($snapshot, JSON_THROW_ON_ERROR));
    $store->writeCatalogue($id, $list->catalogue());
    $metadata = $list->metadata($id);
    $store->update(static function (array &$state) use ($configuration, $metadata): void {
        $state['scopes'][$configuration->enforcementScope()] = ['snapshot' => $metadata];
    });
}
