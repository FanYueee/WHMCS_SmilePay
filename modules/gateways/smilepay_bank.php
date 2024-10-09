<?php
/**
 * WHMCS SmilePay (速買配) 台灣銀行虛擬帳號轉帳支付模組
 *
 * @author      FanYueee(繁月)
 * @link        https://github.com/FanYueee/WHMCS_SmilePay
 * @version     1.5
 * @license     https://github.com/FanYueee/WHMCS_SmilePay/blob/main/LICENSE MIT License
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function smilepay_bank_MetaData()
{
    return [
        'APIVersion' => '1.1',
    ];
}

function smilepay_bank_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SmilePay (速買配) 台灣銀行虛擬帳號轉帳',
        ],
        'Rvg2c' => [
            'FriendlyName' => '參數碼',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'SmilePay 參數碼',
        ],
        'Dcvc' => [
            'FriendlyName' => '商家代號',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'SmilePay 商家代號',
        ],
        'Roturl' => [
            'FriendlyName' => '回傳連結',
            'Type' => 'text',
            'Size' => '100',
            'Default' => 'https://yourdomain/modules/gateways/callback/smilepay_bank.php',
            'Description' => 'WHMCS 回傳接收位置',
        ],
        'Verify_key' => [
            'FriendlyName' => '檢查碼',
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'SmilePay 檢查碼',
        ],
        'Smseid' => [
            'FriendlyName' => '商家驗證參數',
            'Type' => 'text',
            'Size' => '4',
            'Default' => '',
            'Description' => 'SmilePay 四位數商家驗證參數',
        ],
    ];
}

function smilepay_bank_ensureTableExists()
{
    try {
        if (!Capsule::schema()->hasTable('mod_smilepay_payment_info')) {
            Capsule::schema()->create('mod_smilepay_payment_info', function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->unique();
                $table->string('atm_bank_no', 10)->nullable();
                $table->string('atm_no', 20)->nullable();
                $table->decimal('amount', 10, 2);
                $table->dateTime('bank_pay_end_date');
                $table->string('smilepay_no', 20);
                $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
                $table->timestamps();
            });
        } else {
            $schema = Capsule::schema();
            $tableName = 'mod_smilepay_payment_info';
            $columns = ['atm_bank_no', 'atm_no', 'amount', 'bank_pay_end_date', 'smilepay_no', 'status'];
            foreach ($columns as $column) {
                if (!$schema->hasColumn($tableName, $column)) {
                    $schema->table($tableName, function ($table) use ($column) {
                        switch ($column) {
                            case 'atm_bank_no':
                                $table->string('atm_bank_no', 10)->nullable();
                                break;
                            case 'atm_no':
                                $table->string('atm_no', 20)->nullable();
                                break;
                            case 'amount':
                                $table->decimal('amount', 10, 2)->nullable();
                                break;
                            case 'bank_pay_end_date':
                                $table->dateTime('bank_pay_end_date')->nullable();
                                break;
                            case 'smilepay_no':
                                $table->string('smilepay_no', 20)->nullable();
                                break;
                            case 'status':
                                $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending')->nullable();
                                break;
                        }
                    });
                }
            }
        }
    } catch (\Exception $e) {
        logActivity("SmilePay Bank 模組發生錯誤：創建或更新資料表失敗 - " . $e->getMessage());
    }
}

function smilepay_bank_link($params)
{
    smilepay_bank_ensureTableExists();

    $invoiceId = $params['invoiceid'];
    $currentAmount = round($params['amount']);

    $existingPaymentInfo = Capsule::table('mod_smilepay_payment_info')
        ->where('invoice_id', $invoiceId)
        ->whereNotNull('atm_bank_no')
        ->whereNotNull('atm_no')
        ->first();

    if ($existingPaymentInfo && $existingPaymentInfo->amount == $currentAmount) {
        return smilepay_bank_generatePaymentInstructions($existingPaymentInfo);
    }

    $rvg2c = $params['Rvg2c'];
    $dcvc = $params['Dcvc'];
    $roturl = $params['Roturl'];
    $verifyKey = $params['Verify_key'];

    if (empty($rvg2c) || empty($dcvc) || empty($roturl) || empty($verifyKey)) {
        return '請在 WHMCS 後台設定所有必要參數';
    }

    $apiUrl = 'https://ssl.smse.com.tw/api/SPPayment.asp';
    $apiParams = [
        'Rvg2c' => $rvg2c,
        'Dcvc' => $dcvc,
        'Od_sob' => $invoiceId,
        'Amount' => $currentAmount,
        'Pur_name' => $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
        'Mobile_number' => $params['clientdetails']['phonenumber'],
        'Email' => $params['clientdetails']['email'],
        'Remark' => '由 WHMCS 請求生成',
        'Roturl' => $roturl,
        'Roturl_status' => 'Payment_OK',
        'Pay_zg' => '2',
        'Verify_key' => $verifyKey
    ];

    $apiUrl .= '?' . http_build_query($apiParams);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $xml = simplexml_load_string($response);

    if ($xml->Status == '1') {
        $paymentInfo = [
            'invoice_id' => $invoiceId,
            'atm_bank_no' => (string)$xml->AtmBankNo,
            'atm_no' => (string)$xml->AtmNo,
            'amount' => (string)$xml->Amount,
            'bank_pay_end_date' => (string)$xml->PayEndDate,
            'smilepay_no' => (string)$xml->SmilePayNO,
        ];
        smilepay_bank_savePaymentInfo($paymentInfo);

        return smilepay_bank_generatePaymentInstructions((object)$paymentInfo);
    } else {
        return '無法取得繳費資訊';
    }

}

function smilepay_bank_generatePaymentInstructions($paymentInfo)
{
    $info = (array)$paymentInfo;
    $bank_pay_end_date_for_qrcode = date('YmdHis', strtotime($info['bank_pay_end_date']));

    $qrCodeUrl = 'https://payment-code.atomroute.com/qrcode.php?code=' . "TWQRP://台灣銀行轉帳/158/02/V1?D1=". intval($info['amount']) . "00" . "&D5=".$info['atm_bank_no'] . "&D6=" . $info['atm_no'] . "&D12=" . $bank_pay_end_date_for_qrcode;
    
    $atm_no_f = chunk_split($info['atm_no'], 4, ' ');
    $atm_no_f = trim($atm_no_f);

    if($info['atm_bank_no'] == '004') $bank_name = '臺灣銀行';

    $style = "
        style='
            text-align: left;
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 10px;
            display: inline-block;
        '
    ";
    
    return "
        <div $style>
            銀行代號：" . $info['atm_bank_no'] . " " . $bank_name . "<br>
            銀行帳號：" . $atm_no_f . "<br>
            繳費金額：" . intval($info['amount']) . " 元<br>
            繳費截止日期：" . $info['bank_pay_end_date'] . "
        </div>
        <br>
        <strong>台灣銀行轉帳 QRCode</strong><br>
        <img src='" . $qrCodeUrl . "' alt='台灣銀行轉帳 QR Code' style='margin-top: 10px; width: 100%; max-width: 150px; height: auto;'>
    ";

}

function smilepay_bank_savePaymentInfo($paymentInfo)
{
    smilepay_bank_ensureTableExists();

    try {
        Capsule::table('mod_smilepay_payment_info')->updateOrInsert(
            ['invoice_id' => $paymentInfo['invoice_id']],
            $paymentInfo
        );
    } catch (\Exception $e) {
        logActivity("SmilePay Bank 模組發生錯誤：儲存繳費資訊失敗 - " . $e->getMessage());
    }
}