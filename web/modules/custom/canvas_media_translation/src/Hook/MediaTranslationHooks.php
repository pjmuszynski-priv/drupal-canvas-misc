<?php

declare(strict_types=1);

namespace Drupal\canvas_media_translation\Hook;

use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\media\MediaInterface;
use Drupal\media_directories_browser\Controller\MediaDirectoriesBrowserController;

/**
 * Adds alt translations to the Media Directories quick-edit form.
 */
final class MediaTranslationHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ContentTranslationManagerInterface $translationManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly RouteMatchInterface $routeMatch,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ContentEntityFormInterface || $form_object->getOperation() !== 'media_library') {
      return;
    }
    $media = $form_object->getEntity();
    if (!$media instanceof MediaInterface || $media->isNew() || $this->routeMatch->getRouteName() !== 'media_directories_browser.media_quick_edit') {
      return;
    }

    $bundles = $this->configFactory->get('media_directories_browser.settings')->get('translation_types') ?? [];
    if (!in_array($media->bundle(), $bundles, TRUE) || !$this->translationManager->isEnabled('media', $media->bundle())) {
      return;
    }

    $field_name = $media->getSource()->getSourceFieldDefinition($media->bundle->entity)?->getName();
    $field = $field_name ? $media->get($field_name) : NULL;
    $definition = $field?->getFieldDefinition();
    if (!$definition || $definition->getType() !== 'image' || !$definition->isTranslatable() || !$definition->getSetting('alt_field') || $field->isEmpty()) {
      return;
    }

    $handler = $this->entityTypeManager->getHandler('media', 'translation');
    $translations = [];
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      if ($langcode === $media->language()->getId()) {
        continue;
      }
      $exists = $media->hasTranslation($langcode);
      $target = $exists ? $media->getTranslation($langcode) : $media;
      if (!$handler->getTranslationAccess($target, $exists ? 'update' : 'create')->isAllowed() || !$target->get($field_name)->access('edit')) {
        continue;
      }
      $translations[$langcode] = [
        '#type' => 'textfield',
        '#title' => $this->t('Alternative text (@language)', ['@language' => $language->getName()]),
        '#default_value' => $exists ? $target->get($field_name)->alt : '',
        '#maxlength' => $definition->getFieldStorageDefinition()->getColumns()['alt']['length'],
        '#required' => $exists && $definition->getSetting('alt_field_required'),
      ];
    }
    if (!$translations) {
      return;
    }

    $form['canvas_media_alt_translations'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Alternative text translations'),
      '#tree' => TRUE,
      '#weight' => ($form[$field_name]['#weight'] ?? 0) + 0.1,
      '#source_field' => $field_name,
    ] + $translations;
    $form['canvas_media_alt_translations']['save'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save alt translations'),
      '#parents' => ['canvas_media_save_alt_translations'],
      '#limit_validation_errors' => [['canvas_media_alt_translations']],
      '#submit' => [self::class . '::save'],
      '#ajax' => [
        'callback' => [MediaDirectoriesBrowserController::class, 'quickEditAjaxSubmit'],
        'event' => 'click',
      ],
    ];
  }

  /**
   * Saves changed translations independently of the quick-edit file widget.
   */
  public function save(array &$form, FormStateInterface $form_state): void {
    $media_id = $form_state->getFormObject()->getEntity()->id();
    $media = $this->entityTypeManager->getStorage('media')->loadUnchanged($media_id);
    $values = $form_state->getValue('canvas_media_alt_translations', []);
    $field_name = $form['canvas_media_alt_translations']['#source_field'];
    $changed = FALSE;
    foreach ($values as $langcode => $alt) {
      if ($alt === $form['canvas_media_alt_translations'][$langcode]['#default_value']) {
        continue;
      }
      if ($media->hasTranslation($langcode)) {
        $translation = $media->getTranslation($langcode);
      }
      else {
        $source = $media->getUntranslated();
        $translation = $media->addTranslation($langcode, $source->toArray());
        $this->translationManager->getTranslationMetadata($translation)->setSource($source->language()->getId());
      }
      $translation->get($field_name)->first()->set('alt', $alt);
      $changed = TRUE;
    }
    if ($changed) {
      $media->save();
    }
  }

}
