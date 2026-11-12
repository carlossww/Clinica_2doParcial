<section class="payment-card sale-review" aria-labelledby="sale-review-heading">
    <div class="payment-top"><div><span class="payment-eyebrow">CLÍNICA VIDA SALUD</span><h2 id="sale-review-heading">Revisa antes de confirmar</h2><p class="payment-muted">{{ ($data['metodo'] ?? '') === 'PayPal' ? 'El pago se completará en PayPal Sandbox.' : 'Comprueba el importe y los datos del cobro.' }}</p></div></div>
    <div class="payment-amount"><span>Total</span><strong>Bs {{ number_format((float) $summary['Total BOB'], 2, '.', ',') }}</strong>@if(isset($data['usd_esperado']))<small>PayPal Sandbox · USD {{ $data['usd_esperado'] }}</small>@endif</div>
    <dl class="sale-review-details">
        @foreach($summary as $label => $value)
            @continue($label === 'Total BOB' || (($data['metodo'] ?? '') === 'PayPal' && $label === 'Recibido'))
            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
        @endforeach
    </dl>
    <form method="post" action="{{ $action }}" class="payment-actions">
        @csrf
        <input type="hidden" name="clave" value="{{ $key }}"><input type="hidden" name="firma" value="{{ $signature }}">
        @foreach($data as $name => $value)
            @include('workflows.hidden',['fieldName'=>$name,'fieldValue'=>$value])
        @endforeach
        <button name="paso" value="guardar" class="button button-primary"><x-icon name="check" /> {{ $title === 'Confirmar venta' ? 'Confirmar venta' : 'Confirmar pago' }}</button>
    </form>
    <p class="payment-muted text-small">Para corregir los datos, vuelve con el botón Atrás de tu navegador.</p>
</section>
