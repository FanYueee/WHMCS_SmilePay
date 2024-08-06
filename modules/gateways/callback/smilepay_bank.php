<?php
/**
 * WHMCS SmilePay (速買配) 台灣銀行虛擬帳號轉帳支付模組 Callback 
 *
 * @author      FanYueee(繁月)
 * @link        https://github.com/FanYueee/WHMCS_SmilePay
 * @version     1.0
 * @license     https://github.com/FanYueee/WHMCS_SmilePay/blob/main/LICENSE MIT License
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

$gatewayModuleName = 'smilepay_bank';

$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$postData = $_POST;

logTransaction($gatewayModuleName, $postData, "Callback Data");

if ($postData['Classif'] !== 'B' || !isset($postData['Od_sob']) || !isset($postData['Payment_no']) || !isset($postData['Purchamt']) || !isset($postData['Smseid']) || !isset($postData['Mid_smilepay'])) {
    die("0");
}

$calculatedMid = smilepay_bank_calculateMidSmilepay($gatewayParams['Smseid'], $postData['Purchamt'], $postData['Smseid']);
if ($calculatedMid != $postData['Mid_smilepay']) {
    die("0");
}

$invoiceId = $postData['Od_sob'];

$paymentInfo = Capsule::table('mod_smilepay_payment_info')
    ->where('invoice_id', $invoiceId)
    ->first();

if (!$paymentInfo) {
    die("0");
}

if ($paymentInfo->atm_no !== $postData['Payment_no'] || $paymentInfo->amount != $postData['Purchamt']) {
    die("0");
}

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

checkCbTransID($postData['Payment_no']);

addInvoicePayment(
    $invoiceId,
    $postData['Payment_no'],
    $postData['Purchamt'],
    0,
    $gatewayModuleName
);

Capsule::table('mod_smilepay_payment_info')
    ->where('invoice_id', $invoiceId)
    ->update(['status' => 'paid']);

header('Content-Type: text/html; charset=utf-8');
echo "<Roturlstatus>Payment_OK</Roturlstatus>";

logTransaction($gatewayParams['name'], $postData, "Successful");

function smilepay_bank_calculateMidSmilepay($smseid, $amount, $callbackSmseid)
{
    $A = str_pad($smseid, 4, '0', STR_PAD_LEFT);
    $B = str_pad(intval($amount), 8, '0', STR_PAD_LEFT);
    $C = substr($callbackSmseid, -4);
    $C = preg_replace('/\D/', '9', $C);
    $D = $A . $B . $C;
    $E = 0;
    
    for ($i = 1; $i < strlen($D); $i += 2) {
        $E += intval($D[$i]);
    }
    $E *= 3;

    $F = 0;
    for ($i = 0; $i < strlen($D); $i += 2) {
        $F += intval($D[$i]);
    }
    $F *= 9;

    return $E + $F;
}