<?php

namespace App\Domains\TachlyIntegration\Http\Controllers;

use App\Domains\Accounting\Enums\AccountType;
use App\Domains\TachlyIntegration\Services\ClubProvisioningService;
use App\Http\Controllers\Controller;
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
}
