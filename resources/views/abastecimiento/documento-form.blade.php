@extends('layouts.app')
@section('body-class','app-page workflow-page')
@section('title',$title)
@section('content')
<x-page-heading :title="$title" :back="route('home')" />
@unless($purchase)<form method="get" class="filters"><x-field name="q" label="Buscar paciente por nombre o documento" :value="request('q')" /><button class="button">Buscar paciente</button></form>@endunless
<form method="post" action="{{ $action }}" @class(['form-panel', 'sale-entry' => !$purchase])>@csrf
<input type="hidden" name="clave" value="{{ $key }}"><input type="hidden" name="firma" value="{{ \App\Modules\Shared\Services\OperacionWeb::signature(auth()->user(),$operation,$key) }}">
@if($purchase)
<x-field name="proveedor_id" label="Proveedor activo" type="select" :options="$suppliers" :value="$values['proveedor_id'] ?? ''" />
@else
<div class="sale-section-heading"><span class="payment-eyebrow">VENTA DE MEDICAMENTOS</span><h2>Paciente y medicamentos</h2><p class="payment-muted">Selecciona los productos y la cantidad que vas a entregar.</p></div>
<x-field name="paciente_id" label="Paciente activo" type="select" :options="$patients" :value="$values['paciente_id'] ?? ''" />
@endif
@foreach($lines as $i=>$line)
<fieldset @class(['sale-line' => !$purchase])><legend>Producto {{ $i+1 }}</legend>
<x-field :name="'lineas['.$i.'][producto_id]'" label="Producto" type="select" :options="$options" :value="$line['producto_id'] ?? ''" />
<x-field :name="'lineas['.$i.'][cantidad]'" label="Cantidad entera" :value="$line['cantidad'] ?? ''" inputmode="numeric" />
@if($purchase)<x-field :name="'lineas['.$i.'][precio_unitario]'" label="Precio unitario BOB" :value="$line['precio_unitario'] ?? ''" inputmode="decimal" />@endif
</fieldset>
@endforeach
@unless($purchase)
<div class="sale-section-heading"><h2>Forma de pago</h2><p class="payment-muted">Con PayPal generarás el enlace de Sandbox después de confirmar la venta.</p></div>
<x-field name="metodo" label="Método de pago" type="select" :options="['Efectivo'=>'Efectivo','PayPal'=>'PayPal Sandbox']" :value="$values['metodo'] ?? 'Efectivo'" />
<x-field name="recibido" label="Efectivo recibido BOB (solo efectivo)" :value="$values['recibido'] ?? ''" />
@endunless
<p>Las líneas vacías se omiten. El servidor calcula los importes al revisar y confirmar.</p>
<div class="button-row"><button class="button" name="paso" value="agregar" formnovalidate><x-icon name="plus" /> Agregar otro producto</button>
<button class="button button-primary" name="paso" value="revisar">{{ $purchase ? 'Revisar' : 'Revisar venta' }} <x-icon name="arrow-right" /></button></div>
</form>
@endsection
