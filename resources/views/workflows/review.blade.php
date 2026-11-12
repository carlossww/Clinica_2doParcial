@extends('layouts.app')
@section('body-class','app-page workflow-page')
@section('title', $title)
@section('content')
<x-page-heading :title="$title" :back="route('home')" />
@if($title === 'Confirmar cita')
<section class="panel appointment-review" aria-labelledby="appointment-review-heading">
    <header class="appointment-review-heading">
        <span class="appointment-review-icon"><x-icon name="calendar" /></span>
        <div><h2 id="appointment-review-heading">Revisa los datos de tu cita</h2><p class="text-muted">Si todo está correcto, confirma para agendarla.</p></div>
    </header>
    <div class="appointment-review-slot"><x-icon name="clock" /><div><span>Fecha y hora</span><strong>{{ $summary['Turno'] }}</strong></div></div>
    <dl class="appointment-review-details">
        @foreach($summary as $label => $value)
            @continue($label === 'Turno')
            <div @class(['appointment-review-reason' => $label === 'Motivo'])><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <div class="appointment-review-footer">
        <p class="text-muted text-small">¿Necesitas corregir algo? Vuelve con el botón Atrás de tu navegador.</p>
        <form method="post" action="{{ $action }}">
            @csrf
            <input type="hidden" name="clave" value="{{ $key }}"><input type="hidden" name="firma" value="{{ $signature }}">
            @foreach($data as $name => $value)
                @include('workflows.hidden',['fieldName'=>$name,'fieldValue'=>$value])
            @endforeach
            <button name="paso" value="guardar" class="button button-primary"><x-icon name="check" /> Confirmar cita</button>
        </form>
    </div>
</section>
@elseif(in_array($title, ['Confirmar venta', 'Confirmar pago de consulta', 'Confirmar nuevo intento'], true))
@include('finanzas.confirmar-pago')
@else
<dl>@foreach($summary as $label => $value)<dt>{{ $label }}</dt><dd style="white-space:pre-wrap">{{ $value }}</dd>@endforeach</dl>
<form method="post" action="{{ $action }}">@csrf
<input type="hidden" name="clave" value="{{ $key }}"><input type="hidden" name="firma" value="{{ $signature }}">
@foreach($data as $name => $value)
@include('workflows.hidden',['fieldName'=>$name,'fieldValue'=>$value])
@endforeach
<button name="paso" value="guardar" class="button button-primary">Confirmar y guardar</button>
</form>
<p>Para corregir información, vuelve al formulario con el botón Atrás del navegador.</p>
@endif
@endsection
