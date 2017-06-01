<?php

namespace Drupal\commerce_auspost\Plugin\Commerce\ShippingMethod;

use Drupal\commerce_auspost\Address;
use Drupal\commerce_auspost\ConfigurationException;
use Drupal\commerce_auspost\Forms\ConfigureForm;
use Drupal\commerce_auspost\Packer\ShipmentPacking\ShipmentPackerException;
use Drupal\commerce_auspost\PostageAssessment\ClientInterface;
use Drupal\commerce_auspost\PostageAssessment\RequestInterface;
use Drupal\commerce_auspost\PostageAssessment\ResponseException;
use Drupal\commerce_auspost\PostageAssessment\ResponseInterface;
use Drupal\commerce_auspost\PostageServices\ServiceSupport;
use Drupal\commerce_price\Price;
use Drupal\commerce_price\RounderInterface;
use Drupal\commerce_shipping\Entity\ShipmentInterface;
use Drupal\commerce_shipping\PackageTypeManagerInterface;
use Drupal\commerce_shipping\Plugin\Commerce\ShippingMethod\ShippingMethodBase;
use Drupal\commerce_shipping\ShippingRate;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Utility\Error;
use DVDoug\BoxPacker\ItemTooLargeException;
use Exception;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Australia Post shipping method.
 *
 * @see \Drupal\commerce_auspost\PostageServices\ServiceDefinitions
 *   For further information on supported services.
 *
 * @CommerceShippingMethod(
 *   id = "auspost",
 *   label = @Translation("Australia Post"),
 *   services = {
 *     "AUS_SERVICE_OPTION_STANDARD" = @Translation("Australia Post - Standard Post"),
 *     "AUS_PARCEL_COURIER" = @Translation("Australia Post - Courier Post"),
 *     "AUS_PARCEL_EXPRESS" = @Translation("Australia Post - Express Post"),
 *     "INT_PARCEL_AIR_OWN_PACKAGING" = @Translation("Australia Post - International Economy Air"),
 *     "INT_PARCEL_AIR_OWN_PACK_SIG" = @Translation("Australia Post - International Economy Air (Signature required)"),
 *     "INT_PARCEL_AIR_OWN_PACK_INS" = @Translation("Australia Post - International Economy Air (Insured)"),
 *   }
 * )
 */
class AusPost extends ShippingMethodBase {

  // Currency code.
  const AUD_CURRENCY_CODE = 'AUD';

  /**
   * Service container.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  private $container;

  /**
   * Watchdog logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $watchdog;

  /**
   * The price rounder.
   *
   * @var \Drupal\commerce_price\RounderInterface
   */
  private $rounder;

  /**
   * The configuration form.
   *
   * @var \Drupal\commerce_auspost\Forms\ConfigureForm
   */
  private $configurationForm;

  /**
   * Service support helpers
   *
   * @var \Drupal\commerce_auspost\PostageServices\ServiceSupport
   */
  private $serviceSupport;

  /**
   * AusPost client.
   *
   * @var \Drupal\commerce_auspost\PostageAssessment\ClientInterface
   */
  private $client;

