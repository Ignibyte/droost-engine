<?php

declare(strict_types=1);

namespace Droost\Engine\Scaffold\Blueprint;

use Droost\Engine\Scaffold\AbstractBlueprint;
use Droost\Engine\Scaffold\ScaffoldContext;
use Droost\Engine\Scaffold\ScaffoldResult;

/**
 * Scaffolds an access control handler for an entity type you already have.
 *
 * The content-entity blueprint emits a handler as a byproduct of scaffolding a
 * whole entity type; this one serves the far more common ask — adding access
 * control to an entity type that already exists, without re-scaffolding it.
 * Template-only: the emitted class is the shipped droost_examples handler
 * modulo tokens, so it is convention-correct by construction.
 *
 * Inputs: id (the ENTITY TYPE id, e.g. "example_profile"), class (defaults to
 * the entity type in PascalCase + "AccessControlHandler"), label.
 */
final class AccessHandlerBlueprint extends AbstractBlueprint {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'access-handler';
  }

  /**
   * {@inheritdoc}
   */
  public function description(): string {
    return 'An EntityAccessControlHandler for an EXISTING entity type, with per-operation permissions. Pass --id=<entity_type_id> (not a plugin id); the class defaults to <EntityType>AccessControlHandler.';
  }

  /**
   * {@inheritdoc}
   *
   * @throws \InvalidArgumentException
   *   When the inputs cannot yield a valid entity type id and PHP class name.
   */
  public function generate(ScaffoldContext $context, ScaffoldResult $result): void {
    $entityType = $this->machineName($context->input('id', 'example_profile'));
    $rawClass = $context->input('class', '');
    $class = $this->className($rawClass !== '' ? $rawClass : $entityType . '_access_control_handler');
    if ($entityType === '' || $class === '') {
      throw new \InvalidArgumentException('Could not derive a valid entity type id and class from the inputs. Pass --id (the entity type id: a-z, 0-9, _) and optionally --class (a valid PHP class name).');
    }
    // The label reaches a docblock only, so docText() neutralises "*/" — a
    // label cannot terminate the comment and inject top-level code.
    $tokens = [
      '{{module}}' => $context->module,
      '{{class}}' => $class,
      '{{entity_type}}' => $entityType,
      '{{label_doc}}' => $this->docText($context->input('label', $entityType)),
    ];
    $this->writeFile(
      $context,
      $context->modulePath . '/src/' . $class . '.php',
      strtr($this->handlerTemplate(), $tokens),
      $result,
    );
  }

  /**
   * The access control handler template.
   *
   * @return string
   *   The template with {{token}} placeholders.
   */
  private function handlerTemplate(): string {
    return <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Drupal\{{module}};

    use Drupal\Core\Access\AccessResult;
    use Drupal\Core\Entity\EntityAccessControlHandler;
    use Drupal\Core\Entity\EntityInterface;
    use Drupal\Core\Session\AccountInterface;

    /**
     * Defines the access control handler for the {{label_doc}} entity type.
     *
     * Wire it into the entity type attribute you are protecting, in its
     * "handlers" list (an entity type has exactly one access handler slot):
     *
     * @code
     * 'access' => {{class}}::class,
     * @endcode
     *
     * Then declare every permission this class checks in
     * {{module}}.permissions.yml:
     *
     * - "view {{entity_type}}"
     * - "edit {{entity_type}}"
     * - "delete {{entity_type}}"
     * - "create {{entity_type}}"
     * - the entity type's own admin_permission, which short-circuits below.
     *   Mark that one "restrict access: true".
     *
     * To restrict an entity type you do NOT own, do not subclass its handler —
     * implement hook_entity_access()/hook_ENTITY_TYPE_access() instead, so your
     * opinion is merged with the owner's rather than replacing it.
     *
     * Every branch returns a cache-aware AccessResult: a result that forgets it
     * depended on permissions is a cross-user cache leak, so the per-operation
     * checks use allowedIfHasPermission() (which adds the "user.permissions"
     * context for you) and the branches that decide for themselves call
     * cachePerPermissions().
     *
     * This is a permission-only baseline. If the entity type is publishable or
     * owned, "view {{entity_type}}" alone will expose unpublished or other
     * people's entities — split those cases out (see EntityPublishedInterface
     * and EntityOwnerInterface) and add ->addCacheableDependency($entity) to
     * any verdict that reads the entity.
     */
    final class {{class}} extends EntityAccessControlHandler {

      /**
       * {@inheritdoc}
       */
      protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
        // Core forbids deleting an entity that was never saved, ahead of any
        // permission check. Overriding checkAccess() replaces that rule, so
        // restate it rather than letting an admin "delete" a new entity.
        if ($operation === 'delete' && $entity->isNew()) {
          return AccessResult::forbidden()->addCacheableDependency($entity);
        }

        // getAdminPermission() returns FALSE when the entity type declares
        // none; guard rather than cast, so an absent permission falls through
        // to the per-operation checks instead of short-circuiting on ''.
        $adminPermission = $this->entityType->getAdminPermission();
        if (is_string($adminPermission) && $adminPermission !== '' && $account->hasPermission($adminPermission)) {
          return AccessResult::allowed()->cachePerPermissions();
        }

        return match($operation) {
          'view' => AccessResult::allowedIfHasPermission($account, 'view {{entity_type}}'),
          'update' => AccessResult::allowedIfHasPermission($account, 'edit {{entity_type}}'),
          'delete' => AccessResult::allowedIfHasPermission($account, 'delete {{entity_type}}'),
          // Reached only after the admin permission was checked, so this "no
          // opinion" still varies by permissions. Saying so keeps an unhandled
          // operation (e.g. "view all revisions") from caching one user's
          // denial for everyone.
          default => AccessResult::neutral()->cachePerPermissions(),
        };
      }

      /**
       * {@inheritdoc}
       *
       * @phpstan-param array<string, mixed> $context
       */
      protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
        // Read the admin permission from the entity type here too: hardcoding
        // "administer {{entity_type}}" would lock out the real administrator
        // whenever admin_permission is named differently (core's taxonomy_term
        // uses "administer taxonomy", for instance).
        $permissions = ['create {{entity_type}}'];
        $adminPermission = $this->entityType->getAdminPermission();
        if (is_string($adminPermission) && $adminPermission !== '') {
          $permissions[] = $adminPermission;
        }

        return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
      }

    }
    PHP;
  }

}
