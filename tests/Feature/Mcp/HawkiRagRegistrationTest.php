<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\HawkiRagServer;
use App\Mcp\Tools\WebSearchTool;
use App\Services\RagSearch\RagSearcher;
use PHPUnit\Framework\TestCase;

class HawkiRagRegistrationTest extends TestCase
{
    public function test_only_web_search_is_registered_and_the_retired_wrapper_cannot_be_resolved(): void
    {
        $this->assertSame([WebSearchTool::class], (new \ReflectionClass(HawkiRagServer::class))->getDefaultProperties()['tools']);
        $this->assertFileDoesNotExist(dirname(__DIR__, 3).'/app/Mcp/Tools/HawkiRagSearchTool.php');
        // Semantic graph rollback still uses this implementation.
        $this->assertTrue(class_exists(RagSearcher::class));
    }
}
