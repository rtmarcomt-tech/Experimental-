<?php

namespace Tests\Feature;

use App\Models\CreatorLedgerAccount;
use App\Models\CreatorLedgerEntry;
use App\Models\CreatorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_shows_operational_and_financial_overview(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'dashboard-admin@example.test',
            'password' => 'password-long-enough',
        ]);
        $admin->forceFill(['is_admin' => true])->save();

        $activeCreator = User::create([
            'name' => 'Active Creator',
            'email' => 'active-creator-admin-dashboard@example.test',
            'password' => 'password-long-enough',
            'account_status' => 'active',
        ]);
        CreatorProfile::create([
            'user_id' => $activeCreator->id,
            'public_name' => 'Approved Creator',
            'slug' => 'approved-creator-admin-dashboard',
            'status' => 'approved',
        ]);

        $suspendedUser = User::create([
            'name' => 'Suspended User',
            'email' => 'suspended-admin-dashboard@example.test',
            'password' => 'password-long-enough',
            'account_status' => 'suspended',
        ]);
        CreatorProfile::create([
            'user_id' => $suspendedUser->id,
            'public_name' => 'Rejected Creator',
            'slug' => 'rejected-creator-admin-dashboard',
            'status' => 'rejected',
        ]);

        $this->actingAs($admin)->get('/minha-area')
            ->assertOk()
            ->assertSee('Painel de administração')
            ->assertSee('Resumo financeiro')
            ->assertSee('Pedidos recentes')
            ->assertSee('Candidaturas de criadores')
            ->assertSee('Criadores aprovados')
            ->assertSee('Contas ativas')
            ->assertSee('Contas suspensas')
            ->assertSee('1', false);
    }

    public function test_creator_dashboard_shows_ledger_balances_without_exposing_them_as_paid_out(): void
    {
        $creator = User::create([
            'name' => 'Creator Dashboard',
            'email' => 'dashboard-creator@example.test',
            'password' => 'password-long-enough',
        ]);
        $profile = CreatorProfile::create([
            'user_id' => $creator->id,
            'public_name' => 'Creator Public',
            'slug' => 'creator-public',
            'status' => 'approved',
        ]);
        $pending = CreatorLedgerAccount::create([
            'creator_profile_id' => $profile->id,
            'currency' => 'EUR',
            'account_type' => 'pending',
        ]);
        CreatorLedgerEntry::create([
            'ledger_account_id' => $pending->id,
            'entry_type' => 'sale_credit_pending',
            'amount_minor' => 9000,
            'currency' => 'EUR',
            'idempotency_key' => 'dashboard-pending-credit',
            'effective_at' => now(),
        ]);
        $available = CreatorLedgerAccount::create([
            'creator_profile_id' => $profile->id,
            'currency' => 'EUR',
            'account_type' => 'available',
        ]);
        CreatorLedgerEntry::create([
            'ledger_account_id' => $available->id,
            'entry_type' => 'sale_credit_available',
            'amount_minor' => 3000,
            'currency' => 'EUR',
            'idempotency_key' => 'dashboard-available-credit',
            'effective_at' => now(),
        ]);

        $this->actingAs($creator)->get('/minha-area')
            ->assertOk()
            ->assertSee('Resumo financeiro')
            ->assertSee('90,00 €')
            ->assertSee('30,00 €')
            ->assertSee('Saldo disponível')
            ->assertSee('pode ser solicitado quando atinge o mínimo configurado')
            ->assertSee('existe um endereço PayPal válido');
    }
}
