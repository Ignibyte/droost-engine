<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Site;

use Droost\Engine\Site\Provenance;
use Droost\Engine\Site\ScopeClassifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the one answer to "who owns this extension's code".
 */
#[CoversClass(ScopeClassifier::class)]
#[CoversClass(Provenance::class)]
final class ScopeClassifierTest extends TestCase {

  private const string ROOT = '/app/web';

  /**
   * A classifier over the given packages, in getAllRawData()'s shape.
   *
   * @param array<string, array{string, string}> $packages
   *   Package name => [type, install path as composer records it].
   *
   * @return \Droost\Engine\Site\ScopeClassifier
   *   The classifier.
   */
  private static function site(array $packages): ScopeClassifier {
    $versions = [];
    foreach ($packages as $name => [$type, $path]) {
      $versions[$name] = ['type' => $type, 'install_path' => $path, 'dev_requirement' => FALSE];
    }
    return ScopeClassifier::fromInstalledData(self::ROOT, [['root' => [], 'versions' => $versions]]);
  }

  /**
   * A site shaped like a composer project with the usual installer-paths.
   *
   * @return \Droost\Engine\Site\ScopeClassifier
   *   The classifier.
   */
  private static function composerSite(): ScopeClassifier {
    return self::site([
      'drupal/core' => ['drupal-core', '/app/vendor/composer/../../web/core'],
      'drupal/webform' => ['drupal-module', '/app/vendor/composer/../../web/modules/contrib/webform'],
      'drupal/gin' => ['drupal-theme', '/app/vendor/composer/../../web/themes/contrib/gin'],
      'drupal/cms_installer' => ['drupal-profile', '/app/vendor/composer/../../web/profiles/contrib/cms_installer'],
      'acme/tools' => ['drupal-custom-module', '/app/vendor/composer/../../web/modules/custom/acme_tools'],
      'symfony/yaml' => ['library', '/app/vendor/composer/../symfony/yaml'],
    ]);
  }

  /**
   * Every rule, on a site composer built.
   *
   * @param string $path
   *   The extension path relative to the app root.
   * @param \Droost\Engine\Site\Provenance $expected
   *   The provenance.
   */
  #[DataProvider('composerSiteCases')]
  public function testComposerSite(string $path, Provenance $expected): void {
    $this->assertSame($expected, self::composerSite()->classify($path));
  }

  /**
   * Paths on a composer-built site and who owns each.
   *
   * @return array<string, array{string, \Droost\Engine\Site\Provenance}>
   *   Case => [path, provenance].
   */
  public static function composerSiteCases(): array {
    return [
      'core module' => ['core/modules/node', Provenance::Core],
      'core theme' => ['core/themes/olivero', Provenance::Core],
      'contrib module' => ['modules/contrib/webform', Provenance::Contrib],
      'contrib submodule belongs to its project' => ['modules/contrib/webform/modules/webform_ui', Provenance::Contrib],
      'contrib theme' => ['themes/contrib/gin', Provenance::Contrib],
      'contrib profile' => ['profiles/contrib/cms_installer', Provenance::Contrib],
      'custom package' => ['modules/custom/acme_tools', Provenance::Custom],
      'hand-placed under custom' => ['modules/custom/kc_contact', Provenance::Custom],
      'hand-placed theme under custom' => ['themes/custom/kc_theme', Provenance::Custom],
      'hand-placed with no segment is the project\'s own' => ['modules/legacy_tool', Provenance::Custom],
      'a contrib folder composer does not own still says contrib' => ['modules/contrib/orphan', Provenance::Contrib],
      'the first segment decides' => ['modules/custom/acme/modules/contrib/inner', Provenance::Custom],
      'a dot prefix and a trailing slash are read through' => ['./modules/contrib/webform/', Provenance::Contrib],
      'a lookalike segment is not contrib' => ['modules/contributed/x', Provenance::Custom],
    ];
  }

  /**
   * Installer-paths with no contrib folder: composer, not the path, decides.
   *
   * The case the path-only classifiers got wrong. Every one of them filed
   * these as custom.
   */
  public function testInstallerPathsWithoutContribFolder(): void {
    $site = self::site([
      'drupal/webform' => ['drupal-module', '/app/web/modules/webform'],
      'drupal/gin' => ['drupal-theme', '/app/web/themes/gin'],
      'acme/tools' => ['drupal-custom-module', '/app/web/modules/acme_tools'],
    ]);
    $this->assertSame(Provenance::Contrib, $site->classify('modules/webform'));
    $this->assertSame(Provenance::Contrib, $site->classify('modules/webform/modules/webform_ui'));
    $this->assertSame(Provenance::Contrib, $site->classify('themes/gin'));
    $this->assertSame(Provenance::Custom, $site->classify('modules/acme_tools'));
    $this->assertSame(Provenance::Custom, $site->classify('modules/kc_contact'));
  }

