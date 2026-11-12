<?php

namespace Tests\Feature;

use App\Modules\Shared\Clinic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class AppointmentQueriesTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    public function test_dashboard_summary_covers_all_pages_and_week_does_not_hide_appointments(): void
    {
        $this->seedVidaSalud();
        $id = null;
        for ($hour = 0; $hour < 21; $hour++) {
            $id = $this->historicalAppointment(null, $hour);
        }
        $date = Clinic::date(DB::table('cita')->where('id', $id)->value('inicio'));
        $this->actingAs($this->account('recepcion'));
        $this->get(route('agenda.index', ['desde' => $date->toDateString(), 'hasta' => $date->toDateString()]))
            ->assertOk()->assertViewHas('summary', fn ($summary) => (int) $summary['Programada'] === 21)
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 20 && $rows->total() === 21);
        $this->get(route('agenda.index', ['desde' => $date->toDateString(), 'periodo' => 'semana', 'page' => 2]))
            ->assertOk()->assertSee('Semana siguiente')
            ->assertViewHas('filters', fn ($filters) => $filters['desde'] === $date->startOfWeek()->toDateString() && $filters['hasta'] === $date->endOfWeek()->toDateString())
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 21);
    }

    public function test_dashboard_search_specialty_actions_and_free_slots_preserve_permissions(): void
    {
        $f = $this->fixtures();
        $date = Clinic::date($f['payload']['inicio'])->toDateString();
        $patient = DB::table('paciente')->where('id', $f['payload']['paciente_id'])->first();
        $params = ['desde' => $date, 'hasta' => $date, 'medico_id' => $f['payload']['medico_id'], 'especialidad_id' => $f['payload']['especialidad_id'], 'q' => $patient->nombres];
        $response = $this->actingAs($this->account('recepcion'))->get(route('agenda.index', $params))
            ->assertOk()->assertSee('Reprogramar')->assertDontSee('Marcar inasistencia')->assertDontSee('MOTIVO-CLINICO-PRIVADO')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$f['future']]);
        $this->assertNotEmpty($response->viewData('slots'));
        $this->assertArrayNotHasKey(Clinic::date($f['payload']['inicio'])->toIso8601String(), $response->viewData('slots'));
        $this->get(route('agenda.index', array_merge($params, ['q' => 'PACIENTE-INEXISTENTE'])))
            ->assertOk()->assertViewHas('summary', fn ($summary) => array_sum($summary) === 0);
        $this->actingAs($this->account('medico'))->get(route('agenda.index', $params))
            ->assertOk()->assertSee('Abrir historia clínica')->assertDontSee('Reprogramar')->assertDontSee('Elegir paciente y reservar')
            ->assertViewHas('canManage', false);
        $this->getJson(route('agenda.index', ['periodo' => 'mes']))->assertUnprocessable()->assertJsonValidationErrors('periodo');
    }

    private function fixtures(): array
    {
        $this->seedVidaSalud();
        $cancelled = $this->historicalAppointment(null, 9);
        DB::table('cita')->where('id', $cancelled)->update(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Cambio de fecha']);
        $previous = $this->historicalAppointment(null, 10);
        $otherPatient = $this->otherPatient();
        $unrelated = $this->historicalAppointment($otherPatient, 11);
        $payload = [...$this->appointmentPayload(), 'motivo_consulta' => 'MOTIVO-CLINICO-PRIVADO'];
        $future = $this->existingAppointment($payload);
        $otherDoctor = $this->otherDoctor();
        DB::table('medico_especialidad')->insert(['medico_id' => $otherDoctor, 'especialidad_id' => $payload['especialidad_id']]);
        DB::table('horario')->insert(['medico_id' => $otherDoctor, 'dia_semana' => 1, 'hora_inicio' => '09:00', 'hora_fin' => '12:00']);
        $otherFuture = $this->existingAppointment([...$payload, 'paciente_id' => $otherPatient, 'medico_id' => $otherDoctor]);

        return compact('cancelled', 'previous', 'unrelated', 'future', 'otherFuture', 'otherDoctor', 'payload');
    }

    public static function administrativeAccounts(): array
    {
        return [['admin'], ['recepcion']];
    }

    #[DataProvider('administrativeAccounts')]
    public function test_patient_history_includes_old_and_cancelled_appointments_without_other_patients(string $login): void
    {
        $f = $this->fixtures();
        $count = DB::table('bitacora')->count();
        $this->actingAs($this->account($login))->get(route('appointments.patient', $f['payload']['paciente_id']))
            ->assertOk()->assertSee('Medicina General')->assertSee('Cancelada')->assertDontSee('MOTIVO-CLINICO-PRIVADO')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$f['future'], $f['previous'], $f['cancelled']]);
        $this->assertSame($count, DB::table('bitacora')->count());
        $this->assertDatabaseCount('cita', 5);
        $this->get(route('catalog.index', 'pacientes'))->assertSee(route('appointments.patient', $f['payload']['paciente_id']), false);
    }

    public function test_history_filters_include_the_whole_local_day_and_preserve_scope(): void
    {
        $f = $this->fixtures();
        $date = Clinic::date(DB::table('cita')->where('id', $f['previous'])->value('inicio'))->toDateString();
        $this->actingAs($this->account('recepcion'))->get(route('appointments.patient', [
            'id' => $f['payload']['paciente_id'], 'desde' => $date, 'hasta' => $date,
        ]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$f['previous'], $f['cancelled']]);
        $this->get(route('appointments.patient', ['id' => $f['payload']['paciente_id'], 'estado' => 'Cancelada', 'paciente_id' => $this->otherPatientId($f)]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$f['cancelled']]);
        $this->get(route('appointments.patient', ['id' => $f['payload']['paciente_id'], 'hasta' => $date]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
    }

    private function otherPatientId(array $fixtures): int
    {
        return (int) DB::table('cita')->where('id', $fixtures['unrelated'])->value('paciente_id');
    }

    public function test_inactive_people_and_cancelled_history_remain_readable(): void
    {
        $f = $this->fixtures();
        DB::table('cita')->where('inicio', '>', now())->update(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Cierre del período']);
        DB::table('paciente')->where('id', $f['payload']['paciente_id'])->update(['activo' => false]);
        DB::table('medico')->where('id', $f['payload']['medico_id'])->update(['activo' => false]);
        DB::table('usuario')->where('id', $this->account('medico')->id)->update(['activo' => false]);
        $this->actingAs($this->account('admin'))->get(route('appointments.patient', $f['payload']['paciente_id']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 3);
        $this->get(route('agenda.doctor', ['id' => $f['payload']['medico_id'], 'desde' => '', 'hasta' => '', 'vista' => 'historial']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 4);
        $this->get(route('agenda.index'))->assertOk()->assertDontSee('Dr Uno (inactivo)');
        $this->get(route('agenda.index', ['vista' => 'historial']))->assertOk()->assertSee('Dr Uno (inactivo)');
    }

    public function test_date_filter_includes_local_midnight_and_late_evening(): void
    {
        $this->seedVidaSalud();
        $midnight = $this->historicalAppointment(null, 0);
        $late = $this->historicalAppointment(null, 23);
        $date = Clinic::date(DB::table('cita')->where('id', $late)->value('inicio'))->toDateString();
        $this->actingAs($this->account('recepcion'))->get(route('appointments.patient', [
            'id' => $this->account('paciente')->paciente_id, 'desde' => $date, 'hasta' => $date,
        ]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$late, $midnight]);
    }

    #[DataProvider('administrativeAccounts')]
    public function test_directory_agenda_is_filtered_by_doctor_and_dates_without_exposing_clinical_reason(string $login): void
    {
        $f = $this->fixtures();
        $date = Clinic::date($f['payload']['inicio'])->toDateString();
        $this->actingAs($this->account($login))->get(route('agenda.doctor', [
            'id' => $f['payload']['medico_id'], 'desde' => $date, 'hasta' => $date,
        ]))->assertOk()->assertDontSee('MOTIVO-CLINICO-PRIVADO')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$f['future']]);
        $this->get(route('catalog.index', 'medicos'))->assertSee(route('agenda.doctor', $f['payload']['medico_id']), false);
        $this->get(route('agenda.index', ['desde' => $date, 'hasta' => $date, 'medico_id' => $f['otherDoctor']]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$f['otherFuture']]);
        $this->get(route('home'))->assertSee(route('agenda.index'), false);
    }

    public function test_doctor_only_reads_own_agenda_and_authorized_reason(): void
    {
        $f = $this->fixtures();
        $date = Clinic::date($f['payload']['inicio'])->toDateString();
        $this->actingAs($this->account('medico'))->get(route('agenda.index', ['desde' => $date, 'hasta' => $date]))
            ->assertOk()->assertSee('MOTIVO-CLINICO-PRIVADO')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$f['future']]);
        $this->get(route('agenda.doctor', $f['otherDoctor']))->assertNotFound();
        $this->get(route('agenda.index', ['medico_id' => $f['otherDoctor']]))->assertNotFound();
        $this->get(route('agenda.index', ['medico_id' => 999999]))->assertNotFound();
        $this->get(route('appointments.patient', $f['payload']['paciente_id']))->assertForbidden();
        $this->get(route('home'))->assertSee(route('agenda.index'), false);
    }

    public function test_doctor_without_clinical_permission_reads_administrative_data_only(): void
    {
        $f = $this->fixtures();
        $doctor = $this->account('medico');
        DB::table('rol_permiso')->where('rol_id', $doctor->rol_id)
            ->where('permiso_id', DB::table('permiso')->where('codigo', 'atencion.registrar')->value('id'))->delete();
        $this->actingAs($doctor)->get(route('agenda.index', ['desde' => '', 'hasta' => '']))
            ->assertOk()->assertDontSee('MOTIVO-CLINICO-PRIVADO')
            ->assertViewHas('rows', fn ($rows) => $rows->every(fn ($row) => ! property_exists($row, 'motivo_consulta')));
    }

    public function test_agenda_defaults_to_today_and_empty_filters_allow_the_whole_history(): void
    {
        $f = $this->fixtures();
        $this->actingAs($this->account('recepcion'))->get(route('agenda.index'))
            ->assertOk()->assertViewHas('filters', fn ($filters) => $filters['desde'] === now('America/La_Paz')->toDateString() && $filters['hasta'] === $filters['desde']);
        $this->get(route('agenda.index', ['desde' => '', 'hasta' => '', 'estado' => 'Cancelada']))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$f['cancelled']]);
    }

    public static function invalidFilters(): array
    {
        return [
            [['desde' => '2026-02-30']], [['desde' => '0000-01-01']],
            [['desde' => '2026-10-10', 'hasta' => '2026-10-09']], [['hasta' => ['2026-01-01']]],
            [['estado' => 'EnAtencion']], [['medico_id' => 'texto']], [['page' => 0]],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_query_filters_are_rejected(array $filters): void
    {
        $this->seedVidaSalud();
        $patientId = $this->account('paciente')->paciente_id;
        $this->actingAs($this->account('admin'))->getJson(route('agenda.index', $filters))->assertUnprocessable();
        $this->getJson(route('appointments.patient', ['id' => $patientId, ...$filters]))->assertUnprocessable();
    }

    public static function unauthorizedAccounts(): array
    {
        return [['laboratorio'], ['paciente']];
    }

    #[DataProvider('unauthorizedAccounts')]
    public function test_other_roles_cannot_read_administrative_citas(string $login): void
    {
        $this->seedVidaSalud();
        $patientId = $this->account('paciente')->paciente_id;
        $doctorId = DB::table('medico')->value('id');
        $this->actingAs($this->account($login))->get(route('agenda.index'))->assertForbidden();
        $this->get(route('agenda.doctor', $doctorId))->assertForbidden();
        $this->get(route('appointments.patient', $patientId))->assertForbidden();
    }

    public function test_missing_records_and_unauthenticated_queries_are_rejected(): void
    {
        $this->seedVidaSalud();
        $this->get(route('agenda.index'))->assertRedirectToRoute('login');
        $this->get(route('appointments.patient', 999999))->assertRedirectToRoute('login');
        $this->actingAs($this->account('admin'))->get(route('agenda.doctor', 999999))->assertNotFound();
        $this->get(route('appointments.patient', 999999))->assertNotFound();
        $this->getJson(route('agenda.index', ['medico_id' => 999999]))->assertUnprocessable();
        $this->getJson(route('appointments.patient', ['id' => $this->account('paciente')->paciente_id, 'medico_id' => 999999]))->assertUnprocessable();
    }

    public function test_history_paginates_without_duplicates_and_escapes_names_and_reasons(): void
    {
        $this->seedVidaSalud();
        for ($hour = 0; $hour < 21; $hour++) {
            $this->historicalAppointment(null, $hour);
        }
        $patientId = $this->account('paciente')->paciente_id;
        DB::table('paciente')->where('id', $patientId)->update(['nombres' => '<script>Paciente</script>']);
        DB::table('cita')->where('paciente_id', $patientId)->update(['motivo_consulta' => '<script>Motivo</script>']);
        $url = route('appointments.patient', ['id' => $patientId, 'estado' => 'Programada']);
        $this->actingAs($this->account('admin'))->get($url)
            ->assertOk()->assertSee('&lt;script&gt;Paciente&lt;/script&gt;', false)->assertDontSee('<script>Paciente</script>', false)
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21 && $rows->count() === 20 && $rows->pluck('id')->unique()->count() === 20)
            ->assertSee('estado=Programada', false);
        $this->get($url.'&page=2')->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->total() === 21);
        $this->actingAs($this->account('medico'))->get(route('agenda.index', ['desde' => '', 'hasta' => '']))
            ->assertOk()->assertSee('&lt;script&gt;Motivo&lt;/script&gt;', false)->assertDontSee('<script>Motivo</script>', false);
    }

    public static function disabledDoctorParts(): array
    {
        return [['medico'], ['usuario'], ['rol']];
    }

    #[DataProvider('disabledDoctorParts')]
    public function test_normal_agenda_excludes_disabled_doctor_and_history_preserves_records(string $part): void
    {
        $this->seedVidaSalud();
        $id = $this->historicalAppointment(null, 9);
        $doctor = DB::table('cita')->where('id', $id)->value('medico_id');
        $account = $this->account('medico');
        if ($part === 'medico') {
            DB::table('medico')->where('id', $doctor)->update(['activo' => false]);
        } elseif ($part === 'usuario') {
            DB::table('usuario')->where('id', $account->id)->update(['activo' => false]);
        } else {
            DB::table('rol')->where('id', $account->rol_id)->update(['activo' => false]);
        }
        $this->actingAs($this->account('admin'));
        $this->get(route('agenda.index', ['desde' => '', 'hasta' => '']))->assertOk()
            ->assertViewHas('rows', fn ($rows) => ! $rows->pluck('id')->contains($id))
            ->assertViewHas('doctors', fn ($doctors) => ! array_key_exists($doctor, $doctors));
        $response = $this->get(route('agenda.doctor', $doctor))->assertUnprocessable()
            ->assertSee('no está habilitado')->assertSee('Seleccionar otro médico')->assertSee('Historial de citas por médico')
            ->assertViewMissing('rows');
        $this->get($response->viewData('historyUrl'))->assertOk()->assertSee('(inactivo)');
        $this->getJson(route('agenda.index', ['medico_id' => $doctor]))->assertUnprocessable()->assertJsonValidationErrors('medico_id');
        $this->get(route('agenda.index', ['vista' => 'historial', 'medico_id' => $doctor]))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->contains($id))->assertDontSee('MOTIVO-CLINICO-PRIVADO');
        $this->get(route('appointments.patient', $this->account('paciente')->paciente_id))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->contains($id));
    }

    public function test_inactive_medical_profile_can_only_read_own_explicit_history(): void
    {
        $this->seedVidaSalud();
        $id = $this->historicalAppointment();
        $doctor = DB::table('cita')->where('id', $id)->value('medico_id');
        $other = $this->otherDoctor();
        DB::table('medico')->where('id', $doctor)->update(['activo' => false]);
        $this->actingAs($this->account('medico'))->get(route('agenda.index'))->assertUnprocessable()->assertDontSee('Seleccionar otro médico');
        $this->get(route('agenda.index', ['vista' => 'historial']))->assertOk()->assertSee('(inactivo)')
            ->assertViewHas('rows', fn ($rows) => $rows->pluck('id')->all() === [$id]);
        $this->get(route('agenda.doctor', ['id' => $other, 'vista' => 'historial']))->assertNotFound();
        $this->get(route('agenda.index', ['medico_id' => $other, 'vista' => 'historial']))->assertNotFound();
        $this->actingAs($this->account('paciente'))->get(route('agenda.index', ['vista' => 'historial']))->assertForbidden();
        $this->actingAs($this->account('laboratorio'))->get(route('agenda.index', ['vista' => 'historial']))->assertForbidden();
    }

    public function test_history_mode_pagination_filters_and_invalid_mode(): void
    {
        $this->seedVidaSalud();
        for ($hour = 0; $hour < 21; $hour++) {
            $this->historicalAppointment(null, $hour);
        }
        $this->actingAs($this->account('recepcion'));
        $this->get(route('agenda.index', ['vista' => 'historial', 'estado' => 'Programada', 'extra' => 'NO-PRESERVAR']))->assertOk()
            ->assertSee('vista=historial', false)->assertSee('estado=Programada', false)->assertDontSee('NO-PRESERVAR')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21 && $rows->count() === 20);
        $this->get(route('agenda.index', ['vista' => 'historial', 'estado' => 'Programada', 'page' => 2]))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 21 && $rows->count() === 1);
        $this->getJson(route('agenda.index', ['vista' => 'otra']))->assertUnprocessable()->assertJsonValidationErrors('vista');
        $this->get(route('agenda.doctor', ['id' => 999999, 'vista' => 'historial']))->assertNotFound();
    }
}
