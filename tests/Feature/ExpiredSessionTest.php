<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class ExpiredSessionTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    private const NOTICE = 'Tu sesión ha vencido o no está activa. Inicia sesión para continuar.';

    private function enforceCsrf(): void
    {
        // Ejecuta la protección real aun bajo PHPUnit, sin cambiar APP_ENV=testing.
        $this->app->bind(PreventRequestForgery::class, EnforcedSessionCsrf::class);
    }

    public function test_expired_navigation_clears_private_session_and_shows_notice_at_login(): void
    {
        $this->withSession(['private_marker' => 'discard', '_old_input' => ['telefono' => 'discard'], '_token' => 'old-token']);
        $oldId = $this->app['session']->getId();
        $this->get(route('agenda.index'))->assertRedirectToRoute('login')
            ->assertSessionHas('warning', self::NOTICE)->assertSessionMissing('private_marker')
            ->assertSessionMissing('_old_input')->assertSessionMissing('url.intended');
        $this->assertNotSame($oldId, $this->app['session']->getId());
        $this->assertNotSame('old-token', $this->app['session']->token());
        $this->get(route('login'))->assertOk()->assertSee(self::NOTICE);
        $this->assertGuest();
    }

    public function test_json_guest_receives_login_destination_and_notice_survives_redirect(): void
    {
        $this->withSession(['private_marker' => 'discard'])->getJson(route('agenda.index'))
            ->assertUnauthorized()->assertExactJson(['message' => self::NOTICE, 'redirect' => route('login')])
            ->assertSessionMissing('private_marker');
        $this->get(route('login'))->assertOk()->assertSee(self::NOTICE);
    }

    public function test_expired_post_with_stale_csrf_never_writes_or_flashes_submitted_data(): void
    {
        $this->seedVidaSalud();
        $this->enforceCsrf();
        $before = DB::table('paciente')->count();
        $this->withSession(['_token' => 'new-token'])->post(route('catalog.store', 'pacientes'), [
            '_token' => 'stale-token', 'nombres' => 'No guardar', 'telefono' => 'No guardar',
        ])->assertRedirectToRoute('login')->assertSessionHas('warning', self::NOTICE)
            ->assertSessionMissing('_old_input')->assertSessionMissing('url.intended');
        $this->assertSame($before, DB::table('paciente')->count());
        $this->withSession(['_token' => 'new-token'])->postJson(route('catalog.store', 'pacientes'), ['_token' => 'stale-token'])
            ->assertUnauthorized()->assertJsonPath('redirect', route('login'));
        $this->assertSame($before, DB::table('paciente')->count());
    }

    public function test_authenticated_csrf_failure_preserves_session_and_does_not_write(): void
    {
        $this->seedVidaSalud();
        $this->enforceCsrf();
        $actor = $this->account('recepcion');
        $before = DB::table('paciente')->count();
        $this->actingAs($actor)->withSession(['_token' => 'valid-token', 'private_marker' => 'preserve']);
        $this->post(route('catalog.store', 'pacientes'), ['_token' => 'wrong-token'])
            ->assertStatus(419)->assertSee('Tu sesión ha vencido')->assertSessionHas('private_marker', 'preserve');
        $this->assertAuthenticatedAs($actor);
        $this->postJson(route('catalog.store', 'pacientes'), ['_token' => 'wrong-token'])->assertStatus(419);
        $this->assertAuthenticatedAs($actor);
        $this->assertSame($before, DB::table('paciente')->count());
        $this->get(route('catalog.create', 'pacientes'))->assertOk();
    }
}

class EnforcedSessionCsrf extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
