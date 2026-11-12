@extends('layouts.app')
@section('title', $slot !== null ? 'Eliminar franja' : 'Eliminar horarios del médico')
@push('head')<link rel="stylesheet" href="{{ asset('assets/schedules.css') }}?v={{ substr(hash_file('sha256', public_path('assets/schedules.css')), 0, 16) }}">@endpush
@section('content')
<div class="medical-schedules">
    <x-page-heading :title="$slot !== null ? 'Eliminar esta franja' : 'Eliminar todas las franjas'" :description="'Médico: '.$doctorRecord->nombre" :back="route('catalog.index', ['module' => 'horarios', 'medico_id' => $doctorRecord->id])" />
    <section class="panel week-delete-panel">
        <h2>{{ $slot !== null ? 'Revisa la franja que vas a eliminar' : 'Revisa los horarios de este médico' }}</h2>
        <p>{{ $slot !== null ? 'Se eliminará únicamente la franja indicada.' : 'Se eliminarán todas las franjas de este médico, incluidas las inactivas, aunque el listado tuviera filtros.' }} Esta acción no se puede deshacer.</p>
        <ul class="week-delete-list">
        @foreach($schedules->sortBy(fn ($schedule) => sprintf('%d-%s', $schedule->dia_semana, $schedule->hora_inicio)) as $schedule)
            <li><strong>{{ config('clinic.catalogs.horarios.fields.dia_semana.options')[$schedule->dia_semana] }}</strong><span>{{ substr($schedule->hora_inicio, 0, 5) }} – {{ substr($schedule->hora_fin, 0, 5) }}</span><span>{{ $schedule->duracion_minutos }} min por cita · {{ $schedule->activo ? 'Activo' : 'Inactivo' }}</span></li>
        @endforeach
        </ul>
        @if($blocked)
            <div class="alert alert-warning" role="alert"><p>Hay citas pendientes en estos horarios. Reprograma o cancela esas citas antes de eliminar las franjas. No se ha eliminado ninguna.</p></div>
            <a class="button" href="{{ route('catalog.index', ['module' => 'horarios', 'medico_id' => $doctorRecord->id]) }}">Volver a horarios</a>
        @else
            <form method="POST" action="{{ $slot !== null ? route('schedules.slot.destroy', ['doctor' => $doctorRecord->id, 'slot' => $slot]) : route('schedules.doctor.destroy', $doctorRecord->id) }}" class="form-stack" data-submit-form>
                @csrf
                @method('DELETE')
                <input type="hidden" name="fingerprint" value="{{ $fingerprint }}">
                <x-field name="confirm_delete" label="Confirmo que quiero eliminar los horarios indicados" type="checkbox" :value="false" :required="true" scope="schedule-delete" />
                <div class="form-actions"><button type="submit" class="button button-danger">{{ $slot !== null ? 'Eliminar franja' : 'Eliminar las '.$schedules->count().' franjas' }}</button><a class="button" href="{{ route('catalog.index', ['module' => 'horarios', 'medico_id' => $doctorRecord->id]) }}">Cancelar</a></div>
            </form>
        @endif
    </section>
</div>
@endsection
