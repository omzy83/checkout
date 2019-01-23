<?php

namespace App\Http\Services;

class Transaction
{
    public $request;
    public $step1;
    public $step2;
    public $basket;
    public $total;
    public $currency;
    public $reference;
    public $error_message;

    public function __construct($basket_data, $request)
    {
        $this->request = $request;
        $this->step1 = session()->get('checkout-step-1');
        $this->step2 = session()->get('checkout-step-2');
        $this->basket = $basket_data['Basket'];
        $this->total = $basket_data['Total'];
        $this->currency = $basket_data['Currency'];

        $this->error_message = 'Sorry, there was a problem. Please call us for further assistance.';
    }

    public function processPayment()
    {
        $basket = $this->basket;

        // generate transaction reference
        $this->reference = $this->generateWebsiteTransactionReference();

        $regular_result = null;
        $single_result = null;

        if (!empty($baskets['Lines'])) {
            // returns array with status
            $result = $this->processLines();
        }

        return $result;
    }

    public function process3dSecure($values)
    {
        // generate transaction reference
        $this->reference = $this->generateWebsiteTransactionReference();

        $data = [];

        foreach ($values as $key => $value) {
            $data[] = [
                'Name' => $key,
                'Value' => $value,
            ];
        }

        $params = [
            'requestData' => [
                'RequestParameter' => $data,
            ],
        ];

        // returns array with status 'authorised' or 'error'
        $result = $this->authoriseSecure3DTransaction($params);

        if ($result['status'] == 'authorised') {
            $auth_code = $result['auth_code'];

            // returns array with status 'complete' or 'error'
            $result = $this->checkoutSinglePaymentTransaction($auth_code);
        }

        return $result;
    }

    private function processLines()
    {
        $step1 = $this->step1;
        $step2 = $this->step2;

        $result = null;

        if ($step2['payment_method'] == 'Credit Card') {
            // generate the params for card authorisation
            $params = [
                'paymentCard' => [
                    'Cardholder' => $step2['regular_name_on_card'],
                    'CardNumber' => $step2['regular_card_number'],
                    'ExpiryMonth' => $step2['regular_expiry_month'],
                    'ExpiryYear' => $step2['regular_expiry_year'],
                    'IssueNumber' => $step2['regular_issue_number'],
                    'SecurityCode' => $step2['regular_security_code'],
                    'BillingFirstNames' => $step1['first_name'],
                    'BillingLastName' => $step1['last_name'],
                    'BillingAddress1' => $step1['address1'],
                    'BillingAddress2' => $step1['address2'],
                    'BillingAddress3' => $step1['address3'],
                    'BillingTownCity' => $step1['town'],
                    'BillingState' => $step1['state'],
                    'BillingPostalCode' => $step1['postcode'],
                    'BillingCountryCode' => $step1['country'],
                ],

                'securityCodeRequired' => true,
            ];

            if (!empty($step2['regular_valid_month'])) {
                $params['paymentCard']['StartMonth'] = $step2['regular_valid_month'];
            }

            if (!empty($step2['regular_valid_year'])) {
                $params['paymentCard']['StartYear'] = $step2['regular_valid_year'];
            }

            // returns array with status 'valid' or 'error'
            $result = $this->validateCardDetails($params);

            if ($result['status'] == 'valid') {
                // returns array with status 'success' or 'error'
                $result = $this->saveCardAsToken($params);

                // check if card was validated
                if ($result['status'] == 'success') {
                    $token = $result['token'];

                    // returns array with status 'complete' or 'error'
                    $result = $this->checkoutRecurringPaymentTransaction($token);
                }
            }
        }

        elseif ($step2['payment_method'] == 'Direct Debit') {
            $params = [
                'account' => [
                    'Accountholder' => $step2['account_name'],
                    'AccountCode' => $step2['account_number'],
                    'BranchCode' => $step2['sort_code'],
                    'Type' => 'UK',
                ],
            ];

            // returns array with status 'valid' or 'error'
            $result = $this->validateAccount($params);

            if ($result['status'] == 'valid') {
                // returns array with status 'complete' or 'error'
                $result = $this->checkoutRecurringPaymentTransaction(null);
            }
        }

        return $result;
    }

