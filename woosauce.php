<?php
/**
 * Plugin Name: WooSauce
 * Plugin URI:  https://github.com/seb86/woosauce
 * Description: Adds image upload support for products when created via the WooCommerce REST API.
 * Author:      Sébastien Dumont
 * Author URI:  https://sebastiendumont.com
 * Version:     1.0.0
 * Text Domain: woosauce
 * Domain Path: /languages/
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 6.4
 * WC tested up to: 7.2
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_rest_insert_product_object', 'woocommerce_rest_upload_product_images', 10, 3 );

function woocommerce_rest_upload_product_images( $product, $request, $creating ) {
	if ( $creating ) {
		$files = method_exists( $request, 'get_file_params' ) ? $request->get_file_params() : array();

		if ( empty( $files ) ) {
			return;
		}

		$files_uploaded = array();

		foreach ( $files as $field_name => $file ) {
			$file_name  = strtolower( str_replace( ' ', '-', preg_replace( '/\.[^.]+$/', '', wp_basename( $file['name'] ) ) ) );
			$field_name = preg_replace( '/\.[^.]+$/', '', $field_name );

			// Upload file.
			if ( empty( $file ) ) {
				return new WP_Error( 'woosauce_no_file_data', __( 'No file data', 'woosauce' ), array( 'status' => 400 ) );
			}

			$product = woocommerce_rest_upload_image_file( $product, $field_name, $file );
		}
	}

	return $product;
} // END woocommerce_rest_upload_product_images()

function woocommerce_rest_upload_image_file( $product, $field_name, $file ) {
	// wp_handle_sideload function is part of wp-admin.
	if ( ! function_exists( 'wp_handle_sideload' ) ) {
		include_once ABSPATH . 'wp-admin/includes/file.php';
	}

	include_once ABSPATH . 'wp-admin/includes/media.php';

	if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
		include( ABSPATH . 'wp-admin/includes/image.php' );
	}

	// Now, sideload it in.
	$file_data = array(
		'error'    => null,
		'tmp_name' => $file['tmp_name'],
		'name'     => $file['name'],
		'type'     => $file['type'],
	);

	$mimes = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'gif'          => 'image/gif',
		'png'          => 'image/png',
		'bmp'          => 'image/bmp',
		'tiff|tif'     => 'image/tiff',
	);

	$uploaded_file = wp_handle_sideload(
		$file_data,
		array(
			'test_form' => false,
			'mimes'     => $mimes,
		),
		current_time( 'Y/m' )
	);

	if ( ! is_wp_error( $uploaded_file ) ) {
		$info    = wp_check_filetype( $uploaded_file['file'] );
		$title   = '';
		$content = '';

		if ( $image_meta = @wp_read_image_metadata( $uploaded_file['file'] ) ) {
			if ( trim( $image_meta['title'] ) && ! is_numeric( sanitize_title( $image_meta['title'] ) ) ) {
				$title = wc_clean( $image_meta['title'] );
			}
			if ( trim( $image_meta['caption'] ) ) {
				$content = wc_clean( $image_meta['caption'] );
			}
		}

		// Prepare an array of post data for the attachment.
		$attachment = array(
			'post_mime_type' => $info['type'],
			'guid'           => $uploaded_file['url'],
			'post_mime_type' => $uploaded_file['type'],
			'post_title'     => $title ? $title : basename( $uploaded_file['file'] ),
			'post_content'   => $content
		);

		// Insert as attachment.
		$attachment_id = wp_insert_attachment( $attachment, false, $product->get_id() );

		if ( ! is_wp_error( $attachment_id ) ) {
			if ( $field_name !== 'featured_image' ) {
				$files_uploaded[] = $attachment_id;
			}

			// Generate images.
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $uploaded_file['file'] ) );

			// Set product images.
			if ( $field_name === 'featured_image' ) {
				update_post_meta( $product->get_id(), '_thumbnail_id', $attachment_id ); // Force the thumbnail ID meta to attach uploaded image.

				$product->set_image_id( $attachment_id );

			} else {
				$product->set_gallery_image_ids( $files_uploaded );
			}
		}
	}

	return $product;
} // END woocommerce_rest_upload_image_file()

/**
 * Deletes all images attached to the product.
 *
 * If force is set, the images will permanently delete.
 */
add_action( 'woocommerce_rest_delete_product_object', 'woocommerce_rest_delete_product_images', 10, 3 );

function woocommerce_rest_delete_product_images( $product, $response, $request ) {
	$force = (bool) $request['force'];

	$image_ids = array_merge( array( $product->get_image_id( 'view' ) ), $product->get_gallery_image_ids( 'view' ) );

	foreach ( $image_ids as $id ) {
		wp_delete_attachment( $id, $force );
	}
}