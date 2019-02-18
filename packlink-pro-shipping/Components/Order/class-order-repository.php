<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

namespace Packlink\WooCommerce\Components\Order;

use Logeecom\Infrastructure\ORM\QueryFilter\QueryFilter;
use Logeecom\Infrastructure\ORM\RepositoryRegistry;
use Logeecom\Infrastructure\ServiceRegister;
use Logeecom\Infrastructure\Singleton;
use Packlink\BusinessLogic\Http\DTO\Shipment as Shipment_DTO;
use Packlink\BusinessLogic\Http\DTO\Tracking;
use Packlink\BusinessLogic\Order\Exceptions\OrderNotFound;
use Packlink\BusinessLogic\Order\Interfaces\OrderRepository;
use Packlink\BusinessLogic\Order\Objects\Address;
use Packlink\BusinessLogic\Order\Objects\Item;
use Packlink\BusinessLogic\Order\Objects\Order;
use Packlink\BusinessLogic\Order\Objects\Shipment;
use Packlink\BusinessLogic\Order\Objects\Shipping;
use Packlink\BusinessLogic\Order\Objects\TrackingHistory;
use Packlink\WooCommerce\Components\Services\Config_Service;
use WC_Order;
use WP_Term;

/**
 * Class Order_Repository
 * @package Packlink\WooCommerce\Components\Repositories
 */
class Order_Repository extends Singleton implements OrderRepository {

	/**
	 * Singleton instance of this class.
	 *
	 * @var static
	 */
	protected static $instance;

	/**
	 * Configuration service.
	 *
	 * @var Config_Service
	 */
	protected $configuration;

	/**
	 * Order_Repository constructor.
	 */
	protected function __construct() {
		parent::__construct();

		$this->configuration = ServiceRegister::getService( Config_Service::CLASS_NAME );
	}

	/**
	 * Fetches and returns system order by its unique identifier.
	 *
	 * @param string $order_id $orderId Unique order id.
	 *
	 * @return Order Order object.
	 * @throws \Packlink\BusinessLogic\Order\Exceptions\OrderNotFound When order with provided id is not found.
	 */
	public function getOrderAndShippingData( $order_id ) {
		$wc_order = $this->load_order_by_id( $order_id );

		$order = new Order();
		$order->setId( $order_id );
		$order->setStatus( $wc_order->get_status() );
		$order->setBasePrice( $wc_order->get_subtotal() );
		$order->setCartPrice( $wc_order->get_total() - $wc_order->get_shipping_total() );
		$order->setCurrency( $wc_order->get_currency() );
		$order->setCustomerId( $wc_order->get_customer_id() );
		$order->setNetCartPrice( $order->getCartPrice() - $wc_order->get_cart_tax() );
		$order->setOrderNumber( $wc_order->get_order_number() );
		$order->setTotalPrice( $wc_order->get_total() );
		$order->setShippingPrice( $wc_order->get_shipping_total() );
		$order->setItems( $this->get_order_items( $wc_order ) );
		$order->setShipping( $this->get_order_shipping( $wc_order ) );
		$order->setShipment( $this->get_order_shipment( $wc_order ) );
		$order->setPacklinkShipmentLabels( $wc_order->get_meta( Order_Meta_Keys::LABELS ) ?: array() );

		$order->setBillingAddress( $this->get_billing_address( $wc_order ) );
		$order->setShippingAddress( $this->get_shipping_address( $wc_order ) );

		if ( $wc_order->meta_exists( Order_Meta_Keys::DROP_OFF_ID ) ) {
			$order->setShippingDropOffId( $wc_order->get_meta( Order_Meta_Keys::DROP_OFF_ID ) );
			$order->setShippingDropOffAddress( $this->get_drop_off_address( $wc_order ) );
		}

		return $order;
	}

