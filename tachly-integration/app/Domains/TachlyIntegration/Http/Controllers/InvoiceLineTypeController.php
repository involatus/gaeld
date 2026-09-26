<?php

namespace App\Domains\TachlyIntegration\Http\Controllers;

use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceStatus;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\CurrentOrganization;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Upstream's public `POST /invoices` validates only description/quantity/
 * unit_price (min 0)/vat_rate_id per line and silently drops `type`, so every
 * line lands as an Item: a fuel-credit deduction was ADDED to the total, and a
 * negative rounding adjustment was rejected outright. This flips chosen lines
 * of a DRAFT invoice to `discount` (Invoice::recalculate() subtracts those) and
 * recalculates — Tachly creates the draft via the public API, calls this, then
 * finalizes. Only ever touches draft invoices of the addressed club's org.
 */
class InvoiceLineTypeController extends Controller
{
    /**
     * @urlParam tachlyClubId string required
     * @urlParam invoiceId string required Gäld invoice UUID (as returned by POST /invoices).
     * @bodyParam discount_positions int[] required 0-based position (in creation order) of each line to turn into a discount line.
     */
    public function applyDiscounts(Request $request, string $tachlyClubId, string $invoiceId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'discount_positions' => ['present', 'array'],
            'discount_positions.*' => ['integer', 'min:0'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'code' => 'validation_error'], 422);
        }
        $positions = array_map('intval', $validator->validated()['discount_positions']);

        $organization = Organization::withoutGlobalScopes()->where('tachly_club_id', $tachlyClubId)->first();
        if ($organization === null) {
            return response()->json(['message' => "No organization provisioned for tachly_club_id={$tachlyClubId}", 'code' => 'not_found'], 404);
        }
        app(CurrentOrganization::class)->set($organization);

        $invoice = Invoice::query()->whereKey($invoiceId)->first();
        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.', 'code' => 'not_found'], 404);
        }
        if ($invoice->status !== InvoiceStatus::Draft) {
            return response()->json(['message' => 'Only draft invoices can be changed.', 'code' => 'not_draft'], 409);
        }

        DB::transaction(function () use ($invoice, $positions) {
            $lines = $invoice->lines()->orderBy('sort_order')->orderBy('id')->get()->values();
            foreach ($positions as $position) {
                $line = $lines->get($position);
                if ($line === null) {
                    continue;
                }
                $line->type = InvoiceLineType::Discount;
                $line->calculateAndSave();
            }
            $invoice->recalculate();
        });

        $invoice->refresh();

        return response()->json([
            'subtotal' => (string) $invoice->subtotal,
            'total' => (string) $invoice->total,
        ]);
    }
}
