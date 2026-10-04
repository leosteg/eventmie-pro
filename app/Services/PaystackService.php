<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaystackService
{
    public function getPublicKey(): string
    {
        return trim((string) (config('paystack.publicKey') ?: setting('apps.paystack_public_key') ?: env('PAYSTACK_PUBLIC_KEY', '')));
    }

    public function getSecretKey(): string
    {
        return trim((string) (config('paystack.secretKey') ?: setting('apps.paystack_secret_key') ?: env('PAYSTACK_SECRET_KEY', '')));
    }

    public function getApiBaseUrl(): string
    {
        return trim((string) (config('paystack.paymentUrl') ?: env('PAYSTACK_PAYMENT_URL', 'https://api.paystack.co')));
    }

    public function generateReference(array $order = [], ?string $suffix = null): string
    {
        $prefix = 'evt_';
        $orderNumber = $order['order_number'] ?? $order['common_order'] ?? null;

        if ($orderNumber) {
            return $prefix.$orderNumber.'_'.$suffix ?? Str::random(8);
        }

        return $prefix.Str::uuid()->toString();
    }

    public function initializeTransaction(array $order = [], array $booking = [], array $user = []): array
    {
        $secretKey = $this->getSecretKey();

        if (empty($secretKey)) {
            throw new \RuntimeException('Paystack secret key is not configured.');
        }

        $email = $user['email'] ?? data_get($booking, '0.customer_email') ?? null;
        $currency = strtoupper((string) (data_get($booking, '0.currency') ?: setting('regional.currency_default') ?: 'KES'));
        $amount = (float) ($order['price'] ?? 0);
        $convertedAmount = (int) round($amount * 100);
        $reference = $this->generateReference($order, (string) now()->timestamp);

        $payload = [
            'email' => $email,
            'amount' => $convertedAmount,
            'currency' => $currency,
            'reference' => $reference,
            'callback_url' => route('paystack.callback', [], true),
            'metadata' => [
                'booking_id' => $order['order_number'] ?? data_get($booking, '0.common_order') ?? null,
                'event_id' => data_get($booking, '0.event_id') ?? null,
                'user_id' => data_get($booking, '0.customer_id') ?? null,
                'order_number' => $order['order_number'] ?? data_get($booking, '0.common_order') ?? null,
                'ticket_ids' => collect($booking)->pluck('ticket_id')->filter()->values()->all(),
            ],
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($this->getApiBaseUrl().'/transaction/initialize', $payload);

        if ($response->failed()) {
            $message = $response->json('message', 'Paystack initialization failed.');
            throw new \RuntimeException($message);
        }

        $data = $response->json('data', []);

        return [
            'status' => true,
            'reference' => $data['reference'] ?? $reference,
            'authorization_url' => $data['authorization_url'] ?? null,
            'amount' => $convertedAmount,
            'currency' => $currency,
            'metadata' => $payload['metadata'],
            'response' => $response->json(),
        ];
    }

    public function verifyTransaction(string $reference, $expectedAmount = null, ?string $expectedCurrency = null): array
    {
        $secretKey = $this->getSecretKey();

        if (empty($secretKey)) {
            return ['verified' => false, 'reference' => $reference, 'message' => 'Paystack secret key is not configured.'];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$secretKey,
            'Accept' => 'application/json',
        ])->get($this->getApiBaseUrl().'/transaction/verify/'.$reference);

        if ($response->failed()) {
            return ['verified' => false, 'reference' => $reference, 'message' => $response->json('message', 'Unable to verify Paystack transaction.')];
        }

        $data = $response->json('data', []);
        $status = strtolower((string) ($data['status'] ?? ''));
        $amount = (int) ($data['amount'] ?? 0);
        $currency = strtoupper((string) ($data['currency'] ?? ''));

        if ($status !== 'success') {
            return ['verified' => false, 'reference' => $reference, 'message' => $data['status'] ?? 'failed'];
        }

        if ($expectedAmount !== null) {
            $expectedAmountInMinorUnit = (int) round((float) $expectedAmount * 100);
            if ($amount !== $expectedAmountInMinorUnit) {
                return ['verified' => false, 'reference' => $reference, 'message' => 'Amount mismatch.'];
            }
        }

        if ($expectedCurrency !== null && $currency !== strtoupper((string) $expectedCurrency)) {
            return ['verified' => false, 'reference' => $reference, 'message' => 'Currency mismatch.'];
        }

        $this->markBookingPaid($data);

        return [
            'verified' => true,
            'reference' => $data['reference'] ?? $reference,
            'amount' => $amount,
            'currency' => $currency,
            'message' => 'Verification successful',
            'data' => $data,
        ];
    }

    public function handleWebhook(Request $request): \Illuminate\Http\JsonResponse
    {
        $payload = $request->getContent();
        $signature = $request->header('x-paystack-signature');
        $secretKey = $this->getSecretKey();

        if (empty($secretKey) || empty($signature) || !hash_equals(hash_hmac('sha512', $payload, $secretKey), $signature)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 403);
        }

        $event = json_decode($payload, true);
        $eventName = $event['event'] ?? null;

        if ($eventName === 'charge.success') {
            $reference = $event['data']['reference'] ?? null;
            if (empty($reference)) {
                return response()->json(['status' => 'error', 'message' => 'Missing reference.'], 400);
            }

            $this->verifyTransaction($reference);
            return response()->json(['status' => 'success'], 200);
        }

        return response()->json(['status' => 'ignored'], 200);
    }

    protected function markBookingPaid(array $data = []): void
    {
        $bookingIdentifier = $data['metadata']['booking_id'] ?? $data['metadata']['order_number'] ?? null;

        if (empty($bookingIdentifier)) {
            return;
        }

        $booking = Booking::where('common_order', $bookingIdentifier)
            ->orWhere('order_number', $bookingIdentifier)
            ->first();

        if (!$booking) {
            return;
        }

        $booking->update([
            'is_paid' => 1,
            'status' => 1,
        ]);

        DB::table('transactions')->updateOrInsert(
            ['txn_id' => $data['reference'] ?? null],
            [
                'amount_paid' => ((float) ($data['amount'] ?? 0)) / 100,
                'currency_code' => strtoupper((string) ($data['currency'] ?? '')), 
                'payment_status' => 'success',
                'payer_reference' => $data['customer']['id'] ?? null,
                'payment_gateway' => 'Paystack',
                'status' => 1,
                'updated_at' => now(),
            ]
        );
    }
}
