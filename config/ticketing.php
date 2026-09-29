<?php

$ticketSigningKeys = json_decode((string) env('TICKET_SIGNING_KEYS', '{}'), true);
$snapshotPublicKeys = json_decode((string) env('GATE_SNAPSHOT_PUBLIC_KEYS', '{}'), true);

return [
    'active_ticket_key_id' => env('TICKET_ACTIVE_KEY_ID'),
    'ticket_signing_keys' => is_array($ticketSigningKeys) ? $ticketSigningKeys : [],
    'snapshot_private_key' => base64_decode((string) env('GATE_SNAPSHOT_PRIVATE_KEY', ''), true) ?: null,
    'snapshot_public_keys' => is_array($snapshotPublicKeys)
        ? array_map(static fn (mixed $key): ?string => is_string($key) ? base64_decode($key, true) ?: null : null, $snapshotPublicKeys)
        : [],
    'snapshot_key_id' => env('GATE_SNAPSHOT_KEY_ID'),
    'snapshot_lifetime_seconds' => 86400,
    'snapshot_retention_days' => 30,
    'invitation_url' => env('GATE_CLIENT_INVITATION_URL'),
];
