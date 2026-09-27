<?php

return [
    /**
     * Shared secret Tachly's server presents as the `X-Tachly-Internal-Secret`
     * header on every call to `/internal/tachly/*`. Set as a Coolify env var
     * on the gaeld web/worker services, mirrored in Tachly's server env /
     * Vault — never committed, never returned in any API response.
     */
    'shared_secret' => env('TACHLY_INTERNAL_SHARED_SECRET'),

    /**
     * Chart-of-accounts template applied to every newly provisioned club.
     * Must be a valid key from ChartTemplateService (see
     * app/Domains/Accounting/ChartTemplates/) — 'swiss_association' fits a
     * Swiss VEREIN (flying club), which is what Tachly's clubs are.
     */
    'chart_template' => env('TACHLY_CHART_TEMPLATE', 'swiss_association'),

    /**
     * Accounts Tachly's own postings rely on, ensured on every provision (a re-provision
     * also renames the template's 3000 "Mitgliederbeiträge" — Tachly invoices book flights there).
     * Club-supplied `extra_accounts` are applied after these and win on a clash.
     */
    'default_accounts' => [
        ['code' => '3000', 'name' => 'Einnahmen aus Flugstunden', 'type' => 'revenue'],
        ['code' => '6140', 'name' => 'Treibstoff', 'type' => 'expense'],
    ],

    /**
     * Canonical Gäld permission strings granted to every Tachly-minted
     * org-scoped API token. Deliberately excludes anything destructive
     * (organization.delete, accounting.delete, *.delete) and payroll/
     * migration, which Tachly's integration never needs.
     */
    'token_abilities' => [
        'accounting.view', 'accounting.create', 'accounting.edit',
        'banking.view', 'banking.create', 'banking.import', 'banking.reconcile',
        'contacts.view', 'contacts.create', 'contacts.edit',
        'invoicing.view', 'invoicing.create', 'invoicing.edit',
        'invoicing.finalize', 'invoicing.record-payment',
        'reporting.view',
    ],
];
