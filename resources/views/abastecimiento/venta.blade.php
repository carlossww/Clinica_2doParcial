@extends('layouts.app')
@section('body-class','app-page workflow-page')
@section('title','Comprobante de venta')
@section('content')
<x-page-heading :title="'Venta VS-V-'.$row->id" :back="route('supply.sales.index')" />
<div class="payment-layout">
<section class="payment-card payment-main" aria-labelledby="sale-heading">
    <div class="payment-top"><div><span class="payment-eyebrow">VENTA DE MEDICAMENTOS</span><h2 id="sale-heading">Detalle de venta</h2><p class="payment-muted">{{ $row->paciente }}</p></div><span class="payment-status {{ $row->estado === 'Completada' ? 'is-paid' : 'is-pending' }}">{{ $row->estado === 'PendienteResolucion' ? 'Pendiente de resolución' : $row->estado }}</span></div>
    <div class="payment-amount"><span>Total de la venta</span><strong>Bs {{ number_format((float) $row->total, 2, '.', ',') }}</strong></div>
    <div class="table-scroll"><table><thead><tr><th>Medicamento</th><th>Cantidad</th><th>Precio Bs</th><th>Subtotal Bs</th></tr></thead><tbody>
    @foreach($details as $d)<tr><td>{{ $d->nombre }}</td><td>{{ $d->cantidad }}</td><td>{{ $d->precio_unitario }}</td><td>{{ $d->subtotal }}</td></tr>@endforeach
    </tbody></table></div>
    <details class="payment-advanced"><summary>Lotes entregados</summary><div class="payment-advanced-content">@forelse($lots as $l)<p>{{ $l->numero_lote }}: {{ $l->cantidad }} unidades</p>@empty<p>Sin entrega registrada.</p>@endforelse</div></details>
</section>
<aside class="payment-card payment-details" aria-labelledby="sale-payments-heading">
<h2 id="sale-payments-heading">Pago de la venta</h2>
@forelse($payments as $p)
<div class="sale-payment-item"><p><strong>{{ $p->metodo === 'PayPal' ? 'PayPal Sandbox' : $p->metodo }}</strong> · {{ $p->estado }}</p><p class="payment-muted">Pago #{{ $p->id }} · Bs {{ $p->monto }}</p><a class="button {{ $p->estado === 'Pendiente' ? 'button-primary' : '' }}" href="{{ route('finance.payments.show',$p->id) }}">{{ $p->estado === 'Pendiente' ? 'Continuar con el pago' : 'Ver comprobante' }} <x-icon name="arrow-right" /></a></div>
@empty<p class="payment-muted">Sin pagos registrados.</p>@endforelse
@if($row->estado==='Pendiente' && !$payments->contains(fn ($p) => in_array($p->estado, ['Pendiente', 'Completado'], true)))<a class="button" href="{{ route('finance.sale.payment',$row->id) }}">Registrar nuevo intento de pago</a>@endif
</aside>
</div>
<p class="no-print">Imprime desde el menú del navegador. Este comprobante no constituye factura fiscal.</p>
@if($row->estado==='PendienteResolucion' && auth()->user()->roleName()==='Administrador')
<p>Conserva el pago confirmado. Puedes completar la entrega cuando haya existencias o devolver el importe desde el comprobante de pago.</p>
@php($retryKey=\App\Modules\Shared\Services\OperacionWeb::token())
<form class="no-print" method="post" action="{{ route('supply.sales.retry',$row->id) }}">@csrf<input type="hidden" name="clave" value="{{ $retryKey }}"><input type="hidden" name="firma" value="{{ \App\Modules\Shared\Services\OperacionWeb::signature(auth()->user(),'entregar_venta.'.$row->id,$retryKey) }}"><button class="button">Confirmar reintento de entrega completa</button></form>
@endif
@endsection
