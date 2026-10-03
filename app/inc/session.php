<?php
/**
 * Sesije se čuvaju u bazi (a ne u fajlovima servera), tako da ne zavise od
 * podešavanja hostinga i vreme isteka određuje sama aplikacija.
 */
declare(strict_types=1);

class DbSesija implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $podaci = db_val('SELECT podaci FROM sesije WHERE id = ?', [$id]);
        return $podaci === null ? '' : (string)$podaci;
    }

    public function write(string $id, string $data): bool
    {
        db_run('REPLACE INTO sesije (id, podaci, poslednje) VALUES (?, ?, ?)', [$id, $data, time()]);
        return true;
    }

    public function destroy(string $id): bool
    {
        db_run('DELETE FROM sesije WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return db_run('DELETE FROM sesije WHERE poslednje < ?', [time() - $max_lifetime])->rowCount();
    }

    public function validateId(string $id): bool
    {
        return db_val('SELECT 1 FROM sesije WHERE id = ?', [$id]) !== null;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        db_run('UPDATE sesije SET poslednje = ? WHERE id = ?', [time(), $id]);
        return true;
    }
}

function sesija_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $cookie = [
        'lifetime' => 0,
        'path'     => app_base() . '/',
        'secure'   => je_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookie);
    session_name('FKSESIJA');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    if (!defined('INSTALL_RUN')) {
        // Stvarni istek određuje aplikacija (SESIJA_*_MIN); ovo samo čisti stare redove.
        ini_set('session.gc_maxlifetime', '86400');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_set_save_handler(new DbSesija(), true);
    }
    session_start();
}
