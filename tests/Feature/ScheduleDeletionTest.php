<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class ScheduleDeletionTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    public function test_existing_slot_can_be_edited_and_its_actions_are_visible(): void
    {
        $this->seedVidaSalud();
        $schedule = DB::table('horario')->first();
        $params = ['module' => 'horarios', 'id' => $schedule->id];
        $this->actingAs($this->account('admin'))->get(route('catalog.index', 'horarios'))
            ->assertOk()->assertSee('Editar')->assertSee('Eliminar todas');
        DB::table('medico')->where('id', $schedule->medico_id)->update(['activo' => false]);
        $this->get(route('catalog.edit', $params))->assertOk()->assertSee('Guardar cambios')->assertSee('Eliminar esta franja');
        $this->put(route('catalog.update', $params), [
            'medico_id' => $schedule->medico_id, 'dia_semana' => $schedule->dia_semana,
            'hora_inicio' => '13:00', 'hora_fin' => '16:00', 'duracion_minutos' => 45, 'activo' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('horario', ['id' => $schedule->id, 'hora_inicio' => '13:00', 'hora_fin' => '16:00', 'duracion_minutos' => 45]);
    }

    public function test_one_slot_can_be_deleted_without_touching_other_days(): void
    {
        $this->seedVidaSalud();
        $schedule = DB::table('horario')->first();
        $count = DB::table('horario')->count();
        $params = ['doctor' => $schedule->medico_id, 'slot' => $schedule->id];
        $preview = $this->actingAs($this->account('admin'))->get(route('schedules.slot.confirm-delete', $params));
        $preview->assertOk()->assertSee('Eliminar esta franja');
        $this->delete(route('schedules.slot.destroy', $params), ['fingerprint' => $preview->viewData('fingerprint'), 'confirm_delete' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('horario', ['id' => $schedule->id]);
        $this->assertDatabaseCount('horario', $count - 1);
    }

    public function test_all_slots_of_one_doctor_can_be_deleted_including_inactive_slots(): void
    {
        $this->seedVidaSalud();
        $doctor = DB::table('medico')->value('id');
        $other = $this->otherDoctor();
        DB::table('horario')->insert(['medico_id' => $other, 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);
        $first = DB::table('horario')->where('medico_id', $doctor)->value('id');
        DB::table('horario')->where('id', $first)->update(['activo' => false]);
        $preview = $this->actingAs($this->account('admin'))->get(route('schedules.doctor.confirm-delete', $doctor));
        $preview->assertOk();
        $this->delete(route('schedules.doctor.destroy', $doctor), ['fingerprint' => $preview->viewData('fingerprint'), 'confirm_delete' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('horario')->where('medico_id', $doctor)->exists());
        $this->assertDatabaseHas('horario', ['medico_id' => $other]);
    }

    public function test_pending_appointment_blocks_entire_deletion_even_after_confirmation_was_opened(): void
    {
        $this->seedVidaSalud();
        $payload = $this->appointmentPayload();
        $doctor = $payload['medico_id'];
        DB::table('horario')->insert(['medico_id' => $doctor, 'dia_semana' => 2, 'hora_inicio' => '09:00', 'hora_fin' => '12:00']);
        $preview = $this->actingAs($this->account('admin'))->get(route('schedules.doctor.confirm-delete', $doctor));
        $appointment = $this->existingAppointment($payload);
        $this->delete(route('schedules.doctor.destroy', $doctor), ['fingerprint' => $preview->viewData('fingerprint'), 'confirm_delete' => 1])
            ->assertSessionHasErrors('operacion');
        $this->assertSame(2, DB::table('horario')->where('medico_id', $doctor)->count());
        $this->assertDatabaseHas('cita', ['id' => $appointment, 'estado' => 'Programada']);
        $this->get(route('schedules.doctor.confirm-delete', $doctor))->assertOk()->assertViewHas('blocked', true);
    }

    public function test_changed_selection_requires_a_new_confirmation(): void
    {
        $this->seedVidaSalud();
        $doctor = DB::table('medico')->value('id');
        $preview = $this->actingAs($this->account('admin'))->get(route('schedules.doctor.confirm-delete', $doctor));
        DB::table('horario')->insert(['medico_id' => $doctor, 'dia_semana' => 6, 'hora_inicio' => '09:00', 'hora_fin' => '12:00']);
        $count = DB::table('horario')->count();
        $this->delete(route('schedules.doctor.destroy', $doctor), ['fingerprint' => $preview->viewData('fingerprint'), 'confirm_delete' => 1])
            ->assertSessionHasErrors('operacion');
        $this->assertDatabaseCount('horario', $count);
    }

    public function test_deletion_requires_confirmation_and_schedule_permission(): void
    {
        $this->seedVidaSalud();
        $schedule = DB::table('horario')->first();
        $doctor = $schedule->medico_id;
        $params = ['doctor' => $doctor, 'slot' => $schedule->id];
        $this->actingAs($this->account('recepcion'))->get(route('schedules.doctor.confirm-delete', $doctor))->assertForbidden();
        $this->delete(route('schedules.doctor.destroy', $doctor), ['fingerprint' => str_repeat('0', 64), 'confirm_delete' => 1])->assertForbidden();
        $this->get(route('schedules.slot.confirm-delete', $params))->assertForbidden();
        $this->delete(route('schedules.slot.destroy', $params), ['fingerprint' => str_repeat('0', 64), 'confirm_delete' => 1])->assertForbidden();
        $this->actingAs($this->account('admin'))->delete(route('schedules.doctor.destroy', $doctor))
            ->assertSessionHasErrors(['confirm_delete', 'fingerprint']);
        $this->assertDatabaseHas('horario', ['id' => $schedule->id]);
    }

    public function test_slot_cannot_be_deleted_under_a_different_doctor(): void
    {
        $this->seedVidaSalud();
        $schedule = DB::table('horario')->first();
        $other = $this->otherDoctor();
        $this->actingAs($this->account('admin'))->get(route('schedules.slot.confirm-delete', ['doctor' => $other, 'slot' => $schedule->id]))->assertNotFound();
        $this->assertDatabaseHas('horario', ['id' => $schedule->id]);
    }
}
