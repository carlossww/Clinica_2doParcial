<?php

namespace Tests\Feature;

use App\Modules\Shared\Services\CatalogoAbastecimientoService;
use App\Modules\GestionInventarioMedico\Services\InventarioService;
use App\Modules\GestionInventarioMedico\Services\LoteService;
use App\Modules\Shared\Services\OperacionWeb;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsVidaSalud;
use Tests\TestCase;

class InventoryWorkflowsTest extends TestCase
{
    use RefreshDatabase, SeedsVidaSalud;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedVidaSalud();
        $this->actingAs($this->account('admin'));
    }

    private function signed(string $operation, array $data = []): array
    {
        unset($data['clave'], $data['firma'], $data['paso']);
        $key = OperacionWeb::token();

        return $data + ['clave' => $key, 'firma' => OperacionWeb::signature($this->account('admin'), $operation, $key), 'paso' => 'guardar'];
    }

    private function product(string $type = 'Medicamento'): int
    {
        return DB::table('producto')->insertGetId(['nombre' => 'Producto de prueba', 'tipo' => $type, 'unidad_medida' => 'unidad', 'precio_venta' => '5.20']);
    }

    private function lot(int $product, array $values = []): int
    {
        return DB::table('lote')->insertGetId($values + ['producto_id' => $product, 'numero_lote' => 'LOTE-TEST', 'fecha_vencimiento' => now('America/La_Paz')->addYear()->toDateString()]);
    }

    private function movement(int $product, int $lot, string $type, int $quantity = 10): array
    {
        return $this->signed('movimiento.'.$lot, ['producto_id' => $product, 'tipo' => $type, 'cantidad' => $quantity, 'motivo' => 'Conteo físico sintético']);
    }

    public function test_supplier_review_save_search_deactivate_reactivate_and_replay(): void
    {
        $this->get(route('supply.proveedores.index'))->assertOk()->assertSee('No hay coincidencias');
        $this->get(route('supply.proveedores.create'))->assertOk()->assertSee('NIT');
        $data = $this->signed('proveedores.nuevo', ['nombre' => 'Proveedor Alfa', 'nit' => ' abc-123 ', 'correo' => 'TEST@example.com', 'activo' => '1', 'version' => 'nuevo']);
        $this->post(route('supply.proveedores.save'), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('Confirmar y guardar');
        $this->assertDatabaseCount('proveedor', 0);
        $this->post(route('supply.proveedores.save'), $data)->assertRedirect();
        $this->post(route('supply.proveedores.save'), $data)->assertRedirect();
        $this->assertDatabaseCount('proveedor', 1);
        $id = DB::table('proveedor')->value('id');
        $this->assertDatabaseHas('proveedor', ['id' => $id, 'nit' => 'ABC-123', 'correo' => 'test@example.com']);
        $this->get(route('supply.proveedores.index', ['q' => 'abc-123']))->assertOk()->assertSee('Proveedor Alfa');
        foreach ([0, 1] as $active) {
            $row = DB::table('proveedor')->find($id);
            $this->get(route('supply.proveedores.edit', $id))->assertOk();
            $this->post(route('supply.proveedores.save', $id), $this->signed('proveedores.'.$id, ['nombre' => $row->nombre, 'nit' => $row->nit,
                'correo' => $row->correo, 'activo' => $active, 'version' => app(CatalogoAbastecimientoService::class)->version('proveedores', $row)]))->assertRedirect();
            $this->assertDatabaseHas('proveedor', ['id' => $id, 'activo' => (bool) $active]);
        }
        $this->assertSame(3, DB::table('bitacora')->where('entidad', 'proveedor')->count());
    }

    public function test_duplicate_nit_invalid_supplier_fields_and_stale_update_are_rejected(): void
    {
        $id = DB::table('proveedor')->insertGetId(['nombre' => 'Anterior', 'nit' => 'NIT-1', 'activo' => false]);
        $data = $this->signed('proveedores.nuevo', ['nombre' => 'Duplicado', 'nit' => ' nit-1 ', 'activo' => '1', 'version' => 'nuevo']);
        $this->postJson(route('supply.proveedores.save'), $data)->assertUnprocessable();
        $this->postJson(route('supply.proveedores.save'), array_replace($data, ['nit' => null, 'correo' => 'no-es-correo']))->assertUnprocessable();
        $row = DB::table('proveedor')->find($id);
        $version = app(CatalogoAbastecimientoService::class)->version('proveedores', $row);
        DB::table('proveedor')->where('id', $id)->update(['nombre' => 'Cambio concurrente']);
        $this->postJson(route('supply.proveedores.save', $id), $this->signed('proveedores.'.$id, ['nombre' => 'No sobrescribir', 'activo' => '1', 'version' => $version]))->assertUnprocessable();
        $this->assertDatabaseHas('proveedor', ['id' => $id, 'nombre' => 'Cambio concurrente']);
    }

    public function test_product_catalog_preserves_precise_prices_and_historical_units(): void
    {
        $data = $this->signed('productos.nuevo', ['nombre' => 'Insumo nuevo', 'tipo' => 'Insumo', 'unidad_medida' => 'caja', 'precio_venta' => '12.5', 'activo' => '1', 'version' => 'nuevo']);
        $this->get(route('supply.productos.create'))->assertOk();
        $this->post(route('supply.productos.save'), array_replace($data, ['paso' => 'revisar']))->assertOk();
        $this->post(route('supply.productos.save'), $data)->assertRedirect();
        $id = DB::table('producto')->value('id');
        $this->assertDatabaseHas('producto', ['id' => $id, 'precio_venta' => '12.50']);
        $this->get(route('supply.productos.index', ['tipo' => 'Insumo']))->assertOk()->assertSee('12.50');
        $lot = $this->lot($id);
        $this->post(route('supply.movements.store', $lot), $this->movement($id, $lot, 'Entrada'))->assertRedirect();
        $row = DB::table('producto')->find($id);
        $edit = ['nombre' => $row->nombre, 'tipo' => $row->tipo, 'unidad_medida' => 'unidad', 'precio_venta' => '10', 'activo' => '1', 'version' => app(CatalogoAbastecimientoService::class)->version('productos', $row)];
        $this->postJson(route('supply.productos.save', $id), $this->signed('productos.'.$id, $edit))->assertUnprocessable();
        $this->post(route('supply.productos.save', $id), $this->signed('productos.'.$id, array_replace($edit, ['unidad_medida' => 'caja', 'activo' => '0'])))->assertRedirect();
        $this->assertSame(0, (int) app(InventarioService::class)->lots()->where('l.id', $lot)->first()->disponible);
        $this->assertSame(10, app(InventarioService::class)->balance($lot));
    }

    public function test_lot_review_registration_unique_number_and_expiry_requirements(): void
    {
        $product = $this->product();
        $data = $this->signed('lote.nuevo', ['producto_id' => $product, 'numero_lote' => ' lote-a ', 'fecha_vencimiento' => '2030-01-01', 'estado' => 'Disponible', 'motivo' => 'Alta sintética', 'version' => 'nuevo']);
        $this->get(route('supply.lots.create'))->assertOk();
        $this->post(route('supply.lots.save'), array_replace($data, ['paso' => 'revisar']))->assertOk();
        $this->assertDatabaseCount('lote', 0);
        $this->post(route('supply.lots.save'), $data)->assertRedirect();
        $this->post(route('supply.lots.save'), $data)->assertRedirect();
        $this->assertDatabaseHas('lote', ['numero_lote' => 'LOTE-A']);
        $this->assertDatabaseCount('movimiento_inventario', 0);
        $this->postJson(route('supply.lots.save'), $this->signed('lote.nuevo', array_replace($data, ['numero_lote' => 'LOTE-a'])))->assertUnprocessable();
        $this->postJson(route('supply.lots.save'), array_replace($data, ['fecha_vencimiento' => null]))->assertUnprocessable();
        $insumo = $this->product('Insumo');
        $this->post(route('supply.lots.save'), $this->signed('lote.nuevo', ['producto_id' => $insumo, 'numero_lote' => 'SIN-FECHA', 'fecha_vencimiento' => null, 'estado' => 'Disponible', 'motivo' => 'Insumo sin caducidad', 'version' => 'nuevo']))->assertRedirect();
    }

    public function test_lot_metadata_locks_after_movements_and_state_reason_is_audited_once(): void
    {
        $product = $this->product();
        $lot = $this->lot($product);
        $this->post(route('supply.movements.store', $lot), $this->movement($product, $lot, 'Entrada'))->assertRedirect();
        $row = DB::table('lote')->find($lot);
        $data = ['producto_id' => $product, 'numero_lote' => $row->numero_lote, 'fecha_vencimiento' => $row->fecha_vencimiento,
            'estado' => 'Bloqueado', 'motivo' => 'Retiro por verificación de calidad', 'version' => app(LoteService::class)->version($row)];
        $this->postJson(route('supply.lots.save', $lot), $this->signed('lote.'.$lot, array_replace($data, ['numero_lote' => 'ALTERADO'])))->assertUnprocessable();
        $before = DB::table('bitacora')->where('entidad', 'lote')->count();
        $signed = $this->signed('lote.'.$lot, $data);
        $this->post(route('supply.lots.save', $lot), $signed)->assertRedirect();
        $this->post(route('supply.lots.save', $lot), $signed)->assertRedirect();
        $this->assertSame($before + 1, DB::table('bitacora')->where('entidad', 'lote')->count());
        $event = DB::table('bitacora')->where('entidad', 'lote')->orderByDesc('id')->first();
        $history = json_decode($event->descripcion, true);
        $this->assertSame('Disponible', $history['anterior']['estado']);
        $this->assertSame('Bloqueado', $history['nuevo']['estado']);
        $this->assertSame($data['motivo'], $history['motivo']);
        $this->assertSame($this->account('admin')->id, $event->usuario_id);
        $this->assertSame(10, app(InventarioService::class)->balance($lot));
    }

    public function test_manual_movements_review_replay_balance_and_kardex(): void
    {
        $product = $this->product();
        $lot = $this->lot($product);
        $this->get(route('supply.movements.create', $lot))->assertOk();
        $data = $this->movement($product, $lot, 'Entrada');
        $this->post(route('supply.movements.store', $lot), array_replace($data, ['paso' => 'revisar']))->assertOk()->assertSee('Confirmar y guardar');
        $this->assertDatabaseCount('movimiento_inventario', 0);
        $this->post(route('supply.movements.store', $lot), $data)->assertRedirect();
        $this->post(route('supply.movements.store', $lot), $data)->assertRedirect();
        foreach ([['Salida', 3], ['AjusteEntrada', 2], ['AjusteSalida', 1]] as [$type, $quantity]) {
            $this->post(route('supply.movements.store', $lot), $this->movement($product, $lot, $type, $quantity))->assertRedirect();
        }
        $this->assertSame(8, app(InventarioService::class)->balance($lot));
        $this->assertDatabaseCount('movimiento_inventario', 4);
        $this->assertSame(4, DB::table('bitacora')->where('entidad', 'movimiento_inventario')->count());
        $this->get(route('supply.inventory.index'))->assertOk()->assertSee('Producto de prueba');
        $this->get(route('supply.inventory.show', $product))->assertOk()->assertSee('LOTE-TEST');
        $this->get(route('supply.lots.index'))->assertOk();
        $this->get(route('supply.kardex', ['lote_id' => $lot]))->assertOk()->assertSee('Conteo físico sintético')->assertViewHas('rows', fn ($rows) => $rows->total() === 4 && (int) $rows->first()->saldo_lote === 8);
        $this->postJson(route('supply.movements.store', $lot), $this->movement($product, $lot, 'Salida', 9))->assertUnprocessable();
        $this->assertSame(8, app(InventarioService::class)->balance($lot));
    }

    public function test_expired_blocked_and_inactive_inventory_is_physical_but_not_available(): void
    {
        foreach (['vencido', 'bloqueado', 'inactivo'] as $condition) {
            $product = $this->product();
            $lot = $this->lot($product, ['numero_lote' => $condition,
                'fecha_vencimiento' => now('America/La_Paz')->addDays($condition === 'vencido' ? -1 : 1)->toDateString(),
                'estado' => $condition === 'bloqueado' ? 'Bloqueado' : 'Disponible']);
            if ($condition === 'inactivo') {
                DB::table('producto')->where('id', $product)->update(['activo' => false]);
            }
            $this->postJson(route('supply.movements.store', $lot), $this->movement($product, $lot, 'Entrada'))->assertUnprocessable();
            $this->post(route('supply.movements.store', $lot), $this->movement($product, $lot, 'AjusteEntrada'))->assertRedirect();
            $this->postJson(route('supply.movements.store', $lot), $this->movement($product, $lot, 'Salida', 1))->assertUnprocessable();
            $this->assertSame(0, (int) app(InventarioService::class)->lots()->where('l.id', $lot)->first()->disponible);
            $this->post(route('supply.movements.store', $lot), $this->movement($product, $lot, 'AjusteSalida', 2))->assertRedirect();
            $this->assertSame(8, app(InventarioService::class)->balance($lot));
        }
        $product = $this->product();
        $lot = $this->lot($product, ['fecha_vencimiento' => now('America/La_Paz')->toDateString()]);
        $this->post(route('supply.movements.store', $lot), $this->movement($product, $lot, 'Entrada'))->assertRedirect();
        $this->assertSame(10, (int) app(InventarioService::class)->lots()->where('l.id', $lot)->first()->disponible);
    }

    public function test_ledger_filters_keep_opening_balance_and_include_the_entire_bolivian_final_day(): void
    {
        $product = $this->product();
        $lot = $this->lot($product);
        foreach ([['Entrada', 10, '2026-11-01 03:59:59+00'], ['Salida', 2, '2026-11-02 03:59:59+00'], ['Salida', 1, '2026-11-02 04:00:00+00']] as [$type, $quantity, $date]) {
            DB::table('movimiento_inventario')->insert(['producto_id' => $product, 'lote_id' => $lot, 'usuario_id' => $this->account('admin')->id,
                'tipo' => $type, 'cantidad' => $quantity, 'motivo' => 'Historia sintética', 'fecha' => $date]);
        }
        $filters = ['lote_id' => $lot, 'desde' => '2026-11-01', 'hasta' => '2026-11-01', 'tipo' => 'Salida'];
        $this->get(route('supply.kardex', $filters))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && (int) $rows->first()->saldo_lote === 8);
        $this->get(route('supply.kardex', ['hasta' => '2026-11-01']))->assertOk();
        $this->getJson(route('supply.kardex', ['desde' => '2026-11-02', 'hasta' => '2026-11-01']))->assertUnprocessable();
    }

    public function test_pagination_keeps_filters_and_balances_over_all_previous_movements(): void
    {
        $product = $this->product('Insumo');
        $lot = $this->lot($product, ['fecha_vencimiento' => null]);
        for ($i = 0; $i < 25; $i++) {
            DB::table('movimiento_inventario')->insert(['producto_id' => $product, 'lote_id' => $lot, 'usuario_id' => $this->account('admin')->id, 'tipo' => 'Entrada', 'cantidad' => 1, 'motivo' => 'Paginación sintética']);
        }
        $this->get(route('supply.kardex', ['producto_id' => $product, 'page' => 2]))->assertOk()->assertSee('producto_id='.$product)->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->count() === 5);
        $this->assertSame(25, (int) app(InventarioService::class)->products()->where('p.id', $product)->first()->disponible);
        $this->get(route('supply.inventory.index', ['q' => 'inexistente']))->assertOk()->assertSee('No hay productos');
        $this->get(route('supply.lots.index', ['q' => 'inexistente']))->assertOk()->assertSee('No hay lotes');
    }

    public function test_movement_immutability_and_lot_identity_are_enforced_by_postgresql(): void
    {
        $product = $this->product();
        $lot = $this->lot($product);
        $this->post(route('supply.movements.store', $lot), $this->movement($product, $lot, 'Entrada'))->assertRedirect();
        foreach ([fn () => DB::table('movimiento_inventario')->update(['cantidad' => 1]), fn () => DB::table('movimiento_inventario')->delete(), fn () => DB::table('lote')->where('id', $lot)->update(['numero_lote' => 'ALTERADO'])] as $write) {
            try {
                DB::transaction($write);
                $this->fail('La escritura histórica debió rechazarse.');
            } catch (QueryException $error) {
                $this->assertSame('P0001', $error->errorInfo[0]);
            }
        }
        $this->assertSame(10, app(InventarioService::class)->balance($lot));
    }

    public function test_manipulated_actor_origin_quantity_product_and_missing_resources_are_rejected(): void
    {
        $product = $this->product();
        $lot = $this->lot($product);
        $data = $this->movement($product, $lot, 'Entrada');
        foreach ([['usuario_id' => 999], ['fecha' => '2020-01-01'], ['detalle_compra_id' => 1], ['detalle_venta_lote_id' => 1], ['cantidad' => 0], ['cantidad' => -1], ['cantidad' => '1.5'], ['cantidad' => '2147483648'], ['motivo' => '   '], ['tipo' => 'Arbitrario']] as $change) {
            $this->postJson(route('supply.movements.store', $lot), array_replace($data, $change))->assertUnprocessable();
        }
        $this->postJson(route('supply.movements.store', $lot), array_replace($data, ['producto_id' => $this->product()]))->assertNotFound();
        $this->get(route('supply.movements.create', 999999))->assertNotFound();
        $this->get(route('supply.inventory.show', 999999))->assertNotFound();
        $this->assertDatabaseCount('movimiento_inventario', 0);
    }

    public function test_roles_without_inventory_permissions_cannot_read_or_write_new_modules(): void
    {
        $product = $this->product();
        $lot = $this->lot($product);
        foreach (['recepcion', 'medico', 'paciente'] as $login) {
            $this->actingAs($this->account($login));
            foreach (['supply.proveedores.index', 'supply.productos.index', 'supply.lots.index', 'supply.inventory.index', 'supply.kardex'] as $route) {
                $this->get(route($route))->assertForbidden();
            }
            $this->postJson(route('supply.movements.store', $lot), $this->movement($product, $lot, 'Entrada'))->assertForbidden();
        }
    }

    public function test_read_only_inventory_permission_does_not_grant_catalog_or_movement_writes(): void
    {
        $user = $this->account('recepcion');
        DB::table('rol_permiso')->insert(['rol_id' => $user->rol_id, 'permiso_id' => DB::table('permiso')->where('codigo', 'inventario.consultar')->value('id')]);
        $product = $this->product();
        $lot = $this->lot($product);
        $this->actingAs($user)->get(route('supply.inventory.show', $product))->assertOk()->assertDontSee('Registrar movimiento')->assertDontSee('Editar / cambiar estado');
        $this->get(route('supply.kardex'))->assertOk();
        $this->get(route('supply.lots.edit', $lot))->assertForbidden();
        $this->get(route('supply.movements.create', $lot))->assertForbidden();
    }

    public static function invalidProducts(): array
    {
        return [['precio_venta', '-1'], ['precio_venta', 'NaN'], ['precio_venta', '1.001'], ['precio_venta', '10000000000'], ['tipo', 'Otro'], ['nombre', '   '], ['unidad_medida', ''], ['activo', 'arbitrario']];
    }

    #[DataProvider('invalidProducts')]
    public function test_invalid_product_fields_do_not_create_records(string $field, mixed $value): void
    {
        $data = ['nombre' => 'Producto inválido sintético', 'tipo' => 'Insumo', 'unidad_medida' => 'unidad', 'precio_venta' => '20.50', 'activo' => '1', 'version' => 'nuevo'];
        $this->postJson(route('supply.productos.save'), $this->signed('productos.nuevo', array_replace($data, [$field => $value])))->assertUnprocessable();
        $this->assertDatabaseCount('producto', 0);
    }

    public function test_unused_lot_can_be_corrected_but_stale_state_change_is_rejected(): void
    {
        $product = $this->product();
        $lot = $this->lot($product);
        $row = DB::table('lote')->find($lot);
        $data = ['producto_id' => $product, 'numero_lote' => 'CORREGIDO', 'fecha_vencimiento' => '2031-01-01',
            'estado' => 'Bloqueado', 'motivo' => 'Corrección antes del uso', 'version' => app(LoteService::class)->version($row)];
        $this->post(route('supply.lots.save', $lot), $this->signed('lote.'.$lot, $data))->assertRedirect();
        $this->assertDatabaseHas('lote', ['id' => $lot, 'numero_lote' => 'CORREGIDO', 'fecha_vencimiento' => '2031-01-01']);
        $this->postJson(route('supply.lots.save', $lot), $this->signed('lote.'.$lot, array_replace($data, ['estado' => 'Disponible'])))->assertUnprocessable();
        $this->assertDatabaseHas('lote', ['id' => $lot, 'estado' => 'Bloqueado']);
    }

    public function test_product_totals_include_multiple_lots_without_multiplying_balances(): void
    {
        $product = $this->product('Insumo');
        foreach ([['A', null, 'Disponible'], ['B', '2020-01-01', 'Disponible'], ['C', null, 'Bloqueado']] as [$number, $date, $state]) {
            $lot = $this->lot($product, ['numero_lote' => $number, 'fecha_vencimiento' => $date, 'estado' => $state]);
            $this->post(route('supply.movements.store', $lot), $this->movement($product, $lot, 'AjusteEntrada', 10))->assertRedirect();
        }
        $row = app(InventarioService::class)->products()->where('p.id', $product)->first();
        $this->assertSame(30, (int) $row->saldo);
        $this->assertSame(10, (int) $row->disponible);
        $this->get(route('supply.lots.index', ['vigencia' => 'vencido']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
        $this->get(route('supply.lots.index', ['estado' => 'Bloqueado']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    public function test_supplier_pagination_preserves_search_and_inactive_filter(): void
    {
        for ($i = 0; $i < 25; $i++) {
            DB::table('proveedor')->insert(['nombre' => 'Proveedor paginado '.$i, 'activo' => false]);
        }
        $this->get(route('supply.proveedores.index', ['q' => 'paginado', 'activo' => '0', 'page' => 2]))->assertOk()
            ->assertSee('q=paginado')->assertSee('activo=0')->assertViewHas('rows', fn ($rows) => $rows->total() === 25 && $rows->count() === 5);
    }
}
