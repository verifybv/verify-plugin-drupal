<?php

namespace Drupal\verify_dam;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserDataInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Thin client for the Verify Cloud API.
 *
 * API keys are stored per Drupal user (user.data), so each editor connects
 * once and the module remembers the key on every later visit.
 */
class VerifyClient {

  /**
   * The user.data module/key under which the API key is stored.
   */
  protected const DATA_MODULE = 'verify_dam';
  protected const DATA_KEY = 'api_key';

  /**
   * Used when verify_dam.settings has no base_url (e.g. module enabled before
   * the settings existed).
   */
  /**
   * Type filters: key => MIME type prefix.
   */
  public const TYPES = [
    'image' => 'image/',
    'video' => 'video/',
    'audio' => 'audio/',
    'pdf' => 'application/pdf',
  ];

  public const DEFAULT_BASE_URL = 'https://verify-api-gateway-9jozroz9.ew.gateway.dev';

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected UserDataInterface $userData,
    protected AccountProxyInterface $currentUser,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Returns the stored API key for a user (defaults to the current user).
   */
  public function getApiKey(?int $uid = NULL): ?string {
    $uid = $uid ?? (int) $this->currentUser->id();
    if ($uid === 0) {
      return NULL;
    }
    $key = $this->userData->get(self::DATA_MODULE, $uid, self::DATA_KEY);
    return is_string($key) && $key !== '' ? $key : NULL;
  }

  /**
   * Stores an API key for a user (defaults to the current user).
   */
  public function setApiKey(string $key, ?int $uid = NULL): void {
    $uid = $uid ?? (int) $this->currentUser->id();
    $this->userData->set(self::DATA_MODULE, $uid, self::DATA_KEY, trim($key));
  }

  /**
   * Removes the stored API key for a user (defaults to the current user).
   */
  public function clearApiKey(?int $uid = NULL): void {
    $uid = $uid ?? (int) $this->currentUser->id();
    $this->userData->delete(self::DATA_MODULE, $uid, self::DATA_KEY);
  }

  /**
   * Checks an API key against the API. Throws on failure.
   */
  public function validateKey(string $key): void {
    $this->postSearch(['value' => '', 'size' => 1], trim($key));
  }

  /**
   * Returns assets for the library view or a search.
   *
   * - Empty query: the newest assets first (one page).
   * - Otherwise: file-name matches first ("TMR62" finds 24-08-15_TMR6200.jpg),
   *   falling back to Verify's full search (AI/visual, description, metadata)
   *   when no file name matches.
   *
   * @return array[]
   *   Normalized assets.
   *
   * @throws \Drupal\verify_dam\VerifyApiException
   */
  public function search(string $query, ?string $type = NULL, ?string $apiKey = NULL): array {
    $apiKey = $apiKey ?? $this->getApiKey();
    if ($apiKey === NULL) {
      throw new VerifyApiException('No Verify API key connected.', 401);
    }

    $query = trim($query);
    $type = isset(self::TYPES[$type]) ? $type : NULL;
    $limit = $this->pageSize();
    // The API can't filter by type, so fetch the largest page when a type is
    // selected and filter here.
    $size = $type ? 100 : $limit;

    if ($query === '') {
      $hits = $this->postSearch([
        'value' => '',
        'orderBy' => ['field' => 'creationDate', 'direction' => 'desc'],
        'size' => $size,
      ], $apiKey);
      return $this->visible($this->normalizeAll($hits), $type, $limit);
    }

    $hits = $this->postSearch(['value' => $query, 'searchFields' => ['name'], 'size' => $size], $apiKey);
    // The API doesn't match a full file name including its extension, so
    // retry "Photo01.jpg" as "Photo01".
    $base = $this->baseName($query);
    if (!$hits && $base !== $query && $base !== '') {
      $hits = $this->postSearch(['value' => $base, 'searchFields' => ['name'], 'size' => $size], $apiKey);
    }
    // Names with separators ("24-08-15_TMR6200") aren't matched as a whole:
    // search on the longest part ("TMR6200") and keep only real matches.
    if (!$hits && ($part = $this->longestPart($base)) !== NULL) {
      $needle = mb_strtolower($base);
      $hits = array_values(array_filter(
        $this->postSearch(['value' => $part, 'searchFields' => ['name'], 'size' => 100], $apiKey),
        fn($item) => is_array($item) && str_contains(mb_strtolower((string) ($item['name'] ?? '')), $needle),
      ));
    }
    if (!$hits) {
      $hits = $this->postSearch(['value' => $query, 'size' => $size], $apiKey);
    }
    return $this->visible($this->normalizeAll($hits), $type, $limit);
  }

