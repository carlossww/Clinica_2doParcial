<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\GestionInventarioMedico\Services\InventarioService;
use App\Modules\GestionInventarioMedico\Services\LoteService;
use App\Modules\GestionFinanciera\Services\ConfiguracionFinancieraService;
use App\Modules\GestionFinanciera\Services\PagoService;
use App\Modules\GestionFinanciera\Services\PayPalService;
use App\Modules\Shared\Clinic;
use App\Modules\Shared\Services\OperacionWeb;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsVidaSalud;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

class WorkflowConcurrencyTest extends TestCase
{
    use DatabaseMigrations, SeedsVidaSalud;

    public function runDatabaseMigrations(): void
    {
        // TestCase ya verifica y reinicia exclusivamente el esquema _test. No se llama
        // a down(), que preserva deliberadamente registros operativos existentes.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->beforeApplicationDestroyed(function () {
            TestDatabaseSafety::resetSchema($this->app);
            RefreshDatabaseState::$migrated = false;
        });
    }

    private ?string $lastGatewayEvents = null;

    private function race(array $requests): array
    {
        TestDatabaseSafety::assertApplication($this->app);
        $directory = base_path('.runtime/diez-cu');
        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }
        $barrier = $directory.'/race-'.bin2hex(random_bytes(6));
        $this->lastGatewayEvents = $barrier.'.paypal.events';
        $processes = [];
        try {
            foreach ($requests as $i => $request) {
                $process = proc_open([PHP_BINARY, base_path('tests/Support/workflow-worker.php')], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fwrite($pipes[0], json_encode($request + ['database' => config('database.connections.pgsql'), 'app_key' => config('app.key'), 'barrier' => $barrier, 'name' => $i]));
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            $deadline = microtime(true) + 20;
            while (! is_file($barrier.'.0.ready') || ! is_file($barrier.'.1.ready')) {
                if (microtime(true) > $deadline) {
                    $this->fail('Los procesos no alcanzaron la barrera de concurrencia.');
                }
                usleep(10000);
            }
            touch($barrier.'.start');
            $statuses = [];
            foreach ($processes as [$process,$pipes]) {
                $output = stream_get_contents($pipes[1]);
                $error = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $this->assertSame(0, proc_close($process), $error);
                $statuses[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            $processes = [];
            sort($statuses);

            return $statuses;
        } finally {
            foreach ($processes as [$process]) {
                if (is_resource($process)) {
                    proc_terminate($process);
                }
            }
        }
    }

    public function test_two_administrators_cannot_overwrite_the_initial_exchange_rate(): void
    {
        $this->seedVidaSalud();
        $admin = $this->account('admin');
        $second = DB::table('usuario')->insertGetId(['rol_id' => $admin->rol_id, 'nombre_usuario' => 'admin.fase1',
            'password_hash' => $admin->password_hash, 'nombres' => 'Administrador', 'apellidos' => 'Sintético']);
        $requests = [];
        foreach ([$admin->id, $second] as $actor) {
            $requests[] = ['actor' => $actor, 'operation' => 'tipo_cambio', 'uri' => '/finanzas/configuracion',
                'payload' => ['bob_por_usd' => '6.96', 'version' => 0, 'motivo' => 'Prueba concurrente']];
        }
        $this->assertSame([302, 422], $this->race($requests));
        $this->assertDatabaseCount('configuracion_financiera', 1);
        $this->assertSame(1, DB::table('bitacora')->where('accion', 'TIPO_CAMBIO')->count());
    }

    private function inventoryRaceFixture(bool $medicine = false): array
    {
        $this->seedVidaSalud();
        $admin = $this->account('admin');
        $second = DB::table('usuario')->insertGetId(['rol_id' => $admin->rol_id, 'nombre_usuario' => 'admin.inventario',
            'password_hash' => $admin->password_hash, 'nombres' => 'Administrador', 'apellidos' => 'Concurrente']);
        $product = DB::table('producto')->insertGetId(['nombre' => 'Stock concurrente', 'tipo' => $medicine ? 'Medicamento' : 'Insumo', 'unidad_medida' => 'unidad', 'precio_venta' => '1.00']);
        $lot = DB::table('lote')->insertGetId(['producto_id' => $product, 'numero_lote' => 'RACE', 'fecha_vencimiento' => $medicine ? '2030-01-01' : null]);
        DB::table('movimiento_inventario')->insert(['producto_id' => $product, 'lote_id' => $lot, 'usuario_id' => $admin->id, 'tipo' => 'Entrada', 'cantidad' => 10, 'motivo' => 'Saldo inicial sintético']);

        return [$admin->id, $second, $product, $lot];
    }

    public function test_two_receivers_cannot_exceed_purchase_pending_quantity(): void
    {
        [$admin,$second,$product,$lot] = $this->inventoryRaceFixture();
        $provider = DB::table('proveedor')->insertGetId(['nombre' => 'Proveedor concurrente']);
        $purchase = DB::table('compra')->insertGetId(['proveedor_id' => $provider, 'registrada_por' => $admin, 'total' => '20.00']);
        $detail = DB::table('detalle_compra')->insertGetId(['compra_id' => $purchase, 'producto_id' => $product, 'cantidad' => 20, 'precio_unitario' => '1.00']);
        $requests = [];
        foreach ([$admin, $second] as $actor) {
            $requests[] = ['actor' => $actor, 'operation' => 'recepcion_compra.'.$purchase, 'uri' => '/abastecimiento/compras/'.$purchase.'/recepciones',
                'payload' => ['lineas' => [['detalle_id' => $detail, 'cantidad' => 12, 'numero_lote' => 'RECIBIDO', 'fecha_vencimiento' => null]]]];
        }
        $this->assertSame([302, 422], $this->race($requests));
        $this->assertSame(12, (int) DB::table('movimiento_inventario')->where('detalle_compra_id', $detail)->sum('cantidad'));
        $this->assertDatabaseHas('compra', ['id' => $purchase, 'estado' => 'Parcial']);
    }

    public function test_two_cashiers_cannot_charge_the_same_appointment(): void
    {
        $this->seedVidaSalud();
        $id = $this->existingAppointment($this->appointmentPayload());
        DB::table('cita')->where('id', $id)->update(['precio_consulta' => '100.00']);
        $requests = [];
        foreach (['admin', 'recepcion'] as $login) {
            $requests[] = ['actor' => $this->account($login)->id, 'operation' => 'pago_cita.'.$id, 'uri' => '/finanzas/citas/'.$id.'/pago', 'payload' => ['metodo' => 'Efectivo', 'recibido' => '100.00', 'precio_esperado' => '100.00']];
        }
        $this->assertSame([302, 422], $this->race($requests));
        $this->assertDatabaseCount('pago', 1);
    }

    private function paypalRaceFixture(bool $completed = false): array
    {
        [$admin,$second,$product,$lot] = $this->inventoryRaceFixture();
        config(['services.paypal' => ['enabled' => true, 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'merchant_id' => 'MERCHANT-TEST']]);
        $actor = User::find($admin);
        Clinic::transaction($actor, fn () => app(ConfiguracionFinancieraService::class)->save($actor, ['bob_por_usd' => '6.96', 'version' => 0, 'motivo' => 'Tasa concurrente sintética']));
        $cita = $this->existingAppointment($this->appointmentPayload());
        DB::table('cita')->where('id', $cita)->update(['precio_consulta' => '100.00']);
        $payment = Clinic::transaction($actor, fn () => app(PagoService::class)->create($actor, $cita, null, 'PayPal', null));
        DB::table('transaccion_paypal')->insert(['pago_id' => $payment, 'paypal_order_id' => 'ORDER-'.$payment, 'paypal_capture_id' => $completed ? 'CAPTURE-'.$payment : null, 'estado' => $completed ? 'COMPLETED' : 'APPROVED']);
        if ($completed) {
            DB::table('pago')->where('id', $payment)->update(['estado' => 'Completado', 'fecha_confirmacion' => DB::raw('clock_timestamp()')]);
        }
        DB::table('cita')->where('id', $cita)->update(['estado' => 'Cancelada', 'motivo_cancelacion' => 'Cancelación sintética']);

        return [$admin, $second, $payment];
    }

    public function test_two_gateway_processes_capture_only_once(): void
    {
        [$admin,$second,$payment] = $this->paypalRaceFixture();
        $requests = [];
        foreach ([$admin, $second] as $actor) {
            $requests[] = ['actor' => $actor, 'operation' => 'unused', 'uri' => '/finanzas/pagos/'.$payment.'/paypal', 'payload' => [], 'paypal_fixture' => $payment];
        }
        $this->assertSame([302, 302], $this->race($requests));
        $events = file_get_contents($this->lastGatewayEvents);
        $this->assertSame(1, substr_count($events, 'CAPTURE_START'));
        $this->assertDatabaseHas('pago', ['id' => $payment, 'estado' => 'Completado']);
    }

    public function test_two_gateway_processes_refund_only_once(): void
    {
        [$admin,$second,$payment] = $this->paypalRaceFixture(true);
        $actor = User::find($admin);
        Clinic::transaction($actor, fn () => app(PayPalService::class)->prepareRefund($actor, $payment, 'Devolución sintética concurrente'));
        $requests = [];
        foreach ([$admin, $second] as $a) {
            $requests[] = ['actor' => $a, 'operation' => 'unused', 'uri' => '/finanzas/pagos/'.$payment.'/paypal', 'payload' => [], 'paypal_fixture' => $payment];
        }
        $this->assertSame([302, 302], $this->race($requests));
        $this->assertSame(1, substr_count(file_get_contents($this->lastGatewayEvents), 'REFUND_START'));
        $this->assertDatabaseHas('pago', ['id' => $payment, 'estado' => 'Reembolsado']);
    }

    public function test_capture_and_refund_cannot_run_financial_operations_simultaneously(): void
    {
        [$admin,$second,$payment] = $this->paypalRaceFixture();
        $requests = [['actor' => $admin, 'operation' => 'unused', 'uri' => '/finanzas/pagos/'.$payment.'/paypal', 'payload' => [], 'paypal_fixture' => $payment],
            ['actor' => $second, 'operation' => 'devolucion.'.$payment, 'uri' => '/finanzas/pagos/'.$payment.'/devolucion', 'payload' => ['motivo' => 'Solicitud concurrente de devolución'], 'paypal_fixture' => $payment]];
        $this->assertContains($this->race($requests), [[302, 302], [302, 422]]);
        $events = file_get_contents($this->lastGatewayEvents);
        $this->assertSame(1, substr_count($events, 'CAPTURE_START'));
        if (str_contains($events, 'REFUND_START')) {
            $this->assertTrue(strpos($events, 'CAPTURE_END') < strpos($events, 'REFUND_START'));
        }
    }

    public function test_two_sales_cannot_sell_the_same_final_units(): void
    {
        [$admin,$second,$product,$lot] = $this->inventoryRaceFixture(true);
        $requests = [];
        foreach ([$admin, $second] as $actor) {
            $requests[] = ['actor' => $actor, 'operation' => 'venta', 'uri' => '/abastecimiento/ventas', 'payload' => ['paciente_id' => DB::table('paciente')->value('id'), 'metodo' => 'Efectivo', 'recibido' => '7.00', 'lineas' => [['producto_id' => $product, 'cantidad' => 7, 'precio_esperado' => '1.00']]]];
        }
        $this->assertSame([302, 422], $this->race($requests));
        $this->assertDatabaseCount('venta', 1);
        $this->assertDatabaseCount('pago', 1);
        $this->assertSame(3, app(InventarioService::class)->balance($lot));
    }

    public function test_two_operators_cannot_withdraw_the_same_final_units(): void
    {
        [$admin, $second, $product, $lot] = $this->inventoryRaceFixture();
        $requests = [];
        foreach ([$admin, $second] as $actor) {
            $requests[] = ['actor' => $actor, 'operation' => 'movimiento.'.$lot, 'uri' => '/abastecimiento/lotes/'.$lot.'/movimientos',
                'payload' => ['producto_id' => $product, 'tipo' => 'Salida', 'cantidad' => 7, 'motivo' => 'Salida concurrente']];
        }
        $this->assertSame([302, 422], $this->race($requests));
        $this->assertSame(3, app(InventarioService::class)->balance($lot));
        $this->assertDatabaseCount('movimiento_inventario', 2);
        $this->assertSame(2, DB::table('bitacora')->where('entidad', 'movimiento_inventario')->count());
    }

    public function test_simultaneous_replay_of_a_movement_records_only_one_exit(): void
    {
        [$admin, $second, $product, $lot] = $this->inventoryRaceFixture();
        $request = ['actor' => $admin, 'key' => OperacionWeb::token(), 'operation' => 'movimiento.'.$lot,
            'uri' => '/abastecimiento/lotes/'.$lot.'/movimientos', 'payload' => ['producto_id' => $product, 'tipo' => 'Salida', 'cantidad' => 7, 'motivo' => 'Reintento de mismo formulario']];
        $this->assertSame([302, 302], $this->race([$request, $request]));
        $this->assertSame(3, app(InventarioService::class)->balance($lot));
        $this->assertDatabaseCount('movimiento_inventario', 2);
    }

    public function test_lot_blocking_and_ordinary_exit_follow_the_same_lock_order(): void
    {
        [$admin, $second, $product, $lot] = $this->inventoryRaceFixture();
        $version = app(LoteService::class)->version(DB::table('lote')->find($lot));
        $requests = [
            ['actor' => $admin, 'operation' => 'movimiento.'.$lot, 'uri' => '/abastecimiento/lotes/'.$lot.'/movimientos',
                'payload' => ['producto_id' => $product, 'tipo' => 'Salida', 'cantidad' => 7, 'motivo' => 'Salida disputando bloqueo']],
            ['actor' => $second, 'operation' => 'lote.'.$lot, 'uri' => '/abastecimiento/lotes/'.$lot,
                'payload' => ['producto_id' => $product, 'numero_lote' => 'RACE', 'fecha_vencimiento' => null, 'estado' => 'Bloqueado', 'motivo' => 'Bloqueo concurrente', 'version' => $version]],
        ];
        $this->assertContains($this->race($requests), [[302, 302], [302, 422]]);
        $this->assertDatabaseHas('lote', ['id' => $lot, 'estado' => 'Bloqueado']);
        $this->assertSame(0, (int) app(InventarioService::class)->lots()->where('l.id', $lot)->first()->disponible);
        $this->assertContains(app(InventarioService::class)->balance($lot), [3, 10]);
    }

    public function test_two_independent_http_processes_cannot_reserve_the_same_slot(): void
    {
        $this->seedVidaSalud();
        $payload = $this->appointmentPayload();
        $requests = [];
        foreach (['recepcion', 'admin'] as $account) {
            $requests[] = ['actor' => $this->account($account)->id, 'operation' => 'cita', 'uri' => '/citas', 'payload' => $payload];
        }
        $this->assertSame([302, 422], $this->race($requests));
        $this->assertDatabaseCount('cita', 1);
        $this->assertDatabaseCount('evento_cita', 1);
    }

    public function test_two_http_processes_cannot_record_the_same_analysis_twice(): void
    {
        $this->seedVidaSalud();
        $order = $this->laboratoryOrder();
        $actor = DB::table('usuario')->where('rol_id', DB::table('rol')->where('nombre', 'Laboratorista')->value('id'))->value('id');
        $request = ['actor' => $actor, 'operation' => 'resultado.'.$order['items'][0], 'uri' => '/laboratorio/analisis/'.$order['items'][0].'/resultado', 'payload' => ['resultado' => 'Resultado sintético concurrente']];
        $this->assertSame([302, 422], $this->race([$request, $request]));
        $this->assertDatabaseCount('resultado_laboratorio', 1);
        $this->assertDatabaseHas('orden_laboratorio', ['id' => $order['order'], 'estado' => 'Completada']);
    }
}
