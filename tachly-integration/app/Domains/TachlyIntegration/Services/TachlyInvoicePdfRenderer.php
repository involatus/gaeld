<?php

namespace App\Domains\TachlyIntegration\Services;

use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoicePdfRenderer;
use App\Domains\TachlyIntegration\Support\TachlyInvoicePdfStyle as Style;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use TCPDF;

/**
 * TAC-228: Tachly-branded invoice PDF renderer. Bound over the base
 * `InvoicePdfRenderer` in `TachlyIntegrationServiceProvider` — every org on this
 * instance gets this rendering, since this Gäld instance exists specifically for
 * Tachly clubs.
 *
 * This is a full reimplementation, not a thin override: the parent's `$locale`
 * property and `t()` translation helper are both `private` (not `protected`), so a
 * subclass can't reach them, and every render* method references
 * `InvoicePdfStyle`'s color/font constants inline throughout rather than through an
 * overridable seam. Rebranding correctly means replacing every one of those
 * references, not patching a single method. Column widths, positions, and the
 * fold/punch marks are kept byte-for-byte identical to upstream (`TachlyInvoicePdfStyle`
 * mirrors `InvoicePdfStyle`'s non-color constants exactly) — those encode the Swiss
 * SN 010130 / DIN 5008 letter-fold standard and Swiss QR-bill layout, not Gäld's
 * own branding, and must not drift from what the QR-bill placement (rendered
 * separately, by `GenerateQrInvoicePdfAction`, unchanged) expects.
 *
 * The logo is a bundled Tachly asset (`../assets/tachly-logo.png`), not
 * `$organization->logo_path` — every Tachly-provisioned org shares one brand.
 */
class TachlyInvoicePdfRenderer extends InvoicePdfRenderer
{
    private string $locale = 'en';

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    private function t(string $key): string
    {
        return trans('app.'.$key, [], $this->locale);
    }

    public function renderFoldMarks(TCPDF $tcpdf): void
    {
        $tcpdf->SetDrawColor(...Style::COLOR_GRAY);
        $tcpdf->SetLineWidth(0.1);

        foreach ([Style::FOLD_MARK_TOP_Y, Style::PUNCH_MARK_Y, Style::FOLD_MARK_BOTTOM_Y] as $y) {
            $tcpdf->Line(Style::FOLD_MARK_X, $y, Style::FOLD_MARK_X + Style::FOLD_MARK_LENGTH, $y);
        }

        $tcpdf->SetDrawColor(0, 0, 0);
        $tcpdf->SetLineWidth(0.2);
    }

