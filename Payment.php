<?php

namespace App\Services\Gateway\uddoktapay;

use Exception;
use Facades\App\Services\BasicService;

class Payment
{
    public static function prepareData($deposit, $gateway)
    {
        $uddoktapayParams = $gateway->parameters;

        throw_if(empty($uddoktapayParams->api_key ?? "") || empty($uddoktapayParams->api_url ?? ""), "Unable to process with UddoktaPay.");

        $requestData = [
            'full_name'    => isset(optional($deposit->user)->username) ? optional($deposit->user)->username : "John Doe",
            'email'        => isset(optional($deposit->user)->email) ? optional($deposit->user)->email : "John Doe",
            'amount'       => round($deposit->payable_amount, 2),
            'metadata'     => [
                'trx_id' => $deposit->trx_id,
            ],
            'redirect_url' => route('ipn', [$gateway->code, $deposit->trx_id]),
            'return_type'  => 'GET',
            'cancel_url'   => route('failed'),
            'webhook_url'  => route('ipn', [$gateway->code, $deposit->trx_id]),
        ];

        try {
            $redirect_url         = self::initPayment($requestData, $uddoktapayParams);
            $send['redirect']     = true;
            $send['redirect_url'] = $redirect_url;
        } catch (\Exception $e) {
            $send['error']   = true;
            $send['message'] = $e->getMessage();
        }
        return json_encode($send);
    }

    public static function ipn($request, $gateway, $deposit = null, $trx = null, $type = null)
    {
        $uddoktapayParams = $gateway->parameters;

        $response = self::verifyPayment($request, $uddoktapayParams);
        if (isset($response['status']) && $response['status'] === 'COMPLETED') {
            BasicService::preparePaymentUpgradation($deposit);

            $data['status']   = 'success';
            $data['msg']      = 'Transaction was successful.';
            $data['redirect'] = route('success');
        } else {
            $data['status']   = 'error';
            $data['msg']      = 'unexpected error!';
            $data['redirect'] = route('failed');
        }
        return $data;
    }

    public static function initPayment($requestData, $uddoktapayParams)
    {
        $host   = parse_url($uddoktapayParams->api_url, PHP_URL_HOST);
        $apiUrl = "https://{$host}/api/checkout-v2";

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => "POST",
            CURLOPT_POSTFIELDS     => json_encode($requestData),
            CURLOPT_HTTPHEADER     => [
                "RT-UDDOKTAPAY-API-KEY: " . $uddoktapayParams->api_key,
                "accept: application/json",
                "content-type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err      = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error #:" . $err);
        } else {
            $result = json_decode($response, true);
            if (isset($result['status']) && isset($result['payment_url'])) {
                return $result['payment_url'];
            } else {
                throw new Exception($result['message']);
            }
        }
        throw new Exception("Please recheck configurations");
    }

    public static function verifyPayment($resuest, $uddoktapayParams)
    {
        $host      = parse_url($uddoktapayParams->api_url, PHP_URL_HOST);
        $verifyUrl = "https://{$host}/api/verify-payment";

        $invoice_data = [
            'invoice_id' => $resuest->invoice_id,
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL            => $verifyUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => "POST",
            CURLOPT_POSTFIELDS     => json_encode($invoice_data),
            CURLOPT_HTTPHEADER     => [
                "RT-UDDOKTAPAY-API-KEY: " . $uddoktapayParams->api_key,
                "accept: application/json",
                "content-type: application/json",
            ],
        ]);

        $response = curl_exec($curl);
        $err      = curl_error($curl);
        curl_close($curl);

        if ($err) {
            throw new Exception("cURL Error #:" . $err);
        } else {
            return json_decode($response, true);
        }
        throw new Exception("Please recheck configurations");
    }
}
