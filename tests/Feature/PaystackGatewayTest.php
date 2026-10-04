<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaystackGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('paystack.secretKey', 'sk_test_123');
        config()->set('paystack.publicKey', 'pk_test_123');
        config()->set('paystack.paymentUrl', 'https://api.paystack.co');
    }

    public function test_it_initializes_a_transaction_with_the_expected_payload(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/initialize' => Http::response([
                'status' => true,
                'message' => 'Authorization URL created',
                'data' => [
                    'authorization_url' => 'https://checkout.paystack.com/abc',
                    'reference' => 'paystack_ref_123',
                ],
            ], 200),
        ]);

        $order = ['order_number' => 98765, 'price' => 2500, 'product_title' => 'Test booking'];
        $booking = [[
            'customer_email' => 'user@example.com',
            'customer_id' => 9,
            'event_id' => 15,
            'event_title' => 'Test Event',
            'currency' => 'KES',
            'common_order' => 98765,
            'organiser_id' => 1,
            'ticket_id' => 5,
        ]];

        $result = app(PaystackService::class)->initializeTransaction($order, $booking, ['email' => 'user@example.com']);

        $this->assertSame('paystack_ref_123', $result['reference']);
        $this->assertSame('https://checkout.paystack.com/abc', $result['authorization_url']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return $request->hasHeader('Authorization', 'Bearer sk_test_123')
                && $payload['email'] === 'user@example.com'
                && $payload['amount'] === 250000
                && $payload['currency'] === 'KES'
                && !empty($payload['reference'])
                && !empty($payload['metadata']['booking_id']);
        });
    }

    public function test_it_rejects_a_webhook_without_a_valid_signature(): void
    {
        $response = $this->postJson('/paystack/webhook', [
            'event' => 'charge.success',
            'data' => ['reference' => 'paystack_ref_123'],
        ], ['x-paystack-signature' => 'invalid-signature']);

        $response->assertStatus(403);
    }

    public function test_it_verifies_a_successful_transaction_and_marks_the_booking_paid(): void
    {
        Http::fake([
            'https://api.paystack.co/transaction/verify/paystack_ref_123' => Http::response([
                'status' => true,
                'message' => 'Verification successful',
                'data' => [
                    'reference' => 'paystack_ref_123',
                    'amount' => 250000,
                    'currency' => 'KES',
                    'status' => 'success',
                    'customer' => ['id' => 'cust_45', 'email' => 'user@example.com'],
                    'metadata' => ['booking_id' => '98765'],
                ],
            ], 200),
        ]);

        Booking::create([
            'customer_id' => 2,
            'organiser_id' => 1,
            'event_id' => 15,
            'ticket_id' => 5,
            'quantity' => 1,
            'status' => 1,
            'is_paid' => 0,
            'common_order' => 98765,
            'currency' => 'KES',
            'net_price' => 2500,
            'event_title' => 'Test Event',
            'ticket_title' => 'General Admission',
            'customer_name' => 'Test User',
            'customer_email' => 'user@example.com',
            'payment_type' => 'online',
        ]);

        $service = app(PaystackService::class);
        $result = $service->verifyTransaction('paystack_ref_123', 2500, 'KES');

        $this->assertTrue($result['verified']);
        $this->assertSame('paystack_ref_123', $result['reference']);

        $booking = Booking::where('common_order', 98765)->first();
        $this->assertNotNull($booking);
        $this->assertEquals(1, $booking->is_paid);
    }
}
