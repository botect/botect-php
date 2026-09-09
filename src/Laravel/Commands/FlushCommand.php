<?php

declare(strict_types=1);

namespace Botect\Laravel\Commands;

use Botect\Botect;
use Illuminate\Console\Command;

final class FlushCommand extends Command
{
    protected $signature = 'botect:flush {--limit=100 : Maximum queued deliveries to process}';

    protected $description = 'Send locally spooled Botect deliveries (run in a worker or cron)';

    public function handle(Botect $botect): int
    {
        if (config('botect.delivery') !== 'spool') {
            $this->error('Use queue:work for the configured Botect queue.');

            return self::FAILURE;
        }
        $result = $botect->flush(max(1, min(1000, (int) $this->option('limit'))));
        $this->info("Sent: {$result->sent}; retried: {$result->retried}; failed: {$result->failed}.");

        return $result->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
