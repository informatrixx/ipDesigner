<?php
declare(strict_types=1);

namespace IpDesigner;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use WeakReference;

/** Request-scoped restore barrier; deliberately independent of SQL transactions. */
final class PlanningWriteGuard
{
    /** @var resource|null */
    private $lock = null;

    private function __construct() {}

    /** Legacy schema 15 has no epoch; malformed or missing schema-16 state fails closed. */
    public static function epoch(PDO $db): ?string
    {
        try {
            $type = $db->query("SELECT type FROM sqlite_master WHERE name='planning_state'")->fetchColumn();
            if ($type === false) {
                $migrations = $db->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn();
                if ($migrations && (int)$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() >= 16) {
                    throw new DomainException('Planungsschutz fehlt trotz aktuellem Datenbankschema. Bitte die Datenbank prüfen.');
                }
                return null;
            }
            if ($type !== 'table') throw new DomainException('Planungsschutz ist beschädigt. Bitte die Datenbank prüfen.');
            $rows = $db->query('SELECT id,restore_epoch FROM planning_state')->fetchAll(PDO::FETCH_ASSOC);
            $epoch = $rows[0]['restore_epoch'] ?? null;
            if (count($rows) !== 1 || (int)($rows[0]['id'] ?? 0) !== 1 || !is_string($epoch)
                || $epoch === '' || strlen($epoch) > 256 || preg_match('/[\x00-\x20\x7f]/', $epoch)) {
                throw new DomainException('Planungsschutz ist beschädigt. Bitte die Datenbank prüfen.');
            }
            return $epoch;
        } catch (PDOException $error) {
            throw new DomainException('Planungsschutz kann nicht gelesen werden. Bitte die Datenbank prüfen.', 0, $error);
        }
    }

    /** Hold SH for ordinary writes, EX for restore, until release/destruction/request shutdown. */
    public static function enter(PDO $db, ?string $expectedEpoch, bool $restore = false, int $timeoutMs = 5000): self
    {
        if ($timeoutMs < 0 || $timeoutMs > 5000) throw new InvalidArgumentException('Sperrzeit muss zwischen 0 und 5000 ms liegen.');
        if ($db->inTransaction()) throw new DomainException('Planungsschutz muss vor Beginn der Datenbanktransaktion aktiviert werden.');
        $guard = new self();
        if (self::epoch($db) === null) return $guard;
        try {
            $file = '';
            foreach ($db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $database) {
                if ($database['name'] === 'main') { $file = (string)$database['file']; break; }
            }
            // SQLite in-memory databases have no shared filesystem identity.
            if ($file !== '') {
                $canonical = realpath($file);
                if ($canonical === false) throw new DomainException('Planungsdatenbank für den Schreibschutz nicht erreichbar.');
                $guard->lock = @fopen($canonical.'.planning.lock', 'c');
                if ($guard->lock === false) {
                    $guard->lock = null;
                    throw new DomainException('Planungsschutz kann nicht geöffnet werden. Bitte Verzeichnisrechte prüfen.');
                }
                $deadline = hrtime(true) + $timeoutMs * 1000000;
                do {
                    $wouldBlock = 0;
                    if (flock($guard->lock, ($restore ? LOCK_EX : LOCK_SH) | LOCK_NB, $wouldBlock)) break;
                    if (!$wouldBlock) throw new DomainException('Planungsschutz kann nicht gesperrt werden. Bitte erneut versuchen.');
                    $remaining = $deadline - hrtime(true);
                    if ($remaining <= 0) throw new DomainException('Die Planung wird gerade gespeichert oder wiederhergestellt. Bitte kurz warten und erneut versuchen.');
                    usleep((int)min(10000, max(1, intdiv($remaining, 1000))));
                } while (true);
            }
            // Read after acquiring the lock: a restore may have completed while we waited.
            $current = self::epoch($db);
            if ($current === null || $expectedEpoch === null || !hash_equals($current, $expectedEpoch)) {
                throw new DomainException('Der Planungsstand wurde wiederhergestellt oder der Schreibschutz fehlt. Bitte die Seite neu laden, bevor du Änderungen speicherst.');
            }
            $weak = WeakReference::create($guard);
            register_shutdown_function(static function () use ($weak): void { $weak->get()?->release(); });
            return $guard;
        } catch (\Throwable $error) {
            $guard->release();
            throw $error;
        }
    }

    /** Never unlink the lock file: existing waiters must keep using the same inode. */
    public function release(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
        $this->lock = null;
    }

    public function __destruct() { $this->release(); }
}
