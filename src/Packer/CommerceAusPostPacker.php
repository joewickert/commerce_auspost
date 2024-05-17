<?php

namespace Drupal\commerce_auspost\Packer;

use Drupal\commerce_auspost\Event\CommerceAuspostEvents;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_shipping\ProposedShipment;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\commerce_shipping\Packer\DefaultPacker;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\physical\Weight;
use Drupal\physical\WeightUnit;
use Drupal\profile\Entity\ProfileInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Class CommerceAusPostPacker.
 *
 * Based off \Drupal\commerce_fedex\Packer\CommerceFedExPacker.
 *
 * @package Drupal\commerce_auspost\Packer
 */
class CommerceAusPostPacker extends DefaultPacker {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \InvalidArgumentException
   */
  public function pack(OrderInterface $order, ProfileInterface $shipping_profile) {
    $shipments = [
      [
        'title' => $this->t('Primary Shipment'),
        'items' => [],
      ],
    ];

    foreach ($order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();

      // Ship only shippable purchasable entity types.
      if (!$purchased_entity || !$purchased_entity->hasField('weight')) {
        continue;
      }

      $quantity = $order_item->getQuantity();

      $shipments[0]['items'][] = new ShipmentItem([
        'order_item_id' => $order_item->id(),
        'title' => $order_item->getTitle(),
        'quantity' => $quantity,
        'weight' => $this->getWeight($order_item)->multiply($quantity),
        'declared_value' => $order_item->getUnitPrice()->multiply($quantity),
      ]);
    }

    $proposed_shipments = [];

    foreach ($shipments as $shipment) {
      if (!empty($shipment['items'])) {
        $proposed_shipments[] = new ProposedShipment([
          'type' => $this->getShipmentType($order),
          'order_id' => $order->id(),
          'title' => $shipment['title'],
          'items' => $shipment['items'],
          'shipping_profile' => $shipping_profile,
        ]);
      }
    }

    return $proposed_shipments;
  }

  /**
   * Gets the weight of the order item.
   *
   * The weight will be empty if the shippable trait was added but the existing
   * entities were not updated.
   *
   * @param \Drupal\commerce_order\Entity\OrderItemInterface $orderItem
   *   The order item.
   *
   * @return \Drupal\physical\Weight
   *   The order item's weight.
   *
   * @throws \InvalidArgumentException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  private function getWeight(OrderItemInterface $order_item) {
    $purchasedEntity = $order_item->getPurchasedEntity();

    if ($purchasedEntity->get('weight')->isEmpty()) {
      $weight = new Weight(0, WeightUnit::KILOGRAM);
    }
    else {
      /** @var \Drupal\physical\Plugin\Field\FieldType\MeasurementItem $weightItem */
      $weightItem = $purchasedEntity->get('weight')->first();
      $weight = $weightItem->toMeasurement();
    }

    return $weight;
  }

}
