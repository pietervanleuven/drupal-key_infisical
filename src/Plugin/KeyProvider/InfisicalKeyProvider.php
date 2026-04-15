<?php

namespace Drupal\infisical\Plugin\KeyProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\infisical\InfisicalClient;
use Drupal\key\KeyInterface;
use Drupal\key\Plugin\KeyPluginFormInterface;
use Drupal\key\Plugin\KeyProviderBase;
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
   */
  protected InfisicalClient $infisicalClient;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, InfisicalClient $infisicalClient) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->infisicalClient = $infisicalClient;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('infisical.client'),
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
      '#type' => 'textfield',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->t('The Universal Auth machine identity client secret.'),
      '#default_value' => $config['client_secret'],
      '#required' => TRUE,
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
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * {@inheritdoc}
   */
  public function getKeyValue(KeyInterface $key) {
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

}
