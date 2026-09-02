<?php
use App\Domains\Organizations\Models\Organization;
use App\Domains\Reporting\Services\ReportingService;
use Illuminate\Support\Facades\Cache;

$org = Organization::first();
$svc = app(ReportingService::class);
Cache::flush();

$pnl = $svc->profitAndLoss($org->id, '2026-01-01', '2026-09-02');
$bs  = $svc->balanceSheet($org->id, '2026-09-02');

echo "==== ERFOLGSRECHNUNG 2026-01-01..2026-09-02 ====\n";
foreach ($pnl['revenue'] as $r) printf("  %-6s %-40s %12s\n", $r['code'], mb_substr($r['name'],0,40), $r['balance']);
printf("  %-6s %-40s %12s\n", '', 'TOTAL ERTRAG', $pnl['total_revenue']);
echo "\n";
foreach ($pnl['expenses'] as $r) printf("  %-6s %-40s %12s\n", $r['code'], mb_substr($r['name'],0,40), $r['balance']);
printf("  %-6s %-40s %12s\n", '', 'TOTAL AUFWAND', $pnl['total_expenses']);
printf("  %-6s %-40s %12s\n", '', 'GEWINN / VERLUST', $pnl['net_profit']);

echo "\n==== BILANZ per 2026-09-02 ====\n";
echo "-- Aktiven --\n";
foreach ($bs['assets']['accounts'] as $a) printf("  %-6s %-40s %12s\n", $a['code'], mb_substr($a['name'],0,40), $a['balance']);
printf("  %-6s %-40s %12s\n", '', 'TOTAL AKTIVEN', $bs['assets']['total']);
echo "-- Passiven (Fremdkapital) --\n";
foreach ($bs['liabilities']['accounts'] as $a) printf("  %-6s %-40s %12s\n", $a['code'], mb_substr($a['name'],0,40), $a['balance']);
printf("  %-6s %-40s %12s\n", '', 'TOTAL FREMDKAPITAL', $bs['liabilities']['total']);
echo "-- Eigenkapital --\n";
foreach ($bs['equity']['accounts'] as $a) printf("  %-6s %-40s %12s\n", $a['code'], mb_substr($a['name'],0,40), $a['balance']);
printf("  %-6s %-40s %12s\n", '', 'TOTAL EIGENKAPITAL', $bs['equity']['total']);
$pass = bcadd((string)$bs['liabilities']['total'], (string)$bs['equity']['total'], 2);
printf("  %-6s %-40s %12s\n", '', 'TOTAL PASSIVEN', $pass);
