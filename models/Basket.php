<?php

namespace App\Http\Services;

class Basket
{
    /**
     * @return array. Generate formatted basket data
     */
    public function getBasketData()
    {
        $content = session()->get('basket');

        $basket = $this->formatBasketData($content);

        $basket_data = $basket['Basket'];

        return [
            'Basket' => $basket_data['basket'],
            'Currency' => $basket_data['currency'],
            'Total' => $basket_data['total'],
        ];
    }

    /**
     * @return array. Generate formatted basket data
     */
    public function formatBasketData($content)
    {
        // array to hold restructured basket
        $basket = [];
        $total = 0;
        $currency = $this->getBasketCurrency($content);

        if (!empty($basket['items']) && is_array($basket['items'])) {
            foreach ($basket['items'] as $item_id => $values) {
                $product = Product::getProductById($item_id)

                // add new 'Product' element to the existing 'Lines' array
                $basket['Lines'][$item_id] = $product;

                // increment running basket total
                $basket['Total'] += $values['Amount'];
            }

            $total += $basket['Total'];
        }

        return [
            'basket' => $basket,
            'currency' => $currency,
            'total' => $total,
        ];
    }

    /**
     * @return string. Get basket currency
     */
    public function getBasketCurrency($basket_data)
    {
        return session()->get('currency');
    }

    /**
     * @return string. Get total items count
     */
    public function getTotalItemsCount()
    {
        $basket_data = $this->getBasketData();

        $lines = $basket_data['Basket']['Lines'];

        return count($lines);
    }

    /**
     * @return string. Add item to basket
     */
    public function addItem($post)
    {
        $basket_data = $this->getBasketData();

        $return_array = [
            'status' => false,
            'message' => 'Error: Invalid data supplied',
        ];

        if ($isset($post['id'])) {
            $id = $post['id'];

            // create new entry in 'items' array
            $basket_data['items'][] = $id;

            $return_array['status'] = true;
            $return_array['message'] = 'Product added to basket';
        }

        return $return_array;
    }

    /**
     * @return string. Remove item from basket
     */
    public function removeItem($id)
    {
        $basket_data = $this->getBasketData();

        $return_array = [
            'status' => false,
            'message' => 'Error: Invalid data supplied',
        ];

        if (isset($basket_data['items'][$id])) {
            // remove this item from array
            unset($basket_data['items'][$id]);

            $return_array['status'] = true;
            $return_array['message'] = 'Basket updated successfully';
        }

        return $return_array;
    }

    /**
     * @return string. Edit item in basket
     */
    public function editItem($post)
    {
        $basket_data = $this->getBasketData();

        $return_array = [
            'status' => false,
            'message' => 'Error: Invalid data supplied',
        ];

        if ($isset($post['id'])) {
            $id = $post['id'];

            if (isset($basket_data['items'][$id])) {
                $basket_data['items'][$id] = $post;

                $return_array['status'] = true;
                $return_array['message'] = 'Basket updated successfully';
            }
        }

        return $return_array;
    }
}
