<?php

declare(strict_types=1);

use App\Mcp\Servers\BriefServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('briefs', BriefServer::class);
