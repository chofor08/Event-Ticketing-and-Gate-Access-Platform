<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Hold;
use App\Models\OrderItems;
use App\Models\Orders;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TicketIssuanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_order_issues_one_ticket_per_unit_and_retries_safely(): void
    {
        $attendee = User::factory()->create(['role' => 'attendee']);
        $organizer = User::factory()->organizer()->create();
        $event = Event::create([
            'organizer_id' => $organizer->id,
            'title' => 'Ticket Issuance Event',
            'venue' => 'Main Hall',
            'town' => 'Sample Town',
            'date' => now()->addWeek()->toDateString(),
            'start_time' => '19:00:00',
            'status' => 'published',
        ]);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'base_price_cents' => 1000,
            'quantity' => 3,
        ]);
        $hold = Hold::create([
            'user_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 3,
            'status' => 'confirmed',
            'token_hash' => hash('sha256', 'issuance-test-hold'),
            'expires_at' => now()->addMinutes(10),
        ]);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'status' => 'paid',
            'currency' => 'usd',
            'amount_cents' => 3000,
        ]);
        $orderItem = OrderItems::create([
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $hold->id,
            'quantity' => 3,
            'unit_price_cents' => 1000,
            'sub_total_cents' => 3000,
        ]);

        $service = app(TicketIssuanceService::class);
        config([
            'ticketing.active_ticket_key_id' => 'test-key',
            'ticketing.ticket_signing_keys' => ['test-key' => base64_encode(str_repeat('test-key-material-', 3))],
        ]);
        $service->issue($order);
        $service->issue($order);

        $this->assertDatabaseCount('tickets', 3);
        $this->assertSame([1, 2, 3], Ticket::query()
            ->where('order_item_id', $orderItem->id)
            ->orderBy('unit_number')
            ->pluck('unit_number')
            ->all());
        $this->assertSame($attendee->id, Ticket::query()->firstOrFail()->user_id);
        $this->assertSame($event->id, Ticket::query()->firstOrFail()->event_id);
        $this->assertFalse(Schema::hasColumn('tickets', 'credential'));
    }
}
