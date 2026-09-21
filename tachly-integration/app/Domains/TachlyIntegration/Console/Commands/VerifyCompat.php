<?php

namespace App\Domains\TachlyIntegration\Console\Commands;

use App\Domains\Accounting\Constants\AccountCode;
use App\Domains\Accounting\Enums\AccountType;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Services\ChartTemplateService;
use App\Domains\Api\Enums\TokenType;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Organizations\DTOs\CreateOrganizationData;
use App\Domains\Organizations\Models\Organization;
use App\Domains\Organizations\Services\OrganizationService;
use App\Domains\Organizations\Services\OrganizationSetupService;
use App\Domains\Users\DTOs\CreateUserData;
use App\Domains\Users\Models\User;
use App\Domains\Users\Services\UserService;
use App\Http\Middleware\Api\TokenPermissionMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Run this once after every GAELD_VERSION bump, before the new image reaches
 * gaeld.tachly.app. Asserts every upstream class/method/column this package
 * depends on still exists with the expected shape — a version bump can
 * rename or remove any of these without PHP failing until runtime, since
 * this package never touches upstream source and so never fails to build.
 *
 * Not a substitute for the real M2 gate (provisioning a real second org end
 * to end) — this only catches "did the shape change", not "does it still
 * behave correctly".
 */
class VerifyCompat extends Command
{
    protected $signature = 'tachly:verify-compat';

    protected $description = 'Check that TachlyIntegration\'s upstream dependencies still exist after a GAELD_VERSION bump';

    /** @var string[] */
    private array $failures = [];

    public function handle(): int
    {
        $this->checkClassAndMethods(Organization::class, []);
        $this->checkClassAndMethods(Account::class, []);
        $this->checkClassAndMethods(BankAccount::class, []);
        $this->checkClassAndMethods(User::class, ['createToken']);
        $this->checkClassAndMethods(OrganizationService::class, ['create']);
        $this->checkClassAndMethods(OrganizationSetupService::class, ['seedChartOfAccounts', 'ensureSystemAccounts']);
        $this->checkClassAndMethods(UserService::class, ['create']);
        $this->checkClassAndMethods(CreateOrganizationData::class, []);
        $this->checkClassAndMethods(CreateUserData::class, []);
        $this->checkClassAndMethods(TokenPermissionMap::class, ['normalize']);

        $this->checkEnumCase(TokenType::class, 'Organization');
        $this->checkEnumCases(AccountType::class, ['Asset', 'Liability', 'Equity', 'Revenue', 'Expense']);

        $this->checkConstant(AccountCode::class, 'BANK_CASH', '1020');

        $this->checkChartTemplate();
        $this->checkRegisteredProviders();
        $this->checkColumns('organizations', ['id', 'tachly_club_id', 'name', 'locale']);
        $this->checkColumns('accounts', ['organization_id', 'code', 'name', 'type', 'is_active']);
        $this->checkColumns('bank_accounts', ['organization_id', 'account_id', 'iban', 'qr_iban', 'uuid']);

        if ($this->failures !== []) {
            $this->error('tachly:verify-compat FAILED:');
            foreach ($this->failures as $failure) {
                $this->line("  - {$failure}");
            }

            return self::FAILURE;
        }

        $this->info('tachly:verify-compat OK — all upstream dependencies present.');

        return self::SUCCESS;
    }

    /** @param string[] $methods */
    private function checkClassAndMethods(string $class, array $methods): void
    {
        if (! class_exists($class)) {
            $this->failures[] = "class missing: {$class}";

            return;
        }

        foreach ($methods as $method) {
            if (! method_exists($class, $method)) {
                $this->failures[] = "method missing: {$class}::{$method}()";
            }
        }
    }

    private function checkEnumCase(string $enumClass, string $caseName): void
    {
        if (! enum_exists($enumClass)) {
            $this->failures[] = "enum missing: {$enumClass}";

            return;
        }

        $caseNames = array_column($enumClass::cases(), 'name');
        if (! in_array($caseName, $caseNames, true)) {
            $this->failures[] = "enum case missing: {$enumClass}::{$caseName}";
        }
    }

    /** @param string[] $caseNames */
    private function checkEnumCases(string $enumClass, array $caseNames): void
    {
        foreach ($caseNames as $caseName) {
            $this->checkEnumCase($enumClass, $caseName);
        }
    }

    private function checkConstant(string $class, string $constant, mixed $expectedValue): void
    {
        if (! class_exists($class) || ! defined("{$class}::{$constant}")) {
            $this->failures[] = "constant missing: {$class}::{$constant}";

            return;
        }

        $actual = constant("{$class}::{$constant}");
        if ($actual !== $expectedValue) {
            $this->failures[] = "constant changed: {$class}::{$constant} is '{$actual}', expected '{$expectedValue}'";
        }
    }

    /**
     * bootstrap/providers.php is a full replacement of upstream's file (see
     * the Dockerfile/README), not a patch — so a version bump that adds a
     * new upstream provider would silently drop it unless this file is
     * updated to match. This only catches "one of the providers we already
     * know about stopped loading"; catching a brand-new upstream provider
     * we don't know exists requires diffing bootstrap/providers.php against
     * upstream by hand before bumping GAELD_VERSION — do that too.
     */
    private function checkRegisteredProviders(): void
    {
        $loaded = array_keys(app()->getLoadedProviders());

        $expected = [
            \App\Domains\Migration\Providers\MigrationServiceProvider::class,
            \App\Providers\AppServiceProvider::class,
            \App\Providers\FeatureFlagServiceProvider::class,
            \App\Providers\HorizonServiceProvider::class,
            \App\Providers\HttpsServiceProvider::class,
            \App\Providers\PluginServiceProvider::class,
            \App\Domains\TachlyIntegration\TachlyIntegrationServiceProvider::class,
        ];

        foreach ($expected as $provider) {
            if (! in_array($provider, $loaded, true)) {
                $this->failures[] = "provider not loaded: {$provider} (check bootstrap/providers.php)";
            }
        }
    }

    private function checkChartTemplate(): void
    {
        try {
            $keys = app(ChartTemplateService::class)->validKeys();
            $expected = (string) config('tachly-integration.chart_template', 'swiss_association');
            if (! in_array($expected, $keys, true)) {
                $this->failures[] = "chart template '{$expected}' no longer registered in ChartTemplateService (available: ".implode(', ', $keys).')';
            }
        } catch (Throwable $e) {
            $this->failures[] = 'ChartTemplateService threw: '.$e->getMessage();
        }
    }

    /** @param string[] $columns */
    private function checkColumns(string $table, array $columns): void
    {
        try {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $this->failures[] = "column missing: {$table}.{$column}";
                }
            }
        } catch (Throwable $e) {
            $this->failures[] = "could not inspect table '{$table}': ".$e->getMessage();
        }
    }
}
