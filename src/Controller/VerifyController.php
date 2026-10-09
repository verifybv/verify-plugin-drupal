<?php

namespace Drupal\verify_dam\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\verify_dam\AssetImporter;
use Drupal\verify_dam\VerifyApiException;
use Drupal\verify_dam\VerifyClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Asset library page and its JSON feed.
 */
class VerifyController extends ControllerBase {

  public function __construct(
    protected VerifyClient $client,
    protected AssetImporter $importer,
    protected ModuleExtensionList $moduleList,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('verify_dam.client'),
      $container->get('verify_dam.importer'),
      $container->get('extension.list.module'),
    );
  }

  /**
   * The asset library page (/verify).
   */
  public function library(): array|RedirectResponse {
    if ($this->client->getApiKey() === NULL) {
      return $this->redirect('verify_dam.connect', [], ['query' => ['destination' => Url::fromRoute('verify_dam.library')->toString()]]);
    }

    $interval = max(5, (int) $this->config('verify_dam.settings')->get('poll_interval') ?: 15);

    $importUrl = Url::fromRoute('verify_dam.import', ['asset_id' => 'ASSET_ID'])->toString();

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['verify-library']],
      'toolbar' => [
        '#type' => 'inline_template',
        '#template' => '<div class="verify-library__toolbar">
  <img class="verify-library__logo" src="{{ logo_url }}" alt="Verify" width="128" height="56">
  <form class="verify-library__search-form" role="search">
    <input type="search" class="verify-library__search" placeholder="{{ placeholder }}" aria-label="{{ label }}" autocomplete="off">
    <button type="submit" class="verify-library__btn verify-library__btn--primary verify-library__btn--icon" aria-label="{{ search }}" title="{{ search }}">
      <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="16.65" y1="16.65" x2="21" y2="21"/></svg>
    </button>
  </form>
  <span class="verify-library__status" aria-live="polite">{{ loading }}</span>
  <a href="{{ settings_url }}" class="verify-library__btn verify-library__settings" title="{{ settings_title }}">
    <svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    <span>{{ settings }}</span>
  </a>
</div>',
        '#context' => [
          'placeholder' => $this->t('Search assets…'),
          'label' => $this->t('Search assets'),
          'search' => $this->t('Search'),
          'loading' => $this->t('Loading…'),
          'settings' => $this->t('Settings'),
          'settings_title' => $this->t('Verify API key and connection'),
          'settings_url' => Url::fromRoute('verify_dam.connect')->toString(),
          'logo_url' => Url::fromUri('base:' . $this->moduleList->getPath('verify_dam') . '/media/logo_small.png')->toString(),
        ],
      ],
      'grid' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['verify-library__grid'], 'aria-live' => 'polite'],
        '#value' => '',
      ],
      '#attached' => [
        // Inlined so it applies immediately, independent of cached libraries.
        'html_head' => [[
          ['#tag' => 'style', '#value' => Markup::create((string) @file_get_contents(dirname(__DIR__, 2) . '/css/verify-page.css'))],
          'verify_dam_page_css',
        ]],
        'library' => ['verify_dam/library'],
        'drupalSettings' => [
          'verifyDam' => [
            'endpoint' => Url::fromRoute('verify_dam.assets')->toString(),
            'connectUrl' => Url::fromRoute('verify_dam.connect')->toString(),
            'pollInterval' => $interval * 1000,
            'importUrl' => $importUrl,
            'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
            'canImport' => $this->currentUser()->hasPermission('import verify assets'),
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * JSON feed used by the library page (/verify/assets?q=…).
   */
  public function assets(Request $request): JsonResponse {
    $query = mb_substr(trim((string) $request->query->get('q', '')), 0, 200);

    try {
      $assets = $this->client->search($query);
      $imported = array_flip($this->importer->importedIds());
      foreach ($assets as &$asset) {
        $asset['imported'] = isset($imported[$asset['id']]);
      }
      unset($asset);
      $response = new JsonResponse(['assets' => $assets, 'query' => $query]);
    }
    catch (VerifyApiException $e) {
      $status = $e->isAuthError() ? 401 : 502;
      $response = new JsonResponse(['error' => $e->getMessage(), 'auth' => $e->isAuthError()], $status);
    }

    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Copies an asset into Drupal (POST /verify/import/{asset_id}).
   */
  public function import(string $asset_id): JsonResponse {
    try {
      $result = $this->importer->import($asset_id);
      $response = new JsonResponse($result);
    }
    catch (VerifyApiException $e) {
      $status = $e->isAuthError() ? 401 : 502;
      $response = new JsonResponse(['error' => $e->getMessage(), 'auth' => $e->isAuthError()], $status);
    }
    catch (\Throwable $e) {
      $this->getLogger('verify_dam')->error('Import of @id failed: @msg', ['@id' => $asset_id, '@msg' => $e->getMessage()]);
      $response = new JsonResponse(['error' => (string) $this->t('Could not save the file in Drupal. See Reports → Recent log messages.')], 500);
    }
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
