<?php

namespace WooCommerceCustobar\Synchronization;

defined( 'ABSPATH' ) || exit;

use WooCommerceCustobar\DataType\Custobar_Product;

/**
 * Class Product_Sync
 *
 * @package WooCommerceCustobar\Synchronization
 */
class Product_Sync extends Data_Sync {


	protected static $endpoint = '/products/upload/';
	protected static $child    = __CLASS__;

	public static function add_hooks() {
		// Schedule actions
		add_action( 'woocommerce_new_product', array( __CLASS__, 'schedule_single_update' ), 10, 1 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'schedule_single_update' ), 10, 1 );

		// Hook into scheduled actions
		// Call parent method to consider request limit
		add_action( 'woocommerce_custobar_product_sync', array( __CLASS__, 'throttle_single_update' ), 10, 1 );
	}

	public static function schedule_single_update( $product_id ) {
		// Allow 3rd parties to decide if product should be synced
		if ( ! apply_filters( 'woocommerce_custobar_product_should_sync', true, $product_id ) ) {
			return;
		}

		$hook  = 'woocommerce_custobar_product_sync';
		$args  = array( 'product_id' => $product_id );
		$group = 'custobar';

		// We need only one action scheduled
		if ( ! as_next_scheduled_action( $hook, $args, $group ) ) {
			as_schedule_single_action( time(), $hook, $args, $group );

			wc_get_logger()->info(
				'#' . $product_id . ' NEW/UPDATE PRODUCT, SYNC SCHEDULED',
				array( 'source' => 'custobar' )
			);
		}
	}

	public static function single_update( $product_id ) {
		wc_get_logger()->info(
			'#' . $product_id . ' PRODUCT SYNC, UPLOADING TO CUSTOBAR',
			array( 'source' => 'custobar' )
		);

		$product = wc_get_product( $product_id );

		if ( $product ) {

			$properties = self::format_single_item( $product );
			return self::upload_data_type_data( $properties, true );

		} else {

			wc_get_logger()->warning(
				'#' . $product_id . ' tried to sync product, but product was not found',
				array( 'source' => 'custobar' )
			);

		}

		return false;
	}

	public static function batch_update() {
		$response       = new \stdClass();
		$limit          = 500;
		$tracker        = self::tracker_fetch();
		$offset         = $tracker['offset'];
		$variant_offset = $tracker['variant_offset'];
		$product_list   = array();
		$variant_list   = array();

		if ( 0 == $variant_offset ) {

			$products = wc_get_products(
				array(
					'limit'   => $limit,
					'offset'  => $offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			foreach ( $products as $product ) {
				$product_list[] = self::format_single_item( $product );
			}
		}

		$count   = count( $product_list );
		$offset += $count;

		// Fetch variants
		if ( $count < $limit ) {

			$variants = wc_get_products(
				array(
					'type'    => 'variation',
					'limit'   => $limit,
					'offset'  => $variant_offset,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);

			foreach ( $variants as $variant ) {
				$variant_list[] = self::format_single_variant( $variant );
			}

			$count           = count( $variant_list );
			$variant_offset += $count;

			$product_list = array_merge( $product_list, $variant_list );
		}

		// no products
		if ( empty( $product_list ) ) {
			$response->code = 220;
			return $response;
		}

		$api_response = self::upload_data_type_data( $product_list );

		if ( is_wp_error( $api_response ) ) {
			// Request was invalid
			$response->code = 444;
			$response->body = $api_response->get_error_message();
			return $response;
		}

		self::tracker_save( $offset, $variant_offset );

		// return response
		$response->code    = $api_response->code;
		$response->body    = $api_response->body;
		$response->tracker = self::tracker_fetch();
		$response->count   = $count;
		return $response;
	}

	public static function tracker_fetch() {
		$tracker = get_option( 'custobar_export_product' );
		if ( ! is_array( $tracker ) ) {
			$tracker = array();
		}
		if ( ! isset( $tracker['offset'] ) ) {
			$tracker['offset'] = 0;
		}
		if ( ! isset( $tracker['variant_offset'] ) ) {
			$tracker['variant_offset'] = 0;
		}
		if ( ! isset( $tracker['updated'] ) ) {
			$tracker['updated'] = false;
		}
		return $tracker;
	}

	public static function tracker_save( $offset, $variant_offset, $total = null, $variant_total = null ) {
		$tracker = self::tracker_fetch();
		if ( isset( $offset ) && isset( $variant_offset ) ) {
			$tracker['offset']         = $offset;
			$tracker['variant_offset'] = $variant_offset;
			$tracker['updated']        = time();
		}
		if ( isset( $total ) && isset( $variant_total ) ) {
			$tracker['total']         = $total;
			$tracker['variant_total'] = $variant_total;
		}
		update_option( 'custobar_export_product', $tracker );
	}

	protected static function format_single_item( $product ) {
		$custobar_product = new Custobar_Product( $product );
		$properties       = $custobar_product->get_assigned_properties();
		return apply_filters( 'woocommerce_custobar_product_properties', $properties, $product );
	}

	protected static function format_single_variant( $variant ) {
		$custobar_product               = new Custobar_Product( $variant );
		$properties                     = $custobar_product->get_assigned_properties();
		$properties['main_product_ids'] = array( $variant->get_parent_id() );
		return apply_filters( 'woocommerce_custobar_product_properties', $properties, $variant );
	}


	protected static function upload_data_type_data( $data, $single = false ) {
		$formatted_data = array(
			'products' => array(),
		);
		if ( $single ) {
			$formatted_data['products'][] = $data;
		} else {
			$formatted_data['products'] = $data;
		}
		return self::upload_custobar_data( $formatted_data );
	}
}
