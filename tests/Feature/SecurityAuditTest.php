<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Document;
use App\Models\Penelitian;
use App\Models\SupportTicket;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    protected User $dosen1;
    protected User $dosen2;
    protected User $adminPenelitian;
    protected User $adminFakultasTeknik;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dosen1 = User::create([
            'name' => 'Dosen Teknik 1',
            'email' => 'dosen1@test.ac.id',
            'password' => Hash::make('Secret123!'),
            'role' => 'dosen',
            'fakultas' => 'Fakultas Teknik',
            'program_studi' => 'Informatika',
        ]);

        $this->dosen2 = User::create([
            'name' => 'Dosen Ekonomi 2',
            'email' => 'dosen2@test.ac.id',
            'password' => Hash::make('Secret123!'),
            'role' => 'dosen',
            'fakultas' => 'Fakultas Ekonomi',
            'program_studi' => 'Manajemen',
        ]);

        $this->adminPenelitian = User::create([
            'name' => 'Admin LPPM',
            'email' => 'admin.penelitian@test.ac.id',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin penelitian',
        ]);

        $this->adminFakultasTeknik = User::create([
            'name' => 'Admin FT',
            'email' => 'admin.ft@test.ac.id',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin fakultas',
            'fakultas' => 'Fakultas Teknik',
        ]);
    }

    /**
     * Test 1: Valid login returns user data and Sanctum personal access token.
     */
    public function test_valid_login_returns_user_and_bearer_token(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'dosen1@test.ac.id',
            'password' => 'Secret123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'role'],
                'token'
            ]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertEquals($this->dosen1->id, $response->json('user.id'));
    }

    /**
     * Test 2: Invalid password returns 401 Unauthorized.
     */
    public function test_invalid_login_returns_401(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'dosen1@test.ac.id',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => 'Invalid credentials',
            ]);
    }

    /**
     * Test 3: Non-string / array credentials (CVE-2026-114509 regression test)
     * Request with array/non-string credentials must be rejected by validator with 422.
     */
    public function test_cve_2026_114509_non_string_credentials_rejected_with_422(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => ['injected' => 'array'],
            'password' => ['injected' => 'array'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    /**
     * Test 4: Private endpoints reject unauthenticated access with 401.
     */
    public function test_private_endpoints_reject_unauthenticated_requests(): void
    {
        // 4.1 Profile endpoint
        $this->getJson('/api/users/' . $this->dosen1->id)
            ->assertStatus(401)
            ->assertJson(['success' => false]);

        // 4.2 Documents list
        $this->getJson('/api/users/' . $this->dosen1->id . '/documents')
            ->assertStatus(401)
            ->assertJson(['success' => false]);

        // 4.3 Support tickets
        $this->getJson('/api/support-tickets')
            ->assertStatus(401)
            ->assertJson(['success' => false]);

        // 4.4 Admin documents
        $this->getJson('/api/admin/documents')
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    /**
     * Test 5: Role-based authorization: Regular dosen cannot access admin endpoints.
     */
    public function test_regular_dosen_cannot_access_admin_endpoints(): void
    {
        $token = $this->dosen1->createToken('test')->plainTextToken;

        // Attempting to access admin documents
        $this->withToken($token)
            ->getJson('/api/admin/documents')
            ->assertStatus(403);

        // Attempting to access admin cms users
        $this->withToken($token)
            ->getJson('/api/admin/cms/users')
            ->assertStatus(403);

        // Attempting to access admin support tickets
        $this->withToken($token)
            ->getJson('/api/admin/support-tickets')
            ->assertStatus(403);
    }

    /**
     * Test 6: Privilege escalation: Non-admin or regular dosen cannot assign roles.
     */
    public function test_privilege_escalation_assign_role_prevented(): void
    {
        $dosenToken = $this->dosen1->createToken('test-dosen')->plainTextToken;

        // Dosen 1 attempts to promote themselves to admin penelitian
        $this->withToken($dosenToken)
            ->postJson('/api/admin/cms/users/' . $this->dosen1->id . '/assign-role', [
                'role' => 'admin penelitian',
            ])
            ->assertStatus(403);

        // Verify role remained unchanged in database
        $this->assertEquals('dosen', $this->dosen1->fresh()->role);

        // Admin Penelitian CAN assign roles
        $adminToken = $this->adminPenelitian->createToken('admin-test')->plainTextToken;
        $response = $this->withToken($adminToken)
            ->postJson('/api/admin/cms/users/' . $this->dosen2->id . '/assign-role', [
                'role' => 'reviewer',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('reviewer', $this->dosen2->fresh()->role);
    }

    /**
     * Test 7: Faculty boundary enforcement: Admin Fakultas cannot verify documents outside their faculty.
     */
    public function test_admin_fakultas_cannot_verify_documents_from_other_faculty(): void
    {
        // Document belongs to Dosen 2 (Fakultas Ekonomi)
        $docEkonomi = Document::create([
            'user_id' => $this->dosen2->id,
            'title' => 'Riset Ekonomi 2026',
            'category' => 'Jurnal Internasional',
            'file_url' => 'docs/test.pdf',
            'status' => 'Pending',
        ]);

        $adminFtToken = $this->adminFakultasTeknik->createToken('ft-test')->plainTextToken;

        // Admin FT attempts to verify Dosen Ekonomi's document
        $response = $this->withToken($adminFtToken)
            ->postJson('/api/admin/documents/' . $docEkonomi->id . '/verify', [
                'status' => 'Approved',
                'points' => 10,
            ]);

        $response->assertStatus(403);
        $this->assertEquals('Pending', $docEkonomi->fresh()->status);
    }

    /**
     * Test 8: Self-approval prevention: Regular dosen cannot submit documents or research with 'Approved' status.
     */
    public function test_regular_dosen_cannot_self_approve_data(): void
    {
        $token = $this->dosen1->createToken('test')->plainTextToken;

        // Attempting to post a document pre-approved
        $response = $this->withToken($token)
            ->postJson('/api/documents', [
                'title' => 'Makalah Hebat',
                'category' => 'Jurnal Internasional',
                'published_at' => '2026-05-01',
                'doc_type' => 'kpi',
                'status' => 'Approved',
                'points' => 100,
            ]);

        $response->assertStatus(200);
        $doc = Document::find($response->json('docId'));
        $this->assertNotNull($doc);
        $this->assertEquals('Pending', $doc->status);
        $this->assertEquals(0, $doc->awarded_points);

        // Attempting to post research pre-approved
        $penelitianResp = $this->withToken($token)
            ->postJson('/api/penelitian', [
                'judul_penelitian' => 'Penelitian Mandiri',
                'program' => 'hibah internal',
                'fokus' => 'Teknologi Informasi',
                'skema' => 'Dasar',
                'tahun' => '2026-01-01',
                'status' => 'Approved',
                'dana_disetujui' => 50000000,
            ]);

        $penelitianResp->assertStatus(200);
        $this->assertEquals('Pending', $penelitianResp->json('penelitian.status'));
    }

    /**
     * Test 9: Cross-user data isolation (IDOR/BOLA): Dosen 1 cannot modify or delete Dosen 2's documents.
     */
    public function test_cross_user_idor_protection_for_documents(): void
    {
        $docDosen2 = Document::create([
            'user_id' => $this->dosen2->id,
            'title' => 'Dokumen Dosen 2',
            'category' => 'Jurnal Internasional',
            'file_url' => 'docs/test2.pdf',
            'status' => 'Pending',
        ]);

        $token1 = $this->dosen1->createToken('test1')->plainTextToken;

        // Dosen 1 attempts to update Dosen 2's document
        $this->withToken($token1)
            ->putJson('/api/documents/' . $docDosen2->id, [
                'title' => 'Hacked Title',
            ])
            ->assertStatus(403);

        $this->assertEquals('Dokumen Dosen 2', $docDosen2->fresh()->title);

        // Dosen 1 attempts to delete Dosen 2's document
        $this->withToken($token1)
            ->deleteJson('/api/documents/' . $docDosen2->id)
            ->assertStatus(403);

        $this->assertDatabaseHas('documents', ['id' => $docDosen2->id]);
    }

    /**
     * Test 10: Support tickets: User cannot forge sender role or view other user tickets.
     */
    public function test_support_ticket_isolation_and_spoofing_prevention(): void
    {
        $ticketDosen2 = SupportTicket::create([
            'user_id' => $this->dosen2->id,
            'subject' => 'Tiket Dosen 2',
            'message' => 'Pesan privat dosen 2',
            'status' => 'menunggu',
            'messages' => [],
        ]);

        $token1 = $this->dosen1->createToken('test1')->plainTextToken;

        // Dosen 1 attempts to view Dosen 2's ticket
        $this->withToken($token1)
            ->getJson('/api/support-tickets/' . $ticketDosen2->id)
            ->assertStatus(403);

        // Dosen 1 attempts to reply to Dosen 2's ticket claiming to be 'admin'
        $this->withToken($token1)
            ->postJson('/api/support-tickets/' . $ticketDosen2->id . '/messages', [
                'sender' => 'admin',
                'message' => 'Palsu admin',
            ])
            ->assertStatus(403);
    }

    /**
     * Test 11: Logout invalidates token and session.
     */
    public function test_logout_invalidates_token(): void
    {
        $token = $this->dosen1->createToken('test-logout')->plainTextToken;

        // Valid call with active token
        $this->withToken($token)
            ->getJson('/api/users/' . $this->dosen1->id)
            ->assertStatus(200);

        // Logout revokes token
        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertStatus(200);

        // Subsequent call with revoked token must return 401
        $this->withToken($token)
            ->getJson('/api/users/' . $this->dosen1->id)
            ->assertStatus(401);
    }
}