    public function renderInvoiceHeader(TCPDF $tcpdf, Invoice $invoice, Organization $organization): void
    {
        $logoPath = __DIR__.'/../assets/tachly-logo.png';
        $logoHeight = 0.0;
        if (file_exists($logoPath)) {
            $logoWidth = (float) Style::LOGO_WIDTH;
            $imageSize = @getimagesize($logoPath);
            if ($imageSize !== false && $imageSize[0] > 0 && $imageSize[1] > 0) {
                $aspectRatio = $imageSize[0] / $imageSize[1];
                $logoHeight = min(Style::LOGO_MAX_HEIGHT, $logoWidth / $aspectRatio);
                $logoWidth = $logoHeight * $aspectRatio;
            } else {
                $logoHeight = Style::LOGO_MAX_HEIGHT;
            }

            $tcpdf->Image($logoPath, Style::LOGO_X, Style::LOGO_Y, $logoWidth, $logoHeight);
        }

        $tcpdf->SetFont('Helvetica', 'B', 10);
        $organizationY = $logoHeight > 0 ? Style::LOGO_Y + $logoHeight + Style::LOGO_GAP : Style::MARGIN_TOP;
        $tcpdf->SetXY(Style::ORGANIZATION_X, $organizationY);
        $tcpdf->Cell(Style::ORGANIZATION_WIDTH, 5, $organization->legal_name ?? $organization->name, 0, 1, 'L');

        $tcpdf->SetFont('Helvetica', '', 8);
        $orgAddress = array_filter([
            $organization->address,
            trim(($organization->postal_code ?? '').' '.($organization->city ?? '')),
            $organization->canton ? ($organization->country ?? 'CH').' — '.$organization->canton : ($organization->country ?? 'CH'),
        ]);
        foreach ($orgAddress as $line) {
            $tcpdf->SetX(Style::ORGANIZATION_X);
            $tcpdf->Cell(Style::ORGANIZATION_WIDTH, 4, $line, 0, 1, 'L');
        }
        if ($organization->vat_number) {
            $tcpdf->SetX(Style::ORGANIZATION_X);
            $tcpdf->SetFont('Helvetica', '', 7);
            $tcpdf->SetTextColor(...Style::COLOR_GRAY);
            $tcpdf->Cell(Style::ORGANIZATION_WIDTH, 4, $this->t('pdf_vat_number').': '.$organization->vat_number, 0, 1, 'L');
            $tcpdf->SetTextColor(0, 0, 0);
        }

        $customer = $invoice->customer;
        $customerDetails = $invoice->customer_snapshot;
        if ($customerDetails === null && $customer !== null) {
            $customerDetails = $customer->toInvoiceSnapshot();
        }

        if ($customerDetails !== null) {
            $tcpdf->SetXY(Style::CUSTOMER_X, Style::CUSTOMER_INFO_Y);
            $tcpdf->SetFont('Helvetica', 'B', 10);
            $tcpdf->Cell(Style::CUSTOMER_WIDTH, 5, $customerDetails['name'], 0, 1, 'L');

            $tcpdf->SetFont('Helvetica', '', 9);
            $customerAddress = array_filter([
                $customerDetails['address'],
                trim(($customerDetails['postal_code'] ?? '').' '.($customerDetails['city'] ?? '')),
                $customerDetails['country'],
            ]);
            foreach ($customerAddress as $line) {
                $tcpdf->SetX(Style::CUSTOMER_X);
                $tcpdf->Cell(Style::CUSTOMER_WIDTH, 4, $line, 0, 1, 'L');
            }
            if ($customerDetails['vat_number']) {
                $tcpdf->SetFont('Helvetica', '', 7);
                $tcpdf->SetTextColor(...Style::COLOR_GRAY);
                $tcpdf->SetX(Style::CUSTOMER_X);
                $tcpdf->Cell(Style::CUSTOMER_WIDTH, 4, $this->t('pdf_vat_number').': '.$customerDetails['vat_number'], 0, 1, 'L');
                $tcpdf->SetTextColor(0, 0, 0);
            }
        }

        $tcpdf->SetXY(Style::MARGIN_LEFT, Style::INVOICE_TITLE_Y);
        $tcpdf->SetFont('Helvetica', 'B', Style::FONT_INVOICE_TITLE);
        $tcpdf->SetTextColor(...Style::COLOR_ACCENT);
        $invoiceTypeLabel = $invoice->type === InvoiceType::CreditNote ? $this->t('pdf_credit_note') : $this->t('pdf_invoice');
        $tcpdf->Cell(0, 8, $invoiceTypeLabel.' '.($invoice->number ?? ''), 0, 1);

        $tcpdf->SetFont('Helvetica', '', 9);
        $tcpdf->SetTextColor(...Style::COLOR_GRAY);

        $metaLines = [];
        $metaLines[] = $this->t('pdf_date').': '.($invoice->issue_date->format('d.m.Y') ?? '');
        $metaLines[] = $this->t('pdf_due_date').': '.($invoice->due_date->format('d.m.Y') ?? '');
        if ($invoice->payment_terms) {
            $metaLines[] = $this->t('pdf_payment_terms').': '.$invoice->payment_terms;
        }
        $metaLines[] = $this->t('pdf_currency').': '.($invoice->currency ?? 'CHF');

        $tcpdf->Cell(0, 5, implode('    ', $metaLines), 0, 1);

        if ($invoice->qr_reference) {
            $tcpdf->SetFont('Helvetica', '', 8);
            $tcpdf->Cell(0, 4, $this->t('pdf_reference').': '.$invoice->qr_reference, 0, 1);
        }

        if (($invoice->tax_treatment ?? InvoiceTaxTreatment::Standard) === InvoiceTaxTreatment::ReverseCharge) {
            $tcpdf->SetFont('Helvetica', 'B', 8);
            $tcpdf->Cell(0, 4, $this->t('pdf_reverse_charge'), 0, 1);
        }

        $tcpdf->SetTextColor(0, 0, 0);

        if ($organization->invoice_header_text) {
            $tcpdf->Ln(2);
            $tcpdf->SetFont('Helvetica', '', 8);
            $tcpdf->MultiCell(Style::COL_TOTAL_WIDTH, 4, $organization->invoice_header_text, 0, 'L');
        }

        $tcpdf->Ln(4);
    }

