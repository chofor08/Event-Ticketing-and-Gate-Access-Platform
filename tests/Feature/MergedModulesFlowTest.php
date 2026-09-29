<?php

namespace Tests\Feature;

use App\Events\OrderPaid;
use App\Events\OrderSettled;
use App\Mail\PaymentConfirmed;
use App\Mail\RefundConfirmed;
use App\Models\Event as EventModel;
use App\Models\Hold;
use App\Models\LedgerEntries;
use App\Models\OrderItems;
use App\Models\Orders;
use App\Models\TicketType;
use App\Models\User;
use App\Services\HoldService;
use App\Services\OrderRefundService;
use App\Services\StripeGateway;
use App\Services\StripeWebhookService;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Event as StripeEvent;
use Stripe\Refund;
use Tests\TestCase;

class MergedModulesFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_a_role_and_creates_the_selected_role(): void
    {
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'No Role',
            'email' => 'norole@example.test',
            'password' => 'Valid-pass-123!',
            'password_confirmation' => 'Valid-pass-123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('role');

        $attendeeResponse = $this->postJson('/api/register', $this->registrationPayload('attendee', 'attendee@example.test'));
        $attendeeResponse->assertCreated()->assertJsonPath('user.role', 'attendee');

        $organizerResponse = $this->postJson('/api/register', $this->registrationPayload('organizer', 'organizer@example.test'));
        $organizerResponse->assertCreated()->assertJsonPath('user.role', 'organizer');

        $this->assertDatabaseHas('users', ['email' => 'attendee@example.test', 'role' => 'attendee']);
        $this->assertDatabaseHas('users', ['email' => 'organizer@example.test', 'role' => 'organizer']);
        Notification::assertNotSentTo(User::query()->where('email', 'attendee@example.test')->firstOrFail(), VerifyEmail::class);
        Notification::assertSentTo(User::query()->where('email', 'organizer@example.test')->firstOrFail(), VerifyEmail::class);
    }

    public function test_logout_revokes_all_tokens_for_the_authenticated_user(): void
    {
        $user = User::factory()->create(['role' => 'attendee']);
        $user->createToken('first-device');
        $accessToken = $user->createToken('second-device');

        $this->withToken($accessToken->plainTextToken)
            ->postJson('/api/logout')
            ->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_attendees_cannot_manage_events_and_unverified_organizers_are_blocked(): void
    {
        $attendee = User::factory()->unverified()->create(['role' => 'attendee']);
        $this->actingAs($attendee, 'sanctum')
            ->postJson('/api/organizer/events', $this->eventPayload())
            ->assertForbidden();

        $organizer = User::factory()->organizer()->unverified()->create();
        $this->actingAs($organizer, 'sanctum')
            ->postJson('/api/organizer/events', $this->eventPayload())
            ->assertStatus(409);
    }

    public function test_public_discovery_keeps_published_past_events_but_holds_still_require_future_events(): void
    {
        $organizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $event = EventModel::create([
            ...$this->eventPayload(),
            'date' => now()->subDay()->toDateString(),
            'organizer_id' => $organizer->id,
        ]);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'Past Event Ticket',
            'base_price_cents' => 1200,
            'quantity' => 10,
        ]);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonFragment(['id' => $event->id]);

        $this->actingAs($attendee, 'sanctum')
            ->postJson("/api/ticket-types/{$ticketType->id}/holds", ['quantity' => 1])
            ->assertUnprocessable();
    }

    public function test_event_status_is_not_changed_through_the_update_endpoint(): void
    {
        $organizer = User::factory()->organizer()->create();
        $event = $this->createFutureEvent($organizer);

        $this->actingAs($organizer, 'sanctum')
            ->patchJson("/api/organizer/events/{$event->id}", ['status' => 'draft'])
            ->assertOk();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'published']);
    }

    public function test_ticket_types_cannot_be_listed_after_event_cancellation(): void
    {
        $organizer = User::factory()->organizer()->create();
        $event = EventModel::create([
            ...$this->eventPayload(),
            'status' => 'cancelled',
            'organizer_id' => $organizer->id,
        ]);

        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/ticket-types")
            ->assertUnprocessable();
    }

    public function test_ticket_type_with_expired_hold_history_cannot_be_deleted(): void
    {
        $organizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $event = $this->createFutureEvent($organizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'Previously Held',
            'base_price_cents' => 1600,
            'quantity' => 2,
        ]);
        $hold = $this->createHold($attendee, $ticketType);
        $hold->update(['status' => 'expired']);

        $this->actingAs($organizer, 'sanctum')
            ->deleteJson("/api/organizer/ticket-types/{$ticketType->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ticket_type');

        $this->assertDatabaseHas('ticket_types', ['id' => $ticketType->id]);
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'status' => 'expired']);
    }

    public function test_holds_are_attendee_owned_and_inventory_uses_module_a_reservations(): void
    {
        $organizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $event = $this->createFutureEvent($organizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'base_price_cents' => 2500,
            'discount' => 10,
            'quantity' => 2,
        ]);

        $this->actingAs($attendee, 'sanctum');
        $response = $this->postJson("/api/ticket-types/{$ticketType->id}/holds", ['quantity' => 2]);
        $response->assertCreated()->assertJsonMissingPath('hold.token_hash');

        $anotherAttendee = User::factory()->create(['role' => 'attendee']);
        $this->actingAs($anotherAttendee, 'sanctum')
            ->postJson("/api/ticket-types/{$ticketType->id}/holds", ['quantity' => 1])
            ->assertUnprocessable();

        $this->assertDatabaseHas('holds', [
            'user_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 2,
            'status' => 'held',
        ]);
    }

    public function test_checkout_snapshots_ticket_cents_and_is_idempotent(): void
    {
        $attendee = User::factory()->create(['role' => 'attendee']);
        $organizer = User::factory()->organizer()->create();
        $event = $this->createFutureEvent($organizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'Advance',
            'base_price_cents' => 1234,
            'discount' => 10,
            'quantity' => 4,
        ]);
        $hold = app(HoldService::class)->create($ticketType, $attendee, 2)['hold'];

        $session = Session::constructFrom([
            'id' => 'cs_test_123',
            'url' => 'https://checkout.stripe.test/session',
        ]);
        $stripe = \Mockery::mock(StripeGateway::class);
        $stripe->shouldReceive('createCheckoutSession')->once()->andReturn($session);
        $this->app->instance(StripeGateway::class, $stripe);

        $this->actingAs($attendee, 'sanctum');
        $payload = ['hold_ids' => [$hold->id]];
        $headers = ['Idempotency-Key' => 'checkout-request-1'];

        $response = $this->postJson('/api/checkout', $payload, $headers);
        $response->assertCreated()->assertJsonPath('checkout_url', $session->url);

        $order = Orders::query()->firstOrFail();
        $this->assertSame(2222, $order->amount_cents);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $hold->id,
            'quantity' => 2,
            'unit_price_cents' => 1111,
            'sub_total_cents' => 2222,
        ]);

        $this->postJson('/api/checkout', $payload, $headers)
            ->assertCreated()
            ->assertJsonPath('checkout_url', $session->url);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_order_items_endpoint_returns_only_the_authenticated_attendees_ticket_lines(): void
    {
        $organizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $otherAttendee = User::factory()->create(['role' => 'attendee']);
        $event = $this->createFutureEvent($organizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'base_price_cents' => 1800,
            'quantity' => 4,
        ]);

        $attendeeHold = $this->createHold($attendee, $ticketType);
        $attendeeOrder = Orders::create([
            'user_id' => $attendee->id,
            'status' => 'paid',
            'currency' => 'usd',
            'amount_cents' => 1800,
        ]);
        $attendeeLine = OrderItems::create([
            'order_id' => $attendeeOrder->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $attendeeHold->id,
            'quantity' => 1,
            'unit_price_cents' => 1800,
            'sub_total_cents' => 1800,
        ]);

        $otherHold = $this->createHold($otherAttendee, $ticketType);
        $otherOrder = Orders::create([
            'user_id' => $otherAttendee->id,
            'status' => 'paid',
            'currency' => 'usd',
            'amount_cents' => 1800,
        ]);
        $otherLine = OrderItems::create([
            'order_id' => $otherOrder->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $otherHold->id,
            'quantity' => 1,
            'unit_price_cents' => 1800,
            'sub_total_cents' => 1800,
        ]);

        $this->actingAs($attendee, 'sanctum')
            ->getJson('/api/order_items')
            ->assertOk()
            ->assertJsonCount(1, 'Order Details.data')
            ->assertJsonPath('Order Details.data.0.order_item.id', $attendeeLine->id)
            ->assertJsonPath('Order Details.data.0.order_item.ticket_type.event.title', 'Test Event')
            ->assertJsonPath('Order Details.data.0.order_item.unit_price_cents', 1800)
            ->assertJsonMissing(['id' => $otherLine->id]);
    }

    public function test_checkout_return_does_not_expose_order_data_or_decide_payment_status(): void
    {
        $this->getJson('/api/checkout/success?session_id=cs_return_test')
            ->assertOk()
            ->assertJsonMissingPath('order_id')
            ->assertJsonMissingPath('status')
            ->assertJsonPath('message', 'Checkout return received. Payment status is confirmed through the payment webhook.');
    }

    public function test_paid_checkout_after_hold_expiry_is_refunded_without_confirming_tickets(): void
    {
        $attendee = User::factory()->create(['role' => 'attendee']);
        $organizer = User::factory()->organizer()->create();
        $event = $this->createFutureEvent($organizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'base_price_cents' => 3000,
            'discount' => 0,
            'quantity' => 5,
        ]);
        $hold = Hold::create([
            'user_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'status' => 'held',
            'token_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'expires_at' => now()->subMinute(),
        ]);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'status' => 'pending',
            'currency' => 'usd',
            'amount_cents' => 3000,
        ]);
        OrderItems::create([
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $hold->id,
            'quantity' => 1,
            'unit_price_cents' => 3000,
            'sub_total_cents' => 3000,
        ]);

        $refund = Refund::constructFrom(['id' => 're_test', 'amount' => 3000, 'status' => 'succeeded']);
        $stripe = \Mockery::mock(StripeGateway::class);
        $stripe->shouldReceive('createRefund')
            ->once()
            ->with('pi_test', 3000, 'order-'.$order->id.'-full-refund-3000-attempt-1')
            ->andReturn($refund);
        $this->app->instance(StripeGateway::class, $stripe);

        $event = StripeEvent::constructFrom([
            'id' => 'evt_late_payment',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_late',
                'metadata' => ['order_id' => (string) $order->id, 'user_id' => (string) $attendee->id],
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test',
                'amount_total' => 3000,
                'currency' => 'usd',
            ]],
        ]);

        app(StripeWebhookService::class)->process($event);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'status' => 'expired']);
        $this->assertDatabaseHas('ledger_entries', [
            'order_id' => $order->id,
            'type' => 'refund',
            'amount_cents' => -3000,
        ]);
        $this->assertSame(1, LedgerEntries::query()->where('order_id', $order->id)->count());
    }

    public function test_charge_updated_saves_payment_details_before_dispatching_confirmation(): void
    {
        $attendee = User::factory()->create(['role' => 'attendee']);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'status' => 'paid',
            'currency' => 'usd',
            'amount_cents' => 2700,
        ]);
        $stripe = \Mockery::mock(StripeGateway::class);
        $stripe->shouldReceive('retrieveCharge')->once()->with('ch_pi_succeeded')->andReturn(Charge::constructFrom([
            'id' => 'ch_pi_succeeded',
            'payment_intent' => 'pi_charge_updated',
            'payment_method' => 'pm_test_visa',
            'currency' => 'usd',
            'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']],
        ]));
        $this->app->instance(StripeGateway::class, $stripe);
        Event::fake();

        $paymentIntentSucceeded = StripeEvent::constructFrom([
            'id' => 'evt_payment_intent_succeeded',
            'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_charge_updated',
                'latest_charge' => 'ch_pi_succeeded',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ]);

        app(StripeWebhookService::class)->process($paymentIntentSucceeded);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'currency' => 'usd',
            'payment_method_type' => 'card',
            'payment_method_id' => 'pm_test_visa',
            'payment_method_brand' => 'visa',
            'payment_method_last4' => '4242',
        ]);
        $this->assertSame(['type' => 'card'], $order->fresh()->payment_method_details);

        $duplicateChargeUpdate = StripeEvent::constructFrom([
            'id' => 'evt_duplicate_charge_updated',
            'type' => 'charge.updated',
            'data' => ['object' => [
                'payment_intent' => 'pi_charge_updated',
                'currency' => 'usd',
                'payment_method' => 'pm_test_visa',
                'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']],
            ]],
        ]);
        app(StripeWebhookService::class)->process($duplicateChargeUpdate);

        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    public function test_successful_checkout_confirms_valid_holds_and_ledger_before_charge_email(): void
    {
        $organizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $event = $this->createFutureEvent($organizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'Paid Ticket',
            'base_price_cents' => 2100,
            'quantity' => 2,
        ]);
        $hold = Hold::create([
            'user_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'status' => 'held',
            'token_hash' => password_hash('checkout-token', PASSWORD_BCRYPT),
            'expires_at' => now()->addMinutes(5),
        ]);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'status' => 'pending',
            'currency' => 'usd',
            'amount_cents' => 2100,
        ]);
        OrderItems::create([
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $hold->id,
            'quantity' => 1,
            'unit_price_cents' => 2100,
            'sub_total_cents' => 2100,
        ]);

        $this->app->instance(StripeGateway::class, \Mockery::mock(StripeGateway::class));
        Event::fake([OrderPaid::class, OrderSettled::class]);

        $sessionCompleted = StripeEvent::constructFrom([
            'id' => 'evt_session_completed',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_paid',
                'metadata' => ['order_id' => (string) $order->id, 'user_id' => (string) $attendee->id],
                'payment_status' => 'paid',
                'payment_intent' => 'pi_paid',
                'amount_total' => 2100,
                'currency' => 'usd',
            ]],
        ]);

        app(StripeWebhookService::class)->process($sessionCompleted);
        Event::assertDispatchedTimes(OrderSettled::class, 1);
        Event::assertDispatched(OrderSettled::class, function (OrderSettled $settledEvent) use ($attendee, $order, $event, $ticketType): bool {
            $item = $order->orderItems()->firstOrFail();

            return $settledEvent->toModuleCPayload() === [
                'event' => 'order.paid',
                'order_id' => $order->id,
                'user' => ['id' => $attendee->id, 'email' => $attendee->email],
                'items' => [[
                    'product_id' => $ticketType->id,
                    'ticket_type_id' => $ticketType->id,
                    'order_item_id' => $item->id,
                    'event_id' => $event->id,
                    'quantity' => 1,
                    'unit_price_cents' => 2100,
                ]],
                'total_cents' => 2100,
                'currency' => 'usd',
            ];
        });
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'paid',
            'payment_intent_id' => 'pi_paid',
        ]);
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('ledger_entries', [
            'order_id' => $order->id,
            'type' => 'payment',
            'amount_cents' => 2100,
        ]);
        $this->assertSame(21.0, (float) LedgerEntries::query()
            ->where('order_id', $order->id)
            ->where('type', 'payment')
            ->value('payment'));
        Event::assertNotDispatched(OrderPaid::class);

        $duplicateSessionCompleted = StripeEvent::constructFrom([
            'id' => 'evt_session_completed_replayed',
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_paid',
                'metadata' => ['order_id' => (string) $order->id, 'user_id' => (string) $attendee->id],
                'payment_status' => 'paid',
                'payment_intent' => 'pi_paid',
                'amount_total' => 2100,
                'currency' => 'usd',
            ]],
        ]);

        app(StripeWebhookService::class)->process($duplicateSessionCompleted);
        Event::assertDispatchedTimes(OrderSettled::class, 1);

        $chargeUpdated = StripeEvent::constructFrom([
            'id' => 'evt_paid_charge_updated',
            'type' => 'charge.updated',
            'data' => ['object' => [
                'payment_intent' => 'pi_paid',
                'currency' => 'usd',
                'payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']],
            ]],
        ]);

        app(StripeWebhookService::class)->process($chargeUpdated);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_method_type' => 'card',
            'payment_method_brand' => 'visa',
            'payment_method_last4' => '4242',
        ]);
        Event::assertDispatched(OrderPaid::class);
    }

    public function test_payment_and_refund_emails_render_ticket_details_and_actual_refund_amount(): void
    {
        $organizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $event = $this->createFutureEvent($organizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'Balcony',
            'base_price_cents' => 2700,
            'quantity' => 2,
        ]);
        $hold = $this->createHold($attendee, $ticketType);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'payment_intent_id' => 'pi_mail_test',
            'payment_method_type' => 'card',
            'payment_method_brand' => 'visa',
            'payment_method_last4' => '4242',
            'status' => 'paid',
            'currency' => 'usd',
            'amount_cents' => 2700,
        ]);
        OrderItems::create([
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $hold->id,
            'quantity' => 1,
            'unit_price_cents' => 2700,
            'sub_total_cents' => 2700,
        ]);

        $paymentHtml = (new PaymentConfirmed($order))->render();
        $refundHtml = (new RefundConfirmed($order, 1200))->render();

        $this->assertStringContainsString('Balcony', $paymentHtml);
        $this->assertStringContainsString('pi_mail_test', $paymentHtml);
        $this->assertStringContainsString('Visa ending in 4242', $paymentHtml);
        $this->assertStringContainsString('27.00', $paymentHtml);
        $this->assertStringContainsString('Visa ending in 4242', $refundHtml);
        $this->assertStringContainsString('Balcony', $refundHtml);
        $this->assertStringContainsString('12.00', $refundHtml);
    }

    public function test_module_b_simulated_items_table_is_not_in_the_merged_schema(): void
    {
        $this->seed();

        $this->assertFalse(Schema::hasTable('items'));
        $this->assertTrue(Schema::hasColumns('orders', [
            'payment_method_type',
            'payment_method_id',
            'payment_method_brand',
            'payment_method_last4',
            'payment_method_details',
        ]));
        $this->assertTrue(Schema::hasColumns('ledger_entries', ['payment', 'refund', 'adjustment']));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cancelling_an_event_refunds_paid_orders_and_releases_confirmed_holds(): void
    {
        $organizer = User::factory()->organizer()->create();
        $otherOrganizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $event = $this->createFutureEvent($organizer);
        $otherEvent = $this->createFutureEvent($otherOrganizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'Reserved',
            'base_price_cents' => 4500,
            'discount' => 0,
            'quantity' => 2,
        ]);
        $otherTicketType = TicketType::create([
            'event_id' => $otherEvent->id,
            'name' => 'Unaffected',
            'base_price_cents' => 3500,
            'discount' => 0,
            'quantity' => 2,
        ]);
        $hold = Hold::create([
            'user_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'status' => 'confirmed',
            'token_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'expires_at' => now()->addMinutes(10),
        ]);
        $otherHold = $this->createHold($attendee, $otherTicketType);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'payment_intent_id' => 'pi_cancel_test',
            'status' => 'paid',
            'currency' => 'usd',
            'amount_cents' => 8000,
        ]);
        OrderItems::create([
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $hold->id,
            'quantity' => 1,
            'unit_price_cents' => 4500,
            'sub_total_cents' => 4500,
        ]);
        OrderItems::create([
            'order_id' => $order->id,
            'ticket_type_id' => $otherTicketType->id,
            'hold_id' => $otherHold->id,
            'quantity' => 1,
            'unit_price_cents' => 3500,
            'sub_total_cents' => 3500,
        ]);

        $refund = Refund::constructFrom(['id' => 're_cancel', 'amount' => 4500, 'status' => 'succeeded']);
        $remainingRefund = Refund::constructFrom(['id' => 're_remaining', 'amount' => 3500, 'status' => 'succeeded']);
        $stripe = \Mockery::mock(StripeGateway::class);
        $stripe->shouldReceive('createRefund')
            ->once()
            ->with('pi_cancel_test', 4500, 'order-'.$order->id.'-event-'.$event->id.'-refund-attempt-1')
            ->andReturn($refund);
        $stripe->shouldReceive('createRefund')
            ->once()
            ->with('pi_cancel_test', 3500, 'order-'.$order->id.'-full-refund-3500-attempt-1')
            ->andReturn($remainingRefund);
        $this->app->instance(StripeGateway::class, $stripe);

        $this->actingAs($organizer, 'sanctum')
            ->postJson("/api/organizer/events/{$event->id}/cancel")
            ->assertOk()
            ->assertJsonPath('refunds.'.$order->id, 'partially_refunded');

        $this->assertDatabaseHas('events', ['id' => $event->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'partially_refunded']);
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'status' => 'released']);
        $this->assertDatabaseHas('holds', ['id' => $otherHold->id, 'status' => 'confirmed']);
        $this->assertDatabaseHas('ledger_entries', [
            'order_id' => $order->id,
            'type' => 'refund',
            'amount_cents' => -4500,
        ]);
        $this->assertSame(45.0, (float) LedgerEntries::query()
            ->where('order_id', $order->id)
            ->where('type', 'refund')
            ->value('refund'));

        $this->actingAs($attendee, 'sanctum')
            ->postJson('/api/refund', ['id' => $order->id])
            ->assertOk()
            ->assertJsonPath('order.status', 'refunded');

        $this->assertDatabaseHas('holds', ['id' => $otherHold->id, 'status' => 'released']);
        $this->assertDatabaseHas('ledger_entries', [
            'order_id' => $order->id,
            'reference_key' => 'order-'.$order->id.'-full-refund-3500',
            'amount_cents' => -3500,
        ]);
    }

    public function test_pending_stripe_refund_update_settles_only_its_ticket_lines(): void
    {
        $organizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $event = $this->createFutureEvent($organizer);
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'Refundable',
            'base_price_cents' => 2400,
            'quantity' => 2,
        ]);
        $hold = $this->createHold($attendee, $ticketType);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'payment_intent_id' => 'pi_async_refund',
            'status' => 'paid',
            'currency' => 'usd',
            'amount_cents' => 2400,
        ]);
        OrderItems::create([
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $hold->id,
            'quantity' => 1,
            'unit_price_cents' => 2400,
            'sub_total_cents' => 2400,
        ]);

        $pendingRefund = Refund::constructFrom([
            'id' => 're_async_first',
            'amount' => 2400,
            'status' => 'pending',
        ]);
        $retriedRefund = Refund::constructFrom([
            'id' => 're_async_retry',
            'amount' => 2400,
            'status' => 'pending',
        ]);
        $stripe = \Mockery::mock(StripeGateway::class);
        $stripe->shouldReceive('createRefund')
            ->once()
            ->with('pi_async_refund', 2400, 'order-'.$order->id.'-event-'.$event->id.'-refund-attempt-1')
            ->andReturn($pendingRefund);
        $stripe->shouldReceive('createRefund')
            ->once()
            ->with('pi_async_refund', 2400, 'order-'.$order->id.'-event-'.$event->id.'-refund-attempt-2')
            ->andReturn($retriedRefund);
        $this->app->instance(StripeGateway::class, $stripe);

        app(OrderRefundService::class)->requestEventRefund($order, $event);
        $this->assertDatabaseHas('refund_requests', [
            'order_id' => $order->id,
            'stripe_refund_id' => 're_async_first',
            'status' => 'pending',
        ]);

        $failedRefundUpdate = StripeEvent::constructFrom([
            'id' => 'evt_async_refund_failed',
            'type' => 'refund.updated',
            'data' => ['object' => [
                'id' => 're_async_first',
                'amount' => 2400,
                'status' => 'failed',
                'failure_reason' => 'test_failure',
            ]],
        ]);

        $this->assertTrue(app(StripeWebhookService::class)->process($failedRefundUpdate));
        $this->assertDatabaseHas('refund_requests', [
            'order_id' => $order->id,
            'stripe_refund_id' => 're_async_first',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refund_failed']);

        app(OrderRefundService::class)->requestEventRefund($order, $event);
        $this->assertDatabaseHas('refund_requests', [
            'order_id' => $order->id,
            'stripe_refund_id' => 're_async_retry',
            'attempts' => 2,
            'status' => 'pending',
        ]);

        $refundUpdate = StripeEvent::constructFrom([
            'id' => 'evt_async_refund_succeeded',
            'type' => 'refund.updated',
            'data' => ['object' => [
                'id' => 're_async_retry',
                'amount' => 2400,
                'status' => 'succeeded',
            ]],
        ]);

        $chargeRefunded = StripeEvent::constructFrom([
            'id' => 'evt_charge_refunded_first',
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'payment_intent' => 'pi_async_refund',
                'amount_refunded' => 2400,
                'refunds' => ['data' => [[
                    'id' => 're_async_retry',
                    'amount' => 2400,
                    'status' => 'succeeded',
                ]]],
            ]],
        ]);

        $this->assertTrue(app(StripeWebhookService::class)->process($chargeRefunded));
        $this->assertTrue(app(StripeWebhookService::class)->process($refundUpdate));
        $this->assertTrue(app(StripeWebhookService::class)->process($refundUpdate));

        $this->assertDatabaseHas('refund_requests', [
            'order_id' => $order->id,
            'stripe_refund_id' => 're_async_retry',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'refunded']);
        $this->assertDatabaseHas('holds', ['id' => $hold->id, 'status' => 'released']);
        $this->assertDatabaseHas('ledger_entries', [
            'order_id' => $order->id,
            'type' => 'refund',
            'amount_cents' => -2400,
        ]);
        $this->assertSame(1, LedgerEntries::query()->where('order_id', $order->id)->where('type', 'refund')->count());
    }

    public function test_sales_summary_is_scoped_per_organizer_and_attributes_full_mixed_order_refunds(): void
    {
        $firstOrganizer = User::factory()->organizer()->create();
        $secondOrganizer = User::factory()->organizer()->create();
        $attendee = User::factory()->create(['role' => 'attendee']);
        $firstEvent = $this->createFutureEvent($firstOrganizer);
        $secondEvent = EventModel::create([
            ...$this->eventPayload(),
            'title' => 'Second Organizer Event',
            'organizer_id' => $secondOrganizer->id,
        ]);
        $firstTicket = TicketType::create([
            'event_id' => $firstEvent->id,
            'name' => 'First Ticket',
            'base_price_cents' => 1500,
            'quantity' => 2,
        ]);
        $secondTicket = TicketType::create([
            'event_id' => $secondEvent->id,
            'name' => 'Second Ticket',
            'base_price_cents' => 3500,
            'quantity' => 2,
        ]);
        $firstHold = $this->createHold($attendee, $firstTicket);
        $secondHold = $this->createHold($attendee, $secondTicket);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'status' => 'refunded',
            'currency' => 'usd',
            'amount_cents' => 5000,
        ]);

        foreach ([[$firstTicket, $firstHold, 1500], [$secondTicket, $secondHold, 3500]] as [$ticket, $hold, $cents]) {
            OrderItems::create([
                'order_id' => $order->id,
                'ticket_type_id' => $ticket->id,
                'hold_id' => $hold->id,
                'quantity' => 1,
                'unit_price_cents' => $cents,
                'sub_total_cents' => $cents,
            ]);
        }

        LedgerEntries::create([
            'user_id' => $attendee->id,
            'order_id' => $order->id,
            'type' => 'payment',
            'reference_key' => 'test-payment-'.$order->id,
            'amount_cents' => 5000,
        ]);
        LedgerEntries::create([
            'user_id' => $attendee->id,
            'order_id' => $order->id,
            'type' => 'refund',
            'reference_key' => 'test-refund-'.$order->id,
            'amount_cents' => -5000,
        ]);

        $this->actingAs($firstOrganizer, 'sanctum')
            ->getJson('/api/organizer/sales_summary')
            ->assertOk()
            ->assertJsonPath('orders_count', 1)
            ->assertJsonPath('paid_cents', 5000)
            ->assertJsonPath('refunded_cents', 5000)
            ->assertJsonPath('collected_cents', 0)
            ->assertJsonPath('refund_attribution', 'full_order_per_organizer');

        $this->actingAs($secondOrganizer, 'sanctum')
            ->getJson('/api/organizer/sales_summary')
            ->assertOk()
            ->assertJsonPath('paid_cents', 5000)
            ->assertJsonPath('refunded_cents', 5000);
    }

    private function registrationPayload(string $role, string $email): array
    {
        return [
            'name' => 'Test Account',
            'email' => $email,
            'password' => 'Valid-pass-123!',
            'password_confirmation' => 'Valid-pass-123!',
            'role' => $role,
        ];
    }

    private function eventPayload(): array
    {
        return [
            'title' => 'Test Event',
            'status' => 'published',
            'venue' => 'Main Hall',
            'town' => 'Example Town',
            'date' => now()->addDays(2)->toDateString(),
            'start_time' => '18:00',
        ];
    }

    private function createFutureEvent(User $organizer): EventModel
    {
        return EventModel::create([
            ...$this->eventPayload(),
            'organizer_id' => $organizer->id,
        ]);
    }

    private function createHold(User $attendee, TicketType $ticketType): Hold
    {
        return Hold::create([
            'user_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => 1,
            'status' => 'confirmed',
            'token_hash' => password_hash('secret', PASSWORD_BCRYPT),
            'expires_at' => now()->addMinutes(10),
        ]);
    }
}
