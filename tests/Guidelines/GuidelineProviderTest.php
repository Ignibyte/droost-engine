<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Guidelines;

use Droost\Engine\Guidelines\GuidelineProvider;
use Droost\Engine\Site\ExtensionLocatorInterface;
use Droost\Engine\Site\UnknownSite;
use Droost\Engine\Tests\Site\FakeSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers scoping the guidelines catalog to the site it is describing.
 *
 * The behaviour worth pinning is not "files are read" — it is the asymmetry
 * between the three answers a site can give. Installed shows, absent hides
 * from the catalog but still serves by name with a warning, and UNKNOWN shows.
 * Collapsing the third into the second is the failure this class exists to
 * prevent, and it is invisible without a test: guidance silently missing from
 * a plain checkout looks exactly like guidance that was never written.
 */
final class GuidelineProviderTest extends TestCase {

  /**
   * A temp app root holding the shipped corpus and a contributing extension.
   */
  private string $appRoot;

  /**
   * The shipped corpus directory.
   */
  private string $corpus;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/droost_guidelines_' . uniqid('', TRUE);
    $this->corpus = $this->appRoot . '/modules/droost/guidelines';

    mkdir($this->corpus . '/core', 0777, TRUE);
    mkdir($this->corpus . '/topics/11', 0777, TRUE);
    file_put_contents($this->corpus . '/core/conventions.md', "# Conventions\n\nAlways inject.\n");
    // A topic that applies everywhere, and two that are module-conditional.
    file_put_contents($this->corpus . '/topics/routing.md', "# Routing\n\nRoutes and access.\n");
    file_put_contents($this->corpus . '/topics/media.md', "# Media\n\nMedia entities.\n");
    file_put_contents($this->corpus . '/topics/taxonomy.md', "# Taxonomy\n\nVocabularies.\n");
    // A per-major variant of an unconditional topic.
    file_put_contents($this->corpus . '/topics/11/routing.md', "# Routing 11\n\nThe 11 flavour.\n");

    // A third-party extension contributing its own topic.
    mkdir($this->appRoot . '/modules/acme/guidelines/topics', 0777, TRUE);
    file_put_contents($this->appRoot . '/modules/acme/guidelines/topics/acme.md', "# Acme\n\nAcme's own guidance.\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->deleteTree($this->appRoot);
    parent::tearDown();
  }

  /**
   * A site that HAS the module gets the topic, unqualified.
   */
  public function testInstalledTopicIsListedAndServedBare(): void {
    $provider = $this->provider(new FakeSite(['media' => 'modules/media']));

    $this->assertContains('media', $this->topicNames($provider));
    $topic = $provider->getTopic('media');
    $this->assertIsString($topic);
    $this->assertStringStartsWith('# Media', $topic);
  }

  /**
   * A site that lacks the module does not see it advertised.
   */
  public function testAbsentTopicIsNotListed(): void {
    $provider = $this->provider(new FakeSite(['acme' => 'modules/acme']));

    $names = $this->topicNames($provider);
    $this->assertNotContains('media', $names, 'The catalog describes what is actionable HERE.');
    $this->assertNotContains('taxonomy', $names);
    $this->assertContains('routing', $names, 'An unconditional topic always applies.');
  }

  /**
   * Asked for by name, an absent topic is served — but labelled.
   *
   * An agent may be deciding whether to install the module, so withholding
   * the guidance helps nobody. What must not happen is it reading as a
   * description of this site.
   */
  public function testAbsentTopicServedByNameCarriesTheWarning(): void {
    $provider = $this->provider(new FakeSite([]));

    $topic = $provider->getTopic('media');
    $this->assertIsString($topic);
    $this->assertStringContainsString('NOT INSTALLED ON THIS SITE', $topic);
    $this->assertStringContainsString('the media module is not enabled here', $topic);
    $this->assertStringContainsString('# Media', $topic, 'The guidance itself is still there.');
  }

  /**
   * A topic can declare its own module, without an edit to this package.
   *
   * The shipped map only reaches topics this package owns, so gating a topic
   * about a contrib module used to need a change and a release HERE. Any
   * module can drop a topic into guidelines/topics/; it must be able to say
   * what that topic is about, or the only shippable contrib topic is an
   * ungated one that tells every site how to use something it may not have.
   */
  public function testTopicDeclaresItsOwnRequirement(): void {
    file_put_contents(
      $this->corpus . '/topics/eca.md',
      "<!-- droost:requires eca -->\n# ECA\n\nEvent-condition-action.\n",
    );

    $absent = $this->provider(new FakeSite([]));
    $this->assertNotContains('eca', $this->topicNames($absent), 'Declared and absent: pruned.');

    $present = $this->provider(new FakeSite(['eca' => 'modules/eca']));
    $this->assertContains('eca', $this->topicNames($present), 'Declared and present: listed.');
  }

