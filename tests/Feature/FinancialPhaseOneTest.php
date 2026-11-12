<?php

namespace Tests\Feature;

use App\Modules\GestionFinanciera\Services\ConfiguracionFinancieraService;
use App\Modules\Shared\Services\OperacionWeb;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class FinancialPhaseOneTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVidaSalud();
    }

    private function signed(string $login, string $operation, array $data = []): array
    {
        $key = OperacionWeb::token();

        return $data + ['clave' => $key, 'firma' => OperacionWeb::signature($this->account($login), $operation, $key), 'paso' => 'guardar'];
    }

    private function rate(string $value = '6.96', int $version = 0): array
    {
        return $this->signed('admin', 'tipo_cambio', ['bob_por_usd' => $value, 'version' => $version, 'motivo' => 'Cotización sintética autorizada']);
    }

    public function test_configuration_review_history_replay_and_stale_form(): void
    {
        $this->actingAs($this->account('admin'))->get(route('finance.configuration'))->assertOk()->assertSee('Sin configurar');
        $this->get(route('finance.configuration.edit'))->assertOk();
        $data = $this->rate();
        $this->post(route('finance.configuration.save'), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('6.96');
        $this->assertDatabaseCount('configuracion_financiera', 0);
        $this->post(route('finance.configuration.save'), $data)->assertRedirect(route('finance.configuration'));
        $this->post(route('finance.configuration.save'), $data)->assertRedirect();
        $this->assertSame(1, DB::table('bitacora')->where('accion', 'TIPO_CAMBIO')->count());
        $this->post(route('finance.configuration.save'), $this->rate('7.00'))->assertUnprocessable();
        $this->post(route('finance.configuration.save'), $this->rate('7.10', 1))->assertRedirect();
        $this->assertSame(2, DB::table('bitacora')->where('accion', 'TIPO_CAMBIO')->count());
        $last = DB::table('bitacora')->where('accion', 'TIPO_CAMBIO')->orderByDesc('id')->first();
        $change = json_decode($last->descripcion, true);
        $this->assertSame('6.96000000', $change['anterior']);
        $this->assertSame('7.10000000', $change['nueva']);
        $this->assertSame($this->account('admin')->id, $last->usuario_id);
        $this->get(route('finance.configuration'))->assertOk()->assertSee('7.10000000')->assertSee('Cotización sintética autorizada');
    }

    public function test_configuration_rejects_invalid_rates_and_non_admin_roles(): void
    {
        foreach (['recepcion', 'medico', 'paciente'] as $login) {
            $this->actingAs($this->account($login))->get(route('finance.configuration'))->assertForbidden();
            $this->post(route('finance.configuration.save'), $this->rate())->assertForbidden();
        }
        $this->actingAs($this->account('admin'));
        foreach (['0', '-1', 'NaN', 'Infinity', '1e2', '1.123456789', '10000000000', '0.00000000'] as $value) {
            $this->postJson(route('finance.configuration.save'), $this->rate($value))->assertUnprocessable();
        }
        $this->assertDatabaseCount('configuracion_financiera', 0);
    }

    public function test_quote_is_decimal_exact_and_payment_snapshot_cannot_change(): void
    {
        $this->actingAs($this->account('admin'))->post(route('finance.configuration.save'), $this->rate())->assertRedirect();
        $service = app(ConfiguracionFinancieraService::class);
        $quote = $service->quote('100.00');
        $this->assertSame('14.37', $quote['monto_pasarela']);
        $id = $this->existingAppointment($this->appointmentPayload());
        $payment = DB::table('pago')->insertGetId($quote + ['cita_id' => $id, 'metodo' => 'PayPal', 'registrado_por' => $this->account('recepcion')->id]);
        $this->post(route('finance.configuration.save'), $this->rate('7.10', 1))->assertRedirect();
        $this->assertDatabaseHas('pago', ['id' => $payment, 'bob_por_usd' => '6.96000000', 'monto_pasarela' => '14.37']);
        try {
            DB::transaction(fn () => DB::table('pago')->where('id', $payment)->update(['bob_por_usd' => '7.10', 'monto_pasarela' => '14.08']));
            $this->fail('La cotización debió permanecer congelada.');
        } catch (QueryException $error) {
            $this->assertSame('P0001', $error->errorInfo[0]);
        }
    }

    public function test_quote_requires_configuration(): void
    {
        $this->expectException(ValidationException::class);
        app(ConfiguracionFinancieraService::class)->quote('100');
    }

    public function test_permission_assignments_are_separate_and_do_not_expand_clinical_access(): void
    {
        $admin = $this->account('admin');
        $reception = $this->account('recepcion');
        foreach (['pagos.registrar', 'ventas.gestionar', 'pagos.reembolsar', 'finanzas.configurar', 'inventario.gestionar'] as $code) {
            $this->assertTrue($admin->hasPermission($code));
        }
        $this->assertTrue($reception->hasPermission('pagos.registrar'));
        $this->assertTrue($reception->hasPermission('ventas.gestionar'));
        $this->assertFalse($reception->hasPermission('pagos.reembolsar'));
        $this->assertFalse($reception->hasPermission('inventario.gestionar'));
        $this->assertFalse($admin->hasPermission('historia.consultar'));
        $this->assertFalse($this->account('paciente')->hasPermission('pagos.registrar'));
    }

    public function test_administrative_booking_price_replay_and_patient_manipulation(): void
    {
        $payload = $this->appointmentPayload();
        $data = $this->signed('recepcion', 'cita', $payload + ['precio_consulta' => '120.50']);
        $this->actingAs($this->account('recepcion'))->post(route('appointments.store'), $data)->assertRedirect();
        $this->post(route('appointments.store'), $data)->assertRedirect();
        $this->assertDatabaseHas('cita', ['precio_consulta' => '120.50']);
        $id = DB::table('cita')->value('id');
        $this->get(route('appointments.show', $id))->assertOk()->assertSee('120.50 BOB');
        unset($payload['paciente_id']);
        $this->actingAs($this->account('paciente'))->postJson(route('reservations.store'), $this->signed('paciente', 'reserva', $payload + ['precio_consulta' => '1']))->assertUnprocessable();
        $this->assertDatabaseCount('cita', 1);
    }

    public function test_price_cannot_change_when_payment_pending_and_invalid_prices_are_rejected(): void
    {
        $id = $this->existingAppointment($this->appointmentPayload());
        $row = DB::table('cita')->find($id);
        $version = hash('sha256', json_encode([$row->precio_consulta, $row->estado]));
        $data = $this->signed('recepcion', 'precio.'.$id, ['precio_consulta' => '0', 'version' => $version]);
        $this->actingAs($this->account('recepcion'))->get(route('appointments.price.edit', $id))->assertOk();
        $this->post(route('appointments.price.save', $id), $data)->assertRedirect();
        $this->assertDatabaseHas('cita', ['id' => $id, 'precio_consulta' => '0.00']);
        $row = DB::table('cita')->find($id);
        $data = $this->signed('recepcion', 'precio.'.$id, ['precio_consulta' => '20', 'version' => hash('sha256', json_encode([$row->precio_consulta, $row->estado]))]);
        DB::table('pago')->insert(['cita_id' => $id, 'registrado_por' => $this->account('recepcion')->id, 'monto' => '20', 'moneda' => 'BOB', 'metodo' => 'Efectivo']);
        $this->post(route('appointments.price.save', $id), $data)->assertUnprocessable();
        foreach (['-1', 'NaN', '1e2', '1.001', '10000000000'] as $price) {
            $this->postJson(route('appointments.price.save', $id), array_replace($data, ['precio_consulta' => $price]))->assertUnprocessable();
        }
        $this->actingAs($this->account('paciente'))->get(route('appointments.price.edit', $id))->assertForbidden();
    }

    public function test_medical_fields_review_save_replay_own_read_and_foreign_denial(): void
    {
        $id = $this->historicalAppointment();
        $patient = DB::table('cita')->where('id', $id)->value('paciente_id');
        $data = $this->signed('medico', 'datos_medicos.'.$patient, ['enfermedades_cronicas' => 'CLINICO-SOLO-PRUEBA', 'medicamentos_actuales' => 'MEDICAMENTO-SINTETICO', 'version' => hash('sha256', json_encode([null, null, null]))]);
        $this->actingAs($this->account('medico'))->get(route('clinical.medical.edit', $patient))->assertOk();
        $this->post(route('clinical.medical.save', $patient), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('CLINICO-SOLO-PRUEBA');
        $this->assertDatabaseCount('historia_clinica', 0);
        $this->post(route('clinical.medical.save', $patient), $data)->assertRedirect();
        $this->post(route('clinical.medical.save', $patient), $data)->assertRedirect();
        $this->assertDatabaseCount('historia_clinica', 1);
        $this->get(route('clinical.show', $patient))->assertOk()->assertSee('CLINICO-SOLO-PRUEBA');
        $this->get(route('clinical.medical.edit', $this->otherPatient()))->assertNotFound();
        $this->actingAs($this->account('paciente'))->get(route('clinical.mine'))->assertOk()->assertSee('MEDICAMENTO-SINTETICO');
        $this->post(route('clinical.medical.save', $patient), $data)->assertForbidden();
        $this->actingAs($this->account('recepcion'))->get(route('clinical.medical.edit', $patient))->assertForbidden();
        $this->assertFalse(str_contains(json_encode(session()->all()), 'CLINICO-SOLO-PRUEBA'));
        $this->assertFalse(DB::table('bitacora')->where('descripcion', 'like', '%CLINICO-SOLO-PRUEBA%')->exists());
    }

    public function test_patient_catalog_accepts_new_fields_and_rejects_invalid_blood_type(): void
    {
        $patient = (int) DB::table('paciente')->where('ci', 'TEST-001')->value('id');
        $p = (array) DB::table('paciente')->find($patient);
        $data = array_intersect_key($p, array_flip(['ci', 'nombres', 'apellidos', 'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion', 'activo']));
        $data += ['tipo_sangre' => 'AB+', 'parentesco_emergencia' => 'Hermana', 'contacto_emergencia' => 'Contacto <DEMO>', 'telefono_emergencia' => '00000001'];
        $this->actingAs($this->account('recepcion'))->get(route('catalog.edit', ['module' => 'pacientes', 'id' => $patient]))
            ->assertOk()->assertSee('Tipo de sangre')->assertSee('Nombre del contacto de emergencia')->assertSee('Teléfono del contacto de emergencia');
        $this->put(route('catalog.update', ['module' => 'pacientes', 'id' => $patient]), $data)->assertRedirect();
        $this->assertDatabaseHas('paciente', ['id' => $patient, 'tipo_sangre' => 'AB+', 'parentesco_emergencia' => 'Hermana', 'contacto_emergencia' => 'Contacto <DEMO>', 'telefono_emergencia' => '00000001']);
        $this->putJson(route('catalog.update', ['module' => 'pacientes', 'id' => $patient]), array_replace($data, ['tipo_sangre' => 'ZZ']))->assertUnprocessable();
        $this->putJson(route('catalog.update', ['module' => 'pacientes', 'id' => $patient]), array_replace($data, ['telefono_emergencia' => str_repeat('1', 41)]))->assertUnprocessable();
        $this->existingAppointment($this->appointmentPayload());
        $this->actingAs($this->account('medico'))->get(route('clinical.show', $patient))
            ->assertOk()->assertSee('Contacto &lt;DEMO&gt;', false)->assertSee('00000001')->assertSee('Hermana')->assertDontSee('Contacto <DEMO>', false);
        $this->actingAs($this->account('paciente'))->get(route('clinical.mine'))
            ->assertOk()->assertSee('Contacto &lt;DEMO&gt;', false)->assertSee('00000001');
    }

    public function test_support_constraints_prevent_duplicate_nit_and_second_pending_payment(): void
    {
        DB::table('proveedor')->insert(['nombre' => 'Proveedor sintético', 'nit' => 'ABC123']);
        try {
            DB::transaction(fn () => DB::table('proveedor')->insert(['nombre' => 'Otro proveedor', 'nit' => ' abc123 ']));
            $this->fail('Se esperaba rechazo del NIT equivalente.');
        } catch (QueryException $error) {
            $this->assertSame('23505', $error->errorInfo[0]);
        }
        $id = $this->existingAppointment($this->appointmentPayload());
        $payment = ['cita_id' => $id, 'registrado_por' => $this->account('recepcion')->id, 'monto' => '20', 'moneda' => 'BOB', 'metodo' => 'Efectivo'];
        DB::table('pago')->insert($payment);
        try {
            DB::transaction(fn () => DB::table('pago')->insert($payment));
            $this->fail('Se esperaba rechazo de otro cobro pendiente.');
        } catch (QueryException $error) {
            $this->assertSame('23505', $error->errorInfo[0]);
        }
        $this->assertDatabaseCount('pago', 1);
    }

    public function test_stale_medical_submission_does_not_overwrite_or_flash_clinical_content(): void
    {
        $id = $this->historicalAppointment();
        $patient = DB::table('cita')->where('id', $id)->value('paciente_id');
        $data = ['enfermedades_cronicas' => 'TEXTO-MEDICO-PRIVADO', 'medicamentos_actuales' => null, 'version' => hash('sha256', json_encode([null, null, null]))];
        $this->actingAs($this->account('medico'))->post(route('clinical.medical.save', $patient), $this->signed('medico', 'datos_medicos.'.$patient, $data))->assertRedirect();
        $this->post(route('clinical.medical.save', $patient), $this->signed('medico', 'datos_medicos.'.$patient, array_replace($data, ['enfermedades_cronicas' => 'NO-SOBRESCRIBIR'])))->assertUnprocessable();
        $this->assertDatabaseHas('historia_clinica', ['paciente_id' => $patient, 'enfermedades_cronicas' => 'TEXTO-MEDICO-PRIVADO']);
        $this->assertFalse(str_contains(json_encode(session()->all()), 'NO-SOBRESCRIBIR'));
    }
}
