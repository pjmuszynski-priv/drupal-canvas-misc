<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_disclosure\Plugin\Field\FieldType;

use Drupal\canvas\Plugin\Field\FieldTypeOverride\ImageItemOverride;
use Drupal\canvas_ai_disclosure\TypedData\MediaDisclosure;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Keeps Canvas image properties and adds the owning Media's disclosure.
 */
class DisclosureImageItem extends ImageItemOverride {

  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties = parent::propertyDefinitions($field_definition);
    $properties['canvas_ai_disclosure'] = DataDefinition::create('string')
      ->setLabel(t('AI disclosure'))
      ->setComputed(TRUE)
      ->setReadOnly(TRUE)
      ->setClass(MediaDisclosure::class);
    return $properties;
  }

}
