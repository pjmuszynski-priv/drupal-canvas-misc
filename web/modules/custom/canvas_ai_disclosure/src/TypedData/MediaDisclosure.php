<?php

declare(strict_types=1);

namespace Drupal\canvas_ai_disclosure\TypedData;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\TypedData\Plugin\DataType\StringData;
use Drupal\media\MediaInterface;

/**
 * Resolves disclosure on the actual Media translation owning the image field.
 */
class MediaDisclosure extends StringData implements CacheableDependencyInterface, DependentPluginInterface {

  public function calculateDependencies(): array {
    return ['module' => ['canvas_ai_disclosure']];
  }

  private function resolve(): array {
    $entity = $this->getParent()->getEntity();
    $cacheability = CacheableMetadata::createFromObject($entity);
    if (!$entity instanceof MediaInterface) {
      return ['value' => 'none', 'cacheability' => $cacheability];
    }

    // Respect field visibility as well as Media access already checked by Canvas.
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === 'ai_disclosure') {
        $access = $entity->get($name)->access('view', NULL, TRUE);
        $cacheability->addCacheableDependency($access);
        if (!$access->isAllowed()) {
          return ['value' => 'none', 'cacheability' => $cacheability];
        }
      }
    }

    $result = \Drupal::service('ai_disclosure.resolver')->resolveWithCacheability($entity);
    $cacheability->addCacheableDependency($result['cacheability']);
    $value = match ($result['resolved']?->getEuIcon()) {
      'ai_generated' => 'ai_generated',
      'ai_modified', 'ai' => 'ai_assisted',
      default => 'none',
    };
    return ['value' => $value, 'cacheability' => $cacheability];
  }

  public function getValue(): string {
    return $this->resolve()['value'];
  }

  public function getCacheTags(): array {
    return $this->resolve()['cacheability']->getCacheTags();
  }

  public function getCacheContexts(): array {
    return $this->resolve()['cacheability']->getCacheContexts();
  }

  public function getCacheMaxAge(): int {
    return $this->resolve()['cacheability']->getCacheMaxAge();
  }

}
