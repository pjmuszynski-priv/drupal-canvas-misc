<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_disclosure\Hook;

use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaObjectRef;
use Drupal\canvas\PropShape\CandidateStorablePropShape;
use Drupal\canvas_ai_disclosure\ImageDisclosureExpression;
use Drupal\canvas_ai_disclosure\Plugin\Field\FieldType\DisclosureImageItem;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;

/**
 * Extends the mappings after Canvas and its Media Library integration.
 */
final class CanvasDisclosureHooks {

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  #[Hook('library_info_alter')]
  public function libraryInfoAlter(array &$libraries, string $extension): void {
    if ($extension === 'gin' && isset($libraries['media_library'])) {
      $path = '/' . $this->moduleExtensionList->getPath('canvas_ai_disclosure');
      $libraries['media_library']['css']['theme'][$path . '/css/gin-media-library.css'] = ['weight' => 100];
    }
    if ($extension === 'canvas' && isset($libraries['canvas-ui'])) {
      $path = '/' . $this->moduleExtensionList->getPath('canvas_ai_disclosure');
      $libraries['canvas-ui']['css']['theme'][$path . '/css/canvas-media-library.css'] = ['weight' => 100];
    }
  }

  #[Hook('field_info_alter', order: new OrderAfter(['canvas']))]
  public static function fieldInfoAlter(array &$info): void {
    $info['image']['class'] = DisclosureImageItem::class;
  }

  #[Hook('canvas_storable_prop_shape_alter', order: new OrderAfter(['media_library']))]
  public static function storablePropShapeAlter(CandidateStorablePropShape $shape): void {
    if (($shape->shape->schema['$ref'] ?? NULL) === JsonSchemaObjectRef::Image->value && $shape->fieldTypeProp !== NULL) {
      $shape->fieldTypeProp = ImageDisclosureExpression::extend($shape->fieldTypeProp);
      $shape->addCacheTags(['config:core.extension']);
    }
  }

}
