<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class ScheduleQueriesTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    public static function administrativeAccounts(): array
    {
        return [['admin'], ['recepcion']];
    }

    #[DataProvider('administrativeAccounts')]
    public function test_schedules_filter_by_doctor_and_day_and_search_full_names(string $login): void
    {
        $this->seedVidaSalud();
        $doctor = DB::table('medico')->value('id');
        $other = $this->otherDoctor();
        DB::table('horario')->insert(['medico_id' => $other, 'dia_semana' => 1, 'hora_inicio' => '14:00', 'hora_fin' => '16:00']);
        $this->actingAs($this->account($login))->get(route('catalog.index', ['module' => 'horarios', 'medico_id' => $doctor, 'dia_semana' => 1]))
            ->assertOk()->assertSee('Todos los médicos')->assertSee('Toda la semana')
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && (int) $rows->first()->id === (int) $doctor)
            ->assertViewHas('schedules', fn ($schedules) => $schedules->get($doctor)->count() === 1);
        $this->get(route('catalog.index', ['module' => 'horarios', 'q' => '  Dr   Uno  ']))
            ->assertOk()->assertViewHas('schedules', fn ($schedules) => $schedules->get($doctor)->count() === 5);
        $this->get(route('catalog.index', ['module' => 'horarios', 'q' => '14:00', 'medico_id' => $other]))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    public function test_inactive_doctor_schedules_remain_selectable_for_reading(): void
    {
        $this->seedVidaSalud();
        $doctor = DB::table('medico')->value('id');
        DB::table('medico')->where('id', $doctor)->update(['activo' => false]);
        $this->actingAs($this->account('recepcion'))->get(route('catalog.index', ['module' => 'horarios', 'medico_id' => $doctor]))
            ->assertOk()->assertSee('Dr Uno (inactivo)')->assertViewHas('schedules', fn ($schedules) => $schedules->get($doctor)->count() === 5);
        $this->get(route('catalog.create', 'horarios'))->assertForbidden();
    }

    public static function invalidFilters(): array
    {
        return [[['medico_id' => 999999]], [['medico_id' => ['1']]], [['dia_semana' => 8]], [['dia_semana' => 0]], [['q' => ['nombre']]], [['page' => 0]]];
    }

    #[DataProvider('invalidFilters')]
    public function test_schedule_filters_reject_invalid_values(array $filters): void
    {
        $this->seedVidaSalud();
        $this->actingAs($this->account('admin'))->getJson(route('catalog.index', ['module' => 'horarios', ...$filters]))->assertUnprocessable();
    }

    public function test_schedule_pagination_keeps_only_validated_filters(): void
    {
        $this->seedVidaSalud();
        $doctor = DB::table('medico')->value('id');
        DB::table('horario')->where('medico_id', $doctor)->delete();
        for ($hour = 0; $hour < 16; $hour++) {
            DB::table('horario')->insert(['medico_id' => $doctor, 'dia_semana' => 1, 'hora_inicio' => sprintf('%02d:00', $hour), 'hora_fin' => sprintf('%02d:00', $hour + 1)]);
        }
        $params = ['module' => 'horarios', 'medico_id' => $doctor, 'dia_semana' => 1, 'externo' => 'NO-PROPAGAR'];
        $this->actingAs($this->account('recepcion'))->get(route('catalog.index', $params))
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->count() === 1)
            ->assertViewHas('schedules', fn ($schedules) => $schedules->get($doctor)->count() === 16)
            ->assertViewHas('rows', fn ($rows) => str_contains($rows->url(2), 'medico_id='.$doctor)
                && str_contains($rows->url(2), 'dia_semana=1') && !str_contains($rows->url(2), 'NO-PROPAGAR'))
            ->assertDontSee('NO-PROPAGAR');
        $this->get(route('catalog.index', [...$params, 'page' => 2]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 0);
    }

    public function test_read_only_view_has_no_edit_links(): void
    {
        $this->seedVidaSalud();
        $this->actingAs($this->account('recepcion'))->get(route('catalog.index', 'horarios'))
            ->assertOk()->assertDontSee('/franjas/')->assertDontSee('Añadir franja')->assertDontSee('Eliminar todas');
    }

    public function test_weekly_creation_is_atomic_when_one_day_conflicts(): void
    {
        $this->seedVidaSalud();
        $doctor = DB::table('medico')->value('id');
        $count = DB::table('horario')->count();
        $payload = ['medico_id' => $doctor, 'dias_semana' => [6, 1], 'hora_inicio' => '09:00', 'hora_fin' => '12:00', 'duracion_minutos' => 30, 'activo' => 1];
        $this->actingAs($this->account('admin'))->post(route('catalog.store', 'horarios'), $payload)
            ->assertSessionHasErrors('dias_semana');
        $this->assertDatabaseCount('horario', $count);
        $this->post(route('catalog.store', 'horarios'), [...$payload, 'dias_semana' => [6, 7]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('horario', $count + 2);
    }
}