  /**
   * AusPost constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $pluginId
   *   The plugin_id for the plugin instance.
   * @param mixed $pluginDefinition
   *   The plugin implementation definition.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   * @param \Drupal\commerce_shipping\PackageTypeManagerInterface $packageTypeManager
   *   The package type manager.
   * @param \Psr\Log\LoggerInterface $watchdog
   *   Watchdog logger.
   * @param \Drupal\commerce_price\RounderInterface $rounder
   *   The price rounder.
   * @param \Drupal\commerce_auspost\Forms\ConfigureForm $configurationForm
   *   The configuration form.
   * @param \Drupal\commerce_auspost\PostageServices\ServiceSupport $serviceSupport
   *   AusPost PAC supported services.
   * @param \Drupal\commerce_auspost\PostageAssessment\ClientInterface $client
   *   AusPost PAC client.
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition,
    ContainerInterface $container,
    PackageTypeManagerInterface $packageTypeManager,
    LoggerInterface $watchdog,
    RounderInterface $rounder,
    ConfigureForm $configurationForm,
    ServiceSupport $serviceSupport,
    ClientInterface $client
  ) {
    $this->container = $container;
    $this->watchdog = $watchdog;
    $this->rounder = $rounder;
    $this->serviceSupport = $serviceSupport;
    $this->client = $client;

    $configurationForm->setShippingInstance($this)
      ->setServiceSupport($serviceSupport);
    $this->configurationForm = $configurationForm;

    parent::__construct(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $packageTypeManager
    );
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   */
  public static function create(
    ContainerInterface $container,
    array $configuration,
    $pluginId,
    $pluginDefinition
  ) {
    /** @noinspection PhpParamsInspection */
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container,
      $container->get('plugin.manager.commerce_package_type'),
      $container->get('logger.channel.commerce_auspost'),
      $container->get('commerce_price.rounder'),
      new ConfigureForm(),
      $container->get('commerce_auspost.postage_services.service_support'),
      $container->get('commerce_auspost.postage_assessment.client')
    );
  }

  /**
   * Logs requests and responses from AusPost.
   *
   * @param string $message
   *   The message to log.
   * @param mixed $data
   *   The AusPost request or response object.
   * @param string $level
   *   The log level.
   */
  private function logApi($message, $data, $level = LogLevel::INFO) {
    $doLog = FALSE;
    $config = $this->configuration;
    if ($data instanceof RequestInterface &&
        array_key_exists('request', $config['options']['log'])) {
      $doLog = TRUE;
    } elseif ($data instanceof ResponseInterface &&
              array_key_exists('response', $config['options']['log'])) {
      $doLog = TRUE;
    }

    if ($doLog) {
      $this->watchdog->log($level, "$message <br>@details", [
        '@details' => json_encode($data),
      ]);
    }
  }

  /**
   * Log an exception to watchdog.
   *
   * @param \Exception $exception
   *   Exception to log.
   * @param string $message
   *   Additional information.
   */
  private function logException(Exception $exception, $message = '') {
    $variables = Error::decodeException($exception);

    $defaultMessage = '%type: @message in %function (line %line of %file).';
    if (!empty($message)) {
      $message = "{$defaultMessage} {$message}";
    } else {
      $message = $defaultMessage;
    }

    $this->watchdog->error($message, $variables);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\commerce_auspost\ConfigurationException
   * @throws \Drupal\commerce_auspost\PostageAssessment\ClientException
   * @throws \Drupal\commerce_auspost\PostageAssessment\RequestException
   * @throws \Drupal\commerce_auspost\PostageServices\ServiceNotFoundException
   * @throws \Drupal\commerce_auspost\Packer\ShipmentPacking\ShipmentPackerException
   * @throws \Drupal\commerce_auspost\PostageServices\ServiceSupportException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   */
  public function calculateRates(ShipmentInterface $shipment) {
    if (!$this->configurationForm->isConfigured()) {
      throw new ConfigurationException(
        'The commerce_auspost shipping method is not configured.'
      );
    }

    $address = new Address($shipment);
    if ($address->isEmpty()) {
      return [];
    }

    $this->client->setApiKey(
      $this->configuration['api_information']['api_key']
    );

    // @TODO: Allow altering of rate labels.
    $serviceDefinitions = $this->serviceSupport->getServicesByKeys(
      array_keys($this->services)
    );

    // Calculate postage for all services.
    $rates = [];
    foreach ($serviceDefinitions as $definitionKey => $definition) {
      $packageTypes = $this->getEnabledPackageTypes(
        $definition['destination']
      );
      $postagePrice = new Price(0, static::AUD_CURRENCY_CODE);

      try {
        $packedBoxes = $this->getPackedBoxes(
          $packageTypes,
          $shipment->getOrder()->getItems(),
          $definition
        );
      } catch (ItemTooLargeException $e) {
        $this->logException($e, 'No package type large enough could be found.');
        continue;
      }

      foreach ($packedBoxes as $packedBox) {
        try {
          $request = $this->container->get('commerce_auspost.postage_assessment.request')
            ->setAddress($address)
            ->setShipment($shipment)
            ->setPackedBox($packedBox)
            ->setPackageType($definition['type'])
            ->setServiceDefinition($definition);
          // Log request if enabled.
          $this->logApi('Sending AusPost PAC API request', $request);

          $response = $this->client->calculatePostage($request);

          // Log response as well.
          $this->logApi('Received AusPost PAC API response', $response);

          $postage = (string) $response->getPostage();
        } catch (ClientErrorResponseException $e) {
          $this->logException($e, 'Error fetching rates from AusPost.');
          // Skip this service.
          continue 2;
        } catch (ResponseException $e) {
          $this->logException($e, 'Error fetching rates from AusPost.');
          // Skip this service.
          continue 2;
        }

        // Apply any modifiers to the postage cost if necessary.
        $postagePrice = $postagePrice->add(
          $this->getModifiedPostageCost(
            new Price($postage, static::AUD_CURRENCY_CODE)
          )
        );
      }

      $rates[] = new ShippingRate(
        $definitionKey,
        $this->services[$definitionKey],
        $postagePrice
      );
    }

    return $rates;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $defaults = [
      'enabled_package_types' => [],
      'api_information' => [
        'api_key' => '',
      ],
      'options' => [
        'insurance' => FALSE,
        'rate_multiplier' => 1.0,
        'round' => PHP_ROUND_HALF_UP,
        'log' => [],
      ],
    ];

    foreach ($this->serviceSupport->supportedDestinations() as $dest) {
      $defaults['enabled_package_types'][$dest] = [];
    }

    return $defaults + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\commerce_auspost\ConfigurationException
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    return $this->configurationForm->buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configurationForm->submitForm($form, $form_state);
    parent::submitConfigurationForm($form, $form_state);
  }

  /**
   * Get all possible package types for this shipping method.
   *
   * This includes disabled package types.
   *
   * @param string $dest
   *   An (optional) package destination (one of 'domestic' or 'international)
   *   to filter by. If not specified, all package types are returned.
   *
   * @return array
   *   A list of package type definitions.
   *
   * @throws \Drupal\commerce_auspost\ConfigurationException
   */
  public function getPossiblePackageTypes($dest = NULL) {
    $types = $this->packageTypeManager->getDefinitionsByShippingMethod(
      $this->getPluginId()
    );
    if ($dest === NULL) {
      return $types;
    }

    if (!$this->serviceSupport->validateDestination($dest)) {
      throw new ConfigurationException("Unknown package destination '{$dest}'.");
    }

    // Filter out any destination-specific packages.
    return array_filter($types, function($typeKey) use ($dest) {
      // Ignore any custom types.
      if (strpos($typeKey, 'commerce_auspost') !== 0) {
        return TRUE;
      }

      // Strip out the prefix then check if the destination is valid for this
      // package type.
      // @see commerce_auspost.commerce_package_types.yml for all our package
      // types.
      $prefix = 'commerce_auspost:';
      $typeKey = substr($typeKey, strlen($prefix));
      return strpos($typeKey, $dest) === 0;
    }, ARRAY_FILTER_USE_KEY);
  }

  /**
   * Get all enabled package types for this shipping method.
   *
   * @param string $dest
   *   An (optional) package destination (one of 'domestic' or 'international)
   *   to filter by. If not specified, all enabled package types are returned.
   *
   * @return array
   *   A list of enabled package type definitions.
   *
   * @throws \Drupal\commerce_auspost\ConfigurationException
   */
  public function getEnabledPackageTypes($dest = NULL) {
    // Assume all types are fair game unless told otherwise.
    $types = $this->getPossiblePackageTypes($dest);

    if ($dest !== NULL && !empty($this->configuration['enabled_package_types'][$dest])) {
      $types = array_intersect_key(
        $types,
        array_filter($this->configuration['enabled_package_types'][$dest])
      );
    }

    return $types;
  }

  /**
   * Packs order items into boxes and returns the packed boxes.
   *
   * @param array[] $packageTypes
   *   A list package type to use for packing.
   * @param \Drupal\commerce_order\Entity\OrderItemInterface[] $orderItems
   *   A list of purchased order items.
   * @param array $service
   *   Shipping service definition.
   *
   * @return \Drupal\commerce_auspost\Packer\ShipmentPacking\PackedBox[]
   *   A list of packed boxes.
   *
   * @throws \Drupal\commerce_auspost\PostageServices\ServiceSupportException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException
   * @throws \Symfony\Component\DependencyInjection\Exception\ServiceCircularReferenceException
   */
  private function getPackedBoxes(
    array $packageTypes,
    array $orderItems,
    array $service
  ) {
    $packer = $this->container->get('commerce_auspost.shipment_packer');

    // Get rates for each packed box.
    foreach ($packageTypes as $packageType) {
      try {
        $packer->addPackageType($packageType, $service['destination']);
      } catch (ShipmentPackerException $e) {
        $this->logException($e, 'Invalid package type skipped.');
        // Ignore invalid packages.
        continue;
      }
    }

    $packer->addOrderItems($orderItems);

    return $packer->pack();
  }

  /**
   * Applies any multipliers or roundings to the raw postage cost.
   *
   * @param \Drupal\commerce_price\Price $postage
   *   Raw postage cost.
   *
   * @see \Drupal\commerce_price\RounderInterface::round()
   * @see \Drupal\commerce_price\Price::multiply()
   *
   * @return \Drupal\commerce_price\Price
   *   Tweaked postage price.
   *
   * @throws \InvalidArgumentException
   */
  private function getModifiedPostageCost(Price $postage) {
    // Get rounding and multiplier from configuration.
    $multiplier = 1.0;
    if (!empty($this->configuration['options']['rate_multiplier'])) {
      $multiplier = (float) $this->configuration['options']['rate_multiplier'];
    }
    $rounding = PHP_ROUND_HALF_UP;
    if (!empty($this->configuration['options']['round'])) {
      $rounding = (int) $this->configuration['options']['round'];
    }

    if ($multiplier > 1) {
      $postage = $postage->multiply((string) $multiplier);
    }

    $postage = $this->rounder->round($postage, $rounding);

    return $postage;
  }

}
