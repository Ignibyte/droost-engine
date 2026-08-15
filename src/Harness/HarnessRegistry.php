<?php

declare(strict_types=1);

namespace Droost\Engine\Harness;

use Droost\Engine\Guidelines\GuidelineProvider;

/**
 * Resolves and runs the set of harness installers for an install/uninstall.
 *
 * AGENTS.md is always written first (it is the source of truth every other
 * harness file points at).
 */
final class HarnessRegistry {

  /**
   * Installers keyed by harness id.
   *
   * @var array<string, \Droost\Engine\Harness\HarnessInstallerInterface>
   */
  private array $installers = [];

  /**
   * Constructs a HarnessRegistry.
   *
   * @param \Droost\Engine\Guidelines\GuidelineProvider $guidelines
   *   The guideline provider (supplies the block body).
   * @param \Droost\Engine\Harness\HarnessInstallerInterface ...$installers
   *   The available harness installers.
   */
  public function __construct(
    private readonly GuidelineProvider $guidelines,
    HarnessInstallerInterface ...$installers,
  ) {
    foreach ($installers as $installer) {
      $this->installers[$installer->getId()] = $installer;
    }
  }

  /**
   * Returns all registered installers keyed by id.
   *
   * @return array<string, \Droost\Engine\Harness\HarnessInstallerInterface>
   *   The installers.
   */
  public function all(): array {
    return $this->installers;
  }

  /**
   * Resolves which installers to run for a `--harness` selection.
   *
   * @param string $harness
   *   The selection: "auto" (detect), "all", or a comma-separated list of ids.
   * @param string $root
   *   The project root (for detection).
   *
   * @return array<string, \Droost\Engine\Harness\HarnessInstallerInterface>
   *   The resolved installers, always including AGENTS.md.
   *
   * @throws \InvalidArgumentException
   *   If an unknown harness id is requested.
   */
  public function resolve(string $harness, string $root): array {
    $harness = trim($harness);
    if ($harness === 'all') {
      return $this->installers;
    }
    if ($harness === '' || $harness === 'auto') {
      return $this->detect($root);
    }
    $selected = ['agents' => $this->installers['agents']];
    foreach (array_filter(array_map(trim(...), explode(',', $harness))) as $id) {
      if (!isset($this->installers[$id])) {
        throw new \InvalidArgumentException(sprintf('Unknown harness "%s". Known: %s.', $id, implode(', ', array_keys($this->installers))));
      }
      $selected[$id] = $this->installers[$id];
    }
    return $selected;
  }

  /**
   * Detects in-use harnesses (always includes AGENTS.md; baseline fallback).
   *
   * @param string $root
   *   The project root.
   *
   * @return array<string, \Droost\Engine\Harness\HarnessInstallerInterface>
   *   The detected installers.
   */
  public function detect(string $root): array {
    $detected = [];
    foreach ($this->installers as $id => $installer) {
      if ($id === 'agents' || $installer->isDetected($root)) {
        $detected[$id] = $installer;
      }
    }
    // Nothing but AGENTS.md detected — fall back to the Claude baseline.
    if (count($detected) === 1 && isset($this->installers['claude'])) {
      $detected['claude'] = $this->installers['claude'];
    }
    return $detected;
  }

  /**
   * Installs the MCP server + guidance across the resolved harnesses.
   *
   * @param string $root
   *   The project root.
   * @param array{command: string, args: array<int, string>} $server
   *   The MCP server launch command + args.
   * @param string $harness
   *   The harness selection (auto|all|csv).
   * @param string $guidelinesMode
   *   One of block, pointer, or none.
   *
   * @return \Droost\Engine\Harness\InstallResult
   *   The aggregated result.
   */
  public function install(string $root, array $server, string $harness, string $guidelinesMode): InstallResult {
    $context = new InstallContext(
      $server['command'],
      $server['args'],
      $guidelinesMode,
      $this->guidelines->getGuidelinesBlockBody(),
    );
    $result = new InstallResult();
    foreach ($this->ordered($this->resolve($harness, $root)) as $installer) {
      $installer->install($root, $context, $result);
    }
    return $result;
  }

  /**
   * Uninstalls Droost regions/entries across the resolved harnesses.
   *
   * @param string $root
   *   The project root.
   * @param string $harness
   *   The harness selection (auto|all|csv).
   *
   * @return \Droost\Engine\Harness\InstallResult
   *   The aggregated result.
   */
  public function uninstall(string $root, string $harness): InstallResult {
    $result = new InstallResult();
    // On uninstall, target all harnesses (auto-detect would miss removed dirs).
    $installers = $harness === 'auto' || $harness === '' ? $this->installers : $this->resolve($harness, $root);
    foreach ($this->ordered($installers) as $installer) {
      $installer->uninstall($root, $result);
    }
    return $result;
  }

  /**
   * Orders installers so AGENTS.md runs first.
   *
   * @param array<string, \Droost\Engine\Harness\HarnessInstallerInterface> $installers
   *   The installers to order.
   *
   * @return array<int, \Droost\Engine\Harness\HarnessInstallerInterface>
   *   The ordered installers.
   */
  private function ordered(array $installers): array {
    $ordered = [];
    if (isset($installers['agents'])) {
      $ordered[] = $installers['agents'];
    }
    foreach ($installers as $id => $installer) {
      if ($id !== 'agents') {
        $ordered[] = $installer;
      }
    }
    return $ordered;
  }

}
