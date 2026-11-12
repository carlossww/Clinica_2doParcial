<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class CatalogDispatchTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    public static function catalogs(): array
    {
        return [['pacientes'], ['usuarios'], ['roles'], ['medicos'], ['especialidades'], ['horarios']];
    }

    #[DataProvider('catalogs')]
    public function test_existing_urls_preserve_catalog_context_and_forms(string $module): void
    {
        $this->seedVidaSalud();
        $this->actingAs($this->account('admin'))->get(route('catalog.index', $module))
            ->assertOk()->assertViewHas('module', $module);
        $this->get(route('catalog.create', $module))->assertOk()->assertViewHas('module', $module);
    }

    public function test_submitted_module_cannot_redirect_patient_creation_to_user_management(): void
    {
        $this->seedVidaSalud();
        $users = DB::table('usuario')->count();
        $patients = DB::table('paciente')->count();
        $this->actingAs($this->account('recepcion'))->post(route('catalog.store', 'pacientes'), [
            'module' => 'usuarios', 'nombres' => 'Prueba', 'apellidos' => 'Enrutamiento',
            'fecha_nacimiento' => '2000-01-01', 'telefono' => '00000000', 'activo' => true,
        ])->assertRedirect(route('catalog.index', 'pacientes'))->assertSessionHasNoErrors();
        $this->assertSame($users, DB::table('usuario')->count());
        $this->assertSame($patients + 1, DB::table('paciente')->count());
    }

    public function test_pending_controllers_are_not_exposed_as_generic_catalogs(): void
    {
        $this->seedVidaSalud();
        $this->actingAs($this->account('admin'))->get('/gestion/reservas')->assertNotFound();
        $this->get('/gestion/diagnosticos')->assertNotFound();
        $this->get('/gestion/resultados')->assertNotFound();
    }
}