  /**
   * A package at or above the app root owns nothing.
   *
   * A module's own CI checkout makes the module the root package, installed
   * at the project root, and that must not turn every extension into contrib.
   */
  public function testThePackageHoldingTheDocrootOwnsNothing(): void {
    $site = self::site([
      'drupal/droost' => ['drupal-module', '/app/vendor/composer/../..'],
      'drupal/weird' => ['drupal-module', '/app/web'],
    ]);
    $this->assertTrue($site->knowsComposer());
    $this->assertSame(Provenance::Custom, $site->classify('modules/custom/droost'));
    $this->assertSame(Provenance::Custom, $site->classify('modules/tool'));
  }

  /**
   * Only drupal-* packages own directories.
   */
  public function testNonDrupalPackagesOwnNothing(): void {
    $site = self::site([
      'acme/lib' => ['library', '/app/web/modules/vendored_lib'],
      'acme/plugin' => ['composer-plugin', '/app/web/modules/contrib/plugin_dir'],
    ]);
    $this->assertSame(Provenance::Custom, $site->classify('modules/vendored_lib'));
    $this->assertSame(Provenance::Contrib, $site->classify('modules/contrib/plugin_dir'));
  }

  /**
   * Nothing is resolved on disk: a linked package is found at its link.
   *
   * Composer records a path repository's symlink by where it put the link.
   * None of these paths exist, so any realpath() would lose them.
   */
  public function testPathsAreComparedAsWrittenNotResolved(): void {
    $droost = [
      'type' => 'drupal-module',
      'install_path' => '/nowhere/vendor/composer/../../web/modules/contrib/droost',
    ];
    $site = ScopeClassifier::fromInstalledData('/nowhere/web/./', [['versions' => ['drupal/droost' => $droost]]]);
    $this->assertSame(Provenance::Contrib, $site->classify('modules/contrib/droost'));
    $this->assertSame(Provenance::Contrib, $site->classify('modules/custom/../contrib/droost'));
  }

  /**
   * Malformed composer data is skipped, never fatal.
   */
  public function testMalformedDataIsSkipped(): void {
    $versions = [
      'a/b' => 'nope',
      'c/d' => ['type' => 'drupal-module'],
      'e/f' => ['type' => 'drupal-module', 'install_path' => ''],
      'g/h' => ['type' => 7, 'install_path' => '/app/web/modules/x'],
    ];
    $site = ScopeClassifier::fromInstalledData(self::ROOT, [
      'not an array',
      ['versions' => 'nope'],
      ['versions' => $versions],
    ]);
    $this->assertTrue($site->knowsComposer());
    $this->assertSame(Provenance::Custom, $site->classify('modules/x'));
  }

  /**
   * Without composer's record, a path that names no owner is unknown.
   *
   * @param string $path
   *   The extension path relative to the app root.
   * @param \Droost\Engine\Site\Provenance $expected
   *   The provenance.
   */
  #[DataProvider('pathOnlyCases')]
  public function testWithoutComposer(string $path, Provenance $expected): void {
    $site = ScopeClassifier::withoutComposer();
    $this->assertFalse($site->knowsComposer());
    $this->assertSame($expected, $site->classify($path));
  }

  /**
   * Paths with no composer record.
   *
   * @return array<string, array{string, \Droost\Engine\Site\Provenance}>
   *   Case => [path, provenance].
   */
  public static function pathOnlyCases(): array {
    return [
      'core' => ['core/modules/system', Provenance::Core],
      'contrib' => ['modules/contrib/webform', Provenance::Contrib],
      'custom' => ['themes/custom/kc_theme', Provenance::Custom],
      'no segment' => ['modules/webform', Provenance::Unknown],
    ];
  }

  /**
   * The running site's classifier reads composer's runtime record.
   *
   * This repository's own vendor directory holds no Drupal package below
   * the given root, so the record is read and owns nothing there.
   */
  public function testForAppRootReadsComposer(): void {
    $site = ScopeClassifier::forAppRoot('/nowhere/web');
    $this->assertTrue($site->knowsComposer());
    $this->assertSame(Provenance::Custom, $site->classify('modules/tool'));
    $this->assertSame(Provenance::Core, $site->classify('core/modules/node'));
  }

  /**
   * The scopes droost stores: unknown is held to the custom bar.
   */
  public function testScopes(): void {
    $this->assertSame('core', Provenance::Core->scope());
    $this->assertSame('contrib', Provenance::Contrib->scope());
    $this->assertSame('custom', Provenance::Custom->scope());
    $this->assertSame('custom', Provenance::Unknown->scope());

    $this->assertSame('core', Provenance::Core->indexScope(TRUE));
    $this->assertSame('themes', Provenance::Contrib->indexScope(TRUE));
    $this->assertSame('themes', Provenance::Custom->indexScope(TRUE));
    $this->assertSame('contrib', Provenance::Contrib->indexScope(FALSE));
    $this->assertSame('custom', Provenance::Unknown->indexScope(FALSE));

    $this->assertTrue(Provenance::Core->isManaged());
    $this->assertTrue(Provenance::Contrib->isManaged());
    $this->assertFalse(Provenance::Custom->isManaged());
    $this->assertFalse(Provenance::Unknown->isManaged());
  }

}