	/**
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * Sets order packlink reference number.
	 *
	 * @param string $order_id Unique order id.
	 * @param string $shipment_reference Packlink shipment reference.
	 *
	 * @throws \Packlink\BusinessLogic\Order\Exceptions\OrderNotFound When order with provided id is not found.
	 */
	public function setReference( $order_id, $shipment_reference ) {
		$wc_order = $this->load_order_by_id( $order_id );

		$order_shipment = new Order_Shipment_Entity();
		$order_shipment->setPacklinkShipmentReference( $shipment_reference );
		$order_shipment->setWoocommerceOrderId( $order_id );

		/** @noinspection PhpUnhandledExceptionInspection */
		$repository = RepositoryRegistry::getRepository( Order_Shipment_Entity::CLASS_NAME );
		$repository->save( $order_shipment );

		$wc_order->update_meta_data( Order_Meta_Keys::SHIPMENT_REFERENCE, $shipment_reference );
		$wc_order->update_meta_data( Order_Meta_Keys::SHIPMENT_STATUS, __( 'Draft created', 'packlink-pro-shipping' ) );
		$wc_order->update_meta_data( Order_Meta_Keys::SHIPMENT_STATUS_TIME, time() );
		$wc_order->save();
	}

	/**
	 * Sets order packlink shipping labels to an order by shipment reference.
	 *
	 * @param string $shipment_reference Packlink shipment reference.
	 * @param string[] $labels Packlink shipping labels.
	 *
	 * @throws \Packlink\BusinessLogic\Order\Exceptions\OrderNotFound When order with provided reference is not found.
	 */
	public function setLabelsByReference( $shipment_reference, array $labels ) {
		$order = $this->load_order_by_reference( $shipment_reference );

		$order->update_meta_data( Order_Meta_Keys::LABELS, $labels );
		$order->save();
	}

	/**
	 * Sets order packlink shipment tracking history to an order by shipment reference.
	 *
	 * @param string $shipment_reference Packlink shipment reference.
	 * @param Tracking[] $tracking_history Shipment tracking history.
	 * @param Shipment_DTO $shipment_details Packlink shipment details.
	 *
	 * @throws \Packlink\BusinessLogic\Order\Exceptions\OrderNotFound When order with provided reference is not found.
	 */
	public function updateTrackingInfo( $shipment_reference, array $tracking_history, Shipment_DTO $shipment_details ) {
		$order = $this->load_order_by_reference( $shipment_reference );

		usort( $tracking_history, function ( Tracking $a, Tracking $b ) {
			return $b->timestamp - $a->timestamp;
		} );

		$tracking = array();
		foreach ( $tracking_history as $item ) {
			$tracking[] = $item->toArray();
		}

		if ( null !== $shipment_details ) {
			$order->update_meta_data( Order_Meta_Keys::SHIPPING_ID, $shipment_details->serviceId );
			$order->update_meta_data( Order_Meta_Keys::SHIPMENT_PRICE, $shipment_details->price );
			$order->update_meta_data( Order_Meta_Keys::CARRIER_TRACKING_URL, $shipment_details->carrierTrackingUrl );
			if ( ! empty( $shipment_details->trackingCodes ) ) {
				$order->update_meta_data( Order_Meta_Keys::CARRIER_TRACKING_CODES, $shipment_details->trackingCodes );
			}
		}

		$order->update_meta_data( Order_Meta_Keys::TRACKING_HISTORY, json_encode( $tracking ) );
		$order->save();
	}

	/**
	 * Sets order packlink shipping status to an order by shipment reference.
	 *
	 * @param string $shipment_reference Packlink shipment reference.
	 * @param string $shipping_status Packlink shipping status.
	 *
	 * @throws \Packlink\BusinessLogic\Order\Exceptions\OrderNotFound When order with provided reference is not found.
	 */
	public function setShippingStatusByReference( $shipment_reference, $shipping_status ) {
		$order = $this->load_order_by_reference( $shipment_reference );

		$order->update_meta_data( Order_Meta_Keys::SHIPMENT_STATUS, $shipping_status );
		$order->update_meta_data( Order_Meta_Keys::SHIPMENT_STATUS_TIME, time() );

		$status_map = $this->configuration->getOrderStatusMappings();
		if ( isset( $status_map[ $shipping_status ] ) ) {
			$order->set_status( $status_map[ $shipping_status ], __( 'Status set by Packlink PRO.', 'packlink-pro-shipping' ) );
		}

		$order->save();
	}

