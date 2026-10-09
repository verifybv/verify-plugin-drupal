<?php

namespace Drupal\verify_dam;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
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
   * Maximum number of assets remembered per API key.
   */
  protected const INDEX_LIMIT = 5000;

  public const DEFAULT_BASE_URL = 'https://verify-api-gateway-9jozroz9.ew.gateway.dev';

  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected UserDataInterface $userData,
    protected AccountProxyInterface $currentUser,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected KeyValueFactoryInterface $keyValue,
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
    $this->search('', trim($key));
  }

  /**
   * Returns assets for the library view or a search.
   *
   * Verify only offers an AI (semantic) search endpoint, whose results for an
   * empty query are neither complete nor stable. To keep the library steady,
   * every asset the API returns is remembered in a local index (per API key),
   * and the library is always rendered from that index, newest first. Each
   * refresh only adds new assets or updates existing ones.
   *
   * For a non-empty query, assets whose file name matches are returned first
   * ("Photo01.jpg" finds exactly that file). Only when nothing matches by name
   * do we fall back to Verify's semantic results.
   *
   * @return array[]
   *   Normalized assets.
   *
   * @throws \Drupal\verify_dam\VerifyApiException
   */
  public function search(string $query, ?string $apiKey = NULL): array {
    $apiKey = $apiKey ?? $this->getApiKey();
    if ($apiKey === NULL) {
      throw new VerifyApiException('No Verify API key connected.', 401);
    }

    $query = trim($query);
    $index = $this->loadIndex($apiKey);
    $max = (int) $this->config()->get('max_items') ?: 200;

    if ($query === '') {
      $this->ingest($this->browse($apiKey), $index);
      $this->saveIndex($apiKey, $index);
      return array_slice($this->visible($this->sortNewest($index)), 0, $max);
    }

    $semantic = $this->ingest($this->postSearch($query, $apiKey), $index);
    $this->saveIndex($apiKey, $index);

    // A file-name match wins, even if the filter then hides it: searching
    // "Photo01.jpg" should never fall back to unrelated similar images.
    $byName = $this->matchByName($query, $index);
    return array_slice($this->visible($byName ?: $semantic), 0, $max);
  }

  /**
   * Applies the display filter (by default: only VERIFIED assets).
   */
  protected function visible(array $assets): array {
    if ($this->config()->get('verified_only') === FALSE) {
      return $assets;
    }
    return array_values(array_filter($assets, fn(array $a) => strtoupper((string) $a['status']) === 'VERIFIED'));
  }

  /**
   * Fetches the "whole library" via an empty search (or "*" if rejected).
   */
  protected function browse(string $apiKey): array {
    try {
      return $this->postSearch('', $apiKey);
    }
    catch (VerifyApiException $e) {
      if ($e->getCode() !== 400) {
        throw $e;
      }
      return $this->postSearch('*', $apiKey);
    }
  }

  /**
   * Merges raw API items into the index.
   *
   * @return array[]
   *   The normalized, non-deleted items from this response, in API order.
   */
  protected function ingest(array $raw, array &$index): array {
    $fresh = [];
    foreach ($raw as $item) {
      if (!is_array($item) || empty($item['id'])) {
        continue;
      }
      $id = (string) $item['id'];
      if (!empty($item['deleted'])) {
        unset($index[$id]);
        continue;
      }
      $asset = $this->normalize($item);
      // Keep a known thumbnail if this response didn't include one.
      if ($asset['thumbnail'] === NULL && isset($index[$id]['thumbnail'])) {
        $asset['thumbnail'] = $index[$id]['thumbnail'];
      }
      $index[$id] = $asset;
      $fresh[] = $asset;
    }
    return $fresh;
  }

  /**
   * Name matches for a query: exact file name first, then partial matches.
   */
  protected function matchByName(string $query, array $index): array {
    $q = mb_strtolower($query);
    $qBase = $this->baseName($q);
    $exact = $partial = [];
    foreach ($index as $asset) {
      $name = mb_strtolower($asset['name']);
      if ($name === $q || $this->baseName($name) === $qBase) {
        $exact[] = $asset;
      }
      elseif (str_contains($name, $q)) {
        $partial[] = $asset;
      }
    }
    return array_merge($this->sortNewest($exact), $this->sortNewest($partial));
  }

  /**
   * File name without its extension ("photo01.jpg" -> "photo01").
   */
  protected function baseName(string $name): string {
    return preg_replace('/\.[a-z0-9]{1,5}$/i', '', $name);
  }

  /**
   * Sorts assets newest first, with a stable tie-break.
   */
  protected function sortNewest(array $assets): array {
    $assets = array_values($assets);
    usort($assets, fn(array $a, array $b) =>
      strcmp($b['created'] ?? '', $a['created'] ?? '')
        ?: strcmp($a['name'], $b['name'])
        ?: strcmp($a['id'], $b['id'])
    );
    return $assets;
  }

  /**
   * Loads the remembered assets for an API key.
   */
  protected function loadIndex(string $apiKey): array {
    $index = $this->keyValue->get('verify_dam.index')->get($this->indexCid($apiKey), []);
    return is_array($index) ? $index : [];
  }

  /**
   * Stores the remembered assets for an API key (capped in size).
   */
  protected function saveIndex(string $apiKey, array $index): void {
    if (count($index) > self::INDEX_LIMIT) {
      $index = array_column(array_slice($this->sortNewest($index), 0, self::INDEX_LIMIT), NULL, 'id');
    }
    $this->keyValue->get('verify_dam.index')->set($this->indexCid($apiKey), $index);
  }

  /**
   * Cache id for an API key's index (the key itself is never stored).
   */
  protected function indexCid(string $apiKey): string {
    return hash('sha256', $apiKey);
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
   * Performs the raw POST /asset/search call.
   */
  protected function postSearch(string $value, string $apiKey): array {
    $data = $this->request('POST', '/asset/search', $apiKey, ['value' => $value]);
    // Accept both a bare array and a wrapped {"assets": [...]} shape.
    return array_is_list($data) ? $data : ($data['assets'] ?? $data['results'] ?? []);
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
