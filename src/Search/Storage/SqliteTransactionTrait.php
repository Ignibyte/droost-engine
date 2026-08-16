<?php

declare(strict_types=1);

namespace Droost\Engine\Search\Storage;

/**
 * Runs a unit of work in a PDO transaction, rolling back on any throwable.
 *
 * Shared by the SQLite stores because the ownership rule is the part worth
 * getting right once: PDO throws on a nested begin, so a caller that already
 * opened a transaction keeps it, and this neither commits nor rolls back
 * someone else's.
 */
trait SqliteTransactionTrait {

  /**
   * Runs work inside a transaction.
   *
   * @param \PDO $pdo
   *   The connection.
   * @param callable():void $work
   *   The work.
   *
   * @throws \Throwable
   *   Whatever the work threw, after the rollback.
   */
  private function runTransactional(\PDO $pdo, callable $work): void {
    $owns = !$pdo->inTransaction();
    if ($owns) {
      $pdo->beginTransaction();
    }
    try {
      $work();
      if ($owns) {
        $pdo->commit();
      }
    }
    catch (\Throwable $e) {
      if ($owns && $pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

}
