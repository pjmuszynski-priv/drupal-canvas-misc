<?php

/**
 * Exports only demo-owned configuration, preserving unrelated active config.
 *
 * Run: ddev drush php:script scripts/export-slot-demo.php
 */

use Drupal\Component\Serialization\Yaml;

$names = [
  'node.type.slot_demo',
  'field.storage.node.canvas_slot_main',
  'field.storage.node.canvas_slot_additional',
  'field.field.node.slot_demo.canvas_slot_main',
  'field.field.node.slot_demo.canvas_slot_additional',
  'core.entity_form_display.node.slot_demo.default',
  'core.entity_view_display.node.slot_demo.full',
  'canvas.component.js.template_shell',
  'canvas.js_component.template_shell',
  'canvas.component.marker.empty_slot',
  'canvas.content_template.node.slot_demo.full',
  'canvas_headless.settings',
  'user.role.slot_demo_editor',
  'user.role.slot_demo_designer',
];
foreach ($names as $name) {
  $data = \Drupal::config($name)->getRawData();
  if (!$data) {
    throw new RuntimeException("Missing configuration: $name");
  }
  $destination = DRUPAL_ROOT . '/../config/sync/' . $name . '.yml';
  file_put_contents($destination, Yaml::encode($data));
  echo "$name\n";
}
