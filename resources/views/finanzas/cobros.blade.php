@extends('layouts.app')
@section('body-class','app-page workflow-page collections-page')
@section('title','Cobros de consultas')
@section('content')
<x-page-heading title="Cobros de consultas" :back="route('home')" />
<p class="collections-intro">Busca al paciente, revisa el importe y selecciona <strong>Cobrar</strong> para registrar su pago.</p>
<section class="collections-search" aria-labelledby="search-heading">
    <div class="collections-section-heading"><h2 id="search-heading">Buscar una consulta</h2><span>Hora y fecha de Bolivia</span></div>
    <form method="get" action="{{ route('finance.payments.index') }}" class="collections-filters">
        <x-field name="q" label="Paciente o código de cita" placeholder="Nombre, documento o código VS…" :value="$filters['q']??''" />
        <x-field name="fecha" label="Fecha de la cita" type="date" :value="$filters['fecha']??''" />
        <button class="button button-primary">Buscar consultas</button>
    </form>
    <div class="collections-shortcuts">
        <a class="button" href="{{ route('finance.payments.index', array_filter(['q' => $filters['q'] ?? null, 'fecha' => now('America/La_Paz')->format('Y-m-d')])) }}">Citas de hoy</a>
        @if(!empty($filters['q']) || !empty($filters['fecha']))<a href="{{ route('finance.payments.index') }}">Limpiar filtros</a>@endif
        <span>Sin fecha seleccionada se muestran todas las fechas.</span>
    </div>
</section>
<section class="table-panel collections-results" aria-labelledby="results-heading">
    <div class="collections-section-heading"><div><h2 id="results-heading">Consultas encontradas <span class="collections-count">{{ $rows->total() }}</span></h2><p>Las más recientes primero. El estado de cobro es independiente del estado de la cita.</p></div></div>
    <div class="table-scroll"><table class="collections-table"><caption class="sr-only">Consultas, importes, estado del cobro y acciones disponibles</caption><thead><tr><th scope="col">Paciente / cita</th><th scope="col">Fecha y hora</th><th scope="col">Estado de cita</th><th scope="col" class="collections-price">Importe</th><th scope="col">Cobro</th><th scope="col">Acción</th></tr></thead><tbody>
    @forelse($rows as $row)
        @php
            $payment = $payments->get($row->id);
            $eligible = in_array($row->estado, ['Programada', 'Confirmada', 'Atendida'], true);
            $ready = $eligible && $row->precio_consulta !== null;
            $date = \App\Modules\Shared\Clinic::date($row->inicio);
        @endphp
        <tr>
            <td data-label="Paciente / cita"><strong class="collections-patient">{{ $row->paciente }}</strong><span class="collections-secondary">{{ $row->codigo }}</span></td>
            <td data-label="Fecha y hora"><span>{{ $date->format('d/m/Y') }}</span><span class="collections-secondary">{{ $date->format('H:i') }}</span></td>
            <td data-label="Estado de cita"><span class="badge {{ $row->estado === 'Cancelada' ? 'badge-danger' : 'badge-info' }}">{{ $row->estado }}</span></td>
            <td data-label="Importe" class="collections-price">@if($row->precio_consulta !== null)<strong>Bs {{ number_format((float) $row->precio_consulta, 2, '.', ',') }}</strong>@else<span class="collections-secondary">Sin definir</span>@endif</td>
            <td data-label="Cobro">
                @if($payment)<span class="badge {{ $payment->estado === 'Completado' ? 'badge-success' : 'badge-warning' }}">{{ $payment->estado === 'Completado' ? 'Pagado' : 'Pago pendiente' }}</span>
                @elseif(!$eligible)<span class="collections-secondary">No disponible</span>
                @elseif(!$ready)<span class="badge badge-warning">Falta precio</span>
                @else<span class="badge badge-info">Por cobrar</span>@endif
            </td>
            <td data-label="Acción" class="collections-action">
                @if($payment)<a class="button" href="{{ route('finance.payments.show', $payment->id) }}" aria-label="{{ $payment->estado === 'Completado' ? 'Ver comprobante' : 'Continuar pago' }} de {{ $row->codigo }}">{{ $payment->estado === 'Completado' ? 'Ver comprobante' : 'Continuar pago' }}</a>
                @elseif($ready)<a class="button button-primary" href="{{ route('finance.payments.create', $row->id) }}" aria-label="Cobrar consulta {{ $row->codigo }}">Cobrar</a>
                @else<span class="collections-secondary">{{ $eligible ? 'Define el precio de la cita para cobrar.' : 'El estado de la cita no permite cobrar.' }}</span>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="collections-empty"><strong>No se encontraron consultas</strong><p>Revisa el nombre, documento o código, o prueba con otra fecha.</p><a class="button" href="{{ route('finance.payments.index') }}">Ver todas las consultas</a></td></tr>
    @endforelse
    </tbody></table></div>
</section>
{{ $rows->links('pagination.default') }}
@endsection
