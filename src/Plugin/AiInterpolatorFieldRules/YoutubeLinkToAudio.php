<?php

namespace Drupal\ai_interpolator_youtube\Plugin\AiInterPolatorFieldRules;

use Drupal\ai_interpolator\Annotation\AiInterpolatorFieldRule;
use Drupal\ai_interpolator\Exceptions\AiInterpolatorResponseErrorException;
use Drupal\ai_interpolator\PluginInterfaces\AiInterpolatorFieldRuleInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\FileRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The rules for a audio field.
 *
 * @AiInterpolatorFieldRule(
 *   id = "ai_interpolator_youtube_audio",
 *   title = @Translation("Video Provider Link to Audio"),
 *   field_rule = "file",
 *   target = "file",
 * )
 */
class YoutubeLinkToAudio extends AiInterpolatorFieldRule implements AiInterpolatorFieldRuleInterface, ContainerFactoryPluginInterface {

  /**
   * {@inheritDoc}
   */
  public $title = 'Video Provider Link to Audio';

  /**
   * The entity type manager.
   */
  public EntityTypeManagerInterface $entityManager;

  /**
   * The File System interface.
   */
  public FileSystemInterface $fileSystem;

  /**
   * The File Repo.
   */
  public FileRepositoryInterface $fileRepo;

  /**
   * The token system to replace and generate paths.
   */
  public Token $token;

  /**
   * The current user.
   */
  public AccountProxyInterface $currentUser;

  /**
   * The logger channel.
   */
  public LoggerChannelFactoryInterface $loggerChannel;

  /**
   * Construct an image field.
   *
   * @param array $configuration
   *   Inherited configuration.
   * @param string $plugin_id
   *   Inherited plugin id.
   * @param mixed $plugin_definition
   *   Inherited plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityManager
   *   The entity type manager.
   * @param \Drupal\did\Did $did
   *   The Did requester.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The File system interface.
   * @param \Drupal\file\FileRepositoryInterface $fileRepo
   *   The File repo.
   * @param \Drupal\Core\Utility\Token $token
   *   The token system.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannel
   *   The logger channel interface.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entityManager,
    FileSystemInterface $fileSystem,
    FileRepositoryInterface $fileRepo,
    Token $token,
    AccountProxyInterface $currentUser,
    LoggerChannelFactoryInterface $loggerChannel,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityManager = $entityManager;
    $this->fileSystem = $fileSystem;
    $this->fileRepo = $fileRepo;
    $this->token = $token;
    $this->currentUser = $currentUser;
    $this->loggerChannel = $loggerChannel;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('file.repository'),
      $container->get('token'),
      $container->get('current_user'),
      $container->get('logger.factory'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function helpText() {
    return $this->t("Generate audio files from Youtube links.");
  }

  /**
   * {@inheritDoc}
   */
  public function needsPrompt() {
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function advancedMode() {
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function allowedInputs() {
    return [
      'link',
    ];
  }

  /**
   * {@inheritDoc}
   */
  public function ruleIsAllowed(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition) {
    // Checks system for ffmpeg, otherwise this rule does not exist.
    $command = (PHP_OS == 'WINNT') ? 'where ffmpeg' : 'which ffmpeg';
    if (!shell_exec($command)) {
      return FALSE;
    }
    if (!$this->getExecutionPath()) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritDoc}
   */
  public function placeholderText() {
    return "";
  }

  /**
   * {@inheritDoc}
   */
  public function generate(ContentEntityInterface $entity, FieldDefinitionInterface $fieldDefinition, array $interpolatorConfig) {
    $values = [];
    foreach ($entity->{$interpolatorConfig['base_field']} as $link) {
      // Download as last step.
      $values[] = $link->uri;
    }
    return $values;
  }

  /**
   * {@inheritDoc}
   */
  public function verifyValue(ContentEntityInterface $entity, $value, FieldDefinitionInterface $fieldDefinition) {
    // Check so the value is a valid url.
    if (filter_var($value, FILTER_VALIDATE_URL)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritDoc}
   */
  public function storeValues(ContentEntityInterface $entity, array $values, FieldDefinitionInterface $fieldDefinition) {
    $config = $fieldDefinition->getConfig($entity->bundle())->getSettings();
    // Transform string to boolean.
    $fileEntities = [];
    $fileStorage = $this->entityManager->getStorage('file');

    // Successful counter, to only download as many as max.
    foreach ($values as $value) {
      // Create a tmp file using Drupal file system.
      $tmpFile = $this->fileSystem->tempnam($this->fileSystem->getTempDirectory(), 'youtube_') . '.mp3';
      // Get executable.
      $exec = $this->getExecutionPath();
      // Download the file and force webm.
      exec("$exec -o $tmpFile \"$value\" --extract-audio --audio-format mp3", $output, $return);
      // If we have a file, get the filename.
      if ($return) {
        throw new AiInterpolatorResponseErrorException("Failed to download file from Youtube.");
      }
      // Copy the unmanaged file into a managed file system.
      $fileName = basename($tmpFile);
      $path = $this->token->replace($config['uri_scheme'] . '://' . rtrim($config['file_directory'], '/'));
      $filePath = $path . '/' . $fileName;
      // Create directory if not existsing.
      $this->fileSystem->prepareDirectory($path, FileSystemInterface::CREATE_DIRECTORY);
      // Move the file unmanaged.
      $newPath = $this->fileSystem->move($tmpFile, $filePath, FileSystemInterface::EXISTS_REPLACE);
      // Create file entity from the new path.
      $file = $fileStorage->create([
        'uri' => $newPath,
        'uid' => $this->currentUser->id(),
        'status' => 1,
        'filename' => $fileName,
      ]);
      $file->save();
      if ($file) {
        $fileEntities[] = $file->id();
      }
    }
    // Then set the value.
    $entity->set($fieldDefinition->getName(), $fileEntities);
  }

  /**
   * Figure out executable.
   *
   * @return string
   *   The path to the executable.
   */
  public function getExecutionPath() {
    $command = (PHP_OS == 'WINNT') ? 'where youtube-dl' : 'which youtube-dl';
    $path = shell_exec($command);
    if ($path) {
      return 'youtube-dl';
    }
    $command = (PHP_OS == 'WINNT') ? 'where yt-dlpg' : 'which yt-dlp';
    $path = shell_exec($command);
    if ($path) {
      return 'yt-dlp';
    }
    return '';
  }

}
