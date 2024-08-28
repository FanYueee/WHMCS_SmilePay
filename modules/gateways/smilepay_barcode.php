<?php
/**
 * WHMCS SmilePay (速買配) 台灣 超商條碼支付模組
 *
 * @author      FanYueee(繁月)
 * @link        https://github.com/FanYueee/WHMCS_SmilePay
 * @version     1.3
 * @license     https://github.com/FanYueee/WHMCS_SmilePay/blob/main/LICENSE MIT License
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function smilepay_barcode_MetaData()
{
    return [
        'APIVersion' => '1.1',
    ];
}

function smilepay_barcode_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SmilePay 台灣超商條碼',
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
            'Default' => 'https://yourdomain/modules/gateways/callback/smilepay_barcode.php',
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
        'Yourcoolname' => [
            'FriendlyName' => '你的主機商名稱',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => '這會顯示在條碼顯示頁面上',
        ],
    ];
}

function smilepay_barcode_ensureTableExists()
{
    try {
        if (!Capsule::schema()->hasTable('mod_smilepay_payment_info')) {
            Capsule::schema()->create('mod_smilepay_payment_info', function ($table) {
                $table->increments('id');
                $table->integer('invoice_id')->unique();
                $table->string('barcode1', 20)->nullable();
                $table->string('barcode2', 20)->nullable();
                $table->string('barcode3', 20)->nullable();
                $table->decimal('amount', 10, 2);
                $table->dateTime('barcode_pay_end_date');
                $table->string('smilepay_no', 20);
                $table->enum('status', ['pending', 'paid', 'cancelled'])->default('pending');
                $table->timestamps();
            });
        } else {
            $schema = Capsule::schema();
            $tableName = 'mod_smilepay_payment_info';

            $newColumns = ['barcode1', 'barcode2', 'barcode3'];
            foreach ($newColumns as $column) {
                if (!$schema->hasColumn($tableName, $column)) {
                    $schema->table($tableName, function ($table) use ($column) {
                        $table->string($column, 20)->nullable()->after('invoice_id');
                    });
                }
            }

            $requiredColumns = ['amount', 'barcode_pay_end_date', 'smilepay_no', 'status'];
            foreach ($requiredColumns as $column) {
                if (!$schema->hasColumn($tableName, $column)) {
                    $schema->table($tableName, function ($table) use ($column) {
                        switch ($column) {
                            case 'amount':
                                $table->decimal('amount', 10, 2)->nullable();
                                break;
                            case 'barcode_pay_end_date':
                                $table->dateTime('barcode_pay_end_date')->nullable();
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
        logActivity("SmilePay 超商條碼支付模組錯誤：創建或更新資料表失敗 - " . $e->getMessage());
    }
}

function smilepay_barcode_link($params)
{
    smilepay_barcode_ensureTableExists();

    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];
    $verynicename = $params['Yourcoolname'];

    $existingPaymentInfo = Capsule::table('mod_smilepay_payment_info')
        ->where('invoice_id', $invoiceId)
        ->whereNotNull('barcode1')
        ->first();

    if ($existingPaymentInfo) {
        return smilepay_barcode_generatePaymentInstructions($existingPaymentInfo, $verynicename);
    }

    $rvg2c = $params['Rvg2c'];
    $dcvc = $params['Dcvc'];
    $roturl = $params['Roturl'];
    $verifyKey = $params['Verify_key'];

    if (empty($rvg2c) || empty($dcvc) || empty($roturl) || empty($verifyKey)) {
        return '請在 WHMCS 後台設定所有必要參數。';
    }

    $apiUrl = 'https://ssl.smse.com.tw/api/SPPayment.asp';
    $apiParams = [
        'Rvg2c' => $rvg2c,
        'Dcvc' => $dcvc,
        'Od_sob' => $invoiceId,
        'Amount' => $amount,
        'Pur_name' => $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'],
        'Mobile_number' => $params['clientdetails']['phonenumber'],
        'Email' => $params['clientdetails']['email'],
        'Remark' => '由 WHMCS 請求生成',
        'Roturl' => $roturl,
        'Roturl_status' => 'Payment_OK',
        'Pay_zg' => '3',
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
            'barcode1' => (string)$xml->Barcode1,
            'barcode2' => (string)$xml->Barcode2,
            'barcode3' => (string)$xml->Barcode3,
            'amount' => (string)$xml->Amount,
            'barcode_pay_end_date' => (string)$xml->PayEndDate,
            'smilepay_no' => (string)$xml->SmilePayNO,
        ];
        smilepay_barcode_savePaymentInfo($paymentInfo);

        return smilepay_barcode_generatePaymentInstructions((object)$paymentInfo, $verynicename);
    } else {
        return '無法取得繳費資訊';
    }
}

function smilepay_barcode_generatePaymentInstructions($paymentInfo, $verynicename)
{
    $info = (array)$paymentInfo;
    $invoiceId = $info['invoice_id'];

    $barcodeUrl = "https://barcode.vproxy.cloud/barcode?";
    $barcodeUrl .= "barcode=" . urlencode($info['barcode1']);
    $barcodeUrl .= "&barcode=" . urlencode($info['barcode2']);
    $barcodeUrl .= "&barcode=" . urlencode($info['barcode3']);
    $barcodeUrl .= "&name=" . urlencode($verynicename);
    $barcodeUrl .= "&invoice=" . urlencode($invoiceId);
    $barcodeUrl .= "&price=" . urlencode($info['amount']);
    $barcodeUrl .= "&date=" . urlencode($info['barcode_pay_end_date']);

    $style = "
        style='
            text-align: left;
            border: 1px solid #ccc;
            padding: 10px;
            margin-bottom: 10px;
            display: inline-block;
        '
    ";

    $buttonStyle = "
    style='
        background-color: #248756;
        color: white;
        padding: 10px 15px;
        border: none;
        border-radius: 5px;
        cursor: pointer;
    '
    ";
    
    $output = "
        <div $style>
            繳費金額：" . intval($info['amount']) . " 元<br>
            繳費截止日期：" . $info['barcode_pay_end_date'] . "
        </div>
        <br>
        <a href=\"{$barcodeUrl}\" target=\"_blank\"><button $buttonStyle>顯示繳費條碼</button></a>
    ";

    return $output;
}

function smilepay_barcode_savePaymentInfo($paymentInfo)
{
    smilepay_barcode_ensureTableExists();

    try {
        Capsule::table('mod_smilepay_payment_info')->updateOrInsert(
            ['invoice_id' => $paymentInfo['invoice_id']],
            $paymentInfo
        );
    } catch (\Exception $e) {
        logActivity("SmilePay 超商條碼支付模組錯誤：儲存繳費資訊失敗 - " . $e->getMessage());
    }
}