<?php

namespace Drupal\verify_dam\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\verify_dam\VerifyApiException;
use Drupal\verify_dam\VerifyClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lets the current user connect (or disconnect) their Verify API key.
 */
class ConnectForm extends FormBase {

  public function __construct(protected VerifyClient $client) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('verify_dam.client'));
  }

  public function getFormId(): string {
    return 'verify_dam_connect_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $key = $this->client->getApiKey();
    // Inline the page's small stylesheet so it applies immediately, without
    // depending on Drupal's (cached) library definitions.
    $css = @file_get_contents(dirname(__DIR__, 2) . '/css/verify-connect.css');
    if ($css !== FALSE) {
      $form['#attached']['html_head'][] = [
        ['#tag' => 'style', '#value' => Markup::create($css)],
        'verify_dam_connect_css',
      ];
    }

    if ($key !== NULL) {
      $form['status'] = [
        '#type' => 'inline_template',
        '#template' => '<p class="verify-connect__intro">{{ intro }}</p>
<div class="verify-key" role="img" aria-label="{{ label }}">
  <span class="verify-key__hidden" aria-hidden="true">{{ filler }}</span><span class="verify-key__visible" aria-hidden="true">{{ suffix }}</span>
</div>',
        '#context' => [
          'intro' => $this->t('You are connected to Verify with the key ending in:'),
          'label' => $this->t('API key ending in @suffix', ['@suffix' => substr($key, -4)]),
          // Random filler, not the real key: blurring only hides text
          // visually, so the real characters must never reach the page.
          'filler' => $this->filler(max(8, strlen($key) - 4)),
          'suffix' => substr($key, -4),
        ],
      ];
      $form['actions'] = ['#type' => 'actions'];
      $form['actions']['library'] = [
        '#type' => 'link',
        '#title' => $this->t('Open asset library'),
        '#url' => Url::fromRoute('verify_dam.library'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
      $form['actions']['disconnect'] = [
        '#type' => 'submit',
        '#value' => $this->t('Disconnect'),
        '#submit' => ['::disconnect'],
        '#limit_validation_errors' => [],
        '#button_type' => 'danger',
      ];
      return $form;
    }

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Enter your Verify API key once. It is stored on your Drupal account so you stay connected.') . '</p>',
    ];
    $form['api_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Verify API key'),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'off'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Connect'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $key = trim((string) $form_state->getValue('api_key'));
    if ($key === '') {
      return;
    }
    try {
      $this->client->validateKey($key);
    }
    catch (VerifyApiException $e) {
      $form_state->setErrorByName('api_key', $e->getMessage());
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->client->setApiKey((string) $form_state->getValue('api_key'));
    $this->messenger()->addStatus($this->t('Connected to Verify.'));
    $form_state->setRedirect('verify_dam.library');
  }

  /**
   * Submit handler for the Disconnect button.
   */
  public function disconnect(array &$form, FormStateInterface $form_state): void {
    $this->client->clearApiKey();
    $this->messenger()->addStatus($this->t('Your Verify API key has been removed.'));
    $form_state->setRedirect('verify_dam.connect');
  }

  /**
   * Random key-like characters for the blurred part of the key bar.
   */
  protected function filler(int $length): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
      $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
  }

}
