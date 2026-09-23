<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Wiki;

use Droost\Engine\Wiki\PageRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests the model-free page body.
 */
#[CoversClass(PageRenderer::class)]
final class PageRendererTest extends TestCase {

  /**
   * A contrib module's factsheet, every section filled.
   *
   * @return array<string, mixed>
   *   The factsheet.
   */
  private static function contribFactsheet(): array {
    return [
      'module' => 'webform',
      'identity' => [
        'kind' => 'module',
        'provenance' => 'contrib',
        'label' => 'Webform',
        'description' => 'Enables the creation of webforms and questionnaires.',
        'package' => 'Webform',
        'version' => '6.3.0',
        'dependencies' => ['drupal:field', 'drupal:user'],
        'path' => 'modules/contrib/webform',
        'project_path' => 'web/modules/contrib/webform',
      ],
      'services' => self::listed([['id' => 'webform.request', 'class' => 'Drupal\\webform\\WebformRequest']]),
      'routes' => self::listed([['name' => 'entity.webform.canonical', 'path' => '/form/{webform}', 'handler' => '']]),
      'patterns' => self::listed([
        ['kind' => 'plugin', 'name' => 'webform_element', 'fqcn' => 'Drupal\\webform\\Plugin\\WebformElementBase'],
      ]),
      'extension_points' => [
        'available' => TRUE,
        'plugin_types' => [
          ['type' => 'webform_element', 'example' => 'Drupal\\webform\\Plugin\\WebformElement\\TextField'],
        ],
        'hooks' => ['hook_webform_submission_form_alter', 'hook_webform_element_alter'],
      ],
      'usage' => ['available' => TRUE, 'by' => [['module' => 'kchockey_contact', 'edges' => 3]]],
      'graph' => [
        'available' => TRUE,
        'depends_on' => [['module' => 'core', 'edges' => 120, 'symbols' => 40]],
        'used_by' => [['module' => 'kchockey_contact', 'edges' => 3, 'symbols' => 2]],
      ],
      'docs' => ['available' => TRUE, 'files' => ['README.md'], 'primary' => 'README.md'],
    ];
  }

  /**
   * A factsheet list section holding these items.
   *
   * @param list<array<string, string>> $items
   *   The items.
   *
   * @return array<string, mixed>
   *   The section.
   */
  private static function listed(array $items): array {
    return ['available' => TRUE, 'items' => $items, 'total' => count($items), 'clipped' => FALSE];
  }

  /**
   * The same factsheet renders the same bytes.
   */
  public function testRenderIsDeterministic(): void {
    $renderer = new PageRenderer();
    $first = $renderer->render('webform', self::contribFactsheet());
    $this->assertSame($first, $renderer->render('webform', self::contribFactsheet()));
    $this->assertStringEndsWith("\n", $first);
    $this->assertStringEndsNotWith("\n\n", $first);
  }

  /**
   * Every measured section appears, in the factsheet's own facts.
   */
  public function testEverySectionRenders(): void {
    $page = (new PageRenderer())->render('webform', self::contribFactsheet());
    foreach ([
      '# Webform (`webform`)',
      'Enables the creation of webforms and questionnaires.',
      'rendered from droost\'s factsheet for the module, with no model',
      '| Owner | contrib |',
      '| Version | 6.3.0 |',
      '| Path | `web/modules/contrib/webform` |',
      '| Depends on | `drupal:field`, `drupal:user` |',
      '### Services (1)',
      '| `webform.request` | `Drupal\\webform\\WebformRequest` |',
      '| `entity.webform.canonical` | `/form/{webform}` | — |',
      '### Plugins and other patterns (1)',
      '## What it lets other code extend',
      '| `webform_element` | `Drupal\\webform\\Plugin\\WebformElement\\TextField` |',
      '- `hook_webform_submission_form_alter`',
      '## How this project uses it',
      '| `kchockey_contact` | 3 |',
      '### It reaches into',
      '| `core` | 120 | 40 |',
      '- `README.md`',
    ] as $expected) {
      $this->assertStringContainsString($expected, $page);
    }
    $this->assertStringNotContainsString('## The theme', $page, 'a module has no theme section');
  }

  /**
   * An unmeasured section says why, never reads as empty.
   */
  public function testUnmeasuredSectionsSayWhy(): void {
    $factsheet = self::contribFactsheet();
    $factsheet['patterns'] = [
      'available' => FALSE,
      'reason' => 'the brain is not built — run "drush droost:brain:build" first',
    ];
    $factsheet['graph'] = [
      'available' => FALSE,
      'reason' => 'the code graph is not built — run "drush droost:search:index" first',
    ];
    $factsheet['usage'] = ['available' => FALSE, 'reason' => 'the code graph is not built'];
    $page = (new PageRenderer())->render('webform', $factsheet);
    $this->assertStringContainsString('Not listed: the brain is not built', $page);
    $this->assertStringContainsString('Not measured: the code graph is not built — run "drush droost:search:index" first.', $page);
    $this->assertStringNotContainsString('### It reaches into', $page);
  }

  /**
   * A theme renders its base theme, regions, libraries and components.
   */
  public function testThemeSection(): void {
    $page = (new PageRenderer())->render('kc_theme', [
      'identity' => ['kind' => 'theme', 'label' => 'KC Theme', 'provenance' => 'custom'],
      'theme' => [
        'base_theme' => 'olivero',
        'regions' => ['header' => 'Header', 'content' => 'Content'],
        'libraries' => ['kc_theme/global'],
        'components' => ['kc_theme:card'],
        'templates' => 4,
      ],
    ]);
    foreach ([
      'for the theme, with no model',
      '| Kind | theme |',
      '## The theme',
      'Base theme: `olivero`.',
      '| `header` | Header |',
      '### Libraries (1)',
      '- kc_theme/global',
      '### Components (1)',
      '4 Twig template(s)',
      'No services, routes or harvested patterns.',
    ] as $expected) {
      $this->assertStringContainsString($expected, $page);
    }
  }

  /**
   * Table cells cannot break the table, and long lists are counted.
   */
  public function testCellsAreEscapedAndLongListsCounted(): void {
    $items = [];
    for ($i = 0; $i < PageRenderer::MAX_ROWS + 5; $i++) {
      $items[] = ['id' => sprintf('svc.%02d', $i), 'class' => 'A|B'];
    }
    $page = (new PageRenderer())->render('big', [
      'identity' => ['label' => "Big\nModule | x"],
      'services' => ['available' => TRUE, 'items' => $items, 'total' => count($items)],
    ]);
    $this->assertStringContainsString('# Big Module | x (`big`)', $page, 'a label is one line');
    $this->assertStringContainsString(sprintf('### Services (%d)', PageRenderer::MAX_ROWS + 5), $page);
    $this->assertStringContainsString('5 more not shown.', $page);
    $this->assertStringNotContainsString('svc.44', $page);
    $this->assertStringContainsString('| `svc.00` | `A\\|B` |', $page, 'a pipe inside a code span is escaped too');
  }

}
