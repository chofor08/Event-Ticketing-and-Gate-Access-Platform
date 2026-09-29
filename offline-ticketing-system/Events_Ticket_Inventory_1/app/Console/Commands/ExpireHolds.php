<?php

namespace App\Console\Commands;

use App\Models\Hold;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('app:expire-holds')]
#[Description('Expire ticket holds that have exceeded their expiration time')]
class ExpireHolds extends Command
{
    protected $signature = 'holds:expire';

    protected $description = 'Expire ticket holds that have exceeded their expiration time';

    public function handle(): int
    {
        $holds = Hold::query()
            ->where('status', 'held')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($holds as $hold) {
            DB::transaction(function () use ($hold) {
                $activeHold = Hold::query()->lockForUpdate()->find($hold->id);

                if (! $activeHold || $activeHold->status !== 'held') {
                    return;
                }

                $activeHold->ticketType()->lockForUpdate()->first();
                $activeHold->status = 'expired';
                $activeHold->save();
            });
        }

        return self::SUCCESS;
    }
}
