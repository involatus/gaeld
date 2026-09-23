<?php

namespace App\Domains\TachlyIntegration\Http\Controllers;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\TachlyIntegration\Services\ClubProvisioningService;
use App\Http\Controllers\Controller;
use App\Support\AddressData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProvisionController extends Controller
{
    public function __construct(
        private readonly ClubProvisioningService $provisioningService,
    ) {}

    /**
     * Provision (or return the existing) Gäld Organization for a Tachly club.
     * Idempotent: safe to retry with the same `tachly_club_id`.
     *
     * @bodyParam tachly_club_id string required Tachly's own club UUID.
     * @bodyParam club_name string required
     * @bodyParam owner_email string required Becomes the org's owner user; never logs in directly.
     * @bodyParam owner_name string required
     * @bodyParam iban string required
     * @bodyParam qr_iban string
     * @bodyParam locale string default: de
     * @bodyParam bank_ledger_account_code string GL account the bank account posts to. Defaults to the seeded "Bank Account CHF" (1020).
     * @bodyParam extra_accounts array [{code, name, type: asset|liability|equity|revenue|expense}, ...]
     * @bodyParam address_street string The club's own creditor address — required for Swiss QR-bill PDF generation to work at all (`creditor.postalCode`/`creditor.city` must not be blank).
     * @bodyParam address_postal_code string
     * @bodyParam address_city string
     */
    public function store(Request $request): JsonResponse
    {
        // Validated and rendered by hand, not Laravel's default exception
        // renderer: this route lives under /internal/tachly/*, not /api/*,
        // so upstream's ValidationException renderable (scoped to
        // request()->is('api/*') in bootstrap/app.php) never fires here, and
        // this route group carries no session middleware for the web
        // fallback (redirect back with flashed errors) to work either — both
        // produced a bare 500 until this was caught explicitly.
        try {
            $validated = Validator::make($request->all(), [
                'tachly_club_id' => ['required', 'string', 'max:255'],
                'club_name' => ['required', 'string', 'max:255'],
                'owner_email' => ['required', 'email', 'max:255'],
                'owner_name' => ['required', 'string', 'max:255'],
                'iban' => ['required', 'string', 'max:34'],
                'qr_iban' => ['nullable', 'string', 'max:34'],
                'locale' => ['nullable', 'string', 'in:en,fr,de,it'],
                'bank_ledger_account_code' => ['nullable', 'string', 'max:20'],
                'address_street' => ['nullable', 'string', 'max:255'],
                'address_postal_code' => ['nullable', 'string', 'max:20'],
                'address_city' => ['nullable', 'string', 'max:255'],
                'extra_accounts' => ['nullable', 'array'],
                'extra_accounts.*.code' => ['required_with:extra_accounts', 'string', 'max:20'],
                'extra_accounts.*.name' => ['required_with:extra_accounts', 'string', 'max:255'],
                'extra_accounts.*.type' => ['required_with:extra_accounts', 'string', 'in:'.implode(',', array_column(AccountType::cases(), 'value'))],
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'validation_error',
                'errors' => $e->errors(),
            ], $e->status);
        }

        $result = $this->provisioningService->provision(
            tachlyClubId: $validated['tachly_club_id'],
            clubName: $validated['club_name'],
            ownerEmail: $validated['owner_email'],
            ownerName: $validated['owner_name'],
            iban: $validated['iban'],
            qrIban: $validated['qr_iban'] ?? null,
            extraAccounts: $validated['extra_accounts'] ?? [],
            locale: $validated['locale'] ?? 'de',
            bankLedgerAccountCode: $validated['bank_ledger_account_code'] ?? null,
            address: new AddressData(
                address: $validated['address_street'] ?? null,
                city: $validated['address_city'] ?? null,
                postalCode: $validated['address_postal_code'] ?? null,
                country: 'CH',
            ),
        );

        return response()->json($result, $result['was_already_provisioned'] ? 200 : 201);
    }

    /**
     * Mint a fresh org-scoped API token for an already-provisioned club.
     *
     * @urlParam tachlyClubId string required
     */
    public function rotateToken(string $tachlyClubId): JsonResponse
    {
        try {
            $token = $this->provisioningService->rotateToken($tachlyClubId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_found'], 404);
        }

        return response()->json(['api_token' => $token]);
    }

    /**
     * Triggers Gäld's own password-reset email to the club's owner user, so they
     * can set a real password and log into Gäld's web UI directly — for the
     * reports/reconciliation screens that have no `/api/v1` equivalent. Tachly
     * never sees or stores that password; this is purely "ask Gäld to email its
     * own reset link", nothing new.
     *
     * @urlParam tachlyClubId string required
     */
    public function sendLoginLink(string $tachlyClubId): JsonResponse
    {
        try {
            $this->provisioningService->sendLoginLink($tachlyClubId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_found'], 404);
        }

        return response()->json(['message' => 'Login link sent.']);
    }

    /**
     * Permanently, irreversibly deletes the Gäld Organization for a Tachly
     * club — a real hard delete (every invoice, contact, account, journal
     * entry, bank account, and org-scoped token), not a soft-delete. Ops-only:
     * no Tachly UI calls this today. See
     * `ClubProvisioningService::deprovision()` for exactly what it does and
     * does not touch.
     *
     * @urlParam tachlyClubId string required
     */
    public function destroy(string $tachlyClubId): JsonResponse
    {
        try {
            $this->provisioningService->deprovision($tachlyClubId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_found'], 404);
        } catch (\Throwable $e) {
            // TEMPORARY diagnostic detail — this route lives outside the
            // api/* exception-rendering scope (see the class doc comment on
            // store()), so an unhandled exception here otherwise renders as
            // an opaque Inertia error page with none of Laravel's own debug
            // output, even with APP_DEBUG on. Revert before this endpoint is
            // considered done.
            return response()->json([
                'message' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
            ], 500);
        }

        return response()->json(['message' => 'Organization permanently deleted.']);
    }
}
