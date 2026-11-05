<?php

namespace App\Console\Commands;

use App\Domain\Cargo\Exceptions\CargoException;
use App\Domain\Corrections\Actions\ApproveCorrection;
use App\Models\CorrectionRequest;
use App\Models\User;
use Illuminate\Console\Command;

class ApproveCorrectionCommand extends Command
{
    protected $signature = 'cargo:approve-correction {request : correction request id} {approver : approver email}';

    protected $description = 'Approve a correction request as the given user (same rules as the dashboard).';

    public function handle(ApproveCorrection $approve): int
    {
        try {
            $r = $approve(
                CorrectionRequest::findOrFail($this->argument('request')),
                User::where('email', $this->argument('approver'))->firstOrFail(),
            );
            $this->line("approved reversal={$r->reversal_movement_id}");

            return self::SUCCESS;
        } catch (CargoException $e) {
            $this->line('rejected '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
