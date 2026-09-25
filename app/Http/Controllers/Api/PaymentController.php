<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use App\Services\PlutuService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(private readonly WalletService $wallets) {}

    /** البوابات المتاحة للزبون */
    public function gateways(Request $request): JsonResponse
    {
        $purpose = $request->query('purpose', 'topup');

        $data = PaymentGateway::availableFor($purpose)->map(fn ($g) => [
            'key'        => $g->key,
            'name'       => $g->name,
            'flow'       => $g->isOtpFlow() ? 'otp' : 'redirect',
            'min_amount' => $g->min_amount,
            'max_amount' => $g->max_amount,
            'test_mode'  => $g->mode === 'test',
        ]);

        return response()->json(['data' => $data]);
    }

    /** ===== خطوة 1 للبوابات بالرمز: إرسال OTP ===== */
    public function sendOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gateway'       => ['required', 'string'],
            'amount'        => ['required', 'numeric', 'min:1'],
            'mobile_number' => ['required', 'string', 'regex:/^(09[13][0-9]{7})$/'],
            'birth_year'    => ['required', 'digits:4'],
            'purpose'       => ['nullable', 'in:topup,order'],
            'order_id'      => ['nullable', 'integer'],
        ]);

        $gateway = $this->gatewayFor($data['gateway'], $data['purpose'] ?? 'topup', $data['amount']);

        $result = PlutuService::for($gateway->key)->sendOtp(
            $data['mobile_number'],
            $data['birth_year'],
            (float) $data['amount']
        );

        $tx = PaymentTransaction::create([
            'user_id'    => $request->user()->id,
            'gateway'    => $gateway->key,
            'purpose'    => $data['purpose'] ?? 'topup',
            'order_id'   => $data['order_id'] ?? null,
            'invoice_no' => PaymentTransaction::generateInvoiceNo(),
            'amount'     => $data['amount'],
            'status'     => 'pending',
            'process_id' => $result['process_id'] ?? null,
            'meta'       => ['mobile' => $data['mobile_number']],
        ]);

        return response()->json([
            'payment_id' => $tx->id,
            'message'    => 'أُرسل رمز التحقق لرقمك.',
        ]);
    }

    /** ===== خطوة 2: تأكيد الرمز ===== */
    public function confirmOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_id' => ['required', 'integer'],
            'code'       => ['required', 'string', 'max:10'],
        ]);

        $tx = PaymentTransaction::where('id', $data['payment_id'])
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();

        try {
            $result = PlutuService::for($tx->gateway)->confirmOtp(
                (string) $tx->process_id,
                $data['code'],
                (float) $tx->amount,
                $tx->invoice_no,
                $request->ip()
            );
        } catch (ValidationException $e) {
            $tx->update([
                'status'         => 'failed',
                'failure_reason' => collect($e->errors())->flatten()->first(),
            ]);

            throw $e;
        }

        $tx->update([
            'provider_transaction_id' => $result['transaction_id'] ?? null,
        ]);

        $this->applyPayment($tx);

        return response()->json([
            'message' => 'تمت العملية بنجاح',
            'balance' => $this->wallets->balance($request->user()),
        ]);
    }

    /** ===== البوابات بصفحة خارجية ===== */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gateway'  => ['required', 'string'],
            'amount'   => ['required', 'numeric', 'min:1'],
            'purpose'  => ['nullable', 'in:topup,order'],
            'order_id' => ['nullable', 'integer'],
        ]);

        $gateway = $this->gatewayFor($data['gateway'], $data['purpose'] ?? 'topup', $data['amount']);

        $tx = PaymentTransaction::create([
            'user_id'    => $request->user()->id,
            'gateway'    => $gateway->key,
            'purpose'    => $data['purpose'] ?? 'topup',
            'order_id'   => $data['order_id'] ?? null,
            'invoice_no' => PaymentTransaction::generateInvoiceNo(),
            'amount'     => $data['amount'],
            'status'     => 'pending',
        ]);

        $result = PlutuService::for($gateway->key)->checkout(
            (float) $data['amount'],
            $tx->invoice_no,
            route('payments.callback'),
            $request->ip()
        );

        return response()->json([
            'payment_id'   => $tx->id,
            'redirect_url' => $result['redirect_url'] ?? null,
        ]);
    }

    /** حالة عملية — التطبيق يسأل بعد رجوع الزبون من صفحة الدفع */
    public function status(Request $request, int $id): JsonResponse
    {
        $tx = PaymentTransaction::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'status'  => $tx->status,
            'label'   => $tx->statusLabel(),
            'amount'  => $tx->amount,
            'balance' => $this->wallets->balance($request->user()),
        ]);
    }

    /**
     * رد بلوتو بعد الدفع — مسار عام بدون توكن.
     * التحقق من التوقيع هو الحماية الوحيدة هنا.
     */
    public function callback(Request $request)
    {
        $params = $request->query();
        $invoiceNo = $params['invoice_no'] ?? null;

        $tx = $invoiceNo
            ? PaymentTransaction::where('invoice_no', $invoiceNo)->first()
            : null;

        if (! $tx) {
            return $this->resultPage(false, 'ما لقيناش العملية.');
        }

        $gateway = PaymentGateway::where('key', $tx->gateway)->first();
        $secret  = $gateway?->secretKey();

        if (! $secret || ! PlutuService::verifyCallback($params, $secret)) {
            Log::warning('Plutu callback signature failed', ['invoice' => $invoiceNo]);
            $tx->update(['status' => 'failed', 'failure_reason' => 'توقيع غير صالح']);

            return $this->resultPage(false, 'تعذّر التحقق من العملية.');
        }

        if (($params['canceled'] ?? null)) {
            $tx->update(['status' => 'canceled']);

            return $this->resultPage(false, 'ألغيت عملية الدفع.');
        }

        if (($params['approved'] ?? null) != 1) {
            $tx->update(['status' => 'failed', 'failure_reason' => 'غير معتمدة']);

            return $this->resultPage(false, 'ما تمّتش عملية الدفع.');
        }

        $tx->update([
            'provider_transaction_id' => $params['transaction_id'] ?? null,
        ]);

        $this->applyPayment($tx);

        return $this->resultPage(true, 'تم الدفع بنجاح. ارجع للتطبيق.');
    }

    /**
     * تطبيق الدفعة: تتحوّل لرصيد في المحفظة،
     * ولو كانت لطلب معيّن تتخصم منه فوراً.
     * محمية من التكرار بفحص الحالة.
     */
    private function applyPayment(PaymentTransaction $tx): void
    {
        if ($tx->isPaid()) {
            return;
        }

        $paidOrder = null;

        DB::transaction(function () use ($tx, &$paidOrder) {
            // قفل الصف: callback وتأكيد التطبيق ممكن يوصلو في نفس اللحظة —
            // بدون القفل الاثنين يشوفو «غير مدفوع» ويضيفو الرصيد مرتين
            $locked = PaymentTransaction::whereKey($tx->id)->lockForUpdate()->first();

            if (! $locked || $locked->isPaid()) {
                return;
            }

            $tx->setRawAttributes($locked->getAttributes(), true);

            $tx->update(['status' => 'paid', 'paid_at' => now()]);

            $user = $tx->user;

            $this->wallets->credit(
                $user,
                (float) $tx->amount,
                'topup_online',
                null,
                "دفع إلكتروني — {$tx->gateway} — {$tx->invoice_no}"
            );

            // دفع طلب محدد: نخصم من المحفظة ونعلّمه مدفوع
            if ($tx->purpose === 'order' && $tx->order_id) {
                $order = Order::find($tx->order_id);

                $order = $order ? Order::whereKey($order->id)->lockForUpdate()->first() : null;

                if ($order && ! $order->is_paid) {
                    $due = max(0, (float) $order->total - (float) $order->wallet_paid);
                    $pay = min($due, (float) $tx->amount);

                    if ($pay > 0) {
                        $this->wallets->debit(
                            $user,
                            $pay,
                            'order_payment',
                            $order,
                            "طلب {$order->code}"
                        );

                        $order->update([
                            'wallet_paid' => (float) $order->wallet_paid + $pay,
                            'is_paid'     => ((float) $order->wallet_paid + $pay) >= (float) $order->total,
                        ]);

                        if ($order->is_paid) {
                            $paidOrder = $order;
                        }
                    }
                }
            }
        });

        // الدفع تأكد = الطلب يوصل للمتجر توّا
        if ($paidOrder) {
            app(\App\Services\OrderService::class)->notifyStoreNewOrder($paidOrder->fresh('store.owner'));
        }
    }

    private function gatewayFor(string $key, string $purpose, float $amount): PaymentGateway
    {
        $gateway = PaymentGateway::availableFor($purpose)->firstWhere('key', $key);

        if (! $gateway) {
            throw ValidationException::withMessages([
                'gateway' => 'طريقة الدفع هذي مش متاحة توّا.',
            ]);
        }

        if ($amount < $gateway->min_amount) {
            throw ValidationException::withMessages([
                'amount' => "أقل مبلغ {$gateway->min_amount} د.ل",
            ]);
        }

        if ($gateway->max_amount && $amount > $gateway->max_amount) {
            throw ValidationException::withMessages([
                'amount' => "أقصى مبلغ {$gateway->max_amount} د.ل",
            ]);
        }

        return $gateway;
    }

    /** صفحة بسيطة تظهر للزبون بعد رجوعه من بوابة الدفع */
    private function resultPage(bool $success, string $message)
    {
        $color = $success ? '#2e7d32' : '#c62828';
        $icon  = $success ? '✓' : '✕';

        $html = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>نتيجة الدفع</title><style>'
            .'body{font-family:Tahoma,Arial,sans-serif;display:flex;align-items:center;'
            .'justify-content:center;height:100vh;margin:0;background:#f5f5f5}'
            .'.box{background:#fff;padding:40px 30px;border-radius:16px;text-align:center;'
            .'box-shadow:0 2px 16px rgba(0,0,0,.08);max-width:320px}'
            .".icon{font-size:52px;color:$color}"
            .'p{font-size:17px;margin:16px 0 0}'
            .'</style></head><body><div class="box">'
            ."<div class=\"icon\">$icon</div><p>".e($message).'</p>'
            .'</div></body></html>';

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
