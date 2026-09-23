<?php

namespace App\Domains\TachlyIntegration\Support;

/**
 * TAC-228: Tachly-branded layout/style constants for invoice PDFs, mirroring
 * `App\Domains\Invoicing\Support\InvoicePdfStyle` (upstream, `final`, so not
 * extendable) with Tachly's own brand — navy `#0F172A` / sky-blue `#5BB8E8`
 * (`TachlyMark.svelte`, `docs/brand`) instead of Gäld's own green. Positions,
 * column widths, and fold/punch marks are kept identical to upstream — those
 * encode the Swiss SN 010130 / DIN 5008 letter standard and Swiss QR-bill
 * layout requirements, not Gäld's own branding.
 */
final class TachlyInvoicePdfStyle
{
    // Margins
    public const MARGIN_LEFT = 15;

    public const MARGIN_TOP = 15;

    public const MARGIN_RIGHT = 15;

    // Column widths (in mm)
    public const COL_DESCRIPTION = 80;

    public const COL_QUANTITY = 20;

    public const COL_UNIT_PRICE = 30;

    public const COL_VAT = 25;

    public const COL_AMOUNT = 25;

    public const COL_TOTAL_WIDTH = self::COL_DESCRIPTION + self::COL_QUANTITY + self::COL_UNIT_PRICE + self::COL_VAT + self::COL_AMOUNT; // 180

    // X and Y positions
    public const ORGANIZATION_X = 15;

    public const ORGANIZATION_WIDTH = 85;

    public const CUSTOMER_X = 120;

    public const CUSTOMER_INFO_Y = 50;

    public const CUSTOMER_WIDTH = 75;

    public const INVOICE_TITLE_Y = 100;

    public const FOLD_MARK_X = 5;

    public const FOLD_MARK_LENGTH = 3;

    public const FOLD_MARK_TOP_Y = 105;

    public const PUNCH_MARK_Y = 148.5;

    public const FOLD_MARK_BOTTOM_Y = 210;

    public const TOTALS_LABEL_WIDTH = 155;

    // Font sizes
    public const FONT_ORG_NAME = 10;

    public const FONT_ORG_DETAIL = 8;

    public const FONT_CUSTOMER_NAME = 10;

    public const FONT_CUSTOMER_DETAIL = 9;

    public const FONT_INVOICE_TITLE = 16;

    public const FONT_INVOICE_META = 9;

    public const FONT_TABLE_HEADER = 8;

    public const FONT_TABLE_ROW = 8;

    public const FONT_TOTALS = 9;

    public const FONT_TOTALS_GRAND = 11;

    public const FONT_NOTES = 8;

    public const FONT_HEADER_FOOTER_TEXT = 8;

    // Colors (RGB) — Tachly brand: navy #0F172A, sky-blue #5BB8E8 accent.
    public const COLOR_GRAY = [100, 100, 100];

    public const COLOR_DARK_GRAY = [15, 23, 42]; // #0F172A, navy

    public const COLOR_BLACK = [0, 0, 0];

    public const COLOR_FILL = [241, 245, 249]; // light slate, matches web app's muted background

    public const COLOR_RULE = [203, 213, 225];

    public const COLOR_ACCENT = [15, 23, 42]; // #0F172A, navy — replaces Gäld's green

    public const COLOR_LIGHT = [91, 184, 232]; // #5BB8E8, sky-blue

    // Logo — bundled Tachly mark (see ../assets/tachly-logo.png), not an
    // organization-uploaded one (every Tachly-provisioned org uses the same brand).
    public const LOGO_X = 15;

    public const LOGO_Y = 15;

    public const LOGO_WIDTH = 18;

    public const LOGO_MAX_HEIGHT = 18;

    public const LOGO_GAP = 3;

    // Language mapping for QR bill
    public const QR_LANGUAGE_MAP = ['en' => 'en', 'de' => 'de', 'fr' => 'fr', 'it' => 'it'];
}
