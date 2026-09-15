<?php

namespace Drupal\key_infisical\Plugin\KeyProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyProviderBase;
use Drupal\key_infisical\InfisicalClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retrieves keys from Infisical secrets manager.
 *
 * @KeyProvider(
 *   id = "infisical",
 *   label = @Translation("Infisical"),
 *   description = @Translation("Retrieves secrets from an Infisical instance using Universal Auth."),
 *   tags = {
 *     "remote",
 *   },
 *   key_value = {
 *     "accepted" = FALSE,
 *     "required" = FALSE
 *   }
 * )
 */
class InfisicalKeyProvider extends KeyProviderBase implements KeyPluginFormInterface {

  /**
   * The Infisical client.
   *
   * @var \Drupal\key_infisical\InfisicalClient
   */
  protected InfisicalClient $infisicalClient;

  /**
   * The logger channel for this module.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, InfisicalClient $infisicalClient, LoggerChannelFactoryInterface $loggerFactory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->infisicalClient = $infisicalClient;
    $this->logger = $loggerFactory->get('key_infisical');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('key_infisical.client'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'base_url' => 'https://app.infisical.com',
      'client_id' => '',
      'client_secret' => '',
      'project_id' => '',
      'environment' => 'prod',
      'secret_path' => '/',
      'secret_name' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();

    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Infisical URL'),
      '#description' => $this->t('The base URL of your Infisical instance.'),
      '#default_value' => $config['base_url'],
      '#required' => TRUE,
    ];

    $form['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#description' => $this->t('The Universal Auth machine identity client ID.'),
      '#default_value' => $config['client_id'],
      '#required' => TRUE,
    ];

    $form['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->t('The Universal Auth machine identity client secret. This value is stored in Drupal configuration. Leave this field blank to keep the currently stored secret. The secret can be overridden per-environment in settings.php.'),
      '#required' => empty($config['client_secret']),
    ];

    $form['project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project ID'),
      '#description' => $this->t('The Infisical project (workspace) ID.'),
      '#default_value' => $config['project_id'],
      '#required' => TRUE,
    ];

    $form['environment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Environment'),
      '#description' => $this->t('The environment slug (e.g. dev, staging, prod).'),
      '#default_value' => $config['environment'],
      '#required' => TRUE,
    ];

    $form['secret_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret Path'),
      '#description' => $this->t('The folder path for the secret (e.g. / for root).'),
      '#default_value' => $config['secret_path'],
      '#required' => TRUE,
    ];

    $form['secret_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secret Name'),
      '#description' => $this->t('The name of the secret in Infisical.'),
      '#default_value' => $config['secret_name'],
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Strip trailing slash from base URL.
    $baseUrl = rtrim($form_state->getValue('base_url'), '/');
    $form_state->setValue('base_url', $baseUrl);

    // If the client secret was left blank, restore the previously stored
    // value. This must happen here (rather than only in
    // submitConfigurationForm()) because KeyFormBase::validateForm()
    // performs a live fetch of the key value immediately after calling this
    // method, using the values from this form state. Leaving the secret
    // empty at that point would cause the live fetch to fail on every
    // subsequent edit of the key.
    if (empty($form_state->getValue('client_secret'))) {
      $form_state->setValue('client_secret', $this->getConfiguration()['client_secret']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   *
   * Never throws: a Key's value is read in many contexts, including while
   * rendering a form that merely references the key, so an exception here
   * would surface as a fatal error rather than a missing secret. Any
   * failure, anticipated or not, degrades to NULL instead.
   */
  public function getKeyValue(KeyInterface $key) {
    try {
      $config = $this->getConfiguration();

      return $this->infisicalClient->getSecret(
        $config['base_url'],
        $config['client_id'],
        $config['client_secret'],
        $config['project_id'],
        $config['environment'],
        $config['secret_path'],
        $config['secret_name'],
      );
    }
    catch (\Throwable $e) {
      $this->logger->error('Unexpected error resolving Infisical secret for key "@key": @message', [
        '@key' => $key->id(),
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
