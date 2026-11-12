@extends('layouts.app')
@section('title', 'Horarios médicos')
@push('head')<link rel="stylesheet" href="{{ asset('assets/schedules.css') }}?v={{ substr(hash_file('sha256', public_path('assets/schedules.css')), 0, 16) }}">@endpush
@section('content')
<div class="medical-schedules">
    <x-page-heading title="Horarios médicos" description="Toda la semana de cada médico, en un solo lugar.">
        @if($canWrite)<a class="button button-primary" href="{{ route('catalog.create', ['module' => 'horarios']) }}"><x-icon name="plus" />Añadir horario</a>@endif
    </x-page-heading>

    <form method="GET" action="{{ route('catalog.index', ['module' => 'horarios']) }}" class="week-filters">
        <x-field name="medico_id" label="Médico" type="select" :options="['' => 'Todos los médicos'] + $doctors" :value="$filters['medico_id'] ?? null" />
        <x-field name="q" label="Buscar" type="search" placeholder="Nombre u hora…" :value="$filters['q'] ?? null" />
        <x-field name="dia_semana" label="Día" type="select" :options="['' => 'Toda la semana'] + config('clinic.catalogs.horarios.fields.dia_semana.options')" :value="$filters['dia_semana'] ?? null" />
        <div class="filter-actions"><button type="submit" class="button button-primary"><x-icon name="search" />Filtrar</button>@if(array_filter(Arr::except($filters, ['page'])))<a href="{{ route('catalog.index', 'horarios') }}" class="week-text-link">Limpiar</a>@endif</div>
    </form>

    <div class="week-list-heading"><p>{{ $rows->total() }} {{ $rows->total() === 1 ? 'médico con horarios' : 'médicos con horarios' }}</p><span><i class="week-legend-dot" aria-hidden="true"></i> Atención activa <span class="week-inactive-legend">· Inactivos en gris</span></span></div>

    <div class="week-doctors">
    @forelse($rows as $doctor)
        @php($doctorSchedules = $schedules->get($doctor->id, collect()))
        <article class="doctor-week" aria-labelledby="doctor-week-{{ $doctor->id }}">
            <header class="doctor-week-header">
                <div class="doctor-week-identity"><span class="doctor-week-avatar" aria-hidden="true">{{ mb_substr($doctor->nombres, 0, 1) }}{{ mb_substr($doctor->apellidos, 0, 1) }}</span><div><h2 id="doctor-week-{{ $doctor->id }}">{{ $doctor->nombres }} {{ $doctor->apellidos }}</h2><p>{{ $doctorSchedules->where('activo', true)->pluck('dia_semana')->unique()->count() }} días de atención{{ !empty($filters['dia_semana']) || !empty($filters['q']) ? ' en este resultado' : ' por semana' }}</p></div></div>
                @if($canWrite)
                    <div class="week-doctor-actions">
                        <a class="week-text-link" href="{{ route('catalog.create', ['module' => 'horarios', 'medico_id' => $doctor->id]) }}"><x-icon name="plus" />Añadir franja</a>
                        <a class="week-delete-link" href="{{ route('schedules.doctor.confirm-delete', $doctor->id) }}">Eliminar todas</a>
                    </div>
                @endif
            </header>
            <div class="doctor-week-grid">
                @foreach(config('clinic.catalogs.horarios.fields.dia_semana.options') as $day => $label)
                    @php($daySchedules = $doctorSchedules->where('dia_semana', $day))
                    @php($filteredDay = !empty($filters['dia_semana']) && (int) $filters['dia_semana'] !== (int) $day)
                    <section class="week-day {{ $filteredDay ? 'week-day-filtered' : '' }}" aria-label="{{ $label }}">
                        <h3>{{ $label }}</h3>
                        <div class="week-day-slots">
                        @forelse($daySchedules as $slot)
                            <div class="week-time {{ !$slot->activo ? 'week-time-inactive' : '' }}">
                                <strong>{{ substr($slot->hora_inicio, 0, 5) }} <span>–</span> {{ substr($slot->hora_fin, 0, 5) }}</strong>
                                <span class="week-time-detail">{{ $slot->duracion_minutos }} min por cita</span>
                                @if(!$slot->activo)<span class="week-time-detail">Inactivo</span>@endif
                                @if($canWrite)
                                    <div class="week-slot-actions">
                                        <a href="{{ route('catalog.edit', ['module' => 'horarios', 'id' => $slot->id]) }}" aria-label="Editar franja del {{ $label }} de {{ substr($slot->hora_inicio, 0, 5) }} a {{ substr($slot->hora_fin, 0, 5) }} de {{ $doctor->nombres }} {{ $doctor->apellidos }}">Editar</a>
                                        <a class="week-delete-link" href="{{ route('schedules.slot.confirm-delete', ['doctor' => $doctor->id, 'slot' => $slot->id]) }}" aria-label="Eliminar franja del {{ $label }} de {{ substr($slot->hora_inicio, 0, 5) }} a {{ substr($slot->hora_fin, 0, 5) }} de {{ $doctor->nombres }} {{ $doctor->apellidos }}">Eliminar</a>
                                    </div>
                                @endif
                            </div>
                        @empty
                            <p class="week-day-empty">{{ $filteredDay ? 'Fuera del filtro' : ((!empty($filters['q']) || !empty($filters['dia_semana'])) ? 'Sin coincidencias' : 'Sin atención') }}</p>
                        @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </article>
    @empty
        <div class="panel"><x-empty-state title="No hay horarios para mostrar" message="Prueba con otro médico o limpia los filtros para ver la semana completa." /></div>
    @endforelse
    </div>
    {{ $rows->links('pagination.default') }}
</div>
@endsection
