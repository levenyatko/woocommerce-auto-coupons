<?php

    class WCAC_Product
    {
        public static function get_coupons( $product )
        {
            $product_id = 0;

            if ( is_int($product) ) {
                $product_id = $product;
            } elseif ( is_object( $product ) && is_callable( array( $product, 'get_id' ) ) ) {
                $product_id = $product->get_id();
            }

            if ( ! $product_id ) {
                return false;
            }

            $cached = WCAC_Transient::get_product_transient($product_id);

            if ( ! empty($cached) ) {
                return $cached;
            }

            $updated = self::get_available_coupons( $product_id );

            return $updated;
        }

        public static function get_available_coupons( $product )
        {
            $updated_list = [];

            if ( is_int($product) ) {
                $product = wc_get_product( $product );
            }

            if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) {
                return [];
            }

            $args = array(
                'posts_per_page'   => -1,
                'post_type'        => 'shop_coupon',
                'post_status'      => 'publish',
                'no_found_rows'    => true,
                'fields'           => 'ids',
                'meta_query'       => [
                    'relation' => 'AND',
                    [
                        'relation' => 'OR',
                        [
                            'key' => 'date_expires',
                            'value' => 'IS NULL',
                            'compare' => '=',
                        ],
                        [
                            'key' => 'date_expires',
                            'value' => time(),
                            'compare' => '>=',
                            'type'    => 'NUMERIC',
                        ],
                    ]
                ]
            );

            $coupons_query = new WP_Query( $args );

            $available_product_coupons = [];

            if ($coupons_query->have_posts()) {

                foreach ($coupons_query->posts as $coupon_id) {

                    $coupon =  new WC_Coupon( $coupon_id );
                    if ( $coupon->is_valid_for_product( $product ) ) {
                        $available_product_coupons[] = $coupon;
                    }

                }

            }

            if ( $available_product_coupons ) {

                $updated_list['available'] = [];

                foreach ( $available_product_coupons as $c ) {
                    $updated_list['available'][] = $c->get_id();
                }

                $apply_coupon_data = self::get_max_sale_coupon( $available_product_coupons, $product );

                $updated_list['apply'] = $apply_coupon_data;

            }

            WCAC_Transient::update_product_transient($product->get_id(), $updated_list);

            return $updated_list;
        }

        /**
         * Get coupon with max sale for product
         */
        private static function get_max_sale_coupon( $coupons, $product )
        {
            if ( ! is_object( $product ) || ! is_callable( array( $product, 'get_id' ) ) ) {
                return [];
            }

            if ( count($coupons) > 0 ) {

                remove_filter( 'woocommerce_product_get_price', 'wcac_woocommerce_get_coupon_price', 10, 2 );

                $product_price = $product->get_price();

                $min_price = PHP_FLOAT_MAX;
                $min_index = -1;

                $values = array (
                    'data'		=> $product,
                    'quantity'	=> 1
                );

                foreach ($coupons as $i => $coupon) {

                    if ( ! is_object( $coupon ) || ! is_callable( array( $coupon, 'get_id' ) ) ) {
                        continue;
                    }

                    $discount_amount = $coupon->get_discount_amount( $product_price, $values, true );
                    $discount_amount = min( $product_price, $discount_amount );
                    $_price          = max( $product_price - $discount_amount, 0 );

                    if ( $_price < $min_price ) {
                        $min_price = $_price;
                        $min_index = $i;
                    }

                }

                add_filter( 'woocommerce_product_get_price', 'wcac_woocommerce_get_coupon_price', 10, 2 );

                if ( $min_index >= 0 ) {

                    return [
                        'coupon_code' => $coupons[ $min_index ]->get_code(),
                        'product_price' => $min_price,
                    ];

                }

            }

            return [];

        }

    }