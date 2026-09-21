<?php

namespace App\Domains\TachlyIntegration\Console\Commands;

use App\Domains\TachlyIntegration\Services\ClubProvisioningService;
use Illuminate\Console\Command;

/**
 * Ops/disaster-recovery path for provisioning — the same code path the
 * `/internal/tachly/organizations` endpoint calls, runnable from the
 * Coolify web terminal when there's no way to reach Tachly's server (e.g.
 * debugging a failed provisioning call by hand).
 */
class ProvisionClub extends Command
{
    protected $signature = 'tachly:provision-club
        {tachly_club_id : Tachly club UUID}
        {club_name : Display name for the Gäld Organization}
        {owner_email : Becomes the org owner user}
        {owner_name}
        {iban}
        {--qr-iban=}
        {--locale=de}
        {--bank-ledger-account-code=}';

    protected $description = 'Provision (or return the existing) Gäld Organization for a Tachly club';

    public function handle(ClubProvisioningService $service): int
    {
        $result = $service->provision(
            tachlyClubId: $this->argument('tachly_club_id'),
            clubName: $this->argument('club_name'),
            ownerEmail: $this->argument('owner_email'),
            ownerName: $this->argument('owner_name'),
            iban: $this->argument('iban'),
            qrIban: $this->option('qr-iban'),
            locale: $this->option('locale'),
            bankLedgerAccountCode: $this->option('bank-ledger-account-code'),
        );

        $this->table(['organization_id', 'bank_account_id', 'api_token', 'was_already_provisioned'], [[
            $result['organization_id'],
            $result['bank_account_id'],
            $result['api_token'] ?? '(unchanged — already provisioned)',
            $result['was_already_provisioned'] ? 'yes' : 'no',
        ]]);

        if ($result['api_token'] !== null) {
            $this->warn('Capture the api_token now — it is never shown again. Use tachly:provision-club again (idempotent) or the rotate-token endpoint to mint a new one.');
        }

        return self::SUCCESS;
    }
}
