@extends('layouts.app')
@section('title', $record ? 'Editar horario' : 'Añadir horario')
@push('head')<link rel="stylesheet" href="{{ asset('assets/schedules.css') }}?v={{ substr(hash_file('sha256', public_path('assets/schedules.css')), 0, 16) }}">@endpush
@section('content')
<div class="medical-schedules">
    <x-page-heading :title="$record ? 'Editar horario' : 'Añadir horario'" :description="$record ? 'Ajusta esta franja de atención del médico.' : 'Selecciona los días, elige las horas y listo.'" :back="route('catalog.index', 'horarios')" />
    <form method="POST" action="{{ $record ? route('catalog.update', ['module' => 'horarios', 'id' => $record->id]) : route('catalog.store', 'horarios') }}" data-submit-form data-schedule-editor class="week-editor-layout">
        @csrf
        @if($record) @method('PUT') @endif
        <div class="week-editor-main">
            <section class="week-form-section">
                <div class="week-section-title"><span>1</span><div><h2>¿Para qué médico?</h2><p>Su disponibilidad se mostrará en la semana.</p></div></div>
                @if($record)
                    <input type="hidden" name="medico_id" value="{{ $record->medico_id }}" data-doctor-label="{{ $record->medico }}">
                    <p class="week-fixed-doctor">{{ $record->medico }}</p>
                @else
                    <x-field name="medico_id" label="Médico" type="select" :required="true" :options="$options['medico_id']" :value="array_key_exists((string) request('medico_id'), $options['medico_id']) ? request('medico_id') : null" />
                @endif
            </section>
            <section class="week-form-section">
                <div class="week-section-title"><span>2</span><div><h2>¿Qué días atiende?</h2><p>{{ $record ? 'Esta edición corresponde a un solo día.' : 'La misma franja se aplicará a todos los días que elijas.' }}</p></div></div>
                @if($record)
                    <x-field name="dia_semana" label="Día de atención" type="select" :required="true" :options="$definition['fields']['dia_semana']['options']" :value="$record->dia_semana" />
                    @error('dias_semana')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @else
                    <fieldset class="week-days-fieldset">
                        <legend class="sr-only">Días de atención obligatorios</legend>
                        <div class="week-day-presets" data-day-presets hidden><button type="button" data-days="1,2,3,4,5">Lunes a viernes</button><button type="button" data-days="6,7">Fin de semana</button><button type="button" data-days="1,2,3,4,5,6,7">Todos</button><button type="button" data-days="">Limpiar</button></div>
                        <div class="week-pick-days">
                            @foreach($definition['fields']['dia_semana']['options'] as $day => $label)
                                <label><input type="checkbox" name="dias_semana[]" value="{{ $day }}" @checked(in_array((string) $day, array_map('strval', old('dias_semana', [])), true)) aria-label="{{ $label }}"><span><b>{{ mb_substr($label, 0, 3) }}</b><small aria-hidden="true">✓</small></span></label>
                            @endforeach
                        </div>
                        @error('dias_semana')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        @error('dias_semana.*')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </fieldset>
                @endif
            </section>
            <section class="week-form-section">
                <div class="week-section-title"><span>3</span><div><h2>¿En qué horario?</h2><p>Define una franja continua de atención.</p></div></div>
                <div class="week-hours-fields">
                    <x-field name="hora_inicio" label="Desde" type="time" :required="true" :value="$record->hora_inicio ?? '08:00'" />
                    <span class="week-hours-arrow" aria-hidden="true">→</span>
                    <x-field name="hora_fin" label="Hasta" type="time" :required="true" :value="$record->hora_fin ?? '12:00'" />
                </div>
                <x-field name="duracion_minutos" label="Duración de cada cita" type="select" :required="true" :options="$definition['fields']['duracion_minutos']['options']" :value="$record->duracion_minutos ?? 30" />
                <div class="week-active-option"><x-field name="activo" label="Horario activo" type="checkbox" :value="$record->activo ?? true" /><p>Los horarios activos se usan para ofrecer turnos.</p></div>
            </section>
        </div>
        <aside class="week-editor-summary">
            <div class="week-summary-icon"><x-icon name="calendar" /></div>
            <p class="week-summary-eyebrow">Así quedará tu horario</p>
            <h2 data-summary-doctor>Selecciona un médico</h2>
            <p class="week-summary-days" data-summary-days>Elige los días de atención</p>
            <p class="week-summary-time" data-summary-time>{{ $record->hora_inicio ?? '08:00' }} <span>–</span> {{ $record->hora_fin ?? '12:00' }}</p>
            <p class="week-summary-detail" data-summary-detail>Citas de {{ $record->duracion_minutos ?? 30 }} minutos</p>
            <p class="week-summary-message" data-summary-message hidden></p>
            <p class="week-summary-help">{{ $record ? 'Se actualizará únicamente esta franja.' : 'Se guardará la misma franja en todos los días seleccionados.' }}</p>
            <button type="submit" class="button button-primary"><x-icon name="check" />{{ $record ? 'Guardar cambios' : 'Guardar horario' }}</button>
            <a class="week-summary-cancel" href="{{ route('catalog.index', 'horarios') }}">Cancelar</a>
            @if($record)<a class="week-summary-cancel week-delete-link" href="{{ route('schedules.slot.confirm-delete', ['doctor' => $record->medico_id, 'slot' => $record->id]) }}">Eliminar esta franja</a>@endif
        </aside>
    </form>
</div>
@endsection
@push('scripts')<script src="{{ asset('assets/schedules.js') }}?v={{ substr(hash_file('sha256', public_path('assets/schedules.js')), 0, 16) }}" type="module"></script>@endpush