  /**
   * The declaration names the module in the not-installed warning.
   */
  public function testDeclaredRequirementNamesTheModuleInTheWarning(): void {
    file_put_contents(
      $this->corpus . '/topics/eca.md',
      "<!-- droost:requires eca -->\n# ECA\n\nEvent-condition-action.\n",
    );

    $topic = $this->provider(new FakeSite([]))->getTopic('eca');
    $this->assertIsString($topic);
    $this->assertStringContainsString('the eca module is not enabled here', $topic);
  }

  /**
   * The marker never reaches an agent, or the catalog.
   *
   * It is metadata about the topic, not part of it. Served, it would put a
   * machine-readable directive into an agent's context; in the catalog it
   * would BE the summary, because it is the first line that is not a heading.
   */
  public function testRequirementMarkerIsStrippedFromBodyAndSummary(): void {
    file_put_contents(
      $this->corpus . '/topics/eca.md',
      "<!-- droost:requires eca -->\n# ECA\n\nEvent-condition-action.\n",
    );
    $provider = $this->provider(new FakeSite(['eca' => 'modules/eca']));

    $topic = $provider->getTopic('eca');
    $this->assertIsString($topic);
    $this->assertStringStartsWith('# ECA', $topic);
    $this->assertStringNotContainsString('droost:requires', $topic);

    foreach ($provider->listTopics() as $row) {
      if ($row['name'] === 'eca') {
        $this->assertSame('Event-condition-action.', $row['summary']);
        return;
      }
    }
    $this->fail('The declared topic was not listed.');
  }

  /**
   * A topic's own declaration beats the shipped map.
   */
  public function testDeclarationOverridesTheShippedMap(): void {
    file_put_contents(
      $this->corpus . '/topics/media.md',
      "<!-- droost:requires acme -->\n# Media\n\nMedia entities.\n",
    );

    $names = $this->topicNames($this->provider(new FakeSite(['acme' => 'modules/acme'])));
    $this->assertContains('media', $names, 'The declaration decides, not the map entry for "media".');
  }

  /**
   * With no site to ask, everything applies.
   *
   * The load-bearing case. UnknownSite answers NULL, and NULL must not prune —
   * otherwise a plain checkout reports a corpus half its real size and nothing
   * anywhere says why.
   */
  public function testUnknownSiteShowsEveryTopic(): void {
    $provider = $this->provider(new UnknownSite('11.4.2'));

    $names = $this->topicNames($provider);
    $this->assertContains('media', $names);
    $this->assertContains('taxonomy', $names);
    $this->assertContains('routing', $names);
    $topic = $provider->getTopic('media');
    $this->assertIsString($topic);
    $this->assertStringStartsWith('# Media', $topic, 'Unknown is not "absent", so nothing is disclaimed.');
  }

  /**
   * An installed extension's own topics join the catalog.
   */
  public function testContributedTopicsJoinTheCatalog(): void {
    $provider = $this->provider(new FakeSite(['acme' => 'modules/acme']));

    $this->assertContains('acme', $this->topicNames($provider));
    $topic = $provider->getTopic('acme');
    $this->assertIsString($topic);
    $this->assertStringContainsString("Acme's own guidance", $topic);
  }

  /**
   * An extension listed as installed but missing on disk is skipped, not fatal.
   */
  public function testUnresolvableExtensionDoesNotBreakDiscovery(): void {
    $provider = $this->provider(new FakeSite(['ghost' => '', 'acme' => 'modules/acme']));

    $names = $this->topicNames($provider);
    $this->assertContains('acme', $names, 'Discovery continued past the ghost.');
    $this->assertContains('routing', $names);
  }

  /**
   * The per-major variant wins over the unversioned baseline.
   */
  public function testPerMajorVariantWins(): void {
    $provider = $this->provider(new FakeSite([], '11.4.2'));

    $topic = $provider->getTopic('routing');
    $this->assertIsString($topic);
    $this->assertStringStartsWith('# Routing 11', $topic);
  }

