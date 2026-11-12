<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\GestionCitasAgendaMedica\Services\CitaService;
use App\Modules\Shared\Clinic;
use App\Modules\Shared\Services\OperacionWeb;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class ClinicalWorkflowsTest extends TestCase
{
    use RefreshDatabase,SeedsVidaSalud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVidaSalud();
    }

    private function signed(User $user, string $operation, array $data = []): array
    {
        $key = OperacionWeb::token();

        return $data + ['clave' => $key, 'firma' => OperacionWeb::signature($user, $operation, $key), 'paso' => 'guardar'];
    }

    private function lab(): User
    {
        return User::where('rol_id', DB::table('rol')->where('nombre', 'Laboratorista')->value('id'))->firstOrFail();
    }

    public function test_reception_can_review_book_replay_and_read_a_coded_appointment(): void
    {
        $payload = $this->appointmentPayload();
        $user = $this->account('recepcion');
        $data = $this->signed($user, 'cita', $payload);
        $this->actingAs($user)->get(route('appointments.create'))->assertOk()->assertDontSee('name="medico_id"', false);
        $filters = ['paciente_id' => $payload['paciente_id'], 'especialidad_id' => $payload['especialidad_id'], 'fecha' => substr($payload['inicio'], 0, 10)];
        $this->get(route('appointments.create', $filters))->assertOk()->assertSee('Consultar turnos del médico')->assertSee('form="appointment-filters"', false)->assertDontSee('name="inicio"', false);
        $page = $this->actingAs($user)->get(route('appointments.create', $payload + ['fecha' => substr($payload['inicio'], 0, 10)]))->assertOk()->assertSee('Turno disponible');
        $page->assertSee('assets/app.js?v='.substr(hash_file('sha256', public_path('assets/app.js')), 0, 16), false)
            ->assertSee('assets/app.css?v='.substr(hash_file('sha256', public_path('assets/app.css')), 0, 16), false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(0, $xpath->query('//form[@id="appointment-filters"]//select[@name="medico_id"]')->length);
        $this->assertSame(1, $xpath->query('//div[@class="form-panel"]/div/select[@name="medico_id" and @form="appointment-filters"]')->length);
        $this->assertSame(1, $xpath->query('//form[@method="post"]//input[@type="hidden" and @name="medico_id"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//select[@name="inicio"]/option[@value!=""]')->length);
        $this->post(route('appointments.store'), array_replace($data, ['paso' => 'revisar']))->assertOk()
            ->assertSee('Confirmar cita')->assertSee('name="paso" value="guardar"', false);
        $this->assertDatabaseCount('cita', 0);
        $response = $this->post(route('appointments.store'), $data)->assertRedirect();
        $this->post(route('appointments.store'), $data)->assertRedirect($response->headers->get('Location'));
        $this->assertDatabaseCount('cita', 1);
        $this->assertDatabaseCount('evento_cita', 1);
        $id = DB::table('cita')->value('id');
        $this->get(route('appointments.show', $id))->assertOk()->assertSee('VS-')->assertSee('Recepcion');
    }

    public function test_patient_reservation_derives_identity_and_rejects_other_patient_input(): void
    {
        $payload = $this->appointmentPayload();
        $user = $this->account('paciente');
        unset($payload['paciente_id']);
        $data = $this->signed($user, 'reserva', $payload);
        $this->actingAs($user)->postJson(route('reservations.store'), $data + ['paciente_id' => $this->otherPatient()])->assertUnprocessable();
        $this->post(route('reservations.store'), $data)->assertRedirect();
        $this->assertDatabaseHas('cita', ['paciente_id' => $user->paciente_id, 'creada_por' => $user->id]);
        $this->get(route('appointments.mine'))->assertOk()->assertSee('VS-');
    }

    public function test_unverified_patient_cannot_reserve(): void
    {
        $u = $this->account('paciente');
        DB::table('usuario')->where('id', $u->id)->update(['paciente_id' => null, 'paciente_verificado_por' => null, 'paciente_verificado_en' => null]);
        $this->actingAs($u->fresh())->get(route('reservations.create'))->assertForbidden();
    }

    public function test_conflicts_and_inactive_doctor_do_not_create_another_appointment(): void
    {
        $payload = $this->appointmentPayload();
        $this->existingAppointment($payload);
        $u = $this->account('recepcion');
        $this->actingAs($u)->postJson(route('appointments.store'), $this->signed($u, 'cita', $payload))->assertUnprocessable();
        $this->assertDatabaseCount('cita', 1);
        // Un perfil sin citas futuras puede desactivarse; no se eluden sus protecciones.
        $doctor = $this->otherDoctor();
        $this->postJson(route('appointments.store'), $this->signed($u, 'cita', array_replace($payload, ['medico_id' => $doctor])))->assertUnprocessable();
        $this->assertDatabaseCount('cita', 1);
    }

    public function test_reprogram_cancel_and_stale_form_preserve_events(): void
    {
        $payload = $this->appointmentPayload();
        $id = $this->existingAppointment($payload);
        $u = $this->account('recepcion');
        $service = new CitaService;
        $version = $service->version(DB::table('cita')->find($id));
        $new = CarbonImmutable::parse($payload['inicio'])->addHour()->toIso8601String();
        $this->actingAs($u)->post(route('appointments.update', $id), $this->signed($u, 'cita.'.$id.'.reprogramar', ['accion' => 'reprogramar', 'version' => $version, 'inicio' => $new]))->assertRedirect();
        $this->postJson(route('appointments.update', $id), $this->signed($u, 'cita.'.$id.'.cancelar', ['accion' => 'cancelar', 'version' => $version, 'motivo' => 'Ya cambió']))->assertUnprocessable();
        $version = $service->version(DB::table('cita')->find($id));
        $this->post(route('appointments.update', $id), $this->signed($u, 'cita.'.$id.'.cancelar', ['accion' => 'cancelar', 'version' => $version, 'motivo' => 'Paciente solicitó cancelar']))->assertRedirect();
        $this->assertDatabaseHas('cita', ['id' => $id, 'estado' => 'Cancelada']);
        $this->assertDatabaseCount('evento_cita', 3);
        $this->get(route('appointments.edit', ['id' => $id, 'accion' => 'reprogramar']))->assertUnprocessable();
    }

    public function test_confirmation_and_absence_follow_the_clock(): void
    {
        $past = $this->historicalAppointment($this->otherPatient(), 10);
        $p = $this->appointmentPayload();
        $id = $this->existingAppointment($p);
        $u = $this->account('recepcion');
        $service = new CitaService;
        $this->actingAs($u)->post(route('appointments.update', $id), $this->signed($u, 'cita.'.$id.'.confirmar', ['accion' => 'confirmar', 'version' => $service->version(DB::table('cita')->find($id))]))->assertRedirect();
        $this->postJson(route('appointments.update', $id), $this->signed($u, 'cita.'.$id.'.inasistencia', ['accion' => 'inasistencia', 'version' => $service->version(DB::table('cita')->find($id))]))->assertUnprocessable();
        $this->post(route('appointments.update', $past), $this->signed($u, 'cita.'.$past.'.inasistencia', ['accion' => 'inasistencia', 'version' => $service->version(DB::table('cita')->find($past))]))->assertRedirect();
        $this->assertDatabaseHas('cita', ['id' => $past, 'estado' => 'Inasistencia']);
    }

    public static function accessStates(): array
    {
        return [['Programada', 200], ['Confirmada', 200], ['Atendida', 200], ['Cancelada', 404], ['Inasistencia', 404]];
    }

    #[DataProvider('accessStates')]
    public function test_history_scope_depends_on_a_qualifying_patient_doctor_link(string $state, int $status): void
    {
        $id = $this->historicalAppointment();
        $p = DB::table('cita')->where('id', $id)->value('paciente_id');
        if ($state === 'Atendida') {
            DB::table('consulta')->insert(['cita_id' => $id, 'registrada_por' => $this->account('medico')->id]);
        } elseif ($state !== 'Programada') {
            DB::table('cita')->where('id', $id)->update(['estado' => $state, 'motivo_cancelacion' => $state === 'Cancelada' ? 'Prueba' : null]);
        }
        $this->actingAs($this->account('medico'))->get(route('clinical.show', $p))->assertStatus($status);
        $this->get(route('clinical.show', $this->otherPatient()))->assertNotFound();
    }

    public function test_diagnosis_treatment_and_order_are_reviewed_and_linked_to_the_attending_doctor(): void
    {
        $id = $this->historicalAppointment();
        $p = DB::table('cita')->where('id', $id)->value('paciente_id');
        $u = $this->account('medico');
        $data = $this->signed($u, 'diagnostico.'.$p, ['cita_id' => $id, 'descripcion' => 'Diagnóstico de prueba']);
        $this->actingAs($u)->get(route('diagnoses.create', $p))->assertOk();
        $this->post(route('diagnoses.store', $p), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('Revisar diagnóstico');
        $this->assertDatabaseCount('consulta', 0);
        $this->post(route('diagnoses.store', $p), $data)->assertRedirect();
        $this->post(route('diagnoses.store', $p), $data)->assertRedirect();
        $this->assertDatabaseCount('consulta', 1);
        $this->assertDatabaseCount('diagnostico', 1);
        $this->assertDatabaseHas('cita', ['id' => $id, 'estado' => 'Atendida']);
        $diagnosis = DB::table('diagnostico')->value('id');
        $this->get(route('treatments.create', $p))->assertOk();
        $this->post(route('treatments.store', $p), $this->signed($u, 'tratamiento.'.$p, ['diagnostico_id' => $diagnosis, 'descripcion' => 'Tratamiento de prueba', 'fecha_inicio' => '2026-11-07', 'fecha_fin' => '2026-11-08']))->assertRedirect();
        $this->get(route('orders.create', $p))->assertOk();
        $order = $this->signed($u, 'orden.'.$p, ['consulta_id' => DB::table('consulta')->value('id'), 'analisis_ids' => DB::table('analisis_laboratorio')->where('activo', true)->limit(2)->pluck('id')->all(), 'indicaciones' => 'Indicaciones de prueba']);
        $this->post(route('orders.store', $p), array_replace($order, ['paso' => 'revisar']))->assertOk();
        $this->post(route('orders.store', $p), $order)->assertRedirect();
        $this->assertDatabaseCount('orden_laboratorio', 1);
        $this->assertDatabaseCount('orden_analisis', 2);
        $this->get(route('clinical.show', $p))->assertOk()->assertSee('Diagnóstico de prueba')->assertSee('Tratamiento de prueba');
    }

    public function test_clinical_failures_leave_no_partial_attention_or_sensitive_session_input(): void
    {
        $id = $this->historicalAppointment();
        $p = DB::table('cita')->where('id', $id)->value('paciente_id');
        $u = $this->account('medico');
        $this->actingAs($u)->post(route('diagnoses.store', $p), $this->signed($u, 'diagnostico.'.$p, ['cita_id' => $id, 'descripcion' => '']))->assertUnprocessable()->assertSessionMissing('_old_input.descripcion');
        $this->assertDatabaseCount('consulta', 0);
        $this->assertDatabaseHas('cita', ['id' => $id, 'estado' => 'Programada']);
        $future = $this->existingAppointment($this->appointmentPayload());
        $this->post(route('diagnoses.store', $p), $this->signed($u, 'diagnostico.'.$p, ['cita_id' => $future, 'descripcion' => 'No corresponde']))->assertNotFound();
        $this->postJson(route('treatments.store', $p), $this->signed($u, 'tratamiento.'.$p, ['diagnostico_id' => 999999, 'descripcion' => 'No corresponde', 'fecha_inicio' => '2026-11-08', 'fecha_fin' => '2026-11-07']))->assertUnprocessable();
        $this->postJson(route('orders.store', $p), $this->signed($u, 'orden.'.$p, ['consulta_id' => 999999, 'analisis_ids' => []]))->assertUnprocessable();
    }

    public function test_laboratory_partial_results_publication_withdrawal_and_private_download(): void
    {
        Storage::fake('local');
        $o = $this->laboratoryOrder(2);
        $u = $this->lab();
        $this->actingAs($u)->get(route('orders.index'))->assertOk();
        $this->get(route('orders.show', $o['order']))->assertOk();
        $this->get(route('results.create', $o['items'][0]))->assertOk();
        $data = $this->signed($u, 'resultado.'.$o['items'][0], ['resultado' => 'RESULTADO-PRIVADO', 'observaciones' => 'Observación']);
        $preview = $this->post(route('results.store', $o['items'][0]), array_replace($data, ['paso' => 'revisar', 'archivo' => UploadedFile::fake()->createWithContent('informe.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")]))->assertOk();
        preg_match('/name="archivo_token" value="([^"]+)"/', $preview->getContent(), $match);
        $this->assertNotEmpty($match);
        $data['archivo_token'] = html_entity_decode($match[1], ENT_QUOTES);
        $this->post(route('results.store', $o['items'][0]), $data)->assertRedirect();
        $this->post(route('results.store', $o['items'][0]), $data)->assertRedirect();
        $this->assertDatabaseCount('resultado_laboratorio', 1);
        $this->assertDatabaseHas('orden_laboratorio', ['id' => $o['order'], 'estado' => 'En proceso']);
        $result = DB::table('resultado_laboratorio')->first();
        $this->get(route('results.download', $result->id))->assertOk()->assertDownload();
        $this->actingAs($this->account('paciente'))->get(route('results.show', $result->id))->assertNotFound();
        $this->get(route('clinical.mine'))->assertOk()->assertDontSee('RESULTADO-PRIVADO')->assertDontSee('NOTA-INTERNA-NO-PUBLICAR');
        $publish = $this->signed($u, 'publicacion.'.$result->id.'.1', ['publicar' => 1]);
        $this->actingAs($u)->post(route('results.publish', $result->id), $publish)->assertRedirect();
        $this->actingAs($this->account('paciente'))->get(route('results.show', $result->id))->assertOk()->assertSee('RESULTADO-PRIVADO')->assertDontSee('laboratorio/informes');
        $this->get(route('results.download', $result->id))->assertOk()->assertDownload();
        $this->actingAs($u)->post(route('results.publish', $result->id), $this->signed($u, 'publicacion.'.$result->id.'.0', ['publicar' => 0]))->assertRedirect();
        $this->actingAs($this->account('paciente'))->get(route('results.download', $result->id))->assertNotFound();
        $this->actingAs($u)->post(route('results.store', $o['items'][1]), $this->signed($u, 'resultado.'.$o['items'][1], ['resultado' => 'Segundo']))->assertRedirect();
        $this->assertDatabaseHas('orden_laboratorio', ['id' => $o['order'], 'estado' => 'Completada']);
        $this->get(route('results.create', $o['items'][1]))->assertUnprocessable();
    }

    public function test_invalid_uploads_and_unauthorized_actors_are_rejected(): void
    {
        Storage::fake('local');
        $o = $this->laboratoryOrder();
        $u = $this->lab();
        $this->actingAs($u)->postJson(route('results.store', $o['items'][0]), $this->signed($u, 'resultado.'.$o['items'][0], ['resultado' => 'Prueba', 'paso' => 'revisar', 'archivo' => UploadedFile::fake()->create('malware.exe', 1)]))->assertUnprocessable();
        $this->postJson(route('results.store', $o['items'][0]), $this->signed($u, 'resultado.'.$o['items'][0], ['resultado' => 'Prueba', 'paso' => 'revisar', 'archivo' => UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf')]))->assertUnprocessable();
        $this->assertDatabaseCount('resultado_laboratorio', 0);
        $this->get(route('clinical.index'))->assertForbidden();
        $this->actingAs($this->account('recepcion'))->get(route('orders.index'))->assertForbidden();
        $this->actingAs($this->account('admin'))->get(route('clinical.index'))->assertForbidden();
        $this->actingAs($this->account('paciente'))->get(route('clinical.show', $this->otherPatient()))->assertNotFound();
    }

    public function test_report_totals_do_not_multiply_orders_and_never_show_clinical_values(): void
    {
        $o = $this->laboratoryOrder(2);
        $u = $this->lab();
        $this->actingAs($u)->post(route('results.store', $o['items'][0]), $this->signed($u, 'resultado.'.$o['items'][0], ['resultado' => 'VALOR-CLINICO-NO-ADMIN']));
        $this->actingAs($this->account('admin'))->get(route('reports.index', ['tipo' => 'ordenes']))->assertOk()->assertViewHas('totals', fn ($t) => $t['Registros'] === 1 && $t['Análisis solicitados'] === 2 && $t['Resultados registrados'] === 1);
        foreach (['citas', 'pacientes', 'atenciones', 'ordenes', 'resultados'] as $type) {
            $this->get(route('reports.index', ['tipo' => $type]))->assertOk()->assertDontSee('VALOR-CLINICO-NO-ADMIN')->assertDontSee('NOTA-INTERNA-NO-PUBLICAR');
        }
        $this->getJson(route('reports.index', ['desde' => '2026-11-08', 'hasta' => '2026-11-07']))->assertUnprocessable();
        $this->get(route('reports.index', ['desde' => '2099-01-01']))->assertOk()->assertSee('No hay información');
        $this->actingAs($this->account('recepcion'))->get(route('reports.index'))->assertForbidden();
    }

    public function test_analysis_catalog_can_be_created_edited_and_deactivated_without_deletion(): void
    {
        $u = $this->account('admin');
        $data = $this->signed($u, 'analisis.nuevo', ['nombre' => 'Análisis de prueba', 'descripcion' => 'Descripción', 'activo' => 1]);
        $this->actingAs($u)->get(route('analysis-types.create'))->assertOk();
        $this->post(route('analysis-types.save'), $data)->assertRedirect();
        $id = DB::table('analisis_laboratorio')->where('nombre', 'Análisis de prueba')->value('id');
        $this->get(route('analysis-types.edit', $id))->assertOk();
        $this->post(route('analysis-types.save', $id), $this->signed($u, 'analisis.'.$id, ['nombre' => 'Análisis de prueba', 'activo' => 0]))->assertRedirect();
        $this->assertDatabaseHas('analisis_laboratorio', ['id' => $id, 'activo' => false]);
        $this->get(route('analysis-types.index'))->assertOk();
    }

    public function test_availability_json_and_expiration_have_the_same_protection_as_booking(): void
    {
        $p = $this->appointmentPayload();
        $filters = ['paciente_id' => $p['paciente_id'], 'medico_id' => $p['medico_id'], 'especialidad_id' => $p['especialidad_id'], 'fecha' => substr($p['inicio'], 0, 10)];
        $this->getJson(route('appointments.availability', $filters))->assertUnauthorized()->assertJsonStructure(['message', 'redirect']);
        $this->actingAs($this->account('recepcion'))->getJson(route('appointments.availability', $filters))->assertOk()->assertJsonCount(6, 'slots');
        $this->actingAs($this->account('paciente'))->getJson(route('appointments.availability', $filters))->assertForbidden();
        unset($filters['paciente_id']);
        $this->getJson(route('reservations.availability', $filters))->assertOk()->assertJsonCount(6, 'slots');
    }

    public function test_expired_or_forged_upload_reference_and_failed_insert_leave_no_new_file(): void
    {
        Storage::fake('local');
        $o = $this->laboratoryOrder();
        $u = $this->lab();
        $this->actingAs($u)->postJson(route('results.store', $o['items'][0]), $this->signed($u, 'resultado.'.$o['items'][0], ['resultado' => 'Prueba', 'archivo_token' => 'invalido']))->assertUnprocessable();
        $path = 'laboratorio/pendientes/'.$u->id.'/'.OperacionWeb::token().'.pdf';
        Storage::disk('local')->put($path, '%PDF-1.4');
        $token = Crypt::encryptString(json_encode(['usuario' => $u->id, 'ruta' => $path, 'vence' => time() - 1]));
        $this->postJson(route('results.store', $o['items'][0]), $this->signed($u, 'resultado.'.$o['items'][0], ['resultado' => 'Prueba', 'archivo_token' => $token]))->assertUnprocessable();
        $token = Crypt::encryptString(json_encode(['usuario' => $u->id, 'ruta' => $path, 'vence' => time() + 3600]));
        DB::unprepared("CREATE FUNCTION vida_salud.test_informe_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Fallo de prueba al guardar informe'; END; $$;
            CREATE TRIGGER zzz_test_informe_failure BEFORE INSERT ON vida_salud.resultado_laboratorio FOR EACH ROW EXECUTE FUNCTION vida_salud.test_informe_failure();");
        $this->postJson(route('results.store', $o['items'][0]), $this->signed($u, 'resultado.'.$o['items'][0], ['resultado' => 'Prueba', 'archivo_token' => $token]))->assertUnprocessable();
        $this->assertDatabaseCount('resultado_laboratorio', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('laboratorio/informes'));
        Storage::disk('local')->assertExists($path);
        DB::unprepared('DROP TRIGGER zzz_test_informe_failure ON vida_salud.resultado_laboratorio; DROP FUNCTION vida_salud.test_informe_failure();');
        $this->post(route('results.store', $o['items'][0]), $this->signed($u, 'resultado.'.$o['items'][0], ['resultado' => 'Prueba', 'archivo_token' => $token]))->assertRedirect();
        $result = DB::table('resultado_laboratorio')->first();
        $this->assertNotNull($result);
        Storage::disk('local')->delete($result->archivo_referencia);
        $this->get(route('results.download', $result->id))->assertNotFound();
    }

    public function test_new_migration_preserves_existing_clinical_records_and_role_assignments(): void
    {
        $order = $this->laboratoryOrder();
        $before = DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson();
        $migration = require database_path('migrations/2026_11_07_000001_complete_clinical_workflows.php');
        $migration->down();
        $this->assertDatabaseHas('orden_laboratorio', ['id' => $order['order']]);
        $migration->up();
        $this->assertDatabaseHas('orden_laboratorio', ['id' => $order['order']]);
        $this->assertSame($before, DB::table('rol_permiso')->orderBy('rol_id')->orderBy('permiso_id')->get()->toJson());
        $this->actingAs($this->account('medico'))->get(route('orders.show', $order['order']))->assertOk();
    }

    public function test_reports_include_the_whole_local_day_and_keep_totals_across_pages(): void
    {
        $payload = $this->appointmentPayload();
        $base = CarbonImmutable::parse($payload['inicio']);
        for ($i = 0; $i < 25; $i++) {
            $this->existingAppointment(array_replace($payload, ['inicio' => $base->addWeeks(intdiv($i, 6))->addMinutes(($i % 6) * 30)->toIso8601String()]));
        }
        $this->actingAs($this->account('admin'))->get(route('reports.index', ['tipo' => 'citas']))->assertOk()->assertViewHas('totals', fn ($t) => $t['Registros'] === 25)->assertViewHas('rows', fn ($r) => $r->count() === 20);
        $this->get(route('reports.index', ['tipo' => 'citas', 'page' => 2]))->assertOk()->assertViewHas('totals', fn ($t) => $t['Registros'] === 25)->assertViewHas('rows', fn ($r) => $r->count() === 5)->assertSee('tipo=citas', false);
        $this->get(route('reports.index', ['tipo' => 'citas', 'desde' => $base->toDateString(), 'hasta' => $base->toDateString()]))->assertOk()->assertViewHas('totals', fn ($t) => $t['Registros'] === 6);
    }

    public function test_result_correction_preserves_version_and_withdraws_patient_visibility(): void
    {
        $order = $this->laboratoryOrder();
        $u = $this->lab();
        $this->actingAs($u)->post(route('results.store', $order['items'][0]), $this->signed($u, 'resultado.'.$order['items'][0], ['resultado' => 'Resultado original']))->assertRedirect();
        $id = DB::table('resultado_laboratorio')->value('id');
        $this->post(route('results.publish', $id), $this->signed($u, 'publicacion.'.$id.'.1', ['publicar' => 1]))->assertRedirect();
        Clinic::transaction($u, fn () => DB::table('resultado_laboratorio')->where('id', $id)->update(['resultado' => 'Resultado corregido', 'laboratorista_id' => $u->id]));
        $this->assertDatabaseHas('resultado_version', ['resultado_id' => $id, 'valor_anterior' => 'Resultado original']);
        $this->assertDatabaseHas('resultado_laboratorio', ['id' => $id, 'publicado_en' => null, 'publicado_por' => null]);
        $this->actingAs($this->account('paciente'))->get(route('results.show', $id))->assertNotFound();
        $this->actingAs($this->account('medico'))->get(route('results.show',$id))->assertOk()->assertSee('Resultado corregido');
    }
}
