<?php

declare(strict_types=1);

namespace Droost\Engine\Tests\Harness;

use Droost\Engine\Harness\CommandProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the slash-command provider.
 *
 * Commands are copied verbatim and keyed by basename; a missing directory is
 * a consumer that ships none, not an error.
 */
#[CoversClass(CommandProvider::class)]
final class CommandProviderTest extends TestCase {

  /**
   * Commands come back verbatim, keyed by name, sorted, md-only.
   */
  public function testReadsCommandsVerbatimByName(): void {
    $dir = sys_get_temp_dir() . '/droost-cmd-' . bin2hex(random_bytes(6));
    mkdir($dir, 0755, TRUE);
    file_put_contents($dir . '/droost-init.md', "---\ntitle: Init\n---\nBody.\n");
    file_put_contents($dir . '/droost-upgrade.md', "Upgrade.\n");
    file_put_contents($dir . '/notes.txt', 'not a command');

    $commands = (new CommandProvider($dir))->getCommands();

    $this->assertSame(['droost-init', 'droost-upgrade'], array_keys($commands));
    $this->assertSame("---\ntitle: Init\n---\nBody.\n", $commands['droost-init']);

    unlink($dir . '/droost-init.md');
    unlink($dir . '/droost-upgrade.md');
    unlink($dir . '/notes.txt');
    rmdir($dir);
  }

  /**
   * A missing directory yields no commands and no error.
   */
  public function testMissingDirectoryShipsNothing(): void {
    $provider = new CommandProvider(sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(6)));
    $this->assertSame([], $provider->getCommands());
  }

}
