<?php

namespace Tests\Feature\Auth;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_employee_can_authenticate_with_employee_id(): void
    {
        $response = $this->post('/login', [
            'employee_id' => 'hr-0001',
            'password' => 'ChangeMe123!',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
        $this->assertNotNull(auth()->user()->last_login_at);
    }

    public function test_employee_cannot_authenticate_with_invalid_password(): void
    {
        $response = $this->from('/login')->post('/login', [
            'employee_id' => 'HR-0001',
            'password' => 'incorrect-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('employee_id');
    }

    public function test_inactive_employee_cannot_authenticate(): void
    {
        Employee::query()
            ->where('employee_number', 'HR-0001')
            ->update(['employment_status' => 'inactive']);

        $this->post('/login', [
            'employee_id' => 'HR-0001',
            'password' => 'ChangeMe123!',
        ]);

        $this->assertGuest();
    }

    public function test_authenticated_employee_can_log_out(): void
    {
        $this->post('/login', [
            'employee_id' => 'HR-0001',
            'password' => 'ChangeMe123!',
        ]);

        $response = $this->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/login');
    }
}
