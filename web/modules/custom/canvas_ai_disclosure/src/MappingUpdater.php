<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_disclosure;

use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpression;

/**
 * Adds the computed image property to existing component versions.
 */
final class MappingUpdater {

  public static function updateExistingComponents(): void {
    $factory = \Drupal::configFactory();
    foreach ($factory->listAll('canvas.component.') as $name) {
      $config = $factory->getEditable($name);
      $versions = $config->get('versioned_properties');
      if (!is_array($versions)) {
        continue;
      }
      $original = $versions;
      $uses_disclosure = FALSE;
      // Retain historical versions: existing pages still refer to their IDs.
      // Only add a computed optional property; Media IDs and stored values stay.
      foreach ($versions as &$version) {
        foreach ($version['settings']['prop_field_definitions'] ?? [] as $prop => $definition) {
          if (($definition['field_type'] ?? NULL) !== 'entity_reference' || ($definition['field_storage_settings']['target_type'] ?? NULL) !== 'media') {
            continue;
          }
          $expression = StructuredDataPropExpression::fromString($definition['expression']);
          $version['settings']['prop_field_definitions'][$prop]['expression'] = (string) ImageDisclosureExpression::extend($expression);
          $uses_disclosure = $uses_disclosure || str_contains($version['settings']['prop_field_definitions'][$prop]['expression'], 'canvas_ai_disclosure');
        }
      }
      unset($version);
      $dependencies = $config->get('dependencies');
      $old_dependencies = $dependencies;
      if ($uses_disclosure) {
        $dependencies['module'] = array_values(array_unique([...($dependencies['module'] ?? []), 'canvas_ai_disclosure']));
        sort($dependencies['module']);
      }
      if ($original !== $versions || $old_dependencies !== $dependencies) {
        $config->set('versioned_properties', $versions)->set('dependencies', $dependencies)->save(TRUE);
      }
    }
    \Drupal::entityTypeManager()->getStorage('component')->resetCache();
  }

}
