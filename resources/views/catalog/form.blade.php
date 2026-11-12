@extends('layouts.app')
@section('title', ($record ? 'Editar ' : 'Agregar ').mb_strtolower($definition['singular']))
@section('content')
    <x-page-heading :title="$module === 'horarios' && !$record ? 'Configurar horario semanal' : (($record ? 'Editar ' : 'Agregar ').mb_strtolower($definition['singular']))" :description="$module === 'horarios' && !$record ? 'Elige los días y define una franja; se guardará para todos en una sola operación.' : 'Completa la información. Los campos con asterisco son obligatorios.'" :back="route('catalog.index', ['module' => $module])" />
    <section class="panel form-panel">
        <form method="POST" action="{{ $record ? route('catalog.update', ['module' => $module, 'id' => $record->id]) : route('catalog.store', ['module' => $module]) }}" class="form-stack" data-submit-form @if($module === 'medicos') data-doctor-profile data-doctor-accounts="{{ json_encode($options['datos_cuentas']) }}" @endif>
            @csrf
            @if($record) @method('PUT') @endif
            @if($module === 'medicos')
                <p class="text-muted">{{ $record ? 'Código médico: '.$record->id.'. La cuenta vinculada se conserva.' : 'Selecciona una cuenta médica creada por administración. Puedes actualizar sus nombres y correo.' }}</p>
            @endif
            @if($module === 'usuarios' && $record && $record->rol === 'Paciente')
                <p>La edición de esta cuenta conserva su vínculo con el paciente. <a href="{{ route('catalog.users.patient.edit', $record->id) }}">Administrar vínculo con paciente</a></p>
            @elseif($module === 'usuarios' && !$record)
                <p class="text-muted">Después de crear una cuenta de paciente, usa Vínculo con paciente en el listado para verificar su identidad.</p>
            @endif
            @if($module === 'horarios' && !$record)
                <section class="schedule-day-picker grid-full" aria-labelledby="schedule-days-title">
                    <h2 id="schedule-days-title">Días de atención</h2>
                    <p class="text-muted">Selecciona uno o varios días para repetir esta franja.</p>
                    <div class="schedule-day-options">
                        @foreach($definition['fields']['dia_semana']['options'] as $day => $label)
                            <label class="schedule-day-option"><input type="checkbox" name="dias_semana[]" value="{{ $day }}" @checked(in_array((string) $day, old('dias_semana', [])))><span>{{ $label }}</span></label>
                        @endforeach
                    </div>
                    @error('dias_semana')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                </section>
            @endif
            <div class="form-grid">
                @foreach($definition['fields'] as $name => $field)
                    @if($module === 'horarios' && !$record && $name === 'dia_semana') @continue @endif
                    @php
                        $multiple = in_array($name, ['especialidad_ids', 'permiso_ids'], true) || in_array($field['type'], ['multiselect', 'multiple'], true);
                        $fieldOptions = $options[$name] ?? $field['options'] ?? [];
                        $fieldType = $multiple ? 'select' : $field['type'];
                        $fieldValue = data_get($record, $name, $field['default'] ?? ($fieldType === 'checkbox' ? true : null));
                    @endphp
                    <div class="{{ $multiple || $fieldType === 'textarea' ? 'grid-full' : '' }}">
                        <x-field :name="$name" :label="$field['label']" :type="$fieldType" :value="$fieldValue" :options="$fieldOptions" :multiple="$multiple" :required="(($field['required'] ?? false) || (!$record && $fieldType === 'password')) && !($record && $fieldType === 'password')" :hint="$multiple ? 'Puedes seleccionar varias opciones con Ctrl o Cmd; en tu teléfono, toca las opciones.' : ($fieldType === 'password' ? ($record ? 'Deja este campo vacío para conservar la contraseña actual. Si la cambias, usa al menos 12 caracteres.' : 'Usa al menos 12 caracteres.') : null)" :minlength="$fieldType === 'password' ? 12 : null" :max="$name === 'fecha_nacimiento' ? now()->toDateString() : null" :autocomplete="$fieldType === 'password' ? 'new-password' : 'off'" />
                    </div>
                @endforeach
            </div>
            <div class="form-actions"><button class="button button-primary" type="submit"><x-icon name="check" />{{ $record ? 'Guardar cambios' : 'Guardar registro' }}</button><a class="button" href="{{ route('catalog.index', ['module' => $module]) }}">Cancelar</a></div>
        </form>
    </section>
@endsection
@if($module === 'medicos')
    @push('scripts')<script src="{{ asset('assets/doctor-profile.js') }}" type="module"></script>@endpush
@endif
