<?php

namespace App\Domains\TachlyIntegration\Services;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Organizations\DTOs\CreateOrganizationData;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\Organizations\Services\OrganizationService;
use App\Domains\Organizations\Services\OrganizationSetupService;
use App\Domains\Users\DTOs\CreateUserData;
use App\Domains\Users\Models\User;
use App\Domains\Users\Services\UserService;
use App\Http\Middleware\Api\TokenPermissionMap;
use App\Support\AddressData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Orchestrates everything Tachly needs to turn on the accounting module for
 * one club: an Organization, its owner User, a seeded + club-extended chart
 * of accounts, a bank account linked to the ledger, and an org-scoped API
 * token — none of which has a public /api/v1 endpoint today.
 *
 * Every step is find-or-create, keyed on `tachly_club_id` (organizations),
 * `code` (accounts), and `iban` (bank_accounts) — safe to call again for the
 * same club after a network failure or a retried request. Re-running for an
 * already-provisioned club never re-mints an API token (see rotateToken()
 * for that) and never re-creates the owner user.
 */
class ClubProvisioningService
{
    /**
     * @param  array{code: string, name: string, type: string}[]  $extraAccounts
     * @return array{organization_id: string, bank_account_id: string, api_token: string|null, was_already_provisioned: bool}
     */
    public function provision(
        string $tachlyClubId,
        string $clubName,
        string $ownerEmail,
        string $ownerName,
        string $iban,
        ?string $qrIban = null,
        array $extraAccounts = [],
        string $locale = 'de',
        ?string $bankLedgerAccountCode = null,
        ?AddressData $address = null,
    ): array {
        return DB::transaction(function () use (
            $tachlyClubId, $clubName, $ownerEmail, $ownerName,
            $iban, $qrIban, $extraAccounts, $locale, $bankLedgerAccountCode,
            $address,
        ) {
            $organization = Organization::withoutGlobalScopes()
                ->where('tachly_club_id', $tachlyClubId)
                ->first();
            $wasAlreadyProvisioned = $organization !== null;

            $owner = $this->findOrCreateOwner($ownerEmail, $ownerName, $locale);

            if (! $wasAlreadyProvisioned) {
                $organization = app(OrganizationService::class)->create($owner, new CreateOrganizationData(
                    name: $clubName,
                    addressData: $address,
                    country: 'CH',
                    currency: 'CHF',
                    locale: $locale,
                ));
                $organization->forceFill(['tachly_club_id' => $tachlyClubId])->save();

                app(OrganizationSetupService::class)->seedChartOfAccounts(
                    $organization,
                    (string) config('tachly-integration.chart_template', 'swiss_association'),
                );
            } else {
                // Idempotent retry: system accounts may already exist, this is a no-op then.
                app(OrganizationSetupService::class)->ensureSystemAccounts($organization);
            }

            // Always resync the creditor address (not just on first provisioning): the
            // Swiss QR-bill renderer needs `address`/`postal_code`/`city` populated on
            // the organization itself or every PDF fetch 422s ("creditor.postalCode:
            // This value should not be blank."). Patched directly on the model, not
            // through OrganizationService::update()/UpdateOrganizationData — that DTO
            // defaults require_two_factor/default_payment_terms_days/business_type on
            // every call, which would silently clobber anything an admin set by hand
            // via the Gäld web UI (now reachable since TAC-226's login-link flow).
            if ($address !== null && ($address->address !== null || $address->postalCode !== null || $address->city !== null)) {
                $organization->forceFill([
                    'address' => $address->address,
                    'postal_code' => $address->postalCode,
                    'city' => $address->city,
                ])->save();
            }

            $this->applyExtraAccounts($organization, $extraAccounts);

            $bankAccount = $this->upsertBankAccount(
                $organization,
                $clubName,
                $iban,
                $qrIban,
                $bankLedgerAccountCode ?? AccountCode::BANK_CASH,
            );

            $apiToken = $wasAlreadyProvisioned
                ? null
                : $this->mintOrgToken($owner, $organization);

            return [
                'organization_id' => $organization->id,
                'bank_account_id' => $bankAccount->uuid,
                'api_token' => $apiToken,
                'was_already_provisioned' => $wasAlreadyProvisioned,
            ];
        });
    }

    /**
     * Mint a fresh org-scoped token for an already-provisioned club, e.g.
     * because Tachly failed to persist the one returned at provisioning
     * time. Does not revoke any existing token — Tachly's admin flow should
     * treat this as "get me a usable token" and overwrite its stored one.
     */
    public function rotateToken(string $tachlyClubId): string
    {
        [$organization, $owner] = $this->resolveOwner($tachlyClubId);

        return $this->mintOrgToken($owner, $organization);
    }

