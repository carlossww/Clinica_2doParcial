<?php

namespace Tests\Unit;

use App\Modules\GestionFinanciera\Services\PasarelaException;
use App\Modules\GestionFinanciera\Services\PayPalGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayPalTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paypal' => ['enabled' => true, 'client_id' => 'test-client', 'client_secret' => 'test-secret', 'merchant_id' => 'test-merchant']]);
        Http::preventStrayRequests();
    }

    public function test_transport_keeps_certificate_verification_enabled(): void
    {
        $gateway = new PayPalGateway;
        $http = (new \ReflectionMethod($gateway, 'transport'))->invoke($gateway);
        $options = $http->getOptions();
        $this->assertNotFalse($options['verify']);
        if (is_string($options['verify'])) {
            $this->assertFileIsReadable($options['verify']);
        }
        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'test-token']),
            '*/v2/checkout/orders/TEST-ORDER' => Http::response(['id' => 'TEST-ORDER']),
        ]);
        $this->assertSame('TEST-ORDER', $gateway->order('TEST-ORDER')['id']);
        Http::assertSentCount(2);
    }

    public function test_certificate_failure_has_specific_message_without_secret_details(): void
    {
        Http::fake(['*' => Http::failedConnection('cURL error 60: certificate failure test-secret')]);
        try {
            (new PayPalGateway)->order('TEST-ORDER');
            $this->fail('Debe rechazar la conexión.');
        } catch (PasarelaException $error) {
            $this->assertStringContainsString('certificado HTTPS', $error->getMessage());
            $this->assertStringNotContainsString('test-secret', $error->getMessage());
        }
    }

    public function test_authentication_failure_reports_http_status_without_response_body(): void
    {
        Http::fake(['*' => Http::response(['secret' => 'private-response'], 401)]);
        try {
            (new PayPalGateway)->order('TEST-ORDER');
            $this->fail('Debe rechazar las credenciales.');
        } catch (PasarelaException $error) {
            $this->assertStringContainsString('HTTP 401', $error->getMessage());
            $this->assertStringNotContainsString('private-response', $error->getMessage());
        }
    }
}
