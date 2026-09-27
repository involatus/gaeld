<?php

namespace App\Domains\TachlyIntegration\Http\Controllers;

use App\Domains\Accounting\DTOs\JournalEntryData;
use App\Domains\Accounting\DTOs\JournalLineData;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Accounting\Services\LedgerService;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Domains\TachlyIntegration\Services\ClubProvisioningService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

/**
 * Bulk ledger import for Tachly's cashCtrl history import. Upstream's public API takes one journal
 * entry per request; ~900 of them is slow and can half-finish, so this posts a whole chunk in one
 * transaction (all or nothing) through the real LedgerService — same validation, same events.
 *
 * Idempotent by `reference`: an entry whose reference already exists is skipped, never duplicated.
 */
class LedgerImportController extends Controller
{
    public function __construct(private readonly ClubProvisioningService $provisioning) {}

    /**
     * @bodyParam accounts array [{code, name, type}] Create-or-rename these accounts first (only when not dry_run).
     */
    public function upsertAccounts(Request $request, string $tachlyClubId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'accounts' => ['required', 'array', 'min:1', 'max:500'],
            'accounts.*.code' => ['required', 'string', 'max:20'],
            'accounts.*.name' => ['required', 'string', 'max:255'],
            'accounts.*.type' => ['required', 'string', 'in:'.implode(',', array_column(AccountType::cases(), 'value'))],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'code' => 'validation_error'], 422);
        }

        try {
            $count = $this->provisioning->upsertAccounts($tachlyClubId, $validator->validated()['accounts']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_found'], 404);
        }

        return response()->json(['upserted' => $count]);
    }

    /**
     * @bodyParam entries array required [{date, reference, description, lines: [{account_code, debit, credit}]}]
     * @bodyParam dry_run bool Only report what would be created / skipped / unresolvable.
     */
    public function journalImport(Request $request, string $tachlyClubId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'dry_run' => ['sometimes', 'boolean'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.date' => ['required', 'date_format:Y-m-d'],
            'entries.*.reference' => ['required', 'string', 'max:100'],
            'entries.*.description' => ['nullable', 'string', 'max:1000'],
            'entries.*.lines' => ['required', 'array', 'min:2'],
            'entries.*.lines.*.account_code' => ['required', 'string', 'max:20'],
            'entries.*.lines.*.debit' => ['required', 'numeric', 'min:0'],
            'entries.*.lines.*.credit' => ['required', 'numeric', 'min:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'code' => 'validation_error'], 422);
        }
        $entries = $validator->validated()['entries'];
        $dryRun = (bool) ($validator->validated()['dry_run'] ?? false);

        $organization = Organization::withoutGlobalScopes()->where('tachly_club_id', $tachlyClubId)->first();
        if ($organization === null) {
            return response()->json(['message' => "No organization provisioned for tachly_club_id={$tachlyClubId}", 'code' => 'not_found'], 404);
        }
        app(CurrentOrganization::class)->set($organization);
        $orgId = (string) $organization->id;

        $accountIds = Account::withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('id', 'code')->all();

        $wanted = array_unique(array_merge(...array_map(fn ($e) => array_column($e['lines'], 'account_code'), $entries)));
        $unknown = array_values(array_filter($wanted, fn ($code) => ! isset($accountIds[$code])));

        $existing = JournalEntry::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->whereIn('reference', array_column($entries, 'reference'))
            ->pluck('reference')
            ->all();
        $existingSet = array_flip($existing);

        $toCreate = array_values(array_filter($entries, fn ($e) => ! isset($existingSet[$e['reference']])));

        if ($dryRun || $unknown !== []) {
            return response()->json([
                'dry_run' => $dryRun,
                'would_create' => count($toCreate),
                'already_present' => count($entries) - count($toCreate),
                'unknown_accounts' => $unknown,
            ], $unknown !== [] && ! $dryRun ? 422 : 200);
        }

        $ledger = app(LedgerService::class);
        try {
            DB::transaction(function () use ($toCreate, $ledger, $orgId, $accountIds) {
                foreach ($toCreate as $entry) {
                    $ledger->postEntry($orgId, new JournalEntryData(
                        date: $entry['date'],
                        reference: $entry['reference'],
                        description: $entry['description'] ?? null,
                        lines: array_map(fn ($l) => new JournalLineData(
                            accountId: (string) $accountIds[$l['account_code']],
                            debit: number_format((float) $l['debit'], 2, '.', ''),
                            credit: number_format((float) $l['credit'], 2, '.', ''),
                        ), $entry['lines']),
                    ));
                }
            });
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'import_failed'], 422);
        }

        return response()->json([
            'dry_run' => false,
            'created' => count($toCreate),
            'already_present' => count($entries) - count($toCreate),
            'unknown_accounts' => [],
        ]);
    }
}
