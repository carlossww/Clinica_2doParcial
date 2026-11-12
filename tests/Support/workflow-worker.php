<?php

use App\Modules\Shared\Services\OperacionWeb;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\TestDatabaseSafety;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    $app['config']->set('database.connections.pgsql', $input['database']);
    $app['config']->set('session.driver', 'array');
    $app['config']->set('cache.default', 'array');
    $app['config']->set('app.key', $input['app_key']);
    DB::purge('pgsql');
    TestDatabaseSafety::assertApplication($app);
    if (isset($input['paypal_fixture'])) {
        if (realpath(dirname($input['barrier'])) !== realpath(base_path('.runtime/diez-cu'))) {
            throw new RuntimeException('Ruta de prueba inválida.');
        }
        $app['config']->set('services.paypal', ['enabled' => true, 'client_id' => 'fixture-client', 'client_secret' => 'fixture-secret', 'merchant_id' => 'MERCHANT-TEST']);
        $state = $input['barrier'].'.paypal';
        $payment = (int) $input['paypal_fixture'];
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($state, $payment) {
            $p = DB::table('pago')->find($payment);
            $url = $request->url();
            $factory = Http::class;
            if (! str_starts_with($url, 'https://api-m.sandbox.paypal.com/')) {
                throw new RuntimeException('URL ajena a Sandbox.');
            }
            if (str_ends_with($url, '/oauth2/token')) {
                return $factory::response(['access_token' => 'fixture-token']);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/capture')) {
                file_put_contents($state.'.events', "CAPTURE_START\n", FILE_APPEND | LOCK_EX);
                usleep(350000);
                file_put_contents($state.'.captured', '1', LOCK_EX);
                file_put_contents($state.'.events', "CAPTURE_END\n", FILE_APPEND | LOCK_EX);

                return $factory::response(['id' => 'ORDER-'.$p->id]);
            }
            if ($request->method() === 'POST' && str_ends_with($url, '/refund')) {
                file_put_contents($state.'.events', "REFUND_START\n", FILE_APPEND | LOCK_EX);
                usleep(350000);
                file_put_contents($state.'.events', "REFUND_END\n", FILE_APPEND | LOCK_EX);

                return $factory::response(['id' => 'REFUND-'.$p->id]);
            }
            $amount = ['currency_code' => 'USD', 'value' => $p->monto_pasarela];
            if (str_contains($url, '/checkout/orders/')) {
                $unit = ['reference_id' => (string) $p->id, 'custom_id' => (string) $p->id, 'invoice_id' => 'VS-SB-'.$p->clave_pasarela, 'payee' => ['merchant_id' => $p->destinatario_paypal], 'amount' => $amount];
                $done = is_file($state.'.captured');
                if ($done) {
                    $unit['payments'] = ['captures' => [['id' => 'CAPTURE-'.$p->id, 'status' => 'COMPLETED', 'amount' => $amount, 'final_capture' => true]]];
                }

                return $factory::response(['id' => 'ORDER-'.$p->id, 'intent' => 'CAPTURE', 'status' => $done ? 'COMPLETED' : 'APPROVED', 'purchase_units' => [$unit]]);
            }
            if (str_contains($url, '/payments/captures/')) {
                return $factory::response(['id' => 'CAPTURE-'.$p->id, 'status' => 'COMPLETED', 'amount' => $amount, 'supplementary_data' => ['related_ids' => ['order_id' => 'ORDER-'.$p->id]]]);
            }
            if (str_contains($url, '/payments/refunds/')) {
                return $factory::response(['id' => 'REFUND-'.$p->id, 'status' => 'COMPLETED', 'amount' => $amount]);
            }

            return $factory::response([], 500);
        });
    }
    DB::statement("set statement_timeout='15s'");
    Auth::loginUsingId($input['actor']);
    $user = Auth::user();
    $key = $input['key'] ?? OperacionWeb::token();
    $payload = $input['payload'] + ['clave' => $key, 'firma' => OperacionWeb::signature($user, $input['operation'], $key), 'paso' => 'guardar'];
    touch($input['barrier'].'.'.$input['name'].'.ready');
    $deadline = microtime(true) + 15;
    while (! is_file($input['barrier'].'.start')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrera de prueba agotada.');
        }
        usleep(10000);
    }
    $kernel = $app->make(HttpKernel::class);
    $request = Request::create($input['uri'], 'POST', $payload, server: ['HTTP_ACCEPT' => 'application/json']);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    echo json_encode(['status' => $response->getStatusCode()]);
} catch (Throwable $error) {
    fwrite(STDERR, $error::class.' en proceso de concurrencia aislado.');
    exit(1);
}
