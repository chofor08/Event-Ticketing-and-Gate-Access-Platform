<?php

namespace Tests\Feature;

use App\Mail\GateStaffInvitationMail;
use App\Models\Event;
use App\Models\Gate;
use App\Models\GateDevice;
use App\Models\GateStaffAssignment;
use App\Models\GateStaffInvitation;
use App\Models\Hold;
use App\Models\OrderItems;
use App\Models\Orders;
use App\Models\RefundRequest;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TicketCredentialService;
use App\Services\TicketIssuanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class GateAdmissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSigningKeys();
        config(['ticketing.invitation_url' => 'https://gate.example.test/accept-invitation']);
    }

    public function test_organizer_invitation_queues_link_and_persists_only_the_token_hash(): void
    {
        Mail::fake();
        $organizer = User::factory()->organizer()->create();
        $event = $this->createEvent($organizer);

        $response = $this->actingAs($organizer, 'sanctum')
            ->postJson("/api/organizer/events/{$event->id}/gate-staff/invitations", [
                'email' => 'gate-staff@example.test',
            ])->assertCreated();

        Mail::assertQueued(GateStaffInvitationMail::class, function (GateStaffInvitationMail $mail): bool {
            $query = parse_url($mail->invitationUrl, PHP_URL_QUERY);
            parse_str(is_string($query) ? $query : '', $parameters);
            $storedInvitation = GateStaffInvitation::query()->firstOrFail();

            return $mail->eventTitle === 'Admission Test Event'
                && is_string($parameters['token'] ?? null)
                && hash_equals($storedInvitation->token_hash, hash('sha256', $parameters['token']))
                && ! str_contains($storedInvitation->token_hash, $parameters['token']);
        });

        $response->assertJsonPath('invitation.email', 'gate-staff@example.test');
        $this->assertDatabaseCount('gate_staff_invitations', 1);
    }

    public function test_invitation_creates_gate_staff_or_preserves_existing_role(): void
    {
        $organizer = User::factory()->organizer()->create();
        $event = $this->createEvent($organizer);
        $newToken = bin2hex(random_bytes(32));
        $newInvite = $this->createInvitation($event, $organizer, 'new-staff@example.test', $newToken);

        $this->postJson('/api/gate-invitations/accept', [
            'token' => $newToken,
            'name' => 'New Staff',
            'password' => 'Valid-pass-123!',
            'password_confirmation' => 'Valid-pass-123!',
        ])->assertOk()
            ->assertJsonPath('user.role', 'gate_staff');

        $newUser = User::query()->where('email', $newInvite->email)->firstOrFail();
        $this->assertNotNull($newUser->email_verified_at);
        $this->assertDatabaseHas('gate_staff_assignments', [
            'event_id' => $event->id,
            'user_id' => $newUser->id,
            'invitation_id' => $newInvite->id,
        ]);

        $existing = User::factory()->create(['role' => 'attendee']);
        $existingToken = bin2hex(random_bytes(32));
        $this->createInvitation($event, $organizer, $existing->email, $existingToken);

        $this->postJson('/api/gate-invitations/accept', ['token' => $existingToken])->assertUnauthorized();

        $this->actingAs($existing, 'sanctum')
            ->postJson('/api/gate-invitations/accept', ['token' => $existingToken])
            ->assertOk()
            ->assertJsonPath('user.role', 'attendee');

        $this->assertSame('attendee', $existing->fresh()->role);
    }

    public function test_gate_access_lists_only_assigned_events_active_gates_and_owned_devices(): void
    {
        $organizer = User::factory()->organizer()->create();
        $event = $this->createEvent($organizer);
        $staff = User::factory()->create(['role' => 'attendee']);
        $otherStaff = User::factory()->create(['role' => 'organizer']);
        GateStaffAssignment::create([
            'event_id' => $event->id,
            'user_id' => $staff->id,
            'assigned_by_user_id' => $organizer->id,
        ]);
        $activeGate = Gate::create(['event_id' => $event->id, 'name' => 'North', 'status' => 'active']);
        Gate::create(['event_id' => $event->id, 'name' => 'Closed', 'status' => 'inactive']);
        $activeDevice = $this->createDevice($activeGate, $staff);
        $revokedDevice = $this->createDevice($activeGate, $staff);
        $revokedDevice->update(['revoked_at' => now()]);
        $otherDevice = $this->createDevice($activeGate, $otherStaff);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/gate/access')
            ->assertOk()
            ->assertJsonCount(1, 'assignments')
            ->assertJsonPath('assignments.0.event.id', $event->id)
            ->assertJsonCount(1, 'assignments.0.event.gates')
            ->assertJsonPath('assignments.0.event.gates.0.devices.0.id', $activeDevice->public_id)
            ->assertJsonMissing(['id' => $revokedDevice->public_id])
            ->assertJsonMissing(['id' => $otherDevice->public_id]);
    }

    public function test_ticket_holders_cannot_scan_their_assigned_event(): void
    {
        $organizer = User::factory()->organizer()->create();
        $event = $this->createEvent($organizer);
        $attendee = User::factory()->create(['role' => 'attendee']);
        [$ticket] = $this->issueTickets($event, $attendee, 1);
        $gate = Gate::create(['event_id' => $event->id, 'name' => 'North', 'status' => 'active']);
        GateStaffAssignment::create([
            'event_id' => $event->id,
            'user_id' => $attendee->id,
            'assigned_by_user_id' => $organizer->id,
        ]);
        $credential = app(TicketCredentialService::class)->generate(
            $ticket->public_id,
            $event->id,
            $attendee->id,
            $ticket->credential_key_id,
        )['credential'];

        $this->actingAs($attendee, 'sanctum')
            ->postJson("/api/gate/events/{$event->id}/gates/{$gate->id}/scans", [
                'credential' => $credential,
                'attempt_id' => (string) Str::uuid(),
            ])->assertForbidden();

        $this->assertDatabaseCount('scan_attempts', 0);
    }

    public function test_attendees_retrieve_only_their_tickets_and_online_scans_admit_once(): void
    {
        $organizer = User::factory()->organizer()->create();
        $event = $this->createEvent($organizer);
        $attendee = User::factory()->create(['role' => 'attendee']);
        [$ticket, $credential] = $this->issueTickets($event, $attendee, 1);
        $anotherAttendee = User::factory()->create(['role' => 'attendee']);
        $staff = User::factory()->create(['role' => 'organizer']);
        $gate = Gate::create(['event_id' => $event->id, 'name' => 'North', 'status' => 'active']);
        GateStaffAssignment::create([
            'event_id' => $event->id,
            'user_id' => $staff->id,
            'assigned_by_user_id' => $organizer->id,
        ]);

        $this->actingAs($attendee, 'sanctum')
            ->getJson('/api/my-tickets')
            ->assertOk()
            ->assertJsonPath('tickets.data.0.id', $ticket->id)
            ->assertJsonPath('tickets.data.0.credential', $credential);
        $this->actingAs($anotherAttendee, 'sanctum')
            ->getJson('/api/my-tickets')
            ->assertOk()
            ->assertJsonCount(0, 'tickets.data');
        $this->assertFalse(Schema::hasColumn('tickets', 'credential'));

        $attemptId = (string) Str::uuid();
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/gate/events/{$event->id}/gates/{$gate->id}/scans", [
                'credential' => $credential,
                'attempt_id' => $attemptId,
            ])->assertOk()->assertJsonPath('data.outcome', 'admitted');
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/gate/events/{$event->id}/gates/{$gate->id}/scans", [
                'credential' => $credential,
                'attempt_id' => $attemptId,
            ])->assertOk()->assertJsonPath('data.outcome', 'admitted');
        $this->assertDatabaseCount('scan_attempts', 1);
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/gate/events/{$event->id}/gates/{$gate->id}/scans", [
                'credential' => $credential,
                'attempt_id' => (string) Str::uuid(),
            ])->assertOk()
            ->assertJsonPath('data.outcome', 'already_used')
            ->assertJsonPath('data.admission.gate.id', $gate->id)
            ->assertJsonPath('data.admission.gate.name', $gate->name)
            ->assertJsonPath('data.admission.admitted_at', $ticket->fresh()->admitted_at->toIso8601String());

        $this->assertSame('admitted', $ticket->fresh()->status);
        $this->assertDatabaseCount('scan_attempts', 2);
        $this->assertSame(hash('sha256', $credential), $ticket->fresh()->scanAttempts()->firstOrFail()->ticket_code_hash);
    }

    public function test_offline_duplicate_admissions_are_preserved_and_counted_once(): void
    {
        $organizer = User::factory()->organizer()->create();
        $event = $this->createEvent($organizer);
        $attendee = User::factory()->create(['role' => 'attendee']);
        [$ticket] = $this->issueTickets($event, $attendee, 1);
        $staffOne = User::factory()->create(['role' => 'attendee']);
        $staffTwo = User::factory()->create(['role' => 'organizer']);
        $gateOne = Gate::create(['event_id' => $event->id, 'name' => 'North', 'status' => 'active']);
        $gateTwo = Gate::create(['event_id' => $event->id, 'name' => 'South', 'status' => 'active']);
        foreach ([$staffOne, $staffTwo] as $staff) {
            GateStaffAssignment::create([
                'event_id' => $event->id,
                'user_id' => $staff->id,
                'assigned_by_user_id' => $organizer->id,
            ]);
        }
        $deviceOne = $this->createDevice($gateOne, $staffOne);
        $deviceTwo = $this->createDevice($gateTwo, $staffTwo);

        $snapshotOne = $this->actingAs($staffOne, 'sanctum')
            ->getJson("/api/gate/devices/{$deviceOne->public_id}/snapshot")
            ->assertOk()
            ->assertJsonPath('complete', true)
            ->json('snapshot.id');
        $snapshotTwo = $this->actingAs($staffTwo, 'sanctum')
            ->getJson("/api/gate/devices/{$deviceTwo->public_id}/snapshot")
            ->assertOk()
            ->assertJsonPath('complete', true)
            ->json('snapshot.id');
        $credential = app(TicketCredentialService::class)->generate(
            $ticket->public_id,
            $event->id,
            $attendee->id,
            $ticket->credential_key_id,
        )['credential'];
        $scannedAt = now()->toIso8601String();

        $this->actingAs($staffOne, 'sanctum')->postJson("/api/gate/devices/{$deviceOne->public_id}/scan-sync", [
            'snapshot_id' => $snapshotOne,
            'attempts' => [
                'malformed-attempt',
                [
                    'attempt_id' => (string) Str::uuid(),
                    'credential' => $credential,
                    'scanned_at' => $scannedAt,
                    'reported_outcome' => 'admitted',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('results.0.accepted', false)
            ->assertJsonPath('results.1.accepted', true)
            ->assertJsonPath('results.1.data.reconciled_outcome', 'admitted');

        $this->actingAs($staffTwo, 'sanctum')->postJson("/api/gate/devices/{$deviceTwo->public_id}/scan-sync", [
            'snapshot_id' => $snapshotTwo,
            'attempts' => [[
                'attempt_id' => (string) Str::uuid(),
                'credential' => $credential,
                'scanned_at' => $scannedAt,
                'reported_outcome' => 'admitted',
            ]],
        ])->assertOk()->assertJsonPath('results.0.data.reconciliation_flags.0', 'device_time_unverified');

        $this->assertSame('admission_conflict', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->admitted_gate_id);
        $this->assertDatabaseCount('scan_attempts', 2);

        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/entry-counts")
            ->assertOk()
            ->assertJsonPath('admitted_tickets', 1);
    }

    public function test_late_refund_reclassifies_an_offline_admission_and_removes_it_from_counts(): void
    {
        $organizer = User::factory()->organizer()->create();
        $event = $this->createEvent($organizer);
        $attendee = User::factory()->create(['role' => 'attendee']);
        [$ticket, $credential] = $this->issueTickets($event, $attendee, 1);
        $staff = User::factory()->create(['role' => 'attendee']);
        $gate = Gate::create(['event_id' => $event->id, 'name' => 'East', 'status' => 'active']);
        GateStaffAssignment::create([
            'event_id' => $event->id,
            'user_id' => $staff->id,
            'assigned_by_user_id' => $organizer->id,
        ]);
        $device = $this->createDevice($gate, $staff);
        $snapshotId = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/gate/devices/{$device->public_id}/snapshot")
            ->assertOk()
            ->json('snapshot.id');
        $ticketItem = $ticket->orderItem;
        RefundRequest::create([
            'order_id' => $ticketItem->order_id,
            'event_id' => $event->id,
            'reason' => 'attendee_request',
            'order_item_ids' => [$ticketItem->id],
            'amount_xaf' => 1200,
            'idempotency_key' => 'offline-stale-refund-'.$ticketItem->id,
            'status' => 'succeeded',
            'processed_at' => now(),
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/gate/devices/{$device->public_id}/scan-sync", [
                'snapshot_id' => $snapshotId,
                'attempts' => [[
                    'attempt_id' => (string) Str::uuid(),
                    'credential' => $credential,
                    'scanned_at' => now()->toIso8601String(),
                    'reported_outcome' => 'admitted',
                ]],
            ])->assertOk()
            ->assertJsonPath('results.0.data.outcome', 'admitted')
            ->assertJsonPath('results.0.data.reconciled_outcome', 'cancelled_or_refunded');

        $this->assertSame('refunded', $ticket->fresh()->status);
        $this->actingAs($organizer, 'sanctum')
            ->getJson("/api/organizer/events/{$event->id}/entry-counts")
            ->assertOk()
            ->assertJsonPath('admitted_tickets', 0);
    }

    public function test_offline_sync_reclassifies_a_valid_foreign_event_qr(): void
    {
        $organizer = User::factory()->organizer()->create();
        $assignedEvent = $this->createEvent($organizer);
        $ticketEvent = $this->createEvent($organizer);
        $attendee = User::factory()->create(['role' => 'attendee']);
        [$ticket, $credential] = $this->issueTickets($ticketEvent, $attendee, 1);
        $staff = User::factory()->create(['role' => 'attendee']);
        $gate = Gate::create(['event_id' => $assignedEvent->id, 'name' => 'North', 'status' => 'active']);
        GateStaffAssignment::create([
            'event_id' => $assignedEvent->id,
            'user_id' => $staff->id,
            'assigned_by_user_id' => $organizer->id,
        ]);
        $device = $this->createDevice($gate, $staff);
        $snapshotId = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/gate/devices/{$device->public_id}/snapshot")
            ->assertOk()
            ->json('snapshot.id');

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/gate/devices/{$device->public_id}/scan-sync", [
                'snapshot_id' => $snapshotId,
                'attempts' => [[
                    'attempt_id' => (string) Str::uuid(),
                    'credential' => $credential,
                    'scanned_at' => now()->toIso8601String(),
                    'reported_outcome' => 'invalid_code',
                ]],
            ])->assertOk()
            ->assertJsonPath('results.0.data.outcome', 'invalid_code')
            ->assertJsonPath('results.0.data.reconciled_outcome', 'wrong_event');

        $this->assertDatabaseHas('scan_attempts', [
            'ticket_id' => $ticket->id,
            'event_id' => $assignedEvent->id,
            'outcome' => 'invalid_code',
            'reconciled_outcome' => 'wrong_event',
        ]);
    }

    private function configureSigningKeys(): void
    {
        config([
            'ticketing.active_ticket_key_id' => 'test-ticket-key',
            'ticketing.ticket_signing_keys' => ['test-ticket-key' => base64_encode(str_repeat('test-key-material-', 3))],
            'ticketing.snapshot_key_id' => 'test-snapshot-key',
            'ticketing.snapshot_private_key' => <<<'PEM'
                -----BEGIN PRIVATE KEY-----
                MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgIEV58WJYI4Ry4KpK
                mVyTLKX3PzTfvNAEID7tLzqviYehRANCAATRGgRNaVWfnsNrTricoPC92H9SHech
                mZYIKK7H1mpd69Ogwv+PYsVeJbk5julXb58MvQpRYFr2eiNYQraThi/Q
                -----END PRIVATE KEY-----
                PEM,
            'ticketing.snapshot_public_keys' => ['test-snapshot-key' => <<<'PEM'
                -----BEGIN PUBLIC KEY-----
                MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE0RoETWlVn57Da064nKDwvdh/Uh3n
                IZmWCCiux9ZqXevToML/j2LFXiW5OY7pV2+fDL0KUWBa9nojWEK2k4Yv0A==
                -----END PUBLIC KEY-----
                PEM],
            'ticketing.snapshot_lifetime_seconds' => 86400,
            'ticketing.snapshot_retention_days' => 30,
        ]);
    }

    private function createEvent(User $organizer): Event
    {
        return Event::create([
            'organizer_id' => $organizer->id,
            'title' => 'Admission Test Event',
            'venue' => 'Main Hall',
            'town' => 'Sample Town',
            'date' => now()->addWeek()->toDateString(),
            'start_time' => '19:00:00',
            'status' => 'published',
        ]);
    }

    private function createInvitation(Event $event, User $organizer, string $email, string $token): GateStaffInvitation
    {
        return GateStaffInvitation::create([
            'event_id' => $event->id,
            'invited_by_user_id' => $organizer->id,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
        ]);
    }

    private function createDevice(Gate $gate, User $staff): GateDevice
    {
        return GateDevice::create([
            'public_id' => (string) Str::uuid(),
            'gate_id' => $gate->id,
            'user_id' => $staff->id,
            'name' => 'Gate tablet',
        ]);
    }

    /** @return array{Ticket, string} */
    private function issueTickets(Event $event, User $attendee, int $quantity): array
    {
        $ticketType = TicketType::create([
            'event_id' => $event->id,
            'name' => 'General',
            'base_price_xaf' => 1200,
            'quantity' => $quantity,
        ]);
        $hold = Hold::create([
            'user_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'quantity' => $quantity,
            'status' => 'confirmed',
            'token_hash' => hash('sha256', (string) Str::uuid()),
            'expires_at' => now()->addMinutes(10),
        ]);
        $order = Orders::create([
            'user_id' => $attendee->id,
            'status' => 'paid',
            'currency' => 'xaf',
            'amount_xaf' => 1200 * $quantity,
        ]);
        OrderItems::create([
            'order_id' => $order->id,
            'ticket_type_id' => $ticketType->id,
            'hold_id' => $hold->id,
            'quantity' => $quantity,
            'unit_price_xaf' => 1200,
            'sub_total_xaf' => 1200 * $quantity,
        ]);

        app(TicketIssuanceService::class)->issue($order);
        $ticket = Ticket::query()->where('event_id', $event->id)->firstOrFail();
        $credential = app(TicketCredentialService::class)->generate(
            $ticket->public_id,
            $event->id,
            $attendee->id,
            $ticket->credential_key_id,
        )['credential'];

        return [$ticket, $credential];
    }
}
