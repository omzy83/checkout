<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Requests\CheckoutStep1;
use App\Http\Requests\CheckoutStep2;
use App\Http\Services\Checkout;
use App\Http\Services\Basket;
use App\Http\Services\Data;
use App\Http\Services\Transaction;

class CheckoutController extends Controller
{
    /**
     * Step 1 - address details
     */
    public function details(Request $request)
    {
        $form_data = session()->get('checkout-step-1');

        $basket = new Basket();

        $basket_data = $basket->getBasketData();

        $count = $basket->getTotalItemsCount();

        if ($count == 0) {
            return redirect()->route('list-basket');
        }

        $countries = Data::getCountriesList();
        $title_options = Data::getTitleOptions();

        return view('checkout.index', [
            'form_data' => $form_data,
            'title_options' => $title_options,
            'totals_text' => $totals_text,
        ]);
    }

    /**
     * Step 2 - payment details
     */
    public function payment(Request $request)
    {
        $form_data = session()->get('checkout-step-2');

        $basket = new Basket();

        $basket_data = $basket->getBasketData();

        $count = $basket->getTotalItemsCount();

        if ($count == 0 || session()->get('checkout-step-1') === null) {
            return redirect()->route('checkout-details');
        }

        $currency = $basket_data['Currency'];
        $card_types = Data::getCardTypes();
        $payment_methods = Data::getPaymentMethods($allow_direct_debit);

        return view('checkout.step2', [
            'form_data' => $form_data,
            'basket_data' => $basket_data,
            'currency' => $currency,
            'card_types' => $card_types,
            'payment_methods' => $payment_methods,
        ]);
    }

    /**
     * Step 2a - secure3d
     */
    public function secure3d(Request $request)
    {
        if (session()->get('secure3d') === null) {
            return redirect()->route('checkout-details');
        }

        $return = session()->get('secure3d');

        return view('checkout.step2', [
            'return' => $return,
        ]);
    }

    /**
     * Step 3 - checkout complete
     */
    public function complete(Request $request)
    {
        $basket = new Basket();

        $basket_data = $basket->getBasketData();

        $reference = session()->get('reference');

        $transaction = new Transaction($basket_data, null);

        // clear the session
        session()->flush();

        return view('checkout.step3', [
            'reference' => $reference,
        ]);
    }

    /**
     * Submit and validate address details form
     */
    public function storeDetails(CheckoutStep1 $request)
    {
        // only gets this far if validation succeeds
        if ($request->ajax()) {
            // return empty array for valid json response
            return response()->json();
        }
        else {
            // store step 1 attributes in session for use later
            session()->put('checkout-step-1', $request->input());

            return redirect()->route('checkout-payment');
        }
    }

    /**
     * Submit and validate payment details form
     */
    public function storePayment(CheckoutStep2 $request)
    {
        // only gets this far if validation succeeds
        if ($request->ajax()) {
            // return empty array for valid json response
            return response()->json();
        }
        else
        {
            // store step 2 attributes in session for use later
            session()->put('checkout-step-2', $request->input());

            return $this->processPayment($request);
        }
    }

    /**
     * Process payment card / bank details
     */
    private function processPayment($request)
    {
        $basket = new Basket();

        $basket_data = $basket->getBasketData();

        $transaction = new Transaction($basket_data, $request);

        $result = $transaction->processPayment();

        if ($result === true) {
            $return = [
                'status' => 'complete',
                'result' => true,
                'reference' => $transaction->reference,
            ];

            return $this->processResult($return);
        }

        return false;
    }

    /**
     * Secure 3D callback URL (generate 3d secure hidden input)
     */
    public function secure3dCallback(Request $request)
    {
        $value = serialize($request->input());

        return view('checkout.secure3dcallback', [
            'value' => $value,
        ]);
    }

    /**
     * Secure 3D data POSTback URL
     */
    public function secure3dProcess(Request $request)
    {
        $basket = new Basket();

        $basket_data = $basket->getBasketData();

        $count = $basket->getTotalItemsCount();

        if ($count == 0 || session()->get('checkout-step-2') === null) {
            return redirect()->route('checkout-payment');
        }

        $values = unserialize($request->input('Secure3d'));

        $transaction = new Transaction($basket_data, $request);

        $result = $transaction->process3dSecure($values);

        return $this->processResult($result);
    }

    /**
     * Process the result to determine next action
     */
    private function processResult($result)
    {
        if ($result['status'] == 'secure3d') {
            return redirect()->route('checkout-secure3d');
        }

        elseif ($result['status'] == 'error') {
            return redirect()->route('checkout-payment')->with('flash-error', 'Error processing payment: ' . $result['error']);
        }

        elseif ($result['status'] == 'complete') {
            if ($result['result'] === true) {
                $this->sendConfirmationEmail($result['reference']);

                // store reference for use on thank you page
                session()->put('reference', $result['reference']);

                return redirect()->route('checkout-complete');
            }

            else {
                return redirect()->route('checkout-fail');
            }
        }

        elseif ($result['status'] == 'valid') {
            return true;
        }

        else {
            return redirect()->route('checkout-fail');
        }
    }

    /**
     * Send confirmation email
     */
    private function sendConfirmationEmail($reference)
    {
        $basket = new Basket();

        $basket_data = $basket->getBasketData();

        $transaction = new Transaction($basket_data, null);

        $transaction->sendEmail($reference);
    }
}
