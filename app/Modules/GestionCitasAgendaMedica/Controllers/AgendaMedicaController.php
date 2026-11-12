<?php

namespace App\Modules\GestionCitasAgendaMedica\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\GestionCitasAgendaMedica\Services\ConsultaAgendaService;
use App\Modules\Shared\Clinic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AgendaMedicaController extends Controller
{
    public function __construct(private ConsultaAgendaService $appointments) {}

    public function index(Request $request): View|Response|JsonResponse
    {
        return $this->agenda($request);
    }

    public function doctor(Request $request, int $id): View|Response|JsonResponse
    {
        return $this->agenda($request, $id);
    }

    private function agenda(Request $request, ?int $doctorId = null): View|Response|JsonResponse
    {
        $scope = $this->appointments->doctorScope($request->user());
        if ($scope !== null) {
            abort_if($doctorId !== null && $scope !== $doctorId, 404);
        }
        $filters = $this->appointments->filters($request);
        $filters['vista'] ??= 'agenda';
        $doctorHistory = $filters['vista'] === 'historial';
        if ($scope !== null && ! empty($filters['medico_id'])) {
            abort_if((int) $filters['medico_id'] !== $scope, 404);
        }
        if ($doctorId !== null && ! empty($filters['medico_id'])) {
            abort_if((int) $filters['medico_id'] !== $doctorId, 404);
        }
        $fixedDoctor = $scope ?? $doctorId;
        $doctor = $fixedDoctor === null ? null : DB::table('medico as m')
            ->join('usuario as u', 'u.id', '=', 'm.usuario_id')->join('rol as r', 'r.id', '=', 'u.rol_id')->where('m.id', $fixedDoctor)
            ->select('m.id')->selectRaw("concat_ws(' ', u.nombres, u.apellidos) as nombre, (m.activo AND u.activo AND r.activo) as habilitado")->first();
        abort_if($fixedDoctor !== null && ! $doctor, 404);
        if (! $doctorHistory && ! $request->has('desde') && ! $request->has('hasta')) {
            $filters['desde'] = $filters['hasta'] = now('America/La_Paz')->toDateString();
        }
        $weekly = ! $doctorHistory && ($filters['periodo'] ?? 'dia') === 'semana';
        if ($weekly) {
            $start = Clinic::date($filters['desde'] ?? now('America/La_Paz')->toDateString())->startOfWeek();
            $filters['desde'] = $start->toDateString();
            $filters['hasta'] = $start->copy()->addDays(6)->toDateString();
        }
        if ($fixedDoctor !== null) {
            $filters['medico_id'] = $fixedDoctor;
        } elseif (! empty($filters['medico_id'])) {
            $request->validate(['medico_id' => ['exists:medico,id']]);
        }
        $selectedId = $filters['medico_id'] ?? null;
        $selected = $doctor ?? ($selectedId ? DB::table('medico as m')
            ->join('usuario as u', 'u.id', '=', 'm.usuario_id')->join('rol as r', 'r.id', '=', 'u.rol_id')
            ->where('m.id', $selectedId)->select('m.id')
            ->selectRaw('(m.activo AND u.activo AND r.activo) as habilitado')->first() : null);
        if (! $doctorHistory && $selected && ! $selected->habilitado) {
            $message = 'El médico seleccionado no está habilitado. Selecciona otro médico o consulta su historial de citas.';
            $historyUrl = route($doctorId === null ? 'agenda.index' : 'agenda.doctor',
                array_merge($doctorId === null ? [] : ['id' => $doctorId], array_diff_key($filters, ['page' => true]), ['vista' => 'historial']));
            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'errors' => ['medico_id' => [$message]], 'historial' => $historyUrl], 422);
            }

            $canSelectDoctor = $scope === null;
            $doctors = $canSelectDoctor ? Clinic::doctors() : [];

            return response()->view('agenda.unavailable', compact('message', 'historyUrl', 'canSelectDoctor', 'doctors'), 422);
        }
        $showReason = $scope !== null && $request->user()->hasPermission('atencion.registrar');
        $query = $this->appointments->query($showReason);
        if (! $doctorHistory) {
            $this->appointments->enabledDoctors($query);
        }
        $this->appointments->filter($query, $filters);
        $summary = (clone $query)->reorder()->select('c.estado')->selectRaw('count(*) as cantidad')->groupBy('c.estado')->pluck('cantidad', 'c.estado')->all();
        if ($weekly) {
            $items = $query->orderBy('c.inicio')->orderBy('c.id')->get();
            $rows = new \Illuminate\Pagination\LengthAwarePaginator($items, $items->count(), max(1, $items->count()), 1, ['path' => $request->url()]);
        } else {
            $rows = $query->orderBy('c.inicio')->orderBy('c.id')->paginate(20);
        }
        $rows->appends($filters);
        $canManage = $request->user()->hasPermission('citas.gestionar') && ! $request->user()->isDoctor() && ! $request->user()->isPatient();
        $slots = [];
        $availabilitySpecialties = [];
        if (! $doctorHistory && $selectedId && ! empty($filters['desde']) && $filters['desde'] === ($filters['hasta'] ?? null)) {
            $availabilitySpecialties = DB::table('medico_especialidad as me')->join('especialidad as e', 'e.id', '=', 'me.especialidad_id')
                ->where('me.medico_id', $selectedId)->where('me.activo', true)->where('e.activo', true)
                ->when(! empty($filters['especialidad_id']), fn ($q) => $q->where('e.id', $filters['especialidad_id']))
                ->orderBy('e.nombre')->pluck('e.nombre', 'e.id')->all();
            if ($availabilitySpecialties) {
                $slots = app(\App\Modules\GestionCitasAgendaMedica\Services\CitaService::class)->slots((int) $selectedId, $filters['desde'], 0);
            }
        }
        $specialties = Clinic::specialties();
        $title = $doctorHistory
            ? ($doctor ? 'Historial de citas de '.$doctor->nombre.($doctor->habilitado ? '' : ' (inactivo)') : 'Historial de citas por médico')
            : ($doctor ? 'Agenda de '.$doctor->nombre : 'Agenda médica');
        $backUrl = $scope === null && $doctorId !== null ? route('catalog.index', 'medicos') : route('home');
        $filterUrl = $doctorId === null ? route('agenda.index') : route('agenda.doctor', $doctorId);
        $doctors = $fixedDoctor === null ? Clinic::doctors(includeInactive: $doctorHistory) : null;
        $isHistory = false;
        $states = array_combine(ConsultaAgendaService::STATES, ConsultaAgendaService::STATES);

        return view('agenda.dashboard', compact('rows', 'filters', 'title', 'backUrl', 'filterUrl', 'doctors', 'isHistory', 'showReason', 'states', 'doctorHistory', 'summary', 'canManage', 'slots', 'availabilitySpecialties', 'specialties', 'weekly'));
    }
}
