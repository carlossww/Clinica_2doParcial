<?php

namespace Tests\Feature;

use App\Modules\GestionInventarioMedico\Services\InventarioService;
use App\Modules\Shared\Services\OperacionWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class CashWorkflowsTest extends TestCase
{
    use RefreshDatabase,SeedsVidaSalud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVidaSalud();
        $this->actingAs($this->account('recepcion'));
    }

    private function signed(string $op, array $data = [], string $who = 'recepcion'): array
    {
        $key = OperacionWeb::token();

        return $data + ['clave' => $key, 'firma' => OperacionWeb::signature($this->account($who), $op, $key), 'paso' => 'guardar'];
    }

    private function appointment(): int
    {
        $id = $this->existingAppointment($this->appointmentPayload());
        DB::table('cita')->where('id', $id)->update(['precio_consulta' => '100.00']);

        return $id;
    }

    private function charge(int $id): array
    {
        return $this->signed('pago_cita.'.$id, ['metodo' => 'Efectivo', 'recibido' => '120.00', 'precio_esperado' => '100.00']);
    }

    private function stock(): array
    {
        $product = DB::table('producto')->insertGetId(['nombre' => 'Medicamento venta', 'tipo' => 'Medicamento', 'unidad_medida' => 'unidad', 'precio_venta' => '5.00']);
        $lots = [];
        foreach ([['A', '2030-01-01', 6], ['B', '2031-01-01', 4]] as [$number,$date,$qty]) {
            $lot = DB::table('lote')->insertGetId(['producto_id' => $product, 'numero_lote' => $number, 'fecha_vencimiento' => $date]);
            $lots[] = $lot;
            DB::table('movimiento_inventario')->insert(['producto_id' => $product, 'lote_id' => $lot, 'usuario_id' => $this->account('admin')->id, 'tipo' => 'Entrada', 'cantidad' => $qty, 'motivo' => 'Stock sintético']);
        }

        return [$product, $lots];
    }

    public function test_full_cash_payment_change_receipt_and_duplicates(): void
    {
        $id = $this->appointment();
        $data = $this->charge($id);
        $this->get(route('finance.payments.index'))->assertOk();
        $this->get(route('finance.payments.create', $id))->assertOk();
        $this->post(route('finance.payments.store', $id), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('100.00');
        $this->assertDatabaseCount('pago', 0);
        $this->post(route('finance.payments.store', $id), $data)->assertRedirect();
        $this->post(route('finance.payments.store', $id), $data)->assertRedirect();
        $this->assertDatabaseHas('pago', ['monto' => '100.00', 'recibido_efectivo' => '120.00', 'cambio_efectivo' => '20.00', 'estado' => 'Completado']);
        $payment = DB::table('pago')->value('id');
        $this->get(route('finance.payments.show', $payment))->assertOk()->assertSee('20.00')->assertDontSee('Control preventivo');
        $this->postJson(route('finance.payments.store', $id), $this->charge($id))->assertUnprocessable();
        $this->assertDatabaseCount('pago', 1);
    }

    public function test_full_refund_keeps_original_and_requires_admin_and_cancelled_appointment(): void
    {
        $id = $this->appointment();
        $this->post(route('finance.payments.store', $id), $this->charge($id))->assertRedirect();
        $p = DB::table('pago')->first();
        $this->get(route('finance.payments.refund.form', $p->id))->assertForbidden();
        $this->actingAs($this->account('admin'));
        $data = $this->signed('devolucion.'.$p->id, ['motivo' => 'Consulta cancelada, efectivo entregado'], 'admin');
        $this->postJson(route('finance.payments.refund', $p->id), $data)->assertUnprocessable();
        DB::table('cita')->where('id', $id)->update(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Cancelación sintética']);
        $this->get(route('finance.payments.refund.form', $p->id))->assertOk();
        $this->post(route('finance.payments.refund', $p->id), array_replace($data, ['paso' => 'revisar']))->assertOk();
        $this->post(route('finance.payments.refund', $p->id), $data)->assertRedirect();
        $this->post(route('finance.payments.refund', $p->id), $data)->assertRedirect();
        $this->assertDatabaseHas('pago', ['id' => $p->id, 'monto' => '100.00', 'estado' => 'Reembolsado', 'registrado_por' => $p->registrado_por, 'recibido_efectivo' => '120.00', 'reembolsado_por' => $this->account('admin')->id]);
        $this->assertSame($p->fecha_confirmacion, DB::table('pago')->find($p->id)->fecha_confirmacion);
    }

    public function test_sale_selects_earliest_lots_and_cannot_duplicate_outputs(): void
    {
        [$product,$lots] = $this->stock();
        $patient = DB::table('paciente')->value('id');
        $base = ['paciente_id' => $patient, 'metodo' => 'Efectivo', 'recibido' => '60.00', 'lineas' => [['producto_id' => $product, 'cantidad' => 10, 'precio_esperado' => '5.00']]];
        $data = $this->signed('venta', $base);
        $this->get(route('supply.sales.create'))->assertOk();
        $this->post(route('supply.sales.store'), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('50.00');
        $this->post(route('supply.sales.store'), $data)->assertRedirect();
        $this->post(route('supply.sales.store'), $data)->assertRedirect();
        $this->assertDatabaseCount('venta', 1);
        $this->assertDatabaseCount('detalle_venta_lote', 2);
        $this->assertDatabaseHas('venta', ['estado' => 'Completada', 'total' => '50.00']);
        $this->assertDatabaseHas('detalle_venta_lote', ['lote_id' => $lots[0], 'cantidad' => 6]);
        $this->assertSame(0, app(InventarioService::class)->balance($lots[0]));
        $id = DB::table('venta')->value('id');
        $this->get(route('supply.sales.show', $id))->assertOk()->assertSee('Lotes entregados');
        $this->get(route('supply.sales.index'))->assertOk();
        $payment = DB::table('pago')->value('id');
        $this->actingAs($this->account('admin'))->postJson(route('finance.payments.refund', $payment), $this->signed('devolucion.'.$payment, ['motivo' => 'No se admite devolución tras entrega'], 'admin'))->assertUnprocessable();
    }

    public function test_stock_loss_changed_price_and_invalid_cash_do_not_leave_sale_or_payment(): void
    {
        [$product,$lots] = $this->stock();
        $patient = DB::table('paciente')->value('id');
        $base = ['paciente_id' => $patient, 'metodo' => 'Efectivo', 'recibido' => '100.00', 'lineas' => [['producto_id' => $product, 'cantidad' => 11, 'precio_esperado' => '5.00']]];
        $this->postJson(route('supply.sales.store'), $this->signed('venta', $base))->assertUnprocessable();
        $base['lineas'][0]['cantidad'] = 1;
        $base['lineas'][0]['precio_esperado'] = '1.00';
        $this->postJson(route('supply.sales.store'), $this->signed('venta', $base))->assertUnprocessable();
        $base['lineas'][0]['precio_esperado'] = '5.00';
        $base['recibido'] = '1.00';
        $this->postJson(route('supply.sales.store'), $this->signed('venta', $base))->assertUnprocessable();
        $this->assertDatabaseCount('venta', 0);
        $this->assertDatabaseCount('pago', 0);
        $this->assertSame(6, app(InventarioService::class)->balance($lots[0]));
        DB::table('lote')->whereIn('id', $lots)->update(['estado' => 'Bloqueado']);
        $base['recibido'] = '100.00';
        $this->postJson(route('supply.sales.store'), $this->signed('venta', $base))->assertUnprocessable();
    }

    public function test_payment_missing_price_disallowed_state_short_cash_and_patient_permission(): void
    {
        $id = $this->appointment();
        $this->postJson(route('finance.payments.store', $id), array_replace($this->charge($id), ['recibido' => '10']))->assertUnprocessable();
        DB::table('cita')->where('id', $id)->update(['precio_consulta' => null]);
        $this->postJson(route('finance.payments.store', $id), $this->charge($id))->assertUnprocessable();
        DB::table('cita')->where('id', $id)->update(['precio_consulta' => '100.00', 'estado' => 'Cancelada', 'motivo_cancelacion' => 'Cancelación sintética']);
        $this->postJson(route('finance.payments.store', $id), $this->charge($id))->assertUnprocessable();
        $this->actingAs($this->account('paciente'))->get(route('finance.payments.index'))->assertForbidden();
        $this->get(route('supply.sales.index'))->assertForbidden();
        $this->assertDatabaseCount('pago', 0);
    }
}
