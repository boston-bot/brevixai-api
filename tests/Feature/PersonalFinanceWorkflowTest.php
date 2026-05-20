<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PersonalFinanceBudgetProfile;
use App\Models\PersonalFinanceRule;
use App\Models\PersonalFinanceStatementImport;
use App\Models\PersonalFinanceTransaction;
use App\Models\User;
use App\Services\PersonalFinance\PersonalFinanceAnalyticsService;
use App\Services\PersonalFinance\PersonalFinanceImportService;
use App\Services\PersonalFinance\PersonalFinancePdfExtractionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonalFinanceWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
        config()->set('personal_finance.enabled', true);
        config()->set('personal_finance.route_environments', ['testing']);
        config()->set('personal_finance.export_path', sys_get_temp_dir().'/brevix-pf-exports');
    }

    public function test_import_is_idempotent_and_reclassification_updates_existing_transactions(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $path = $this->makeStatementFile('20260131-statements-0059-.pdf');
        $this->app->instance(PersonalFinancePdfExtractionService::class, new FakePersonalFinancePdfExtractionService([$path], $this->statementText()));

        $service = $this->app->make(PersonalFinanceImportService::class);
        $firstRun = $service->run($company->id, $user->id);

        $this->assertSame(1, $firstRun['imported']);
        $this->assertSame(4, $firstRun['transactionCount']);
        $this->assertSame(4, PersonalFinanceTransaction::where('company_id', $company->id)->count());

        $secondRun = $service->run($company->id, $user->id);
        $this->assertSame(1, $secondRun['skipped']);
        $this->assertSame(4, PersonalFinanceTransaction::where('company_id', $company->id)->count());

        PersonalFinanceRule::create([
            'company_id' => $company->id,
            'rule_type' => PersonalFinanceRule::TYPE_PERSON,
            'name' => 'Starbucks person A',
            'match_field' => 'description',
            'pattern' => 'STARBUCKS',
            'target_value' => PersonalFinanceTransaction::PERSON_A,
            'priority' => 1,
            'is_active' => true,
            'metadata' => [],
        ]);

        $reclassify = $service->run($company->id, $user->id, reclassify: true);

        $this->assertSame(4, $reclassify['reclassified']);
        $this->assertSame(
            PersonalFinanceTransaction::PERSON_A,
            PersonalFinanceTransaction::where('description', 'like', '%STARBUCKS%')->first()?->person_scope
        );
    }

    public function test_summary_and_catch_up_math_prioritize_cash_flow_gap(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $statement = $this->createStatement($company->id, $user->id);
        $this->createTransaction($company->id, $statement->id, '2026-01-03', 4000, 'inflow', 'income', 'unknown', 'ACME PAYROLL');
        $this->createTransaction($company->id, $statement->id, '2026-01-05', -3200, 'outflow', 'credit_card_payment', 'unknown', 'CHASE CREDIT CRD AUTOPAY');
        $this->createTransaction($company->id, $statement->id, '2026-01-08', -1800, 'outflow', 'groceries', 'shared', 'KROGER');
        $this->createTransaction($company->id, $statement->id, '2026-02-03', 4000, 'inflow', 'income', 'unknown', 'ACME PAYROLL');
        $this->createTransaction($company->id, $statement->id, '2026-02-05', -5200, 'outflow', 'credit_card_payment', 'unknown', 'CHASE CREDIT CRD AUTOPAY');

        $analytics = $this->app->make(PersonalFinanceAnalyticsService::class);
        $summary = $analytics->summary($company->id);

        $this->assertSame(8000.0, $summary['totals']['income']);
        $this->assertSame(10200.0, $summary['totals']['outflow']);
        $this->assertSame(1100.0, $summary['totals']['averageMonthlyDeficit']);
        $this->assertContains('Credit card payments are a major outflow but are opaque in checking-account statements. Import card statements later for better category detail.', $summary['warnings']);

        $scenario = $analytics->catchUpScenario($company->id, 2400, 6);

        $this->assertSame(1500.0, $scenario['requiredMonthlySwing']);
        $this->assertNotEmpty($scenario['suggestedCuts']);
    }

    public function test_api_is_hidden_when_local_feature_flag_is_disabled(): void
    {
        [$company, $user] = $this->createCompanyUser();
        Sanctum::actingAs($user);
        config()->set('personal_finance.enabled', false);

        $this->getJson('/api/local/personal-finance/status')->assertNotFound();
    }

    public function test_api_status_transactions_budget_and_export_endpoints(): void
    {
        [$company, $user] = $this->createCompanyUser();
        $statement = $this->createStatement($company->id, $user->id);
        $transaction = $this->createTransaction($company->id, $statement->id, '2026-01-05', -42.50, 'outflow', 'dining', 'unknown', 'STARBUCKS');
        Sanctum::actingAs($user);

        $this->getJson('/api/local/personal-finance/status')
            ->assertOk()
            ->assertJsonPath('importedStatementCount', 1)
            ->assertJsonPath('transactionCount', 1);

        $this->patchJson("/api/local/personal-finance/transactions/{$transaction->id}", [
            'personScope' => PersonalFinanceTransaction::PERSON_A,
        ])
            ->assertOk()
            ->assertJsonPath('personScope', PersonalFinanceTransaction::PERSON_A);

        $this->putJson('/api/local/personal-finance/budgets', [
            'personALabel' => 'Person One',
            'personAMonthlyAllowance' => 300,
        ])
            ->assertOk()
            ->assertJsonPath('budgetProfile.personALabel', 'Person One')
            ->assertJsonPath('budgetProfile.personAMonthlyAllowance', 300);

        $this->postJson('/api/local/personal-finance/exports', [
            'format' => 'pdf',
            'includeTransactions' => false,
        ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->postJson('/api/local/personal-finance/exports', [
            'format' => 'docx',
            'includeTransactions' => false,
        ])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('personal_finance_exports');
        Schema::dropIfExists('personal_finance_analysis_runs');
        Schema::dropIfExists('personal_finance_budget_profiles');
        Schema::dropIfExists('personal_finance_rules');
        Schema::dropIfExists('personal_finance_transactions');
        Schema::dropIfExists('personal_finance_statement_imports');
        Schema::dropIfExists('users');
        Schema::dropIfExists('companies');

        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->nullable();
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('role')->default('owner');
            $table->boolean('is_verified')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_finance_statement_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('imported_by_user_id')->nullable();
            $table->text('source_filename');
            $table->text('source_path')->nullable();
            $table->string('sha256', 64);
            $table->date('statement_date')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('account_last4', 8)->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('transaction_count')->default(0);
            $table->json('warnings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_finance_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('statement_import_id');
            $table->date('posted_date');
            $table->text('description');
            $table->text('normalized_merchant')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('direction', 16);
            $table->string('category', 80)->default('uncategorized');
            $table->string('person_scope', 32)->default('unknown');
            $table->string('recurring_key', 160)->nullable();
            $table->string('source_section', 120)->nullable();
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_finance_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('rule_type', 32);
            $table->string('name', 160);
            $table->string('match_field', 40)->default('description');
            $table->text('pattern');
            $table->string('target_value', 160)->nullable();
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_finance_budget_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name', 120)->default('Default');
            $table->string('person_a_label', 120)->default('Person A');
            $table->string('person_b_label', 120)->default('Person B');
            $table->decimal('person_a_monthly_allowance', 14, 2)->default(0);
            $table->decimal('person_b_monthly_allowance', 14, 2)->default(0);
            $table->decimal('shared_monthly_cap', 14, 2)->nullable();
            $table->decimal('opaque_card_payment_cap', 14, 2)->nullable();
            $table->decimal('catch_up_target_amount', 14, 2)->nullable();
            $table->json('category_caps')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_finance_analysis_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->json('summary');
            $table->json('warnings')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
        });

        Schema::create('personal_finance_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('analysis_run_id')->nullable();
            $table->uuid('generated_by_user_id')->nullable();
            $table->string('format', 16);
            $table->text('filename');
            $table->string('report_hash', 64);
            $table->json('filters')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
        });
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(): array
    {
        $company = new Company(['name' => 'Personal Finance Test']);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid().'@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        PersonalFinanceBudgetProfile::create([
            'company_id' => $company->id,
            'name' => 'Default',
            'person_a_label' => 'Person A',
            'person_b_label' => 'Person B',
            'person_a_monthly_allowance' => 0,
            'person_b_monthly_allowance' => 0,
            'category_caps' => [],
            'metadata' => [],
        ]);

        return [$company, $user];
    }

    private function createStatement(string $companyId, string $userId): PersonalFinanceStatementImport
    {
        return PersonalFinanceStatementImport::create([
            'company_id' => $companyId,
            'imported_by_user_id' => $userId,
            'source_filename' => '20260131-statements-0059-.pdf',
            'source_path' => '/tmp/20260131-statements-0059-.pdf',
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'statement_date' => '2026-01-31',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'account_last4' => '0059',
            'status' => 'imported',
            'transaction_count' => 0,
            'warnings' => [],
            'metadata' => [],
        ]);
    }

    private function createTransaction(
        string $companyId,
        string $statementId,
        string $date,
        float $amount,
        string $direction,
        string $category,
        string $personScope,
        string $description,
    ): PersonalFinanceTransaction {
        return PersonalFinanceTransaction::create([
            'company_id' => $companyId,
            'statement_import_id' => $statementId,
            'posted_date' => $date,
            'description' => $description,
            'normalized_merchant' => $description,
            'amount' => $amount,
            'direction' => $direction,
            'category' => $category,
            'person_scope' => $personScope,
            'recurring_key' => $direction === 'outflow' ? strtolower($category.'|'.$description) : null,
            'source_section' => 'fixture',
            'confidence' => 90,
            'raw_payload' => [],
        ]);
    }

    private function makeStatementFile(string $filename): string
    {
        $directory = sys_get_temp_dir().'/brevix-pf-fixtures';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $path = $directory.'/'.$filename;
        file_put_contents($path, 'fake pdf bytes '.Str::uuid());

        return $path;
    }

    private function statementText(): string
    {
        return <<<'TEXT'
CHASE TOTAL CHECKING
January 1, 2026 through January 31, 2026
Account Number: 0000000059

DEPOSITS AND ADDITIONS
01/03 ACME PAYROLL DIRECT DEP 5,000.00 5,100.00

ATM & DEBIT CARD WITHDRAWALS
01/04 POS DEBIT STARBUCKS STORE
SEATTLE WA 7.50 5,092.50

ELECTRONIC WITHDRAWALS
01/05 CHASE CREDIT CRD AUTOPAY 1,200.00 3,892.50

FEES
01/31 MONTHLY SERVICE FEE 12.00 3,880.50
TEXT;
    }
}

class FakePersonalFinancePdfExtractionService extends PersonalFinancePdfExtractionService
{
    /**
     * @param  array<int, string>  $files
     */
    public function __construct(private readonly array $files, private readonly string $text) {}

    public function listStatementFiles(): array
    {
        return $this->files;
    }

    public function extractText(string $path): string
    {
        return $this->text;
    }
}
