<?php
use App\Domains\Organizations\Models\Organization;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Accounting\Models\Account;

$org = Organization::first();
$org->forceFill([
    'legal_name'  => 'Fluggruppe GlaStar Flyers',
    'address'     => 'Flugplatzstrasse 1',
    'postal_code' => '8589',
    'city'        => 'Sitterdorf',
    'country'     => 'CH',
    'vat_number'  => null,
])->save();

$acc = Account::withoutGlobalScopes()->where('organization_id',$org->id)->where('code','1010')->first();

$ba = BankAccount::withoutGlobalScopes()->where('organization_id',$org->id)->first();
$data = [
    'organization_id' => $org->id,
    'name'            => 'PostFinance QR',
    'iban'            => 'CH4431999123000889012',      // Sprain test QR-IBAN (QR-IID 31999)
    'qr_iban'         => 'CH4431999123000889012',
    'currency'        => 'CHF',
    'is_active'       => true,
    'is_default_for_invoicing' => true,
];
if (isset($acc)) $data['account_id'] = $acc->id;
if ($ba) { $ba->forceFill($data)->save(); echo "updated bank account {$ba->id}\n"; }
else { $ba = BankAccount::create($data); echo "created bank account {$ba->id}\n"; }

echo "default invoicing BA: ".optional($org->fresh()->defaultInvoicingBankAccount())->iban."\n";
