@extends('layouts.app')
@section('title', $title)
@push('head')
<link rel="stylesheet" href="{{ asset('assets/agenda.css') }}?v={{ substr(hash_file('sha256', public_path('assets/agenda.css')),0,16) }}">
@endpush
@section('content')
@php
    $base = array_diff_key($filters, ['page'=>true]);
    $link = fn(array $changes) => $filterUrl.'?'.http_build_query(array_merge($base, $changes));
    $anchor = \App\Modules\Shared\Clinic::date($filters['desde'] ?? now('America/La_Paz')->toDateString());
    $step = $weekly ? 7 : 1;
    $singleDay = !empty($filters['desde']) && $filters['desde'] === ($filters['hasta'] ?? null);
@endphp
<x-page-heading :title="$title" description="Organiza los turnos y consulta el seguimiento de las citas." :back="$backUrl">
@if($canManage)<a class="button button-primary" href="{{ route('appointments.create', array_filter(['medico_id'=>$filters['medico_id'] ?? null, 'especialidad_id'=>$filters['especialidad_id'] ?? null, 'fecha'=>$filters['desde'] ?? null])) }}">Agendar cita</a>@endif
</x-page-heading>
<nav class="button-row agenda-tabs" aria-label="Tipo de consulta de citas">
<a class="button {{ !$doctorHistory ? 'button-primary' : '' }}" href="{{ $link(['vista'=>'agenda']) }}" @if(!$doctorHistory) aria-current="page" @endif>Agenda médica</a>
<a class="button {{ $doctorHistory ? 'button-primary' : '' }}" href="{{ $link(['vista'=>'historial']) }}" @if($doctorHistory) aria-current="page" @endif>Historial de citas por médico</a>
</nav>
@if(!$doctorHistory)
<section class="agenda-toolbar" aria-label="Navegar por fechas">
<div><p class="agenda-eyebrow">{{ $weekly ? 'Semana de atención' : 'Fecha de atención' }}</p><h2>{{ $singleDay ? $anchor->format('d/m/Y') : ($filters['desde'] ?? 'Inicio abierto').' — '.($filters['hasta'] ?? 'Fin abierto') }}</h2></div>
<nav class="button-row" aria-label="Cambiar período">
<a class="button" href="{{ $link(['desde'=>$anchor->copy()->subDays($step)->toDateString(),'hasta'=>$anchor->copy()->subDays($step)->toDateString()]) }}">{{ $weekly ? 'Semana anterior' : 'Día anterior' }}</a>
<a class="button" href="{{ $link(['desde'=>now('America/La_Paz')->toDateString(),'hasta'=>now('America/La_Paz')->toDateString(),'periodo'=>'dia']) }}">Hoy</a>
<a class="button" href="{{ $link(['desde'=>$anchor->copy()->addDays($step)->toDateString(),'hasta'=>$anchor->copy()->addDays($step)->toDateString()]) }}">{{ $weekly ? 'Semana siguiente' : 'Día siguiente' }}</a>
</nav>
<nav class="button-row" aria-label="Vista de agenda">
<a class="button {{ !$weekly ? 'button-primary' : '' }}" href="{{ $link(['periodo'=>'dia','desde'=>$anchor->toDateString(),'hasta'=>$anchor->toDateString()]) }}" @if(!$weekly) aria-current="page" @endif>Día</a>
<a class="button {{ $weekly ? 'button-primary' : '' }}" href="{{ $link(['periodo'=>'semana']) }}" @if($weekly) aria-current="page" @endif>Semana</a>
</nav>
</section>
@endif
<form method="GET" action="{{ $filterUrl }}" class="filters agenda-filters">
<input type="hidden" name="vista" value="{{ $doctorHistory ? 'historial' : 'agenda' }}">
@if(!$doctorHistory)<input type="hidden" name="periodo" value="{{ $weekly ? 'semana' : 'dia' }}">@endif
<div class="filter-grid">
<x-field name="q" label="Buscar paciente" :value="$filters['q'] ?? null" maxlength="100" placeholder="Nombre o apellido" />
@if($doctors !== null)<x-field name="medico_id" label="Médico" type="select" :options="[''=>'Todos los médicos']+$doctors" :value="$filters['medico_id'] ?? null" />@endif
<x-field name="especialidad_id" label="Especialidad" type="select" :options="[''=>'Todas las especialidades']+$specialties" :value="$filters['especialidad_id'] ?? null" />
<x-field name="estado" label="Estado de cita" type="select" :options="[''=>'Todos los estados']+$states" :value="$filters['estado'] ?? null" />
<x-field name="desde" :label="$weekly ? 'Semana que contiene' : 'Desde'" type="date" :value="$filters['desde'] ?? null" />
@if(!$weekly || $doctorHistory)<x-field name="hasta" label="Hasta" type="date" :value="$filters['hasta'] ?? null" />@endif
<div class="filter-actions"><button class="button button-primary">Filtrar</button><a class="button" href="{{ $filterUrl.'?vista='.($doctorHistory ? 'historial' : 'agenda') }}">Limpiar</a></div>
</div>
</form>
<section class="agenda-summary" aria-label="Resumen de las citas filtradas">
@foreach(['Total'=>array_sum($summary), 'Programadas'=>$summary['Programada'] ?? 0, 'Confirmadas'=>$summary['Confirmada'] ?? 0, 'Atendidas'=>$summary['Atendida'] ?? 0, 'Inasistencias'=>$summary['Inasistencia'] ?? 0, 'Canceladas'=>$summary['Cancelada'] ?? 0] as $label=>$count)
<div><span>{{ $label }}</span><strong>{{ $count }}</strong></div>
@endforeach
</section>
<p class="text-muted">Resumen del período y filtros seleccionados. {{ $rows->total() }} {{ $rows->total() === 1 ? 'cita encontrada' : 'citas encontradas' }}.</p>
@if($weekly)
<div class="agenda-week">
@for($day=0; $day<7; $day++)
@php($date = $anchor->copy()->addDays($day))
<section class="agenda-day"><h2>{{ ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'][$day] }} <span>{{ $date->format('d/m') }}</span></h2>
@forelse($rows->filter(fn($row)=>\App\Modules\Shared\Clinic::date($row->inicio)->toDateString() === $date->toDateString()) as $row)
@include('agenda.card')
@empty<p class="text-muted">Sin citas.</p>@endforelse
</section>
@endfor
</div>
@else
<div class="agenda-list">
@forelse($rows as $row)
@include('agenda.card')
@empty<x-empty-state title="Sin citas para mostrar" message="No hay citas que coincidan con los filtros seleccionados. Prueba otra fecha o limpia los filtros." icon="calendar" />@endforelse
</div>
{{ $rows->links('pagination.default') }}
@endif
@if(!$doctorHistory)
<section class="agenda-availability" aria-labelledby="agenda-availability-title">
<h2 id="agenda-availability-title">Turnos disponibles</h2>
@if(!empty($filters['medico_id']) && $singleDay)
<p class="text-muted">Disponibilidad del médico para el {{ $anchor->format('d/m/Y') }}. Al elegir paciente se revisarán también sus cruces de horario. Los turnos pueden cambiar antes de confirmar.</p>
@if($slots)
@if($canManage)
<form method="GET" action="{{ route('appointments.create') }}" class="agenda-booking">
<input type="hidden" name="medico_id" value="{{ $filters['medico_id'] }}"><input type="hidden" name="fecha" value="{{ $filters['desde'] }}">
<x-field name="especialidad_id" label="Especialidad para reservar" type="select" :options="$availabilitySpecialties" :value="count($availabilitySpecialties) === 1 ? array_key_first($availabilitySpecialties) : ($filters['especialidad_id'] ?? null)" required />
<button class="button button-primary">Elegir paciente y reservar</button>
</form>
@endif
<ul class="agenda-slots" aria-label="Horarios libres">@foreach($slots as $start=>$label)<li>{{ $label }}</li>@endforeach</ul>
@else<p>No hay turnos disponibles para esta fecha y especialidad. Consulta otro día o revisa los horarios del médico.</p>@endif
@else<p class="text-muted">Selecciona un médico y un único día para consultar sus turnos libres.</p>@endif
</section>
@endif
@endsection
