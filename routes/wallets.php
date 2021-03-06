<?php

use Core\MethodAccountProvider;
use Core\SystemProperties;
use Core\UserProvider;
use FedaPay\FedaPay;
use FedaPay\Transaction;
use Providers\WalletProvider;
use Routing\Request;
use Routing\Response;
use Routing\Router;

$walletRouter = new Router();

$walletRouter->get("/confirm/:method/:userId", function(Request $req, Response $res){
    global $logger;
    $logger->info("Creating a business wallet after a confirmed payment is done.");
    $txId = $req->getQuery('id');
    $method = $req->getParam('method');
    $userId = $req->getParam('userId');

    if($method !=="mobile"){
        return $res->json(['success' => false]);
    }
    
    $client = $req->getOption('storage');
    $systemProps = new SystemProperties($req->getOption('storage'));
    $methodAccountProvider = new MethodAccountProvider($req->getOption('storage'));
    $userProvider = new UserProvider($req->getOption('storage'));

    $fees = $systemProps->getBusinessWalletFee();
    $user = $userProvider->getProfileById($userId);
    if($client instanceof PDO && isset($user) && intval($user['isMerchant']) === 1){
        $logger->info("Well, user is a merchant");
        if(isset($fees)){
            $logger->info("Registration fees are avaiable. Fees: ".json_encode($fees));
            if($fees->amount > 0){
                $feda_account = $methodAccountProvider->getFedaPay();
                FedaPay::setEnvironment("live");
                FedaPay::setApiKey($feda_account['details']['privateKey']);

                $transaction = Transaction::retrieve($txId);
                if(
                    $transaction->status === "approved" && 
                    floatval($transaction->amount) === floatval($fees->amount) &&
                    $transaction->currency === $fees->currency
                ){
                    $logger->info("Transaction is okay.");
    
                    $walletProvider = new WalletProvider($client);
                    $wallet = $walletProvider->getWalletByUser($user['id']);
                    if(!isset($wallet) || !isset($wallet['id'])){
                        $logger->info("User didn't have a business wallet.");
                        $client->beginTransaction();
                        $registrationId = $walletProvider->saveRegistrationFeeInstant(
                            $user['id'],
                            $transaction->mode,
                            $txId,
                            $transaction->amount,
                            $fees->currency, 
                            time()
                        );
                        $logger->info("Registration entry saved.");
                        if(!empty($registrationId)){
                            $logger->info("Creating wallet");
                            $walletId = $walletProvider->createWallet(WalletProvider::WALLET_BUSINESS,'XOF',0,$user['id']);
                            if(!empty($walletId)){
                                $logger->info("Wallet created");
                                $client->commit();
                                return $res->redirect("https://1xcrypto.net/account/merchant");
                            }
                        }
                        $client->rollBack();
                    }
                }
            }
        }
    }

    return $res->status(403)->json(['success' => false]);
});

$walletRouter->get("/create/:userId", function(Request $req, Response $res){
    global $logger;
    $logger->info("Trying to create a free business wallet for a user");

    $client = $req->getOption('storage');

    $systemProps = new SystemProperties($client);
    $fees = $systemProps->getBusinessWalletFee();
    $userProvider = new UserProvider($client);
    $user = $userProvider->getProfileById($req->getParam('userId'));

    if($client instanceof PDO){
        if(isset($user) && intval($user['isMerchant']) === 1){
            $logger->info("User is a merchant");
            if($fees->amount === 0){ /// YOU CAN ONLY CREATE A BUSINESS ACCOUNT WHEN IT's FREE
                $logger->info("Creation fees are 0.");
                $walletProvider = new WalletProvider($client);
                $wallet = $walletProvider->getWalletByUser($user['id']);
                if(!isset($wallet) || !isset($wallet['id'])){
                    $logger->info("User didn't have a wallet account");
                    $client->beginTransaction();
                    $registrationId = $walletProvider->saveRegistrationFeeInstant(
                        $user['id'],
                        "",
                        "",
                        0,
                        $fees->currency, 
                        time()
                    );
                    if(!empty($registrationId)){
                        $logger->info("Saved registration entry");
                        $walletId = $walletProvider->createWallet(WalletProvider::WALLET_BUSINESS,'XOF',0,$user['id']);
                        if(!empty($walletId)){
                            $logger->info("Created business wallet");
                            $client->commit();
                            return $res->redirect("https://1xcrypto.net/account/merchant");
                        }
                    }
                    $logger->error("Could not create wallet");
                    $client->rollBack();
                }
            }
        }
    }
    return $res->status(403)->json(['success' => false]);
});