    private function validateCardDetails($params)
    {
        $options = [
            'trace' => 1,
            'exceptions' => true
        ];

        try {
            $client = new \SoapClient(env('API_CARD_PAYMENTS_URL'), $options);
            $result = $client->ValidateCardDetails($params);

            //$this->debug($result, $client);

            if (isset($result->ValidateCardDetailsResult)) {
                // check if card details are valid
                if ($result->ValidateCardDetailsResult->IsValid == true) {
                    return [
                        'status' => 'valid',
                    ];
                }
                else {
                    $error = $result->ValidateCardDetailsResult->ErrorResult->Message;

                    return [
                        'status' => 'error',
                        'error' => $error,
                    ];
                }
            }
        }
        catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $this->error_message,
                //'error' => $e->getMessage(),
            ];
        }

        return [
            'status' => 'error',
            'error' => $this->error_message,
        ];
    }

    private function authoriseSecure3DTransaction($params)
    {
        $options = [
            'trace' => 1,
            'exceptions' => true
        ];

        try {
            $client = new \SoapClient(env('API_CARD_PAYMENTS_URL'), $options);
            $result = $client->AuthoriseSecure3DTransaction($params);

            //$this->debug($result, $client);

            if (isset($result->AuthoriseSecure3DTransactionResult)) {
                if (isset($result->AuthoriseSecure3DTransactionResult->Authorised)) {
                    // check if transaction authorised
                    if ($result->AuthoriseSecure3DTransactionResult->Authorised == true) {
                        // get the transaction auth code
                        $auth_code = $result->AuthoriseSecure3DTransactionResult->AcquirerAuthorisationCode;

                        return [
                            'status' => 'authorised',
                            'auth_code' => $auth_code,
                        ];
                    }
                    else {
                        $error = $result->AuthoriseSecure3DTransactionResult->ErrorResult->Message;

                        return [
                            'status' => 'error',
                            'error' => 'Card not authorised. Please try again or contact your bank.',
                        ];
                    }
                }
                else {
                    $error = $result->AuthoriseSecure3DTransactionResult->ErrorResult->Message;

                    return [
                        'status' => 'error',
                        'error' => $error,
                    ];
                }
            }
        }
        catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $this->error_message,
                //'error' => $e->getMessage(),
            ];
        }

        return [
            'status' => 'error',
            'error' => $this->error_message,
        ];
    }

    private function saveCardAsToken($params)
    {
        $options = [
            'trace' => 1,
            'exceptions' => true
        ];

        try {
            $client = new \SoapClient(env('API_CARD_PAYMENTS_URL'), $options);
            $result = $client->saveCardAsToken($params);

            //$this->debug($result, $client);

            if (isset($result->SaveCardAsTokenResult)) {
                if ($result->SaveCardAsTokenResult->Success == true) {
                    $token = $result->SaveCardAsTokenResult->Token;

                    return [
                        'status' => 'success',
                        'token' => $token,
                    ];
                }
                else {
                    $error = $result->SaveCardAsTokenResult->ErrorResult->Message;

                    return [
                        'status' => 'error',
                        'error' => $error,
                    ];
                }
            }
        }
        catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $this->error_message,
                //'error' => $e->getMessage(),
            ];
        }

        return [
            'status' => 'error',
            'error' => $this->error_message,
        ];
    }

    private function validateAccount($params)
    {
        $options = [
            'trace' => 1,
            'exceptions' => true
        ];

        try {
            $client = new \SoapClient(env('API_BANK_PAYMENTS_URL'), $options);
            $result = $client->validateAccount($params);

            //$this->debug($result, $client);

            if (isset($result->ValidateAccountResult)) {
                if ($result->ValidateAccountResult->IsValid == true) {
                    return [
                        'status' => 'valid',
                    ];
                }
                else {
                    return [
                        'status' => 'error',
                        'error' => 'Invalid bank details.',
                    ];
                }
            }
        }
        catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $this->error_message,
                //'error' => $e->getMessage(),
            ];
        }

        return [
            'status' => 'error',
            'error' => $this->error_message,
        ];

    }

    private function authoriseWebTransaction($params)
    {
        $options = [
            'trace' => 1,
            'exceptions' => true
        ];

        try {
            $client = new \SoapClient(env('API_CARD_PAYMENTS_URL'), $options);
            $result = $client->authoriseWebTransaction($params);

            //$this->debug($result, $client);

            if (isset($result->AuthoriseWebTransactionResult)) {
                // check for 3d secure
                if (isset($result->AuthoriseWebTransactionResult->Secure3DResult)) {
                    if ($result->AuthoriseWebTransactionResult->Secure3DResult->Secure3DRequired) {
                        $secure3d = $result->AuthoriseWebTransactionResult->Secure3DResult->Html;

                        // store the 3d secure html in session
                        session()->put('secure3d', $secure3d);

                        return [
                            'status' => 'secure3d',
                        ];
                    }
                }
                // else check for authorised repsonse
                elseif (isset($result->AuthoriseWebTransactionResult->Authorised)) {
                    // check if transaction authorised
                    if ($result->AuthoriseWebTransactionResult->Authorised == true) {
                        // get the transaction auth code
                        $auth_code = $result->AuthoriseWebTransactionResult->AcquirerAuthorisationCode;

                        return [
                            'status' => 'authorised',
                            'auth_code' => $auth_code,
                        ];
                    }
                    else {
                        $error = $result->AuthoriseWebTransactionResult->ErrorResult->Message;

                        return [
                            'status' => 'error',
                            'error' => $error,
                        ];
                    }
                }
            }
        }
        catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $this->error_message,
                //'error' => $e->getMessage(),
            ];
        }

        return [
            'status' => 'error',
            'error' => $this->error_message,
        ];
    }

    private function getSingleTransactionData($auth_code)
    {
        $request = $this->request;
        $reference = $this->reference;

        if (!empty($reference)) {
            $params = [
                'transaction' => [
                    'TransactionId' => $this->getGuid(),
                    'TransactionTimeUtc' => gmdate("Y-m-d\TH:i:s\Z"),
                    'BasketCollectionId' => $this->getGuid(),
                    'UserIp' => $request->ip(),
                    'PaymentMethod' => $this->getPaymentMethodSingle(),
                ],
            ];

            return $params;
        }

        return false;
    }

    private function getRegularTransactionData($token)
    {
        $request = $this->request;
        $step2 = $this->step2;
        $reference = $this->reference;

        if (!empty($reference)) {
            $params = [
                'transaction' => [
                    'TransactionId' => $this->getGuid(),
                    'TransactionTimeUtc' => gmdate("Y-m-d\TH:i:s\Z"),
                    'BasketCollectionId' => $this->getGuid(),
                    'AnalyticsData' => $this->getAnalyticsData($request),
                    'UserIp' => $request->ip(),
                    'PaymentMethod' => $this->getPaymentMethodRegular(),
                    'PaymentDayOfMonth' => 1,
                    'TargetAmount' => null,
                    'StopPaymentsWhenTargetReached' => null,
                ],
            ];

            if ($step2['payment_method'] == 'Credit Card') {
                $params['transaction']['PaymentCardDetails'] = $this->getCardTokenDetails($token);
            }

            elseif ($step2['payment_method'] == 'Direct Debit') {
                $params['transaction']['BankAccountDetails'] = $this->getBankDetails($token);
            }

            return $params;
        }

        return false;
    }

    private function checkoutSinglePaymentTransaction($auth_code)
    {
        $params = $this->getSingleTransactionData($auth_code);

        if (!empty($params)) {
            $options = [
                'trace' => 1,
                'exceptions' => true
            ];

            try {
                $client = new \SoapClient(env('API_WEBSITE_CHECKOUT_URL'), $options);
                $result = $client->checkoutSinglePaymentTransaction($params);

                //$this->debug($result, $client);

                if (isset($result->CheckoutSinglePaymentTransactionResult)) {
                    return [
                        'status' => 'complete',
                        'result' => $result->CheckoutSinglePaymentTransactionResult, // true or false
                        'reference' => $this->reference,
                    ];
                }
            }
            catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'error' => $this->error_message,
                    //'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 'error',
            'error' => $this->error_message,
        ];
    }

    private function checkoutRecurringPaymentTransaction($token)
    {
        $params = $this->getRegularTransactionData($token);

        if (!empty($params)) {
            $options = [
                'trace' => 1,
                'exceptions' => true
            ];

            try {
                $client = new \SoapClient(env('API_WEBSITE_CHECKOUT_URL'), $options);
                $result = $client->checkoutRecurringPaymentTransaction($params);

                //$this->debug($result, $client);

                if (isset($result->CheckoutRecurringPaymentTransactionResult)) {
                    return [
                        'status' => 'complete',
                        'result' => $result->CheckoutRecurringPaymentTransactionResult, // true or false
                        'reference' => $this->reference,
                    ];
                }
            }
            catch (\Exception $e) {
                return [
                    'status' => 'error',
                    'error' => $this->error_message,
                    //'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 'error',
            'error' => $this->error_message,
        ];
    }

    private function generateWebsiteTransactionReference()
    {
        $params = [
            'GenerateWebsiteTransactionReference',
        ];

        $options = [
            'trace' => 1,
            'exceptions' => true
        ];

        try {
            $client = new \SoapClient(env('API_WEBSITE_CHECKOUT_URL'), $options);
            $result = $client->GenerateWebsiteTransactionReference($params);

            //$this->debug($result, $client);

            if (isset($result->GenerateWebsiteTransactionReferenceResult))
                return $result->GenerateWebsiteTransactionReferenceResult;
        }
        catch (\Exception $e) {
            //return $e->getMessage();
            return false;
        }

        return false;
    }

    public function getGuid()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            // 32 bits for "time_low"
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

            // 16 bits for "time_mid"
            mt_rand( 0, 0xffff ),

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand( 0, 0x0fff ) | 0x4000,

            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand( 0, 0x3fff ) | 0x8000,

            // 48 bits for "node"
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
    }

    private function debug($result, $client)
    {
        //$result = $client->__getFunctions();
        //$result = $client->__getTypes();

        echo "<pre>";
        print_r($result);
        echo "REQUEST:\n" . htmlentities(str_ireplace('><', ">\n<", $client->__getLastRequest())) . "\n";
        echo "RESPONSE:\n" . htmlentities(str_ireplace('><', ">\n<", $client->__getLastResponse())) . "\n";
        echo "</pre>";
        die();
    }
}
