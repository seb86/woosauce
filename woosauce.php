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

if ( ! function_exists( 'woocommerce_rest_upload_product_images' ) ) {
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
				$featured   = false;

				if ( $field_name === 'featured_image' ) {
					$featured = true;
				}

				// Upload file.
				if ( empty( $file ) ) {
					return new WP_Error( 'woosauce_no_file_data', __( 'No file data', 'woosauce' ), array( 'status' => 400 ) );
				}

				$attachment_id = woocommerce_rest_upload_image_file( $product, $file, $featured );

				if ( ! is_wp_error( $attachment_id ) ) {
					if ( ! $featured ) {
						$files_uploaded[] = $attachment_id;
					}

					// Set featured image ID.
					if ( $featured ) {
						$product->set_image_id( $attachment_id );
					}
				}
			}

			if ( ! empty( $files_uploaded ) ) {
				$product->set_gallery_image_ids( $files_uploaded );
			}
		}

		return $product;
	} // END woocommerce_rest_upload_product_images()
}

if ( ! function_exists( 'woocommerce_rest_upload_image_file' ) ) {
	function woocommerce_rest_upload_image_file( $product, $file, $featured ) {
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
			$attachment_id = wp_insert_attachment( $attachment, $uploaded_file['file'], $product->get_id() );

			if ( ! is_wp_error( $attachment_id ) ) {
				$meta_data = wp_generate_attachment_metadata( $attachment_id, $uploaded_file['file'] );

				if ( ! is_wp_error( $meta_data ) ) {
					// Generate images.
					wp_update_attachment_metadata( $attachment_id, $meta_data );
				}

				// Set featured image.
				if ( $featured ) {
					update_post_meta( $product->get_id(), '_thumbnail_id', $attachment_id ); // Force the thumbnail ID meta to attach uploaded image.
				}
			}
		}

		return $attachment_id;
	} // END woocommerce_rest_upload_image_file()
}

if ( ! function_exists( 'woocommerce_rest_delete_product_images' ) ) {
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
}

if ( ! function_exists( 'wc_rest_get_product_images' ) ) {
	add_filter( 'woocommerce_rest_prepare_product_object', 'wc_rest_get_product_images', 10, 2 );

	/**
	 * Get the images for a product or product variation
	 * and returns all image sizes.
	 *
	 * @param WP_REST_Request                 $request Request object.
	 * @param WC_Product|WC_Product_Variation $product Product instance.
	 *
	 * @return WP_REST_Response
	 */
	function wc_rest_get_product_images( $response, $product ) {
		$images           = array();
		$attachment_ids   = array();
		$attachment_sizes = array_merge( get_intermediate_image_sizes(), array( 'full', 'custom' ) );

		// Add featured image.
		if ( $product->get_image_id() ) {
			$attachment_ids[] = $product->get_image_id();
		}

		// Add gallery images.
		$attachment_ids = array_merge( $attachment_ids, $product->get_gallery_image_ids() );

		$attachments = array();

		// Build image data.
		foreach ( $attachment_ids as $position => $attachment_id ) {
			$attachment_post = get_post( $attachment_id );
			if ( is_null( $attachment_post ) ) {
				continue;
			}

			// Get each image size of the attachment.
			foreach ( $attachment_sizes as $size ) {
				$attachments[ $size ] = current( wp_get_attachment_image_src( $attachment_id, $size ) );
			}

			$featured = $position === 0 ? true : false; // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda

			$images[] = array(
				'id'                => (int) $attachment_id,
				'date_created'      => wc_rest_prepare_date_response( $attachment_post->post_date, false ),
				'date_created_gmt'  => wc_rest_prepare_date_response( strtotime( $attachment_post->post_date_gmt ) ),
				'date_modified'     => wc_rest_prepare_date_response( $attachment_post->post_modified, false ),
				'date_modified_gmt' => wc_rest_prepare_date_response( strtotime( $attachment_post->post_modified_gmt ) ),
				'src'               => $attachments,
				'name'              => get_the_title( $attachment_id ),
				'alt'               => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'position'          => (int) $position,
				'featured'          => $featured,
			);
		}

		// Set a placeholder image if the product has no images set.
		if ( empty( $images ) ) {
			// Get each image size of the attachment.
			foreach ( $attachment_sizes as $size ) {
				$attachments[ $size ] = current( wp_get_attachment_image_src( get_option( 'woocommerce_placeholder_image', 0 ), $size ) );
			}

			$images[] = array(
				'id'       => 0,
				'date_created'      => wc_rest_prepare_date_response( current_time( 'mysql' ), false ), // Default to now.
				'date_created_gmt'  => wc_rest_prepare_date_response( time() ), // Default to now.
				'date_modified'     => wc_rest_prepare_date_response( current_time( 'mysql' ), false ),
				'date_modified_gmt' => wc_rest_prepare_date_response( time() ),
				'src'               => $attachments,
				'name'              => __( 'Placeholder', 'woosauce' ),
				'alt'               => __( 'Placeholder', 'woosauce' ),
				'position'          => 0,
				'featured'          => true,
			);
		}

		$response->data['images'] = $images;

		return $response;
	} // END wc_rest_get_product_images()
}