	/**
	 * Fetches and returns order instance.
	 *
	 * @param string $order_id $orderId Unique order id.
	 *
	 * @return WC_Order WooCommerce order object.
	 * @throws \Packlink\BusinessLogic\Order\Exceptions\OrderNotFound When order with provided id is not found.
	 */
	private function load_order_by_id( $order_id ) {
		$wc_order = \WC_Order_Factory::get_order( $order_id );
		if ( false === $wc_order ) {
			throw new OrderNotFound( sprintf( __( 'Order with id(%s) not found!', 'packlink-pro-shipping' ), $order_id ) );
		}

		return $wc_order;
	}

	/**
	 * @noinspection PhpDocMissingThrowsInspection
	 *
	 * Fetches and returns order instance.
	 *
	 * @param string $shipment_reference $orderId Unique order id.
	 *
	 * @return WC_Order WooCommerce order object.
	 * @throws \Packlink\BusinessLogic\Order\Exceptions\OrderNotFound When order with provided id is not found.
	 */
	private function load_order_by_reference( $shipment_reference ) {
		/** @noinspection PhpUnhandledExceptionInspection */
		$repository = RepositoryRegistry::getRepository( Order_Shipment_Entity::CLASS_NAME );

		$query_filter = new QueryFilter();
		/** @noinspection PhpUnhandledExceptionInspection */
		$query_filter->where( 'packlinkShipmentReference', '=', $shipment_reference );
		/** @var Order_Shipment_Entity $order_shipment */
		$order_shipment = $repository->selectOne( $query_filter );

		if ( null === $order_shipment ) {
			throw new OrderNotFound( sprintf( __( 'Order with shipment reference(%s) not found!', 'packlink-pro-shipping' ), $shipment_reference ) );
		}

		return $this->load_order_by_id( $order_shipment->getWoocommerceOrderId() );
	}

	/**
	 * Returns category name.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 *
	 * @return string|null Category name.
	 */
	private function get_product_category_name( \WC_Product $product ) {
		$category_ids = $product->get_category_ids();
		if ( empty( $category_ids ) ) {
			return null;
		}

		$category = WP_Term::get_instance( $category_ids[0] );

		return $category instanceof WP_Term ? $category->name : null;
	}

