<?php

namespace Tests\Feature;

use App\Modules\GestionFinanciera\Services\VentaService;
use App\Modules\GestionFinanciera\Services\ConfiguracionFinancieraService;
use App\Modules\GestionFinanciera\Services\PagoService;
use App\Modules\GestionFinanciera\Services\PayPalService;
use App\Modules\Shared\Clinic;
use App\Modules\Shared\Services\OperacionWeb;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class PayPalWorkflowsTest extends TestCase
{
    use RefreshDatabase,SeedsVidaSalud;

    private string $orderState = 'APPROVED';

    private string $refundState = 'COMPLETED';

    private int $captureCalls = 0;

    private int $refundCalls = 0;

    private array $createKeys = [];

    private array $captureKeys = [];

    private bool $loseCapture = false;

    private bool $loseCreate = false;

    private bool $wrongAmount = false;

    private bool $wrongMerchant = false;

    private bool $staleOwner = false;

    private bool $staleRefundOwner = false;

    private bool $liveApproval = false;

    private bool $declined = false;

    private int $payment = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVidaSalud();
        $this->actingAs($this->account('admin'));
        config(['services.paypal' => ['enabled' => true, 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'merchant_id' => 'MERCHANT-TEST']]);
        Clinic::transaction($this->account('admin'), fn () => app(ConfiguracionFinancieraService::class)->save($this->account('admin'), ['bob_por_usd' => '6.96', 'version' => 0, 'motivo' => 'Tasa sintética Sandbox']));
    }

    private function signed(string $op, array $data = []): array
    {
        $key = OperacionWeb::token();

        return $data + ['clave' => $key, 'firma' => OperacionWeb::signature($this->account('admin'), $op, $key), 'paso' => 'guardar'];
    }

    private function pending(): int
    {
        $id = $this->existingAppointment($this->appointmentPayload());
        DB::table('cita')->where('id', $id)->update(['precio_consulta' => '100.00']);

        return $this->payment = Clinic::transaction($this->account('admin'), fn () => app(PagoService::class)->create($this->account('admin'), $id, null, 'PayPal', null));
    }

    private function order(): array
    {
        $p = DB::table('pago')->find($this->payment);
        $amount = ['currency_code' => 'USD', 'value' => $this->wrongAmount ? '99.00' : $p->monto_pasarela];
        $unit = ['reference_id' => (string) $p->id, 'custom_id' => (string) $p->id, 'invoice_id' => 'VS-SB-'.$p->clave_pasarela, 'payee' => ['merchant_id' => $this->wrongMerchant ? 'FOREIGN' : $p->destinatario_paypal], 'amount' => $amount];
        if ($this->orderState === 'COMPLETED') {
            $unit['payments'] = ['captures' => [['id' => 'CAPTURE-'.$p->id, 'status' => 'COMPLETED', 'amount' => $amount, 'final_capture' => true]]];
        }

        return ['id' => 'ORDER-'.$p->id, 'intent' => 'CAPTURE', 'status' => $this->orderState, 'purchase_units' => [$unit], 'links' => [['rel' => 'approve', 'href' => ($this->liveApproval ? 'https://www.paypal.com' : 'https://www.sandbox.paypal.com').'/checkoutnow?token=ORDER-'.$p->id]]];
    }

    private function fake(): void
    {
        Http::fake(function ($r) {
            $p = DB::table('pago')->find($this->payment);
            $url = $r->url();
            if (str_ends_with($url, '/oauth2/token')) {
                return Http::response(['access_token' => 'fixture-token']);
            }
            if ($r->method() === 'POST' && str_ends_with($url, '/checkout/orders')) {
                $this->createKeys[] = $r->header('PayPal-Request-Id')[0] ?? '';
                if ($this->loseCreate) {
                    $this->loseCreate = false;
                    throw new ConnectionException('Respuesta de creación sintética perdida');
                }

                return Http::response(['id' => 'ORDER-'.$p->id, 'status' => 'CREATED'], 201);
            }
            if ($r->method() === 'POST' && str_ends_with($url, '/capture')) {
                if ($this->declined) {
                    return Http::response(['details' => [['issue' => 'INSTRUMENT_DECLINED']]], 422);
                }
                $this->captureCalls++;
                $this->captureKeys[] = $r->header('PayPal-Request-Id')[0] ?? '';
                $this->orderState = 'COMPLETED';
                if ($this->staleOwner) {
                    DB::table('pago')->where('id', $p->id)->update(['propietario' => OperacionWeb::token(), 'version_operacion' => DB::raw('version_operacion+1')]);
                }
                if ($this->loseCapture) {
                    $this->loseCapture = false;
                    throw new ConnectionException('Respuesta sintética perdida después de captura');
                }

                return Http::response(['id' => 'ORDER-'.$p->id, 'status' => 'COMPLETED']);
            }
            if ($r->method() === 'POST' && str_ends_with($url, '/refund')) {
                $this->refundCalls++;

                return Http::response(['id' => 'REFUND-'.$p->id, 'status' => $this->refundState], 201);
            }
            if (str_contains($url, '/checkout/orders/')) {
                return Http::response($this->order());
            }
            if (str_contains($url, '/payments/captures/')) {
                if ($this->staleRefundOwner && $p->estado === 'Completado') {
                    DB::table('pago')->where('id', $p->id)->update(['propietario' => OperacionWeb::token(), 'version_operacion' => DB::raw('version_operacion+1')]);
                    $this->staleRefundOwner = false;
                }

                return Http::response(['id' => 'CAPTURE-'.$p->id, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => $p->monto_pasarela], 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-'.$p->id]]]);
            }
            if (str_contains($url, '/payments/refunds/')) {
                return Http::response(['id' => 'REFUND-'.$p->id, 'status' => $this->refundState, 'amount' => ['currency_code' => 'USD', 'value' => $p->monto_pasarela], 'links' => [['rel' => 'up', 'href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAPTURE-'.$p->id]]]);
            }

            return Http::response([], 500);
        });
    }

    public function test_server_verified_capture_snapshot_and_replay_are_idempotent(): void
    {
        $id = $this->pending();
        $this->fake();
        $this->post(route('finance.paypal.process', $id))->assertRedirect();
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Completado', 'monto' => '100.00', 'moneda' => 'BOB', 'monto_pasarela' => '14.37', 'bob_por_usd' => '6.96000000']);
        $this->post(route('finance.paypal.process', $id))->assertRedirect();
        $this->assertSame(1, $this->captureCalls);
        $this->assertCount(1, $this->createKeys);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/checkout/orders') && $r['purchase_units'][0]['amount']['value'] === '14.37');
        $this->get(route('finance.payments.show', $id))->assertOk()->assertSee('14.37');
    }

    public function test_lost_capture_response_is_recovered_without_another_capture(): void
    {
        $id = $this->pending();
        $this->loseCapture = true;
        $this->fake();
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Pendiente', 'resultado_incierto' => true]);
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Completado']);
        $this->assertSame(1, $this->captureCalls);
    }

    public function test_lost_creation_reuses_same_request_id_and_does_not_store_dummy_order(): void
    {
        $id = $this->pending();
        $this->loseCreate = true;
        $this->orderState = 'CREATED';
        $this->fake();
        app(PayPalService::class)->process($id);
        $this->assertDatabaseCount('transaccion_paypal', 0);
        app(PayPalService::class)->process($id);
        $this->assertCount(2, $this->createKeys);
        $this->assertSame($this->createKeys[0], $this->createKeys[1]);
        $this->assertDatabaseCount('transaccion_paypal', 1);
        $this->assertSame(0, $this->captureCalls);
    }

    public function test_wrong_amount_or_merchant_never_confirms_or_captures(): void
    {
        $id = $this->pending();
        $this->wrongAmount = true;
        $this->fake();
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Pendiente', 'resultado_incierto' => true]);
        $this->assertSame(0, $this->captureCalls);
        $this->wrongAmount = false;
        $this->wrongMerchant = true;
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Pendiente']);
        $this->assertSame(0, $this->captureCalls);
    }

    public function test_browser_return_does_not_confirm_and_cancel_without_capture_allows_cash_retry(): void
    {
        $id = $this->pending();
        $this->orderState = 'CREATED';
        $this->fake();
        $this->get(route('finance.paypal.return', $id).'?token=UNTRUSTED')->assertRedirect();
        Http::assertNothingSent();
        app(PayPalService::class)->process($id);
        $this->get(route('finance.payments.show', $id))->assertOk()->assertSee('Pagar con PayPal')->assertSee('Copiar enlace');
        $this->post(route('finance.paypal.cancel', $id))->assertRedirect();
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Cancelado']);
        $p = DB::table('pago')->find($id);
        Clinic::transaction($this->account('admin'), fn () => app(PagoService::class)->create($this->account('admin'), $p->cita_id, null, 'Efectivo', '100.00'));
        $this->assertDatabaseCount('pago', 2);
        $this->assertSame(0, $this->captureCalls);
    }

    public function test_customer_return_is_signed_read_only_and_captures_once_without_staff_login(): void
    {
        $id = $this->pending();
        $this->orderState = 'CREATED';
        $this->fake();
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Pendiente']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/checkout/orders') && str_contains($r['payment_source']['paypal']['experience_context']['return_url'], '/pago/paypal/'.$id.'/retorno?') && str_contains($r['payment_source']['paypal']['experience_context']['cancel_url'], '/pago/paypal/'.$id.'/cancelado?'));
        $this->app['auth']->guard()->logout();
        $return = URL::temporarySignedRoute('finance.paypal.customer.return', now()->addHour(), ['id' => $id]);
        $verify = URL::temporarySignedRoute('finance.paypal.customer.verify', now()->addHour(), ['id' => $id]);
        $this->get(route('finance.paypal.customer.return', $id))->assertForbidden();
        $this->post(route('finance.paypal.customer.verify', $id))->assertForbidden();
        $this->get($return.'&token=WRONG')->assertForbidden();
        $this->get($return.'&token=ORDER-'.$id.'&PayerID=EXAMPLE')->assertOk()->assertSee('Verificando tu pago');
        $this->assertSame(0, $this->captureCalls);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Pendiente']);
        $this->orderState = 'APPROVED';
        $response = $this->post($verify)->assertRedirect();
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Completado']);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('¡Pago completado!')->assertDontSee('Paciente:');
        $this->post($verify)->assertRedirect();
        $this->assertSame(1, $this->captureCalls);
        $this->assertSame(0, $this->refundCalls);
        $expired = URL::temporarySignedRoute('finance.paypal.customer.return', now()->subMinute(), ['id' => $id]);
        $this->get($expired)->assertForbidden();
    }

    public function test_customer_cancel_does_not_capture_or_change_payment(): void
    {
        $id = $this->pending();
        $this->orderState = 'CREATED';
        $this->fake();
        app(PayPalService::class)->process($id);
        $this->app['auth']->guard()->logout();
        $cancel = URL::temporarySignedRoute('finance.paypal.customer.cancel', now()->addHour(), ['id' => $id]);
        $this->get($cancel.'&token=ORDER-'.$id)->assertOk()->assertSee('Pago sin completar');
        $this->assertSame(0, $this->captureCalls);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Pendiente']);
    }

    public function test_refund_pending_then_completed_preserves_original_and_never_repeats_post(): void
    {
        $id = $this->pending();
        $this->fake();
        app(PayPalService::class)->process($id);
        $p = DB::table('pago')->find($id);
        DB::table('cita')->where('id', $p->cita_id)->update(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Cancelación sintética']);
        $this->refundState = 'PENDING';
        $data = $this->signed('devolucion.'.$id, ['motivo' => 'Reembolso total por cancelación']);
        $this->post(route('finance.payments.refund', $id), $data)->assertRedirect();
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Completado', 'resultado_incierto' => true]);
        $customerVerify = URL::temporarySignedRoute('finance.paypal.customer.verify', now()->addHour(), ['id' => $id]);
        $this->post($customerVerify)->assertRedirect();
        $this->assertSame(1, $this->refundCalls);
        $this->refundState = 'COMPLETED';
        $this->post(route('finance.paypal.process', $id))->assertRedirect();
        $this->post(route('finance.paypal.process', $id))->assertRedirect();
        $this->assertSame(1, $this->refundCalls);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Reembolsado', 'monto' => '100.00', 'reembolsado_por' => $this->account('admin')->id]);
        $this->assertSame($p->fecha_confirmacion, DB::table('pago')->find($id)->fecha_confirmacion);
    }

    public function test_sale_checkout_reuses_payment_screen_and_sandbox_capture_without_duplicate_delivery(): void
    {
        $product = DB::table('producto')->insertGetId(['nombre' => 'Medicamento checkout', 'tipo' => 'Medicamento', 'unidad_medida' => 'unidad', 'precio_venta' => '10.00']);
        $lot = DB::table('lote')->insertGetId(['producto_id' => $product, 'numero_lote' => 'CHECKOUT', 'fecha_vencimiento' => '2030-01-01']);
        DB::table('movimiento_inventario')->insert(['producto_id' => $product, 'lote_id' => $lot, 'usuario_id' => $this->account('admin')->id, 'cantidad' => 10, 'motivo' => 'Stock sintético', 'tipo' => 'Entrada']);
        $data = $this->signed('venta', ['paciente_id' => DB::table('paciente')->value('id'), 'metodo' => 'PayPal', 'lineas' => [['producto_id' => $product, 'cantidad' => 10, 'precio_esperado' => '10.00']], 'tasa_esperada' => '6.96000000', 'usd_esperado' => '14.37']);
        $this->post(route('supply.sales.store'), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('PayPal Sandbox')->assertSee('Revisa antes de confirmar');
        $response = $this->post(route('supply.sales.store'), $data);
        $sale = DB::table('venta')->value('id');
        $this->payment = DB::table('pago')->where('venta_id', $sale)->value('id');
        $response->assertRedirect(route('finance.payments.show', $this->payment));
        $this->post(route('supply.sales.store'), $data)->assertRedirect(route('finance.payments.show', $this->payment));
        $this->assertDatabaseCount('venta', 1);
        $this->assertDatabaseCount('pago', 1);
        $this->assertDatabaseCount('detalle_venta_lote', 0);
        $this->get(route('supply.sales.show', $sale))->assertOk()->assertSee('Continuar con el pago')->assertDontSee('Registrar nuevo intento');
        $this->get(route('finance.payments.show', $this->payment))->assertOk()->assertSee('Generar enlace de pago');
        $this->orderState = 'CREATED';
        $this->fake();
        $this->post(route('finance.paypal.process', $this->payment))->assertRedirect();
        $this->get(route('finance.payments.show', $this->payment))->assertOk()->assertSee('Pagar con PayPal')->assertSee('www.sandbox.paypal.com');
        $this->assertDatabaseHas('pago', ['id' => $this->payment, 'estado' => 'Pendiente']);
        $this->orderState = 'APPROVED';
        $this->post(route('finance.paypal.process', $this->payment))->assertRedirect();
        $this->post(route('finance.paypal.process', $this->payment))->assertRedirect();
        $this->assertSame(1, $this->captureCalls);
        $this->assertDatabaseHas('venta', ['id' => $sale, 'estado' => 'Completada']);
        $this->assertDatabaseCount('detalle_venta_lote', 1);
        $this->get(route('finance.payments.show', $this->payment))->assertOk()->assertSee('Pago confirmado');
    }

    public function test_paid_sale_without_stock_stays_in_resolution_and_can_be_refunded(): void
    {
        $product = DB::table('producto')->insertGetId(['nombre' => 'Venta pasarela', 'tipo' => 'Medicamento', 'unidad_medida' => 'unidad', 'precio_venta' => '10.00']);
        $lot = DB::table('lote')->insertGetId(['producto_id' => $product, 'numero_lote' => 'PAYPAL', 'fecha_vencimiento' => '2030-01-01']);
        $move = ['producto_id' => $product, 'lote_id' => $lot, 'usuario_id' => $this->account('admin')->id, 'cantidad' => 10, 'motivo' => 'Stock sintético'];
        DB::table('movimiento_inventario')->insert($move + ['tipo' => 'Entrada']);
        $sale = Clinic::transaction($this->account('admin'), fn () => app(VentaService::class)->create($this->account('admin'), ['paciente_id' => DB::table('paciente')->value('id'), 'metodo' => 'PayPal', 'lineas' => [['producto_id' => $product, 'cantidad' => 10, 'precio_esperado' => '10.00']]]));
        $this->payment = DB::table('pago')->where('venta_id', $sale)->value('id');
        DB::table('movimiento_inventario')->insert($move + ['tipo' => 'Salida']);
        $this->fake();
        app(PayPalService::class)->process($this->payment);
        $this->assertDatabaseHas('pago', ['id' => $this->payment, 'estado' => 'Completado']);
        $this->assertDatabaseHas('venta', ['id' => $sale, 'estado' => 'PendienteResolucion']);
        $this->assertDatabaseCount('detalle_venta_lote', 0);
        $this->post(route('finance.payments.refund', $this->payment), $this->signed('devolucion.'.$this->payment, ['motivo' => 'Sin existencias para entrega']))->assertRedirect();
        $this->assertDatabaseHas('venta', ['id' => $sale, 'estado' => 'Cancelada']);
    }

    public function test_expired_replay_window_and_stale_owner_cannot_start_duplicate_financial_operations(): void
    {
        $id = $this->pending();
        $this->loseCreate = true;
        $this->fake();
        app(PayPalService::class)->process($id);
        DB::table('pago')->where('id', $id)->update(['creacion_paypal_iniciada_en' => now()->subHour()]);
        app(PayPalService::class)->process($id);
        $this->assertCount(1, $this->createKeys);
        $this->orderState = 'COMPLETED';
        $message = app(PayPalService::class)->associate($this->account('admin'), $id, 'ORDER-'.$id, null);
        $this->assertStringContainsString('verificados', $message);
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Completado']);
        $this->assertSame(0, $this->captureCalls);
    }

    public function test_stale_process_cannot_overwrite_a_new_owner(): void
    {
        $id = $this->pending();
        $this->staleOwner = true;
        $this->fake();
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Pendiente']);
        DB::table('pago')->where('id', $id)->update(['bloqueo_hasta' => now()->subMinute()]);
        $this->staleOwner = false;
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Completado']);
        $this->assertSame(1, $this->captureCalls);
    }

    public function test_missing_credentials_and_live_links_are_not_used(): void
    {
        $id = $this->pending();
        config(['services.paypal.enabled' => false]);
        $this->artisan('paypal:conciliar')->assertExitCode(1);
        Http::assertNothingSent();
        config(['services.paypal.enabled' => true]);
        $this->orderState = 'CREATED';
        $this->liveApproval = true;
        $this->fake();
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Pendiente', 'resultado_incierto' => true]);
        $this->assertDatabaseHas('transaccion_paypal', ['pago_id' => $id, 'approval_url' => null]);
    }

    public function test_scheduled_reconciliation_and_rate_change_require_review(): void
    {
        $id = $this->pending();
        $this->fake();
        $this->artisan('paypal:conciliar')->assertExitCode(0);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Completado']);
        $old = DB::table('cita')->where('id', DB::table('pago')->find($id)->cita_id)->first();
        $appointment = $this->existingAppointment(['paciente_id' => $old->paciente_id, 'medico_id' => $old->medico_id, 'especialidad_id' => $old->especialidad_id, 'inicio' => CarbonImmutable::parse($old->inicio)->addHour()->format('c'), 'motivo_consulta' => 'Control sintético']);
        DB::table('cita')->where('id', $appointment)->update(['precio_consulta' => '100.00']);
        $data = $this->signed('pago_cita.'.$appointment, ['metodo' => 'PayPal', 'precio_esperado' => '100.00', 'tasa_esperada' => '7.00', 'usd_esperado' => '14.29']);
        $this->postJson(route('finance.payments.store', $appointment), $data)->assertUnprocessable();
        $this->assertDatabaseCount('pago', 1);
    }

    public function test_reconciliation_batches_do_not_starve_new_payments_behind_unapproved_orders(): void
    {
        $quote = app(ConfiguracionFinancieraService::class)->quote('100.00');
        $actor = $this->account('admin');
        $patient = DB::table('paciente')->value('id');
        for ($i = 0; $i < 101; $i++) {
            $sale = DB::table('venta')->insertGetId(['paciente_id' => $patient, 'registrada_por' => $actor->id, 'total' => '100.00']);
            DB::table('pago')->insert($quote + ['venta_id' => $sale, 'registrado_por' => $actor->id, 'metodo' => 'PayPal', 'destinatario_paypal' => 'MERCHANT-TEST']);
        }
        Http::fake(function ($r) {
            if (str_ends_with($r->url(), '/oauth2/token')) {
                return Http::response(['access_token' => 'fixture-token']);
            }
            if ($r->method() === 'POST') {
                return Http::response(['id' => 'ORDER-'.$r['purchase_units'][0]['custom_id']], 201);
            }
            preg_match('/ORDER-(\d+)$/', $r->url(), $match);
            $p = DB::table('pago')->find((int) $match[1]);

            return Http::response(['id' => 'ORDER-'.$p->id, 'intent' => 'CAPTURE', 'status' => 'CREATED', 'purchase_units' => [['reference_id' => (string) $p->id, 'custom_id' => (string) $p->id, 'invoice_id' => 'VS-SB-'.$p->clave_pasarela, 'payee' => ['merchant_id' => $p->destinatario_paypal], 'amount' => ['currency_code' => 'USD', 'value' => $p->monto_pasarela]]]]);
        });
        $this->artisan('paypal:conciliar')->assertExitCode(0);
        $this->assertSame(1, DB::table('pago')->whereNull('ultima_verificacion_en')->count());
        $this->artisan('paypal:conciliar')->assertExitCode(0);
        $this->assertSame(0, DB::table('pago')->whereNull('ultima_verificacion_en')->count());
        $this->assertDatabaseCount('transaccion_paypal', 101);
    }

    public function test_refund_worker_losing_ownership_does_not_send_refund(): void
    {
        $id = $this->pending();
        $this->fake();
        app(PayPalService::class)->process($id);
        $p = DB::table('pago')->find($id);
        DB::table('cita')->where('id', $p->cita_id)->update(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Cancelación sintética']);
        Clinic::transaction($this->account('admin'), fn () => app(PayPalService::class)->prepareRefund($this->account('admin'), $id, 'Devolución controlada'));
        $this->staleRefundOwner = true;
        app(PayPalService::class)->process($id);
        $this->assertSame(0, $this->refundCalls);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Completado']);
        DB::table('pago')->where('id', $id)->update(['bloqueo_hasta' => now()->subMinute()]);
        app(PayPalService::class)->process($id);
        $this->assertSame(1, $this->refundCalls);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Reembolsado']);
    }

    public function test_verified_decline_releases_payment_for_another_method(): void
    {
        $id = $this->pending();
        $this->declined = true;
        $this->fake();
        app(PayPalService::class)->process($id);
        $this->assertDatabaseHas('pago', ['id' => $id, 'estado' => 'Fallido', 'resultado_incierto' => false]);
        $p = DB::table('pago')->find($id);
        Clinic::transaction($this->account('admin'), fn () => app(PagoService::class)->create($this->account('admin'), $p->cita_id, null, 'Efectivo', '100.00'));
        $this->assertDatabaseCount('pago', 2);
    }
}
