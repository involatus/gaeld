<?php
// Run:  docker compose exec -T web php artisan tinker --execute="require '/path'"  OR paste via tinker
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Organizations\Models\Organization;

$org = Organization::first();
$oid = $org->id;

function upsert($oid, $code, $name, AccountType $type, $desc = null) {
    $a = Account::withoutGlobalScopes()->where('organization_id', $oid)->where('code', $code)->first();
    if ($a) {
        $a->name = $name;
        if ($desc) $a->description = $desc;
        $a->is_active = true;
        $a->save();
        echo "renamed  $code -> $name\n";
    } else {
        Account::create([
            'organization_id' => $oid,
            'code' => $code,
            'name' => $name,
            'type' => $type->value,
            'is_active' => true,
            'is_system' => false,
            'description' => $desc,
        ]);
        echo "created  $code -> $name ({$type->value})\n";
    }
}

// cashctrl -> Gäld chart-of-accounts mapping for GlaStar Flyers
upsert($oid, '1010', 'PostFinance CHF (Post)', AccountType::Asset, 'cashctrl 1010 Post');
upsert($oid, '1100', 'Debitoren (Forderungen Mitglieder)', AccountType::Asset, 'cashctrl 1050-1053 consolidated');
upsert($oid, '1300', 'Bezahlter Aufwand des Folgejahres', AccountType::Asset, 'cashctrl 1300');
upsert($oid, '2000', 'Kreditoren', AccountType::Liability, 'cashctrl 2000');
upsert($oid, '2330', 'Kurzfristige Rückstellungen Unterhalt', AccountType::Liability, 'cashctrl 2330');
upsert($oid, '2960', 'Gewinnvortrag oder Verlustvortrag', AccountType::Equity, 'cashctrl 2960');
upsert($oid, '3000', 'Einnahmen aus Flugstunden', AccountType::Revenue, 'cashctrl 3000');
upsert($oid, '3010', 'Einnahmen aus Flugstunden Extern', AccountType::Revenue, 'cashctrl 3010');
upsert($oid, '3100', 'Einnahmen aus Fixbeiträgen', AccountType::Revenue, 'cashctrl 3100');
upsert($oid, '6110', 'Gebühren', AccountType::Expense, 'cashctrl 6110');
upsert($oid, '6120', 'Unterhalt', AccountType::Expense, 'cashctrl 6120');
upsert($oid, '6125', 'Upgrades', AccountType::Expense, 'cashctrl 6125');
upsert($oid, '6130', 'Hangar', AccountType::Expense, 'cashctrl 6130');
upsert($oid, '6140', 'Treibstoff', AccountType::Expense, 'cashctrl 6140');
upsert($oid, '6150', 'Versicherung', AccountType::Expense, 'cashctrl 6150');
upsert($oid, '6950', 'Kontoführung & Spesen PostFinance', AccountType::Expense, 'cashctrl 6160');

echo "accounts now: " . Account::withoutGlobalScopes()->where('organization_id', $oid)->count() . "\n";
