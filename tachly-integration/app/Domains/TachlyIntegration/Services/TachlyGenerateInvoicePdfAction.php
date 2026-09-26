<?php

namespace App\Domains\TachlyIntegration\Services;

use App\Domains\Invoicing\Actions\GenerateQrInvoicePdfAction;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Services\InvoicePdfRenderer;
use App\Domains\Invoicing\Services\SwissQrInvoiceService;
use App\Domains\Invoicing\Support\InvoicePdfStyle;
use App\Domains\Organizations\Models\Organization;
use TCPDF;

/**
 * A credit note is money owed TO the customer — a Swiss QR payment slip asking them to pay the
 * club is wrong on it. Upstream's action always appends the slip and offers no seam, so this
 * repeats its invoice-content half and stops there for credit notes; every other invoice defers
 * to the upstream action untouched. Upstream's own collaborators are private, hence the own
 * constructor. `artisan tachly:verify-compat` after a GAELD_VERSION bump should be paired with a
 * look at upstream's execute() for drift.
 */
class TachlyGenerateInvoicePdfAction extends GenerateQrInvoicePdfAction
{
    public function __construct(
        SwissQrInvoiceService $qrService,
        private readonly InvoicePdfRenderer $renderer,
    ) {
        parent::__construct($qrService, $renderer);
    }

    public function execute(Invoice $invoice, Organization $organization, string $language = 'en'): string
    {
        if ($invoice->type !== InvoiceType::CreditNote) {
            return parent::execute($invoice, $organization, $language);
        }

        $invoice->loadMissing(['customer', 'lines.vatRate']);

        $tcpdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $tcpdf->setPrintHeader(false);
        $tcpdf->setPrintFooter(false);
        $tcpdf->SetMargins(InvoicePdfStyle::MARGIN_LEFT, InvoicePdfStyle::MARGIN_TOP, InvoicePdfStyle::MARGIN_RIGHT);
        $tcpdf->SetAutoPageBreak(false);
        $tcpdf->AddPage();
        $tcpdf->SetFillColor(255, 255, 255);
        $tcpdf->Rect(0, 0, $tcpdf->getPageWidth(), $tcpdf->getPageHeight(), 'F');

        $this->renderer->setLocale($language);
        $this->renderer->renderFoldMarks($tcpdf);
        $this->renderer->renderInvoiceHeader($tcpdf, $invoice, $organization);
        $this->renderer->renderLineItems($tcpdf, $invoice);
        $this->renderer->renderTotals($tcpdf, $invoice, $organization);
        $this->renderer->renderFooter($tcpdf);

        return $tcpdf->Output('', 'S');
    }
}
