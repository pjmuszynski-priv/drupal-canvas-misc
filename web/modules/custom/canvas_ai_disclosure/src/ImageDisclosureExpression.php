<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_disclosure;

use Drupal\canvas\PropExpressions\StructuredData\FieldObjectPropsExpression;
use Drupal\canvas\PropExpressions\StructuredData\FieldPropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferenceFieldTypePropExpression;
use Drupal\canvas\PropExpressions\StructuredData\ReferencedBundleSpecificBranches;
use Drupal\canvas\PropExpressions\StructuredData\StructuredDataPropExpressionInterface;

/**
 * Extends Canvas's Media image mapping without changing the Media selection.
 */
final class ImageDisclosureExpression {

  public static function extend(StructuredDataPropExpressionInterface|ReferencedBundleSpecificBranches $expression): StructuredDataPropExpressionInterface|ReferencedBundleSpecificBranches {
    if ($expression instanceof ReferenceFieldTypePropExpression) {
      return new ReferenceFieldTypePropExpression($expression->referencer, self::extend($expression->referenced));
    }
    if ($expression instanceof ReferencedBundleSpecificBranches) {
      return new ReferencedBundleSpecificBranches(array_map(self::extend(...), $expression->bundleSpecificReferencedExpressions));
    }
    if ($expression instanceof FieldObjectPropsExpression
      && $expression->entityType->getEntityTypeId() === 'media'
      && isset($expression->objectPropsToFieldProps['src'], $expression->objectPropsToFieldProps['alt'], $expression->objectPropsToFieldProps['width'], $expression->objectPropsToFieldProps['height'])) {
      return new FieldObjectPropsExpression(
        $expression->entityType,
        $expression->fieldName,
        $expression->delta,
        $expression->objectPropsToFieldProps + [
          'aiDisclosure' => new FieldPropExpression($expression->entityType, $expression->fieldName, $expression->delta, 'canvas_ai_disclosure'),
        ],
      );
    }
    return $expression;
  }

}
