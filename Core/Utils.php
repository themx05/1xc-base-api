<?php
namespace Core;

use FedaPay\FedaPay;
use FedaPay\Transaction;
use Models\ExpectedPayment;
use Models\Method;
use PDO;

Class Utils{
    
    function preparePMTransaction(PDO $client){
        if(
            isset($_POST['PAYMENT_ID']) &&
            isset($_POST['PAYEE_ACCOUNT']) &&
            isset($_POST['PAYMENT_AMOUNT']) &&
            isset($_POST['PAYMENT_UNITS']) &&
            isset($_POST['PAYMENT_BATCH_NUM']) &&
            isset($_POST['PAYER_ACCOUNT']) &&
            isset($_POST['TIMESTAMPGMT']) && 
            isset($_POST['V2_HASH'])
        ){
            $v2_hash = $_POST['V2_HASH'];
            $paymentId = protectString($_POST['PAYMENT_ID']);
            
            $methodAccountProvider = new MethodAccountProvider($client);
            $pm_account = $methodAccountProvider->getPerfectMoney(Method::TYPE_PERFECTMONEY);

            if($pm_account != null){
                $paymentProvider = new ExpectedPaymentProvider($client);
                $expected_payment = $paymentProvider->getExpectedPaymentById($paymentId);
                if($expected_payment != null){
                    $original_passphrase_hash = strtoupper(md5($pm_account->alternatePassphrase));
                    $computed_v2_hash = strtoupper(md5($_POST['PAYMENT_ID'].':'.$_POST['PAYEE_ACCOUNT'].':'.$_POST['PAYMENT_AMOUNT'].':'.$_POST['PAYMENT_UNITS'].':'.$_POST['PAYMENT_BATCH_NUM'].':'.$_POST['PAYER_ACCOUNT'].':'.$original_passphrase_hash.':'.$_POST['TIMESTAMPGMT']));
    
                    if($v2_hash === $computed_v2_hash){
                        /// This payment is authentic.
                        $confirmation = new ConfirmationData(
                            generateHash(),
                            Method::TYPE_PERFECTMONEY,
                            protectString($_POST['PAYMENT_ID']),
                            doubleval($_POST['PAYMENT_AMOUNT']),
                            protectString($_POST['PAYMENT_UNITS']),
                            protectString($_POST['PAYER_ACCOUNT']),
                            protectString($_POST['PAYEE_ACCOUNT']),
                            protectString($_POST['PAYMENT_BATCH_NUM']),
                            intval($_POST['TIMESTAMPGMT'])
                        );
    
                        if(
                            $confirmation->amount >= doubleval($expected_payment->amount) &&
                            $confirmation->units === $expected_payment->currency
                        ){
                            return $confirmation;
                        }
                    }
                }
            }
        }
        return null;
    }

    function prepareFedaPayTransaction(PDO $client, string $txId, string $paymentId){
        $provider = new ExpectedPaymentProvider($client);
        $expected_payment = $provider->getExpectedPaymentById($paymentId);
        if($expected_payment !== null && $expected_payment instanceof ExpectedPayment){
            $methodAccountProvider = new MethodAccountProvider($client);
            $feda = $methodAccountProvider->getFedaPay();
    
            FedaPay::setEnvironment('live');
            FedaPay::setApiKey($feda['details']['privateKey']);
            $transaction = Transaction::retrieve($txId);
            if(
                $transaction->status === "approved" && 
                floatval($transaction->amount) === floatval($expected_payment->amount)
            ){
                $meta = $transaction->metadata;
                $payment_number = $meta->paid_phone_number;
                $number = $payment_number->number;
    
                $mode = "";
                if($transaction->mode === "mtn"){
                    $mode = Method::TYPE_MTN;
                }
                elseif($transaction->mode === "moov"){
                    $mode = Method::TYPE_MOOV;
                }else{
                    $mode = $transaction->mode;
                }
    
                return new ConfirmationData(
                    generateHash(),
                    $mode,
                    $paymentId,
                    floatval($transaction->amount),
                    $expected_payment->currency,
                    $number,
                    $expected_payment->address,
                    $txId,
                    time()
                );
            }
    
        }
        return null;
    }
    
}
?>