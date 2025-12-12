<?php

namespace App\Services\ScrapeService\Pipeline;

use App\Events\ScrapeEvent;
use App\Jobs\ScrapeEventJob;
use App\Models\ScrapeStatistics;
use App\Services\ScrapeService\Data\ScrapeContext;
use App\Services\ScrapeService\Data\ScrapeEventPacket;
use Exception;
use Illuminate\Support\Facades\Log;

class ScrapeEventHandler
{

    private ScrapeDatasetCreator $datasetCreator;
    public function __construct(
    )
    {
        $this->datasetCreator = new ScrapeDatasetCreator();
    }


    public function handle(array $payload){
        // Validate message structure
        if (!$this->isValidEventPacket($payload)) {
            Log::warning("Invalid event packet structure in job", [
                'data' => $payload
            ]);
            return;
        }
        // Create ScrapeEventPacket from the incoming message
        $packet = $this->createScrapeEventPacket($payload);

        // Process the event using the existing handler
        $this->processEvent($packet);
    }

    /**
     * Validate event packet structure.
     *
     * @param array $data
     * @return bool
     */
    protected function isValidEventPacket(array $data): bool
    {
        return isset($data['job_id']) &&
            isset($data['event']) &&
            isset($data['data']) &&
            isset($data['timestamp']) &&
            is_string($data['job_id']) &&
            is_string($data['event']) &&
            is_array($data['data']) &&
            is_string($data['timestamp']);
    }

    /**
     * Create a ScrapeEventPacket from decoded payload.
     *
     * @param array $data
     * @return ScrapeEventPacket
     */
    protected function createScrapeEventPacket(array $data): ScrapeEventPacket
    {
        return new ScrapeEventPacket(
            jobId: $data['job_id'],
            event: $data['event'],
            data: $data['data'],
            timestamp: $data['timestamp']
        );
    }

    /**
     * Process a validated event packet.
     *
     * @param ScrapeEventPacket $packet
     * @return void
     * @throws Exception
     */
    protected function processEvent(ScrapeEventPacket $packet): void
    {
        // Rebuild context from job ID
        $context = ScrapeContextBuilder::rebuildContext($packet->jobId);
        switch($packet->event){
            case('stage'):
                $this->processStageChange($packet, $context);
                break;
            case('report'):
                $this->processJobReport($packet, $context);
                break;
            case('summary'):
                $this->processSummary($packet, $context);
                break;
        }
    }

    protected function processStageChange(ScrapeEventPacket $packet, ScrapeContext $context): void
    {
        $context->setStage($packet->data['stage']);
        if($packet->data['stage'] === 'sitemap_detected'){
            $context->setStats('total_urls', $packet->data['details']['total_urls']);
        }
    }

    /**
     * @throws Exception
     */
    protected function processJobReport(ScrapeEventPacket $packet, ScrapeContext $context): void
    {
        if(array_key_exists('stats', $packet->data)){
            foreach($packet->data['stats'] as $name => $value){
                $context->setStats($name, $value);
            }
        }
        if(array_key_exists('url_completion', $packet->data)){
            $completion = $packet->data['url_completion'];
            $context->setStats('current_url', $completion['url']);
            $this->datasetCreator->createElementData($context, $completion['url_hash']);
        }
    }
    protected function processSummary(ScrapeEventPacket $packet, ScrapeContext $context): void
    {
        $this->datasetCreator->recordScrapeSummary($context, $packet->data);
        $context->setEndProcess(true);
    }


}