    public function renderLineItems(TCPDF $tcpdf, Invoice $invoice): void
    {
        $tcpdf->SetFont('Helvetica', 'B', Style::FONT_TABLE_HEADER);
        $tcpdf->SetFillColor(...Style::COLOR_FILL);
        $tcpdf->Cell(Style::COL_DESCRIPTION, 6, $this->t('pdf_description'), 0, 0, 'L', true);
        $tcpdf->Cell(Style::COL_QUANTITY, 6, $this->t('pdf_quantity'), 0, 0, 'R', true);
        $tcpdf->Cell(Style::COL_UNIT_PRICE, 6, $this->t('pdf_unit_price'), 0, 0, 'R', true);
        $tcpdf->Cell(Style::COL_VAT, 6, $this->t('pdf_vat'), 0, 0, 'R', true);
        $tcpdf->Cell(Style::COL_AMOUNT, 6, $this->t('pdf_amount'), 0, 1, 'R', true);

        $tcpdf->SetFont('Helvetica', '', Style::FONT_TABLE_ROW);
        foreach ($invoice->lines as $line) {
            if ($line->type === InvoiceLineType::Discount && $line->discount_type === 'percentage') {
                $lineTotal = '-'.$line->amount;
                $qtyLabel = '—';
                $priceLabel = $line->unit_price.'%';
            } elseif ($line->type === InvoiceLineType::Discount) {
                $lineTotal = '-'.Money::multiply2((string) $line->quantity, (string) $line->unit_price);
                $qtyLabel = number_format((float) $line->quantity, 2);
                $priceLabel = number_format((float) $line->unit_price, 2);
            } else {
                $lineTotal = Money::multiply2((string) $line->quantity, (string) $line->unit_price);
                $qtyLabel = number_format((float) $line->quantity, 2);
                $priceLabel = number_format((float) $line->unit_price, 2);
            }
            $vatLabel = $line->vatRate ? ($line->vatRate->rate.'%') : '-';

            $descText = str_replace(["\r\n", "\r"], "\n", (string) $line->description);
            $rowY = $tcpdf->GetY();
            $lineCount = max(1, $tcpdf->getNumLines($descText, Style::COL_DESCRIPTION));
            $rowHeight = max(5.0, $lineCount * 4.0);

            $tcpdf->MultiCell(Style::COL_DESCRIPTION, 4, $descText, 0, 'L', false, 0);
            $tcpdf->SetXY(Style::MARGIN_LEFT + Style::COL_DESCRIPTION, $rowY);
            $tcpdf->Cell(Style::COL_QUANTITY, $rowHeight, $qtyLabel, 0, 0, 'R');
            $tcpdf->Cell(Style::COL_UNIT_PRICE, $rowHeight, $priceLabel, 0, 0, 'R');
            $tcpdf->Cell(Style::COL_VAT, $rowHeight, $vatLabel, 0, 0, 'R');
            $tcpdf->Cell(Style::COL_AMOUNT, $rowHeight, number_format((float) $lineTotal, 2), 0, 1, 'R');
            $tcpdf->SetY($rowY + $rowHeight);
        }

        $tcpdf->Cell(Style::COL_TOTAL_WIDTH, 0, '', 'T', 1);
    }