  /**
   * Without a known version there is no variant to prefer.
   */
  public function testUnknownVersionFallsBackToTheBaseline(): void {
    $provider = $this->provider(new UnknownSite());

    $topic = $provider->getTopic('routing');
    $this->assertIsString($topic);
    $this->assertStringStartsWith("# Routing\n", $topic);
    $this->assertStringContainsString('Drupal version unknown', $provider->getCoreStamped());
  }

  /**
   * A core major with no variant directory falls back to the baseline.
   *
   * Distinct from the unknown-version case: here the site answered, and the
   * answer simply has no tailored content — which must serve the baseline
   * rather than nothing.
   */
  public function testMismatchedMajorFallsBackToTheBaseline(): void {
    $provider = $this->provider(new FakeSite([], '12.0.0'));

    $topic = $provider->getTopic('routing');
    $this->assertIsString($topic);
    $this->assertStringStartsWith("# Routing\n", $topic);
  }

  /**
   * A contributed topic version-branches the same way the shipped ones do.
   */
  public function testContributedTopicsVersionBranch(): void {
    $dir = $this->appRoot . '/modules/acme/guidelines/topics/11';
    mkdir($dir, 0777, TRUE);
    file_put_contents($dir . '/acme.md', "# Acme 11\n\nThe 11 flavour.\n");

    $topic = $this->provider(new FakeSite(['acme' => 'modules/acme'], '11.4.2'))->getTopic('acme');
    $this->assertIsString($topic);
    $this->assertStringStartsWith('# Acme 11', $topic);
  }

  /**
   * A version directory is never itself listed as a topic.
   */
  public function testVersionDirectoryIsNeverListed(): void {
    $names = $this->topicNames($this->provider(new FakeSite([], '11.4.2')));

    $this->assertNotContains('11', $names);
    $this->assertSame(array_unique($names), $names);
  }

  /**
   * The major derives from the version string, malformed disabling it.
   *
   * @param string $version
   *   The version string.
   * @param string $expected
   *   The expected major.
   */
  #[DataProvider('majorCases')]
  public function testDeriveMajor(string $version, string $expected): void {
    $this->assertSame($expected, GuidelineProvider::deriveMajor($version));
  }

  /**
   * Version-derivation cases.
   *
   * @return array<int, array{string, string}>
   *   [version, expected major].
   */
  public static function majorCases(): array {
    return [
      ['11.4.2', '11'],
      ['10.3.0', '10'],
      ['12.0.0-dev', '12'],
      ['9.5.11', '9'],
      ['abc', ''],
      ['', ''],
      ['x.1', ''],
    ];
  }

  /**
   * The corpus owner does not contribute its own directory twice.
   */
  public function testOwnerIsNotEnumeratedTwice(): void {
    $provider = $this->provider(new FakeSite(['droost' => 'modules/droost']));

    $names = array_column($provider->listTopics(), 'name');
    $this->assertSame(array_unique($names), $names, 'Each topic appears once.');
  }

  /**
   * The stamped core carries the version and the directive.
   */
  public function testCoreIsStampedWithTheRunningVersion(): void {
    $stamped = $this->provider(new FakeSite([], '11.4.2'))->getCoreStamped();

    $this->assertStringContainsString('Drupal 11.4.2', $stamped);
    $this->assertStringContainsString('Use Droost first', $stamped);
    $this->assertStringContainsString('Always inject.', $stamped);
  }

  /**
   * Builds a provider over the fixture corpus.
   *
   * @param \Droost\Engine\Site\ExtensionLocatorInterface $site
   *   The site to scope to.
   *
   * @return \Droost\Engine\Guidelines\GuidelineProvider
   *   The provider.
   */
  private function provider(ExtensionLocatorInterface $site): GuidelineProvider {
    return new GuidelineProvider($this->appRoot, $this->corpus, $site);
  }

  /**
   * The machine names in the catalog.
   *
   * @param \Droost\Engine\Guidelines\GuidelineProvider $provider
   *   The provider.
   *
   * @return list<string>
   *   The topic names.
   */
  private function topicNames(GuidelineProvider $provider): array {
    return array_values(array_column($provider->listTopics(), 'name'));
  }

  /**
   * Removes a directory tree.
   *
   * @param string $dir
   *   The directory.
   */
  private function deleteTree(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var \SplFileInfo $item */
    foreach ($items as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
  }

}
