<?php

namespace Drupal\verify_dam\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Site-wide settings for the Verify DAM module.
 */
class SettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'verify_dam_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['verify_dam.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('verify_dam.settings');

    $form['base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Verify API base URL'),
      '#default_value' => $config->get('base_url'),
      '#required' => TRUE,
    ];
    $form['poll_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Live refresh interval'),
      '#description' => $this->t('How often the library checks Verify for new assets.'),
      '#field_suffix' => $this->t('seconds'),
      '#min' => 5,
      '#default_value' => $config->get('poll_interval'),
      '#required' => TRUE,
    ];
    $form['max_items'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum assets shown'),
      '#min' => 1,
      '#max' => 2000,
      '#default_value' => $config->get('max_items'),
      '#required' => TRUE,
    ];

    $form['verified_only'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only show verified assets'),
      '#description' => $this->t('Hide assets whose Verify status is not VERIFIED (e.g. uploaded, awaiting approval or declined).'),
      '#default_value' => $config->get('verified_only') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('verify_dam.settings')
      ->set('base_url', rtrim((string) $form_state->getValue('base_url'), '/'))
      ->set('poll_interval', (int) $form_state->getValue('poll_interval'))
      ->set('max_items', (int) $form_state->getValue('max_items'))
      ->set('verified_only', (bool) $form_state->getValue('verified_only'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