  /**
   * Number of assets requested per call (setting "Maximum assets shown").
   *
   * Capped at 100: larger pages make the API noticeably slower.
   */
  protected function pageSize(): int {
    $max = (int) $this->config()->get('max_items') ?: 60;
    return max(1, min(100, $max));
  }

  /**
   * Keeps only VERIFIED assets (always), optionally of one type, up to $limit.
   *
   * The API has no status or type parameter, so both filters run here.
   */
  protected function visible(array $assets, ?string $type = NULL, ?int $limit = NULL): array {
    $prefix = $type ? self::TYPES[$type] : NULL;
    $assets = array_values(array_filter($assets, fn(array $a) =>
      strtoupper((string) $a['status']) === 'VERIFIED'
      && ($prefix === NULL || str_starts_with(strtolower((string) $a['type']), $prefix))
    ));
    return $limit ? array_slice($assets, 0, $limit) : $assets;
  }

  /**
   * Normalizes raw API items, skipping deleted or malformed ones.
   */
  protected function normalizeAll(array $raw): array {
    $assets = [];
    foreach ($raw as $item) {
      if (is_array($item) && !empty($item['id']) && empty($item['deleted'])) {
        $assets[] = $this->normalize($item);
      }
    }
    return $assets;
  }

  /**
   * Longest alphanumeric part of a name with separators, or NULL if the name
   * has no separators ("24-08-15_TMR6200" -> "TMR6200").
   */
  protected function longestPart(string $name): ?string {
    $parts = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) < 2) {
      return NULL;
    }
    usort($parts, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return mb_strlen($parts[0]) >= 3 ? $parts[0] : NULL;
  }

  /**
   * File name without its extension ("photo01.jpg" -> "photo01").
   */
  protected function baseName(string $name): string {
    return preg_replace('/\.[a-z0-9]{1,5}$/i', '', $name);
  }

  /**
   * Fetches one asset by ID, including signed download URLs
   * (files.original, files.previewHighres, files.previewLowres).
   *
   * @throws \Drupal\verify_dam\VerifyApiException
   */
  public function getAsset(string $id, ?string $apiKey = NULL): array {
    $apiKey = $apiKey ?? $this->getApiKey();
    if ($apiKey === NULL) {
      throw new VerifyApiException('No Verify API key connected.', 401);
    }
    $data = $this->request('GET', '/asset/' . rawurlencode($id), $apiKey);
    if (empty($data['id'])) {
      throw new VerifyApiException('Unexpected response from the Verify API.', 502);
    }
    return $data;
  }

  /**
   * Performs POST /asset/search and returns the list of hits.
   */
  protected function postSearch(array $body, string $apiKey): array {
    $data = $this->request('POST', '/asset/search', $apiKey, $body);
    // Current API: {"hits": [...], "totalHits": n, "from": n, "size": n}.
    // Older API versions returned a bare list.
    return array_is_list($data) ? $data : ($data['hits'] ?? []);
  }

  /**
   * Calls the Verify API and returns the decoded JSON body.
   *
   * @throws \Drupal\verify_dam\VerifyApiException
   */
  protected function request(string $method, string $path, string $apiKey, ?array $json = NULL): array {
    $base = (string) $this->config()->get('base_url') ?: self::DEFAULT_BASE_URL;
    $url = rtrim($base, '/') . $path;
    $options = [
      'headers' => [
        'x-api-key' => $apiKey,
        'Accept' => 'application/json',
      ],
      'timeout' => 45,
      'connect_timeout' => 15,
    ];
    if ($json !== NULL) {
      $options['json'] = $json;
    }

    // One retry on network-level failures (timeouts, dropped connections).
    for ($attempt = 1; ; $attempt++) {
      try {
        $response = $this->httpClient->request($method, $url, $options);
        break;
      }
      catch (RequestException $e) {
        $status = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 0;
        if ($status === 0 && $attempt < 2) {
          continue;
        }
        $apiMessage = $this->apiMessage($e);
        // The API gateway answers an invalid key with 400 "API key not valid".
        if ($status === 400 && stripos($apiMessage, 'API key') !== FALSE) {
          throw new VerifyApiException('This API key is not valid. Check that you copied the complete key.', 401, $e);
        }
        if (!in_array($status, [400, 401, 403, 404], TRUE)) {
          $this->loggerFactory->get('verify_dam')->error('Verify API request failed: @msg', ['@msg' => $e->getMessage()]);
        }
        $message = match ($status) {
          401, 403 => 'The Verify API key was rejected.',
          404 => 'This asset no longer exists in Verify.',
          0 => 'Could not reach the Verify API (' . $this->reason($e) . ').',
          default => sprintf('The Verify API returned an error (HTTP %d)', $status) . ($apiMessage !== '' ? ': ' . $apiMessage : '.'),
        };
        throw new VerifyApiException($message, $status, $e);
      }
      catch (GuzzleException $e) {
        if ($attempt < 2) {
          continue;
        }
        $this->loggerFactory->get('verify_dam')->error('Verify API request failed: @msg', ['@msg' => $e->getMessage()]);
        throw new VerifyApiException('Could not reach the Verify API (' . $this->reason($e) . ').', 0, $e);
      }
    }

    $data = json_decode((string) $response->getBody(), TRUE);
    if (!is_array($data)) {
      throw new VerifyApiException('Unexpected response from the Verify API.', 502);
    }
    return $data;
  }

  /**
   * The "message" field of an API error response, if any (max 200 chars).
   */
  protected function apiMessage(RequestException $e): string {
    if (!$e->hasResponse()) {
      return '';
    }
    $data = json_decode((string) $e->getResponse()->getBody(), TRUE);
    $message = is_array($data) ? (string) ($data['message'] ?? '') : '';
    return mb_substr(trim(preg_replace('/^[A-Z_]+:\s*/', '', $message)), 0, 200);
  }

  /**
   * Short, key-free description of a transport error, e.g.
   * "cURL error 28: Operation timed out after 45001 milliseconds".
   */
  protected function reason(\Throwable $e): string {
    $msg = $e->getMessage();
    if (preg_match('/cURL error \d+: [^(]+/', $msg, $m)) {
      $msg = $m[0];
    }
    return trim(mb_substr($msg, 0, 160));
  }

  /**
   * Maps an AssetSearchResponse item to the shape the front-end uses.
   */
  protected function normalize(array $item): array {
    $thumb = $item['files']['previewLowres'] ?? NULL;
    if (!is_string($thumb) || !preg_match('#^https?://#i', $thumb)) {
      $thumb = NULL;
    }
    return [
      'id' => (string) $item['id'],
      'name' => (string) ($item['name'] ?? ''),
      'type' => isset($item['type']) ? (string) $item['type'] : NULL,
      'status' => isset($item['status']) ? (string) $item['status'] : NULL,
      'width' => isset($item['width']) ? (int) $item['width'] : NULL,
      'height' => isset($item['height']) ? (int) $item['height'] : NULL,
      'size' => isset($item['size']) ? (int) $item['size'] : NULL,
      'created' => isset($item['creationDate']) ? (string) $item['creationDate'] : NULL,
      'modified' => isset($item['modificationDate']) ? (string) $item['modificationDate'] : NULL,
      'thumbnail' => $thumb,
    ];
  }

  /**
   * Module configuration.
   */
  protected function config() {
    return $this->configFactory->get('verify_dam.settings');
  }

}
