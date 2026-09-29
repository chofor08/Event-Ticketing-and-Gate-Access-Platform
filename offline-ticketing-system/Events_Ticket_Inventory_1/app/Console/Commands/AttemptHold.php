<?php

namespace App\Console\Commands;

use App\Services\HoldService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('app:attempt-hold')]
#[Description('Command description')]
class AttemptHold extends Command
{
    protected $signature = 'holds:attempt {ticketTypeId: The ticket Type ID}
                            {quantity: The number of tickets}';

    protected $description = 'Attempt to create a ticket hold';

    /**
     * Execute the console command.
     */
    public function handle(HoldService $holdService): int
    {
        try {
            $holdService->createHold(
                (int) $this->argument('ticketTypeId'),
                (int) $this->argument('quantity')
            );

            $this->info('SUCCESS');

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

    }
}
