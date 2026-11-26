<?php

declare(strict_types=1);

namespace Botect\Laravel\Commands;

use Botect\Botect;
use Illuminate\Console\Command;

final class RefreshChallengeListCommand extends Command
{
    protected $signature = 'botect:refresh-challenge-list {--dispatch}';

    protected $description = 'Refresh local Botect header-shape evidence';

    public function handle(Botect $botect): int
    {
        $ok = $this->option('dispatch') ? $botect->dispatchChallengeListRefresh() : $botect->refreshChallengeList();
        $this->line($ok ? 'Challenge list refresh accepted.' : 'Challenge list refresh unavailable.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
