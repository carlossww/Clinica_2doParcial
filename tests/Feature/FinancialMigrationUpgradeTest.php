<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsVidaSalud;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class FinancialMigrationUpgradeTest extends TestCase
{
    use DatabaseMigrations, SeedsVidaSalud;

    public function runDatabaseMigrations(): void
    {
        // Reproduce el repositorio remoto inspeccionado, incluidas las once tablas
        // ya creadas y las tres migraciones históricas todavía pendientes allí.
        $pending = ['2026_10_25_000001_grant_reception_directory_permissions.php', '2026_10_25_000002_configure_appointment_duration.php', '2026_11_07_000001_complete_clinical_workflows.php'];
        $paths = array_map(fn ($file) => 'database/migrations/'.basename($file), array_filter(glob(database_path('migrations/*.php')), fn ($file) => basename($file) < '2026_11_07_000004' && ! in_array(basename($file), $pending, true)));
        $this->artisan('migrate', ['--path' => $paths, '--force' => true])->assertExitCode(0);
        $this->beforeApplicationDestroyed(function () {
            TestDatabaseSafety::resetSchema($this->app);
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_upgrade_adopts_existing_fields_and_preserves_synthetic_records_and_permissions(): void
    {
        $this->seedVidaSalud();
        DB::unprepared('ALTER TABLE paciente ADD parentesco_emergencia varchar(60), ADD tipo_sangre varchar(10); ALTER TABLE historia_clinica ADD enfermedades_cronicas text, ADD medicamentos_actuales text; ALTER TABLE cita ADD precio_consulta numeric(12,2), ADD CONSTRAINT cita_precio_consulta_no_negativo CHECK(precio_consulta IS NULL OR precio_consulta>=0)');
        $id = $this->existingAppointment($this->appointmentPayload());
        DB::table('cita')->where('id', $id)->update(['precio_consulta' => '80.25']);
        DB::table('paciente')->where('id', DB::table('cita')->where('id', $id)->value('paciente_id'))->update(['tipo_sangre' => 'O+', 'parentesco_emergencia' => 'Hermano']);
        $before = DB::table('rol_permiso')->get()->map(fn ($row) => (array) $row)->all();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertDatabaseHas('cita', ['id' => $id, 'precio_consulta' => '80.25']);
        $this->assertDatabaseHas('paciente', ['tipo_sangre' => 'O+', 'parentesco_emergencia' => 'Hermano']);
        foreach ($before as $assignment) {
            $this->assertDatabaseHas('rol_permiso', $assignment);
        }
        $this->assertTrue($this->account('admin')->hasPermission('finanzas.configurar'));
        $this->assertTrue($this->account('recepcion')->hasPermission('ventas.gestionar'));
        $this->assertDatabaseCount('configuracion_financiera', 0);
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_11_07_000003_create_payment_purchase_inventory_tables')->count());
        $this->assertNotNull(DB::selectOne("SELECT to_regclass('vida_salud.operacion_web') AS name")->name);
        $this->assertSame(30, DB::table('cita')->where('id', $id)->value('duracion_minutos'));
    }

    public function test_incompatible_existing_column_stops_adoption_without_partial_changes(): void
    {
        DB::statement('ALTER TABLE paciente ADD tipo_sangre varchar(11)');
        try {
            DB::transaction(fn () => (require database_path('migrations/2026_11_07_000004_adopt_patient_profile_and_consultation_price.php'))->up());
            $this->fail('La incompatibilidad debió detener la migración.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Estructura incompatible', $error->getMessage());
        }
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('paciente', 'parentesco_emergencia'));
    }

    public function test_functions_stay_disabled_before_dependencies_are_applied(): void
    {
        $this->seedVidaSalud();
        $this->actingAs($this->account('admin'))->get(route('finance.configuration'))->assertStatus(503)->assertSee('actualización autorizada');
    }

    public function test_inventory_upgrade_preserves_existing_movements_and_then_protects_them(): void
    {
        $this->seedVidaSalud();
        $product = DB::table('producto')->insertGetId(['nombre' => 'Existencia histórica sintética', 'tipo' => 'Insumo', 'unidad_medida' => 'unidad']);
        $lot = DB::table('lote')->insertGetId(['producto_id' => $product, 'numero_lote' => 'ANTERIOR']);
        $move = DB::table('movimiento_inventario')->insertGetId(['producto_id' => $product, 'lote_id' => $lot, 'usuario_id' => $this->account('admin')->id,
            'tipo' => 'Entrada', 'cantidad' => 12, 'motivo' => 'Recepción histórica sintética']);
        $before = (array) DB::table('movimiento_inventario')->find($move);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, (array) DB::table('movimiento_inventario')->find($move));
        try {
            DB::transaction(fn () => DB::table('movimiento_inventario')->where('id', $move)->delete());
            $this->fail('El movimiento histórico debió conservarse.');
        } catch (QueryException $error) {
            $this->assertSame('P0001', $error->errorInfo[0]);
        }
    }
}
