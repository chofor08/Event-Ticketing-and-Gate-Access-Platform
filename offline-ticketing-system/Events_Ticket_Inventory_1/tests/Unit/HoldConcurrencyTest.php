<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class HoldConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_holds_cannot_oversell_tickets(): void
    {
        $organizer = User::create([
            'name' => 'Concurrency Organizer',
            'email' => 'concurrency-organizer@example.test',
            'password' => 'password',
            'role' => 'organizer',
        ]);

        $event = Event::create([
            'organizer_id' => $organizer->id,
            'title' => 'Concurrency Event',
            'status' => 'published',
            'venue' => 'Test Venue',
            'town' => 'Test Town',
            'description' => 'Concurrency test event',
            'date' => now()->addWeek()->toDateString(),
            'start_time' => '18:00:00',
        ]);

        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price' => 25,
            'quantity' => 5,
        ]);

        $processes = [];

        for ($i = 0; $i < 7; $i++) {
            $process = new Process([
                PHP_BINARY,
                'artisan',
                'holds:attempt',
                (string) $ticketType->id,
                '1',
            ]);

            $process->start();
            $processes[] = $process;
        }

        $successful = 0;
        $failed = 0;

        foreach ($processes as $process) {
            $process->wait();

            if ($process->isSuccessful()) {
                $successful++;
            } else {
                $failed++;
            }
        }

        $ticketType->refresh();

        $held = $ticketType->holds()->where('status', 'held')->sum('quantity');
        $confirmed = $ticketType->holds()->where('status', 'confirmed')->sum('quantity');

        $this->assertEquals(5, $successful);
        $this->assertEquals(2, $failed);
        $this->assertLessThanOrEqual($ticketType->quantity, $held + $confirmed);
    }
}