$walletRouter->global(function(Request $req, Response $res, Closure $next){
    if($req->getOption('connected')){
        return $next();
    }
    return $res->json(['success' => false]);
});

$walletRouter->get("/", function(Request $req, Response $res){
    $walletProvider = new WalletProvider($req->getOption('storage'));
    if($req->getOption('isAdmin')){
        $wallets =  $walletProvider->getWallets();
        if(isset($wallets)){
            return $res->json(['success' => true, 'wallets' => $wallets]);
        }
    }else{
        $wallet =  $walletProvider->getWalletByUser($req->getOption('user')['id']);
        if(isset($wallet)){
            return $res->json(['success' => true, 'wallet' => $wallet]);
        }
    }
    return $res->json(['success'=> false]);
});

$walletRouter->get("/fee", function(Request $req, Response $res){
    $systemProvider = new SystemProperties($req->getOption('storage'));
    return $res->json(['success' => true, 'fee' => $systemProvider->getBusinessWalletFee()]);
});

$walletRouter->get("/payment-link", function(Request $req, Response $res){
    $systemProvider = new SystemProperties($req->getOption('storage'));
    $userProvider = new UserProvider(($req->getOption('storage')));
    $connected_user = $req->getOption('user');
    $user = $userProvider->getProfileById($connected_user['id']);
    $fee = $systemProvider->getBusinessWalletFee();

    if(isset($fee)){
        // Create fedapay link and launch payment.
        $methodAccountProvider = new MethodAccountProvider($req->getOption('storage'));
        $feda_account = $methodAccountProvider->getFedaPay();
        FedaPay::setEnvironment("live");
        FedaPay::setApiKey($feda_account['details']['privateKey']);
        $fedaTrans = Transaction::create([
            'description' => "Frais de portefeuille business",
            'amount' => $fee->amount,
            'callback_url' => "https://api.1xcrypto.net/wallets/confirm/mobile/{$user['id']}",
            'currency' => [
                'iso' => $fee->currency
            ],
            'customer' => [
                'firstname' => $user['firstName'],
                'lastname' => $user['lastName'],
                'email' => $user['email']
            ]
        ]);
        $paymentUrl = $fedaTrans->generateToken()->url;
        return $res->json(['success' => true, 'link' => $paymentUrl]);
    }
    return $res->json(['success' => false]);
});

$walletRouter->get("/payticket/:expectedPayment", function(Request $req, Response $res){
    $walletId = $req->getParam('wallet');
    $expectationId = $req->getParam('expectedPayment');
    /// Launch payment from wallet.
});

$singleWallet = new Router();

$singleWallet->get("/",function(Request $req, Response $res){
    // Return wallets
    $walletProvider = new WalletProvider($req->getOption('storage'));
    $wallet = $walletProvider->getWalletById($req->getParam('wallet'));
    return $res->json(['success' => true, 'wallet' => $wallet]);
});

$singleWallet->get("/history", function(Request $req, Response $res){
    $walletId = $req->getParam('wallet');
    $walletProvider = new WalletProvider($req->getOption('storage'));
    $history = $walletProvider->getHistoriesByWallet($walletId);

    if(isset($history)){
        return $res->json(['success' => true, 'history' => $history]);
    }
    return $res->json(['success' => false]);
    /// Get wallet history
});

$singleWallet->get("/deposit/:amount", function(Request $req, Response $res){
    $walletId = $req->getParam('wallet');
    $amount = floatval($req->getParam('amount'));
    if(isset($walletId) && isset($amount)){
        $userProvider = new UserProvider($req->getOption('storage'));
        $walletProvider = new WalletProvider($req->getOption('storage'));
        $user = $userProvider->getProfileById($req->getOption('user')['id']);
        $wallet = $walletProvider->getWalletById($walletId);

        if(isset($user) && isset($wallet)){
            if($user['id'] === $wallet['userId']){
                // Create fedapay link and launch payment.
                $methodAccountProvider = new MethodAccountProvider($req->getOption('storage'));
                $feda_account = $methodAccountProvider->getFedaPay();
                FedaPay::setEnvironment("live");
                FedaPay::setApiKey($feda_account['details']['privateKey']);
                $fedaTrans = Transaction::create([
                    'description' => "Depot sur compte business",
                    'amount' => $amount,
                    'callback_url' => "https://api.1xcrypto.net/wallets/{$wallet['id']}/confirm-deposit/mobile",
                    'currency' => [
                        'iso' => $wallet['balance']['currency']
                    ],
                    'customer' => [
                        'firstname' => $user['firstName'],
                        'lastname' => $user['lastName'],
                        'email' => $user['email']
                    ]
                ]);
                $paymentUrl = $fedaTrans->generateToken()->url;
                return $res->json(['success' => true, 'link' => $paymentUrl]);
            }
        }
    }
    return $res->json(['success' => false]);
});

