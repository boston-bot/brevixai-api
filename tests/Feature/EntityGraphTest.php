<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Company;
use App\Models\Transaction;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EntityGraphTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('alerts');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('uploads');
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

        Schema::create('uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('uploaded_by');
            $table->text('filename');
            $table->text('status')->default('completed');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->uuid('company_id');
            $table->text('vendor_customer')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->text('type')->nullable();
            $table->text('category')->nullable();
            $table->date('date')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->text('rule_key');
            $table->text('severity');
            $table->text('title');
            $table->text('detail')->nullable();
            $table->text('status')->default('open');
            $table->json('evidence')->nullable();
            $table->integer('priority_score')->nullable();
            $table->uuid('group_id')->nullable();
            $table->uuid('alert_recommendation_id')->nullable();
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_entity_graph_returns_nodes_and_edges(): void
    {
        [$company, $user, $upload] = $this->createCompanyUserUpload();
        $this->createTransaction($company->id, $upload->id, 'Acme Supplies');
        $this->createTransaction($company->id, $upload->id, 'Beta Corp');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/entity-graph')
            ->assertOk()
            ->assertJsonStructure([
                'nodes',
                'edges',
                'patterns',
                'summary' => [
                    'risk_score',
                    'risk_level',
                    'node_count',
                    'edge_count',
                    'totalNodes',
                    'totalEdges',
                    'totalPatterns',
                    'nodesByType',
                    'criticalPatterns',
                    'warningPatterns',
                ],
            ]);

        $nodes = $response->json('nodes');
        $types = array_column($nodes, 'type');

        $this->assertContains('company', $types);
        $this->assertContains('employee', $types);
        $this->assertContains('vendor', $types);
        $this->assertSame(count($nodes), $response->json('summary.totalNodes'));
        $this->assertSame(1, $response->json('summary.nodesByType.company'));
        $this->assertArrayHasKey('transactionCount', $nodes[0]);
        $this->assertArrayHasKey('totalVolume', $nodes[0]);
    }

    public function test_entity_graph_exposes_relationship_patterns_for_frontend_contract(): void
    {
        [$company, $user, $upload] = $this->createCompanyUserUpload();
        $this->createTransaction($company->id, $upload->id, $user->first_name.' '.$user->last_name.' Services');

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/entity-graph')
            ->assertOk()
            ->assertJsonPath('patterns.0.type', 'employee_vendor_overlap')
            ->assertJsonPath('patterns.0.severity', 'warning')
            ->assertJsonPath('patterns.0.title', 'Employee/Vendor Overlap')
            ->assertJsonPath('summary.totalPatterns', 1)
            ->assertJsonPath('summary.warningPatterns', 1);

        $pattern = $response->json('patterns.0');
        $this->assertContains($user->id, $pattern['involvedEntities']);
    }

    public function test_entity_graph_is_company_scoped(): void
    {
        [$companyA, $userA, $uploadA] = $this->createCompanyUserUpload('Company A');
        [$companyB, $userB, $uploadB] = $this->createCompanyUserUpload('Company B');
        $this->createTransaction($companyA->id, $uploadA->id, 'Company A Vendor');
        $this->createTransaction($companyB->id, $uploadB->id, 'Company B Vendor');

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/entity-graph')->assertOk();
        $labels = array_column($response->json('nodes'), 'label');

        $this->assertContains('Company A Vendor', $labels);
        $this->assertNotContains('Company B Vendor', $labels);
    }

    public function test_entity_graph_returns_403_without_company(): void
    {
        $user = new User([
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->company_id = null;
        $user->save();

        Sanctum::actingAs($user);

        $this->getJson('/api/entity-graph')->assertForbidden();
    }

    public function test_entity_graph_node_returns_user_detail(): void
    {
        [$company, $user, $upload] = $this->createCompanyUserUpload();
        $this->createTransaction($company->id, $upload->id, $user->first_name . ' ' . $user->last_name . ' Services');

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/entity-graph/node/{$user->id}")
            ->assertOk()
            ->assertJsonStructure([
                'node' => ['id', 'type', 'label', 'metadata', 'transactionCount', 'totalVolume'],
                'connectedEdges',
                'connectedNodes',
                'patterns',
                'transactions',
            ]);

        $this->assertSame('employee', $response->json('node.type'));
        $this->assertSame($user->id, $response->json('node.id'));
    }

    public function test_entity_graph_node_returns_vendor_detail(): void
    {
        [$company, $user, $upload] = $this->createCompanyUserUpload();
        $vendorName = 'Unique Vendor Ltd';
        $this->createTransaction($company->id, $upload->id, $vendorName);

        Sanctum::actingAs($user);

        // Get the graph to find the vendor node ID
        $graphResponse = $this->getJson('/api/entity-graph')->assertOk();
        $vendorNode = collect($graphResponse->json('nodes'))
            ->firstWhere('label', $vendorName);

        $this->assertNotNull($vendorNode);

        $nodeResponse = $this->getJson("/api/entity-graph/node/{$vendorNode['id']}")
            ->assertOk()
            ->assertJsonPath('node.type', 'vendor')
            ->assertJsonPath('node.label', $vendorName);

        $this->assertArrayHasKey('transactions', $nodeResponse->json());
    }

    public function test_entity_graph_node_returns_404_for_unknown_id(): void
    {
        [, $user] = $this->createCompanyUserUpload();

        Sanctum::actingAs($user);

        $this->getJson('/api/entity-graph/node/' . Str::uuid())
            ->assertNotFound();
    }

    public function test_entity_graph_node_does_not_cross_company_boundary(): void
    {
        [$companyA, $userA] = $this->createCompanyUserUpload('A');
        [$companyB, $userB] = $this->createCompanyUserUpload('B');

        // User from company B trying to access user node from company A
        Sanctum::actingAs($userB);

        $this->getJson("/api/entity-graph/node/{$userA->id}")
            ->assertNotFound();
    }

    /** @return array{0: Company, 1: User, 2: Upload} */
    private function createCompanyUserUpload(string $suffix = ''): array
    {
        $company = new Company(['name' => 'Test Co ' . $suffix]);
        $company->id = (string) Str::uuid();
        $company->save();

        $user = new User([
            'company_id' => $company->id,
            'email' => Str::uuid() . '@example.com',
            'password_hash' => Hash::make('password'),
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'role' => 'owner',
        ]);
        $user->id = (string) Str::uuid();
        $user->save();

        $upload = new Upload([
            'company_id' => $company->id,
            'uploaded_by' => $user->id,
            'filename' => 'ledger.csv',
            'status' => 'completed',
        ]);
        $upload->id = (string) Str::uuid();
        $upload->save();

        return [$company, $user, $upload];
    }

    private function createTransaction(string $companyId, string $uploadId, string $vendorName): void
    {
        $txn = new Transaction([
            'upload_id' => $uploadId,
            'company_id' => $companyId,
            'vendor_customer' => $vendorName,
            'amount' => 1000.00,
            'type' => 'expense',
        ]);
        $txn->id = (string) Str::uuid();
        $txn->save();
    }
}