	/**
	 * Returns array of formatted order items.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Item[] Array of formatted order items.
	 */
	private function get_order_items( WC_Order $wc_order ) {
		$items = array();
		/** @var \WC_Order_Item_Product $wc_item */
		foreach ( $wc_order->get_items() as $wc_item ) {
			$product = $wc_item->get_product();
			if ( $product->is_downloadable() || $product->is_virtual() ) {
				continue;
			}

			$item = new Item();
			$item->setQuantity( $wc_item->get_quantity() );
			$item->setId( $wc_item->get_product_id() );
			$item->setTotalPrice( (float) $wc_item->get_total() );
			$item->setSku( $product->get_sku() );
			$item->setHeight( (float) $product->get_height() );
			$item->setLength( (float) $product->get_length() );
			$item->setWidth( (float) $product->get_width() );
			$item->setWeight( (float) $product->get_weight() );
			$item->setTitle( $product->get_title() );
			$item->setCategoryName( $this->get_product_category_name( $product ) );
			$item->setPrice( $wc_item->get_subtotal() );
			$item->setConcept( $product->get_description() );

			$picture = wp_get_attachment_image_src( $product->get_image_id(), 'single' );
			if ( $picture ) {
				$item->setPictureUrl( $picture[0] );
			}

			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Builds Shipping object for provided order.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Shipping Shipping.
	 */
	private function get_order_shipping( WC_Order $wc_order ) {
		$shipping_method = Order_Details_Helper::get_packlink_shipping_method( $wc_order );
		if ( null === $shipping_method ) {
			return null;
		}

		$shipping = new Shipping();
		$shipping->setId( $shipping_method->getId() );
		$shipping->setCarrierName( $shipping_method->getCarrierName() );
		$shipping->setName( $shipping_method->getTitle() );
		$shipping->setShippingServiceName( $shipping_method->getServiceName() );
		$shipping->setShippingServiceId( $shipping_method->getServiceId() );

		return $shipping;
	}

	/**
	 * Builds Shipment object for provided order.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Shipment Shipment.
	 */
	private function get_order_shipment( WC_Order $wc_order ) {
		$shipment = new Shipment();
		$shipment->setReferenceNumber( $wc_order->get_meta( Order_Meta_Keys::SHIPMENT_REFERENCE ) );
		$shipment->setTrackingNumber( $wc_order->get_meta( Order_Meta_Keys::SHIPMENT_REFERENCE ) );
		$shipment->setStatus( $wc_order->get_meta( Order_Meta_Keys::SHIPMENT_STATUS ) );

		$tracking_json    = $wc_order->get_meta( Order_Meta_Keys::TRACKING_HISTORY );
		$tracking_history = array();
		if ( $tracking_json ) {
			$entries = json_decode( $tracking_json, true );
			if ( is_array( $entries ) ) {
				foreach ( $entries as $item ) {
					$tracking = new TrackingHistory();
					$tracking->setDescription( $item['description'] );
					$tracking->setCity( $item['city'] );
					$tracking->setTimestamp( $item['timestamp'] );
					$tracking_history[] = $tracking;
				}
			}
		}

		$shipment->setTrackingHistory( $tracking_history );

		return $shipment;
	}

	/**
	 * Returns billing address.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Address Billing address.
	 */
	private function get_billing_address( WC_Order $wc_order ) {
		$address = new Address();
		if ( $wc_order->has_billing_address() ) {
			$address->setEmail( $wc_order->get_billing_email() );
			$address->setPhone( $wc_order->get_billing_phone() );
			$address->setName( $wc_order->get_billing_first_name() );
			$address->setSurname( $wc_order->get_billing_last_name() );
			$address->setCompany( $wc_order->get_billing_company() );
			$address->setCity( $wc_order->get_billing_city() );
			$address->setStreet1( $wc_order->get_billing_address_1() );
			$address->setStreet2( $wc_order->get_billing_address_2() );
			$address->setCountry( $wc_order->get_billing_country() );
			$address->setZipCode( $wc_order->get_billing_postcode() );
		}

		return $address;
	}

	/**
	 * Returns shipping address.
	 *
	 * @param WC_Order $wc_order WooCommerce order.
	 *
	 * @return Address Shipping address.
	 */
	private function get_shipping_address( WC_Order $wc_order ) {
		$address = new Address();
		if ( $wc_order->has_shipping_address() ) {
			$address->setEmail( $wc_order->get_billing_email() );
			$address->setPhone( $wc_order->get_billing_phone() );
			$address->setName( $wc_order->get_shipping_first_name() );
			$address->setSurname( $wc_order->get_shipping_last_name() );
			$address->setCompany( $wc_order->get_shipping_company() );
			$address->setCity( $wc_order->get_shipping_city() );
			$address->setStreet1( $wc_order->get_shipping_address_1() );
			$address->setStreet2( $wc_order->get_shipping_address_2() );
			$address->setCountry( $wc_order->get_shipping_country() );
			$address->setZipCode( $wc_order->get_shipping_postcode() );
		} else {
			$address = $this->get_billing_address( $wc_order );
		}

		return $address;
	}

	/**
	 * Returns drop-off address.
	 *
	 * @param WC_Order $order WooCommerce order.
	 *
	 * @return null|Address Drop-off address.
	 */
	private function get_drop_off_address( WC_Order $order ) {
		$address      = new Address();
		$json_address = $order->get_meta( Order_Meta_Keys::DROP_OFF_EXTRA );
		$raw_address  = json_decode( stripslashes( $json_address ), true );
		if ( empty( $json_address ) || ! is_array( $raw_address ) ) {
			return null;
		}

		$address->setCountry( $raw_address['countryCode'] );
		$address->setStreet1( $raw_address['address'] );
		$address->setZipCode( $raw_address['zip'] );
		$address->setCity( $raw_address['city'] );
		$address->setName( $raw_address['name'] );
		$address->setPhone( $raw_address['phone'] );

		return $address;
	}
}