$singleWallet->get("/confirm-deposit/:method", function(Request $req, Response $res){
    $txId = $req->getQuery('id');
    $method = $req->getParam('method');
    $walletId = $req->getParam('wallet');

    if($method !== "mobile"){
        return $res->json(['success'=> false]);
    }
    $client = $req->getOption('storage');
    if($client instanceof PDO){
        $methodAccountProvider = new MethodAccountProvider($client);
        $walletProvider = new WalletProvider($client);
        $wallet = $walletProvider->getWalletById($walletId);

        if(isset($wallet) && isset($txId)){
            $feda_account = $methodAccountProvider->getFedaPay();
            FedaPay::setEnvironment("live");
            FedaPay::setApiKey($feda_account['details']['privateKey']);
            $transaction = Transaction::retrieve($txId);
            if(
                $transaction->status === "approved" && 
                floatval($transaction->amount) > 0
            ){
                $client->beginTransaction();
                $depositId = $walletProvider->saveUserDeposit(
                    $wallet['id'],
                    $method,
                    $txId,
                    floatval($transaction->amount),
                    $wallet['balance']['currency']
                );
                if(!empty($depositId)){
                    $historyId = $walletProvider->deposit(
                        $wallet['id'],
                        floatval($transaction->amount),
                        $wallet['balance']['currency'],
                        "Dépot ".$method,
                        WalletProvider::TX_DEPOSIT
                    );
                    if(!empty($historyId)){
                        $client->commit();
                        return $res->redirect("https://1xcrypto.net/account");
                    }
                }
                $client->rollBack();
            }
        }
    }
    return $res->status(403)->json(['success' => false]);
});

$singleWallet->post("/credit", function(Request $req, Response $res){
    if($req->getOption('isAdmin')){
        $data = $req->getOption('body');
        if(isset($data->memo) && isset($data->amount) && $data->amount > 0){
            $walletProvider = new WalletProvider($req->getOption('storage'));
            $walletId = $req->getParam('wallet');

            $client = $req->getOption('storage');
            if($client instanceof PDO){
                $wallet = $walletProvider->getWalletById($walletId);
                if(isset($wallet)){
                    $client->beginTransaction();
                    $depositId = $walletProvider->deposit($walletId, $data->amount, $wallet['balance']['currency'],$data->memo);
                    if(isset($depositId)){
                        $client->commit();
                        return $res->json(['success' => true, 'deposit' => $depositId]);
                    }
                    $client->rollBack();
                }
            }
        }
    }
    return $res->json(['success' => false]);
});

$singleWallet->post("/debit", function(Request $req, Response $res){
    if($req->getOption('isAdmin')){
        $data = $req->getOption('body');
        if(isset($data->memo) && isset($data->amount) && $data->amount > 0){
            $walletProvider = new WalletProvider($req->getOption('storage'));
            $walletId = $req->getParam('wallet');

            $client = $req->getOption('storage');
            if($client instanceof PDO){
                $wallet = $walletProvider->getWalletById($walletId);
                if(isset($wallet)){
                    if($data->amount <= $wallet['balance']['amount']){
                        $client->beginTransaction();
                        $depositId = $walletProvider->withdraw($walletId, $data->amount, $wallet['balance']['currency'],$data->memo);
                        if(isset($depositId)){
                            $client->commit();
                            return $res->json(['success' => true, 'deposit' => $depositId]);
                        }
                        $client->rollBack();
                    }
                }
            }
        }
    }
    return $res->json(['success' => false]);
});

$singleWallet->post("/withdraw", function(Request $req, Response $res){
    $wallet = $req->getParam('wallet');
    $data = $req->getOption('body');
    //Parse amount, try to send amount and if it pass, debit account.
});

$walletRouter->router("/:wallet", $singleWallet);

global $application;
$application->router("/wallets", $walletRouter);
?>