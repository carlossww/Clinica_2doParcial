<?php

namespace App\Modules\GestionMedicosEspecialidades\Controllers;

use App\Modules\Shared\Controllers\CatalogoController;
use App\Modules\Shared\Clinic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HorarioMedicoController extends CatalogoController
{
    protected const MODULO = 'horarios';

    public function confirmDelete(Request $request, int $doctor, ?int $slot = null): View
    {
        Clinic::authorize($request->user(), 'horarios.gestionar');
        $doctorRecord = $this->catalog->query('medicos')->where('t.id', $doctor)->first();
        abort_unless($doctorRecord, 404);
        $schedules = DB::table('horario')->where('medico_id', $doctor)
            ->when($slot !== null, fn ($query) => $query->where('id', $slot))
            ->orderBy('id')->get();
        abort_if($schedules->isEmpty(), 404);
        $fingerprint = $this->fingerprint($schedules);
        $blocked = $schedules->contains(fn ($schedule) => $this->hasPendingAppointments($schedule));

        return view('schedules.confirm-delete', compact('doctorRecord', 'schedules', 'fingerprint', 'blocked', 'slot'));
    }

    public function destroy(Request $request, int $doctor, ?int $slot = null): RedirectResponse
    {
        Clinic::authorize($request->user(), 'horarios.gestionar');
        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:64'], 'confirm_delete' => ['accepted']]);
        Clinic::transaction($request->user(), function () use ($doctor, $slot, $data): void {
            // Mismo bloqueo del médico que usan los disparadores de horarios y citas.
            abort_unless(DB::table('medico')->where('id', $doctor)->lockForUpdate()->first(), 404);
            $schedules = DB::table('horario')->where('medico_id', $doctor)
                ->when($slot !== null, fn ($query) => $query->where('id', $slot))
                ->orderBy('id')->lockForUpdate()->get();
            if ($schedules->isEmpty() || !hash_equals($this->fingerprint($schedules), $data['fingerprint'])) {
                Clinic::fail('Los horarios cambiaron desde que abriste la confirmación. Vuelve al listado y revisa la selección.');
            }
            if ($schedules->contains(fn ($schedule) => $this->hasPendingAppointments($schedule))) {
                Clinic::fail('No se eliminó ninguna franja: hay citas pendientes en estos horarios. Reprograma o cancela esas citas primero.');
            }
            DB::table('horario')->whereIn('id', $schedules->pluck('id'))->delete();
        });

        return redirect()->route('catalog.index', ['module' => 'horarios', 'medico_id' => $doctor])
            ->with('success', $slot !== null ? 'Franja eliminada correctamente.' : 'Se eliminaron todas las franjas de este médico.');
    }

    private function fingerprint(Collection $schedules): string
    {
        return hash('sha256', $schedules->map(fn ($row) => [
            (int) $row->id, (int) $row->medico_id, (int) $row->dia_semana,
            $row->hora_inicio, $row->hora_fin, (int) $row->duracion_minutos, (bool) $row->activo,
        ])->values()->toJson());
    }

    private function hasPendingAppointments(object $schedule): bool
    {
        return DB::table('cita')->where('medico_id', $schedule->medico_id)
            ->whereIn('estado', ['Programada', 'Confirmada'])->whereRaw('inicio >= CURRENT_TIMESTAMP')
            ->whereRaw("extract(isodow FROM inicio AT TIME ZONE 'America/La_Paz') = ?", [$schedule->dia_semana])
            ->whereRaw("(inicio AT TIME ZONE 'America/La_Paz')::time < ?::time", [$schedule->hora_fin])
            ->whereRaw("(fin AT TIME ZONE 'America/La_Paz')::time > ?::time", [$schedule->hora_inicio])->exists();
    }
}
