<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\GetBriefStatusTool;
use App\Mcp\Tools\SubmitBriefTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Shot Creative Agent')]
#[Version('1.0.0')]
#[Instructions('Submit a creative brief and read its status. Tools return immediately and never wait on a render.')]
class BriefServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        SubmitBriefTool::class,
        GetBriefStatusTool::class,
    ];
}
