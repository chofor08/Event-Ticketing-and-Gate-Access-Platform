<?php

use App\Services\TicketCredentialService;

it('verifies the ticket identity encoded in a generated credential', function () {
    $service = app(TicketCredentialService::class);

    $credential = $service->generate('5c66c7db-a952-4d1b-88f4-d37e0434776d', 17, 29);

    expect($service->verify($credential))->toBe([
        'ticket_id' => '5c66c7db-a952-4d1b-88f4-d37e0434776d',
        'event_id' => 17,
        'attendee_id' => 29,
    ]);
});

it('rejects a credential with a tampered payload', function () {
    $service = app(TicketCredentialService::class);
    $parts = explode(':', $service->generate('5c66c7db-a952-4d1b-88f4-d37e0434776d', 17, 29));
    $parts[1] = '18';

    $tamperedCredential = implode(':', $parts);

    expect(fn () => $service->verify($tamperedCredential))->toThrow(InvalidArgumentException::class);
});
