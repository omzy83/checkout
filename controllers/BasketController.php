<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Services\Basket;

class BasketController extends Controller
{
    /**
     * @return string
     * Show basket page
     */
    public function index()
    {
        $basket = new Basket();

        $basket_data = $basket->getBasketData();

        $count = $basket->getTotalItemsCount();

        $currency = $basket_data['Currency'];

        return view('basket.index', [
            'basket_data' => $basket_data,
            'count' => $count,
            'currency' => $currency,
        ]);
    }

    /**
     * @return string
     * Change currency (ajax action)
     */
    public function changeCurrency(Request $request)
    {
        $basket = new Basket();

        $session = $request->session();

        $code = $request->input('code');

        $basket->changeCurrency($code);

        return response()->json();
    }

    /**
     * @return string
     * Add item (ajax action)
     */
    public function addItem(Request $request)
    {
        $return_array = [
            'status' => false,
            'message' => null,
        ];

        $basket = new Basket();

        $post = $request->input();

        $result = $basket->addItem($post);

        if ($result === true) {
            $basket_data = $basket->getBasketData();

            $currency = $basket_data['Currency'];

            $count = $basket->getTotalItemsCount();

            $html = view('layouts.mini-basket', [
                'basket_data' => $basket_data,
                'currency' => $currency,
                'total_count' => $count,
            ])->render();

            $return_array['status'] = true;
            $return_array['basket'] = $html;
            $return_array['count'] = $count;
        }

        elseif ($result === false) {
            $return_array['message'] = 'There was a problem adding this item to your basket. Please call us to complete your donation.';
        }

        else { // a validation or other error
            $return_array['message'] = $result;
        }

        return response()->json($return_array);
    }

    /**
     * @return string
     * Remove item (ajax action)
     */
    public function removeItem(Request $request)
    {
        $return_array = [
            'status' => false,
            'message' => null,
        ];

        $basket = new Basket();

        $id = $request->input('id');

        $result = $basket->removeItem($id);

        if ($result === true) {
            $basket_data = $basket->getBasketData();

            $currency = $basket_data['Currency'];

            $count = $basket->getTotalItemsCount();

            $total = number_format($basket_data['Total'], 2);

            $html = view('basket.basket', [
                'basket_data' => $basket_data,
                'currency' => $currency,
            ])->render();

            $return_array['status'] = true;
            $return_array['basket'] = $html;
            $return_array['count'] = $count;
            $return_array['total'] = $total;
        }
        else {
            $return_array['message'] = 'There was a problem processing your request.';
        }

        return response()->json($return_array);
    }

    /**
     * @return string
     * Edit item (ajax action)
     */
    public function editItem(Request $request)
    {
        $return_array = [
            'status' => false,
            'message' => null,
        ];

        $basket = new Basket();

        $post = $request->input();

        $result = $basket->editItem($post);

        if ($result == 'true') {
            $basket_data = $basket->getBasketData();

            $currency = $basket_data['Currency'];

            $count = $basket->getTotalItemsCount();

            $total = number_format($basket_data['Total'], 2);

            $html = view('basket.basket', [
                'basket_data' => $basket_data,
                'currency' => $currency,
            ])->render();

            $return_array['status'] = true;
            $return_array['basket'] = $html;
            $return_array['count'] = $count;
            $return_array['total'] = $total;
        }
        else {
            $return_array['message'] = 'There was a problem processing your request.';
        }

        return response()->json($return_array);
    }
}
