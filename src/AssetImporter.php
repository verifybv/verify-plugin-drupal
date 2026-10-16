<?php

namespace Drupal\verify_dam;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Copies a Verify asset into Drupal as a managed file (and a Media item when
 * the Media module is enabled).
 */
class AssetImporter {

  /**
   * File extensions that are safe to store under their own name. Anything
   * else gets ".txt" appended, as Drupal core does for uploads.
   */
  protected const SAFE_EXTENSIONS = [
    'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'tif', 'tiff', 'bmp', 'heic', 'heif',
    'mp4', 'mov', 'm4v', 'webm', 'avi', 'mkv', 'mp3', 'wav', 'm4a', 'ogg',
    'pdf', 'zip', 'psd', 'ai', 'eps', 'raw', 'raf', 'cr2', 'nef', 'dng',
  ];

  /**
   * Preview file extensions => MIME types.
   */
  protected const IMAGE_TYPES = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'avif' => 'image/avif',
  ];

  public function __construct(
    protected VerifyClient $client,
    protected ClientInterface $httpClient,
    protected FileSystemInterface $fileSystem,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected KeyValueFactoryInterface $keyValue,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * Imports an asset (or returns the earlier import of it).
   *
   * @return array
   *   Keys: name, existing (bool), file_url, media_url (or NULL), edit_url.
   *
   * @throws \Drupal\verify_dam\VerifyApiException
   */
  public function import(string $assetId): array {
    if ($existing = $this->existing($assetId)) {
      return $existing + ['existing' => TRUE];
    }

    $asset = $this->client->getAsset($assetId);
    $name = (string) ($asset['name'] ?? $assetId);
    $mime = (string) ($asset['type'] ?? '');
    $files = $asset['files'] ?? [];

    // Images: copy Verify's web-sized preview (1000px) instead of the
    // original, which can be tens of megabytes. Other types (video, audio,
    // PDF) only have an image preview, so they keep the original file.
    if (str_starts_with($mime, 'image/')) {
      $source = $files['previewHighres'] ?? $files['previewLowres'] ?? $files['original'] ?? NULL;
    }
    else {
      $source = $files['original'] ?? NULL;
    }
    if (!is_string($source) || !str_starts_with($source, 'https://')) {
      throw new VerifyApiException('Verify did not provide a download link for this asset.', 502);
    }

    // A preview may have a different format than the original (e.g. a JPEG
    // preview of a PNG); name and type the file after what is downloaded.
    $filename = $name;
    if ($source !== ($files['original'] ?? NULL)) {
      $ext = strtolower(pathinfo((string) parse_url($source, PHP_URL_PATH), PATHINFO_EXTENSION));
      if (isset(self::IMAGE_TYPES[$ext])) {
        $filename = pathinfo($name, PATHINFO_FILENAME) . '.' . $ext;
        $mime = self::IMAGE_TYPES[$ext];
      }
    }

    $file = $this->download($source, $this->safeFilename($filename), $mime);
    $media = $this->createMedia($file, $name, $mime);

    $this->store()->set($assetId, [
      'fid' => (int) $file->id(),
      'mid' => $media ? (int) $media->id() : NULL,
    ]);

    return $this->describe($file, $media, $name) + ['existing' => FALSE];
  }

  /**
   * IDs of all assets that have been imported into Drupal.
   */
  public function importedIds(): array {
    return array_keys($this->store()->getAll());
  }

  /**
   * Returns the earlier import of an asset, if its file still exists.
   */
  protected function existing(string $assetId): ?array {
    $record = $this->store()->get($assetId);
    if (!$record) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($record['fid']);
    if (!$file instanceof FileInterface) {
      $this->store()->delete($assetId);
      return NULL;
    }
    $media = NULL;
    if (!empty($record['mid']) && $this->moduleHandler->moduleExists('media')) {
      $media = $this->entityTypeManager->getStorage('media')->load($record['mid']);
    }
    return $this->describe($file, $media, $file->getFilename());
  }

  /**
   * Downloads the asset into public://verify/YYYY-MM and creates a file entity.
   */
  protected function download(string $url, string $filename, string $mime): FileInterface {
    $directory = 'public://verify/' . date('Y-m');
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $temp = $this->fileSystem->tempnam('temporary://', 'verify_');
    try {
      // The URL is a pre-signed storage link, so no API key is sent with it.
      $this->httpClient->request('GET', $url, [
        'sink' => $this->fileSystem->realpath($temp),
        'timeout' => 300,
        'connect_timeout' => 15,
      ]);
      $uri = $this->fileSystem->move($temp, $directory . '/' . $filename, FileExists::Rename);
    }
    catch (GuzzleException $e) {
      $this->fileSystem->delete($temp);
      throw new VerifyApiException('Could not download the file from Verify.', 0, $e);
    }

    /** @var \Drupal\file\FileInterface $file */
    $file = $this->entityTypeManager->getStorage('file')->create([
      'uri' => $uri,
      'filename' => basename($uri),
      'filemime' => $mime ?: 'application/octet-stream',
      'uid' => $this->currentUser->id(),
      'status' => 1,
    ]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Creates a Media item for the file when a fitting media type exists.
   */
  protected function createMedia(FileInterface $file, string $name, string $mime) {
    if (!$this->moduleHandler->moduleExists('media')) {
      return NULL;
    }
    $wanted = match (TRUE) {
      str_starts_with($mime, 'image/') => ['image'],
      str_starts_with($mime, 'video/') => ['video_file', 'file'],
      str_starts_with($mime, 'audio/') => ['audio_file', 'file'],
      default => ['file'],
    };

    $types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
    foreach ($wanted as $plugin) {
      foreach ($types as $type) {
        /** @var \Drupal\media\MediaTypeInterface $type */
        if ($type->status() && $type->getSource()->getPluginId() === $plugin) {
          $field = $type->getSource()->getSourceFieldDefinition($type)?->getName();
          if (!$field) {
            continue;
          }
          $value = ['target_id' => $file->id()];
          if ($plugin === 'image') {
            $value['alt'] = pathinfo($name, PATHINFO_FILENAME);
          }
          $media = $this->entityTypeManager->getStorage('media')->create([
            'bundle' => $type->id(),
            'name' => $name,
            'uid' => $this->currentUser->id(),
            'status' => 1,
            $field => $value,
          ]);
          $media->save();
          return $media;
        }
      }
    }
    return NULL;
  }

  /**
   * Shape returned to the browser.
   */
  protected function describe(FileInterface $file, $media, string $name): array {
    $fileUrl = $this->fileUrlGenerator->generateString($file->getFileUri());
    $mediaUrl = $media ? $media->toUrl('edit-form')->toString() : NULL;
    return [
      'name' => $name,
      'file_url' => $fileUrl,
      'media_url' => $mediaUrl,
      'open_url' => $mediaUrl ?? $fileUrl,
    ];
  }

  /**
   * Makes a Verify asset name safe to use as a file name.
   */
  protected function safeFilename(string $name): string {
    $name = trim(preg_replace('/[^\w.\- ()]+/u', '_', basename($name)), ' ._');
    $name = $name !== '' ? $name : 'verify-asset';
    // Like Drupal core's upload munging: neutralise inner extensions such as
    // "x.php.jpg" -> "x.php_.jpg" so web servers can't treat them as scripts.
    $parts = explode('.', $name);
    $last = array_pop($parts);
    foreach ($parts as $i => $part) {
      if ($i > 0 && !in_array(strtolower($part), self::SAFE_EXTENSIONS, TRUE) && preg_match('/^[a-z0-9]{2,5}$/i', $part)) {
        $parts[$i] .= '_';
      }
    }
    $name = $parts ? implode('.', $parts) . '.' . $last : $last;
    if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::SAFE_EXTENSIONS, TRUE)) {
      $name .= '.txt';
    }
    return mb_substr($name, -200);
  }

  /**
   * Asset ID => ['fid' => …, 'mid' => …] map.
   */
  protected function store() {
    return $this->keyValue->get('verify_dam.imported');
  }

}