    /**
     * Triggers Gäld's own standard password-reset email to the club's owner user —
     * the same flow any Gäld user would use if they forgot their password
     * (`Password::sendResetLink`, already fully wired: `PasswordResetController`,
     * configured mailer, signed token). Nothing here is new auth machinery; this
     * just calls it for a user whose password (a throwaway random string, see
     * `findOrCreateOwner`) nobody has ever known. The admin clicks the emailed
     * link, sets their own password, and can log into Gäld directly from then on
     * (2FA optional on top, same as any other Gäld user) — for the reports/
     * reconciliation screens that only exist in the web UI, not `/api/v1`.
     *
     * Safe to call repeatedly — each call just issues a fresh reset token:
     * Gäld's own `password_reset_tokens` table invalidates the previous one.
     */
    public function sendLoginLink(string $tachlyClubId): void
    {
        [, $owner] = $this->resolveOwner($tachlyClubId);

        Password::sendResetLink(['email' => $owner->email]);
    }

    /**
     * Permanently deletes the Gäld Organization for a Tachly club — a real,
     * cascading hard delete (`forceDelete()`, bypassing Organization's own
     * SoftDeletes), not the soft-delete `DeleteOrganizationAction` uses
     * elsewhere in Gäld. Deliberately not the same operation: a soft-deleted
     * org is still found by `provision()`'s own lookup
     * (`Organization::withoutGlobalScopes()`, which strips the SoftDeletes
     * scope along with every other one) and would be silently reused instead
     * of provisioning fresh — exactly wrong for "delete this club's test data
     * and start over." Every org-scoped table (`invoices`/`invoice_lines`,
     * `accounts`/`journal_entries`/`transaction_lines`, `bank_accounts`/
     * `bank_transactions`, `customers`, `suppliers`, `organization_users`,
     * `personal_access_tokens`) has `organization_id` with
     * `->cascadeOnDelete()`, so this one call is genuinely complete — no
     * separate per-table cleanup needed. The owner User row is intentionally
     * left intact (orphaned, no membership) so `findOrCreateOwner` reuses the
     * same person on a later re-provision instead of erroring on a duplicate
     * email.
     */
    public function deprovision(string $tachlyClubId): void
    {
        $organization = Organization::withoutGlobalScopes()
            ->where('tachly_club_id', $tachlyClubId)
            ->first();

        if (! $organization) {
            throw new InvalidArgumentException("No organization provisioned for tachly_club_id={$tachlyClubId}");
        }

        $organization->forceDelete();
    }

    /** @return array{0: Organization, 1: User} */
    private function resolveOwner(string $tachlyClubId): array
    {
        $organization = Organization::withoutGlobalScopes()
            ->where('tachly_club_id', $tachlyClubId)
            ->first();

        if (! $organization) {
            throw new InvalidArgumentException("No organization provisioned for tachly_club_id={$tachlyClubId}");
        }

        $owner = $organization->users()->wherePivot('role', 'owner')->first()
            ?? $organization->users()->first();

        if (! $owner) {
            throw new InvalidArgumentException("Organization {$organization->id} has no user to mint a token from.");
        }

        return [$organization, $owner];
    }

    private function findOrCreateOwner(string $email, string $name, string $locale): User
    {
        return User::where('email', $email)->first()
            ?? app(UserService::class)->create(new CreateUserData(
                name: $name,
                email: $email,
                // Never surfaced to anyone — the club admin operates entirely
                // through Tachly's own UI, not a direct Gäld login. A real
                // login can be issued later via Gäld's own password-reset
                // flow if direct access is ever needed.
                password: Str::random(40),
                locale: $locale,
                emailVerifiedAt: now(),
            ));
    }

    /** @param array{code: string, name: string, type: string}[] $extraAccounts */
    private function applyExtraAccounts(Organization $organization, array $extraAccounts): void
    {
        foreach ($extraAccounts as $extra) {
            Account::withoutGlobalScopes()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'code' => $extra['code'],
                ],
                [
                    'name' => $extra['name'],
                    'type' => AccountType::from($extra['type'])->value,
                    'is_active' => true,
                ],
            );
        }
    }

    private function upsertBankAccount(
        Organization $organization,
        string $clubName,
        string $iban,
        ?string $qrIban,
        string $bankLedgerAccountCode,
    ): BankAccount {
        $ledgerAccount = Account::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('code', $bankLedgerAccountCode)
            ->first();

        return BankAccount::withoutGlobalScopes()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'iban' => $iban,
            ],
            [
                'account_id' => $ledgerAccount?->id,
                'name' => $clubName.' — Bank',
                'qr_iban' => $qrIban,
                'currency' => 'CHF',
                'is_active' => true,
                'is_default_for_invoicing' => true,
            ],
        );
    }

    private function mintOrgToken(User $owner, Organization $organization): string
    {
        $abilities = TokenPermissionMap::normalize(
            (array) config('tachly-integration.token_abilities', ['*']),
        );

        // PersonalAccessToken::booted() stamps organization_id from the bound
        // CurrentOrganization at INSERT time (the column is NOT NULL) — the
        // real web flow gets this from EnsureApiOrganization/EnsureHasOrganization
        // middleware; our internal endpoint has no such middleware, so it must
        // be bound explicitly here before createToken() runs.
        app(CurrentOrganization::class)->set($organization);

        $token = $owner->createToken('Tachly integration', $abilities === [] ? ['*'] : $abilities);

        $token->accessToken->forceFill([
            'organization_id' => $organization->id,
            'type' => TokenType::Organization,
        ])->save();

        return $token->plainTextToken;
    }
}
