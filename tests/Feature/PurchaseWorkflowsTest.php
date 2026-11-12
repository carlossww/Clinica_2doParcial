<?php

namespace Tests\Feature;

use App\Modules\GestionInventarioMedico\Services\InventarioService;
use App\Modules\Shared\Services\OperacionWeb;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class PurchaseWorkflowsTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    private int $product;

    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVidaSalud();
        $this->actingAs($this->account('admin'));
        $this->product = DB::table('producto')->insertGetId(['nombre' => 'Compra sintética', 'tipo' => 'Medicamento', 'unidad_medida' => 'unidad']);
        $this->supplier = DB::table('proveedor')->insertGetId(['nombre' => 'Proveedor sintético']);
    }

    private function signed(string $operation, array $data): array
    {
        $key = OperacionWeb::token();

        return $data + ['clave' => $key, 'firma' => OperacionWeb::signature($this->account('admin'), $operation, $key), 'paso' => 'guardar'];
    }

    private function purchase(): int
    {
        $this->post(route('supply.purchases.store'), $this->signed('compra', ['proveedor_id' => $this->supplier, 'lineas' => [['producto_id' => $this->product, 'cantidad' => 20, 'precio_unitario' => '1.25']]]))->assertRedirect();

        return DB::table('compra')->orderByDesc('id')->value('id');
    }

    private function receipt(int $id, int $qty, string $lot = 'A'): array
    {
        return $this->signed('recepcion_compra.'.$id, ['lineas' => [['detalle_id' => DB::table('detalle_compra')->where('compra_id', $id)->value('id'), 'cantidad' => $qty, 'numero_lote' => $lot, 'fecha_vencimiento' => '2030-01-01']]]);
    }

    public function test_create_review_multirow_and_receive_partial_then_final_without_duplicate(): void
    {
        $this->get(route('finance.configuration'))->assertOk();
        $this->get(route('home'))->assertSee('Configuración financiera');
        $this->get(route('supply.purchases.create'))->assertOk();
        $data = $this->signed('compra', ['proveedor_id' => $this->supplier, 'lineas' => [['producto_id' => $this->product, 'cantidad' => 20, 'precio_unitario' => '1.25']]]);
        $this->post(route('supply.purchases.store'), array_replace($data, ['paso' => 'agregar']))->assertOk()->assertSee('Producto 2');
        $this->post(route('supply.purchases.store'), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('25.00')->assertSee('lineas[0][producto_id]', false);
        $this->assertDatabaseCount('compra', 0);
        $this->post(route('supply.purchases.store'), $data)->assertRedirect();
        $this->post(route('supply.purchases.store'), $data)->assertRedirect();
        $id = DB::table('compra')->value('id');
        $this->assertDatabaseHas('compra', ['total' => '25.00', 'estado' => 'Registrada']);
        $this->get(route('supply.purchases.show', $id))->assertOk();
        $receive = $this->receipt($id, 12);
        $this->post(route('supply.purchases.receive', $id), array_replace($receive, ['paso' => 'revisar']))->assertOk();
        $this->post(route('supply.purchases.receive', $id), $receive)->assertRedirect();
        $this->post(route('supply.purchases.receive', $id), $receive)->assertRedirect();
        $this->assertDatabaseHas('compra', ['id' => $id, 'estado' => 'Parcial']);
        $this->assertDatabaseCount('movimiento_inventario', 1);
        $this->postJson(route('supply.purchases.receive', $id), $this->receipt($id, 9))->assertUnprocessable();
        $this->post(route('supply.purchases.receive', $id), $this->receipt($id, 8, 'B'))->assertRedirect();
        $this->assertDatabaseHas('compra', ['id' => $id, 'estado' => 'Recibida']);
        $this->assertDatabaseCount('lote', 2);
        $this->postJson(route('supply.purchases.receive', $id), $this->receipt($id, 1))->assertUnprocessable();
        $this->get(route('supply.purchases.index'))->assertOk()->assertSee('Proveedor sintético');
    }

    public function test_split_receipt_lots_and_receipt_of_previous_inactive_entities(): void
    {
        $id = $this->purchase();
        DB::table('producto')->where('id', $this->product)->update(['activo' => false]);
        DB::table('proveedor')->where('id', $this->supplier)->update(['activo' => false]);
        $one = $this->receipt($id, 6);
        $two = $this->receipt($id, 14, 'B');
        $one['lineas'][] = $two['lineas'][0];
        $this->post(route('supply.purchases.receive', $id), $one)->assertRedirect();
        $this->assertSame(20, (int) app(InventarioService::class)->products()->where('p.id', $this->product)->first()->saldo);
    }

    public function test_wrong_detail_expired_lot_and_overflow_are_atomic(): void
    {
        $id = $this->purchase();
        $second = $this->purchase();
        $data = $this->receipt($second, 1);
        $this->postJson(route('supply.purchases.receive', $id), $this->signed('recepcion_compra.'.$id, ['lineas' => $data['lineas']]))->assertNotFound();
        $expired = $this->receipt($id, 1);
        $expired['lineas'][0]['fecha_vencimiento'] = '2020-01-01';
        $this->postJson(route('supply.purchases.receive', $id), $expired)->assertUnprocessable();
        $bad = $this->receipt($id, 12);
        $bad['lineas'][] = $bad['lineas'][0];
        $this->postJson(route('supply.purchases.receive', $id), $bad)->assertUnprocessable();
        $this->assertDatabaseCount('movimiento_inventario', 0);
        $this->assertDatabaseCount('lote', 0);
        $this->postJson(route('supply.purchases.store'), $this->signed('compra', ['proveedor_id' => $this->supplier, 'lineas' => [['producto_id' => $this->product, 'cantidad' => 2147483647, 'precio_unitario' => '9999999999.99']]]))->assertUnprocessable();
    }

    public function test_cancel_without_receipts_and_permissions(): void
    {
        $id = $this->purchase();
        $data = $this->signed('cancelar_compra.'.$id, []);
        $this->post(route('supply.purchases.cancel', $id), $data)->assertRedirect();
        $this->assertDatabaseHas('compra', ['estado' => 'Cancelada']);
        $id = $this->purchase();
        $this->post(route('supply.purchases.receive', $id), $this->receipt($id, 1))->assertRedirect();
        $this->postJson(route('supply.purchases.cancel', $id), $this->signed('cancelar_compra.'.$id, []))->assertUnprocessable();
        $this->actingAs($this->account('recepcion'))->get(route('supply.purchases.index'))->assertForbidden();
        $this->postJson(route('supply.purchases.receive', $id), $this->receipt($id, 1))->assertForbidden();
    }
}