    public function renderTotals(TCPDF $tcpdf, Invoice $invoice, Organization $organization): void
    {
        $tcpdf->Ln(2);
        $tcpdf->SetFont('Helvetica', '', Style::FONT_TOTALS);

        $tcpdf->Cell(Style::TOTALS_LABEL_WIDTH, 5, $this->t('pdf_subtotal'), 0, 0, 'R');
        $tcpdf->Cell(Style::COL_AMOUNT, 5, number_format((float) $invoice->subtotal, 2), 0, 1, 'R');

        if ((float) $invoice->vat_amount > 0) {
            $tcpdf->Cell(Style::TOTALS_LABEL_WIDTH, 5, $this->t('pdf_vat_total'), 0, 0, 'R');
            $tcpdf->Cell(Style::COL_AMOUNT, 5, number_format((float) $invoice->vat_amount, 2), 0, 1, 'R');
        }

        $tcpdf->SetFont('Helvetica', 'B', Style::FONT_TOTALS_GRAND);
        $tcpdf->SetTextColor(...Style::COLOR_ACCENT);
        $tcpdf->Cell(Style::TOTALS_LABEL_WIDTH, 7, $this->t('pdf_total').' '.($invoice->currency ?? 'CHF'), 0, 0, 'R');
        $tcpdf->Cell(Style::COL_AMOUNT, 7, number_format((float) $invoice->total, 2), 0, 1, 'R');
        $tcpdf->SetTextColor(...Style::COLOR_BLACK);

        if ($invoice->notes) {
            $tcpdf->Ln(8);
            $tcpdf->SetFont('Helvetica', '', 8);
            $tcpdf->SetTextColor(...Style::COLOR_GRAY);
            $tcpdf->MultiCell(Style::COL_TOTAL_WIDTH, 4, $invoice->notes, 0, 'L');
            $tcpdf->SetTextColor(0, 0, 0);
        }

        if ($organization->invoice_footer_text) {
            $tcpdf->Ln(4);
            $tcpdf->SetFont('Helvetica', '', 7);
            $tcpdf->SetTextColor(...Style::COLOR_GRAY);
            $tcpdf->MultiCell(Style::COL_TOTAL_WIDTH, 3.5, $organization->invoice_footer_text, 0, 'L');
            $tcpdf->SetTextColor(0, 0, 0);
        }
    }

    public function renderFooter(TCPDF $tcpdf): void
    {
        $footerY = $tcpdf->getPageHeight() - 12;

        $tcpdf->SetDrawColor(...Style::COLOR_RULE);
        $tcpdf->SetLineWidth(0.2);
        $tcpdf->Line(Style::MARGIN_LEFT, $footerY - 2, $tcpdf->getPageWidth() - Style::MARGIN_RIGHT, $footerY - 2);
        $tcpdf->SetXY(Style::MARGIN_LEFT, $footerY);
        $tcpdf->SetFont('Helvetica', '', 7);
        $tcpdf->SetTextColor(...Style::COLOR_LIGHT);
        $tcpdf->Cell(70, 4, '© '.now()->year.' Tachly', 0, 0, 'L');
        $tcpdf->Cell(Style::COL_TOTAL_WIDTH - 70, 4, $tcpdf->getAliasNumPage().'/'.$tcpdf->getAliasNbPages(), 0, 1, 'R');
        $tcpdf->SetTextColor(...Style::COLOR_BLACK);
        $tcpdf->SetLineWidth(0.2);
    }
}
