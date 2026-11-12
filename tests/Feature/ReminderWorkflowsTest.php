<?php

namespace Tests\Feature;

use App\Modules\GestionPacientes\Services\RecordatorioService;
use App\Modules\Shared\Clinic;
use App\Modules\Shared\Services\OperacionWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class ReminderWorkflowsTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    public function test_booking_and_portal_reservation_schedule_one_reminder_even_on_replay(): void
    {
        $this->seedVidaSalud();
        $payload = $this->appointmentPayload();
        foreach (['recepcion' => 'appointments.store', 'paciente' => 'reservations.store'] as $role => $route) {
            $user = $this->account($role);
            $key = OperacionWeb::token();
            $data = $payload;
            if ($role === 'paciente') {
                unset($data['paciente_id']);
                $data['inicio'] = Clinic::date($payload['inicio'])->addHour()->toIso8601String();
            }
            $data += ['clave' => $key, 'firma' => OperacionWeb::signature($user, $role === 'paciente' ? 'reserva' : 'cita', $key), 'paso' => 'guardar'];
            $this->actingAs($user)->post(route($route), $data)->assertRedirect()->assertSessionHasNoErrors();
            $appointment = DB::table('cita')->where('inicio', Clinic::date($data['inicio']))->first();
            $reminder = DB::table('recordatorio')->where('cita_id', $appointment->id)->sole();
            $this->assertSame('Correo', $reminder->medio);
            $this->assertSame('Paciente', $reminder->destinatario);
            $this->assertSame('Programado', $reminder->estado);
            $this->assertSame(Clinic::date($appointment->inicio)->subHours(24)->getTimestamp(), Clinic::date($reminder->fecha_programada)->getTimestamp());
            $this->post(route($route), $data)->assertRedirect();
            app(RecordatorioService::class)->scheduleForAppointment($user, $appointment->id);
            $this->assertSame(1, DB::table('recordatorio')->where('cita_id', $appointment->id)->count());
            $this->assertSame(1, DB::table('historial_recordatorio')->where('recordatorio_id', $reminder->id)->count());
            $this->get(route('appointments.show', $appointment->id))->assertOk()->assertViewHas('recordatorios', fn ($rows) => $rows->contains('id', $reminder->id));
        }
    }

    public function test_near_appointment_is_scheduled_now_without_external_contact(): void
    {
        $this->seedVidaSalud();
        $payload = $this->appointmentPayload();
        $start = now('America/La_Paz')->addDay()->startOfDay();
        DB::table('horario')->where('medico_id', $payload['medico_id'])->update([
            'dia_semana' => $start->isoWeekday(), 'hora_inicio' => '00:00', 'hora_fin' => '01:00',
        ]);
        $payload['inicio'] = $start->toIso8601String();
        $appointment = $this->existingAppointment($payload);
        $before = time();
        $id = app(RecordatorioService::class)->scheduleForAppointment($this->account('recepcion'), $appointment);
        $row = DB::table('recordatorio')->find($id);
        $this->assertGreaterThanOrEqual($before, Clinic::date($row->fecha_programada)->getTimestamp());
        $this->assertLessThanOrEqual(time(), Clinic::date($row->fecha_programada)->getTimestamp());
        $this->assertSame('Programado', $row->estado);
        $this->assertSame(0, $row->intentos);
        app(RecordatorioService::class)->processScheduled($this->account('recepcion'));
        $this->assertDatabaseHas('recordatorio', ['id' => $id, 'estado' => 'Programado', 'intentos' => 0, 'fecha_envio' => null]);
        $this->actingAs($this->account('recepcion'))->post(route('reminders.send', $id))->assertSessionHasErrors('operacion');
        $this->assertDatabaseHas('recordatorio', ['id' => $id, 'estado' => 'Programado', 'intentos' => 0]);
    }

    public function test_backfill_programs_existing_future_appointments_without_duplicates_or_emails(): void
    {
        $this->seedVidaSalud();
        $payload = $this->appointmentPayload();
        $appointment = $this->existingAppointment($payload);
        $other = $payload;
        $other['paciente_id'] = $this->otherPatient();
        $other['inicio'] = Clinic::date($payload['inicio'])->addHour()->toIso8601String();
        $withoutEmail = $this->existingAppointment($other);
        $service = app(RecordatorioService::class);
        $first = $service->scheduleExistingAppointments($this->account('recepcion'));
        $this->assertSame(1, $first['creados']);
        $this->assertSame(1, $first['sin_correo']);
        $this->assertDatabaseHas('recordatorio', ['cita_id' => $appointment, 'medio' => 'Correo', 'destinatario' => 'Paciente', 'estado' => 'Programado']);
        $this->assertSame(0, DB::table('recordatorio')->where('cita_id', $withoutEmail)->count());
        $again = $service->scheduleExistingAppointments($this->account('recepcion'));
        $this->assertSame(0, $again['creados']);
        $this->assertSame(1, $again['existentes']);
        $this->assertDatabaseCount('recordatorio', 1);
        $this->assertDatabaseCount('historial_recordatorio', 1);
    }

    public function test_immediate_email_is_only_scheduled_and_past_appointments_are_rejected(): void
    {
        $this->seedVidaSalud();
        $past = $this->historicalAppointment();
        $appointment = $this->existingAppointment($this->appointmentPayload());
        $service = app(RecordatorioService::class);
        $id = $service->create($this->account('recepcion'), ['cita_id' => $appointment, 'destinatario' => 'Paciente', 'medio' => 'Correo', 'momento_tipo' => 'inmediato']);
        $this->assertDatabaseHas('recordatorio', ['id' => $id, 'estado' => 'Programado', 'intentos' => 0]);
        $this->actingAs($this->account('recepcion'))->post(route('reminders.store'), ['cita_id' => $past, 'destinatario' => 'Paciente', 'medio' => 'Correo', 'momento_tipo' => '24h_antes'])->assertSessionHasErrors('cita_id');
        $this->assertSame(0, DB::table('recordatorio')->where('cita_id', $past)->count());
    }

    private function reminder(string $recipient = 'Paciente'): array
    {
        $this->seedVidaSalud();
        $payload = $this->appointmentPayload();
        $appointment = $this->existingAppointment($payload);
        $id = app(RecordatorioService::class)->create($this->account('recepcion'), [
            'cita_id' => $appointment, 'destinatario' => $recipient,
            'medio' => 'Sistema', 'momento_tipo' => '24h_antes',
        ]);

        return [$id, $appointment, $payload];
    }

    public function test_reception_can_read_edit_simulate_failure_retry_and_cancel(): void
    {
        [$id] = $this->reminder();
        $this->actingAs($this->account('recepcion'));
        $this->get(route('reminders.index'))->assertOk();
        $this->get(route('reminders.create'))->assertOk();
        $this->get(route('reminders.show', $id))->assertOk();
        $this->get(route('reminders.edit', $id))->assertOk();
        $this->post(route('reminders.send', $id), ['simular_fallo' => true])->assertRedirect();
        $this->assertDatabaseHas('recordatorio', ['id' => $id, 'estado' => 'Fallido', 'intentos' => 1]);
        $this->post(route('reminders.send', $id))->assertRedirect();
        $this->assertDatabaseHas('recordatorio', ['id' => $id, 'estado' => 'Enviado', 'intentos' => 2]);
        $this->assertDatabaseCount('historial_recordatorio', 3);
    }

    public function test_patient_and_doctor_only_read_reminders_addressed_to_them(): void
    {
        [$id, $appointment] = $this->reminder('Medico');
        $this->actingAs($this->account('paciente'))->get(route('reminders.show', $id))->assertNotFound();
        $this->get(route('appointments.show', $appointment))->assertOk()
            ->assertViewHas('recordatorios', fn ($rows) => $rows->isEmpty());
        $this->actingAs($this->account('medico'))->get(route('reminders.show', $id))->assertOk();
        $this->get(route('reminders.index'))->assertForbidden();
        $this->post(route('reminders.send', $id))->assertForbidden();
    }

    public function test_role_without_management_permission_cannot_read_details(): void
    {
        [$id] = $this->reminder();
        $user = $this->account('recepcion');
        DB::table('rol_permiso')->where('rol_id', $user->rol_id)->delete();
        $this->actingAs($user->fresh())->get(route('reminders.show', $id))->assertForbidden();
    }

    public function test_reprogramming_moves_reminder_by_the_actual_appointment_difference(): void
    {
        [$id, $appointment, $payload] = $this->reminder();
        $before = Clinic::date(DB::table('recordatorio')->where('id', $id)->value('fecha_programada'));
        $user = $this->account('recepcion');
        $key = OperacionWeb::token();
        $operation = 'cita.'.$appointment.'.reprogramar';
        $version = app(\App\Modules\GestionCitasAgendaMedica\Services\CitaService::class)
            ->version(DB::table('cita')->where('id', $appointment)->first());
        $this->actingAs($user)->post(route('appointments.update', $appointment), [
            'accion' => 'reprogramar', 'inicio' => Clinic::date($payload['inicio'])->addHour()->toIso8601String(),
            'version' => $version, 'clave' => $key,
            'firma' => OperacionWeb::signature($user, $operation, $key), 'paso' => 'guardar',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $after = Clinic::date(DB::table('recordatorio')->where('id', $id)->value('fecha_programada'));
        $this->assertSame(3600, $after->getTimestamp() - $before->getTimestamp());
        $this->assertDatabaseHas('historial_recordatorio', ['recordatorio_id' => $id, 'accion' => 'SincronizadoCita']);
    }

    public function test_cancellation_prevents_scheduled_delivery(): void
    {
        [$id] = $this->reminder();
        $this->actingAs($this->account('recepcion'))->post(route('reminders.cancel', $id), [
            'motivo_cancelacion' => 'Cancelación de prueba',
        ])->assertRedirect();
        $this->assertDatabaseHas('recordatorio', ['id' => $id, 'estado' => 'Cancelado']);
        $this->post(route('reminders.send', $id))->assertSessionHasErrors('operacion');
    }
}
