<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\FrontendEnumCatalog;
use Illuminate\Console\Command;

class ExportFrontendStatesCommand extends Command
{
    protected $signature = 'frontend:export-states';

    protected $description = 'Write resources/js/pipeline-states.json from the PHP enums';

    public function handle(): int
    {
        $path = base_path('resources/js/pipeline-states.json');
        file_put_contents($path, FrontendEnumCatalog::toJson());
        $this->info('Wrote resources/js/pipeline-states.json');

        return self::SUCCESS;
    }
}
