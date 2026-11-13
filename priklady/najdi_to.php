<?php
/**
 * Volitelný backend adaptér pro ukládání výsledků hry "Najdeš to?" do SQLite.
 *
 * Ochrana soukromí (GDPR):
 * - Skript NEUKLÁDÁ surové IP adresy hráčů do databáze.
 * - Pro detekci podvodů (opakované pokusy z anonymních oken) se z IP adresy a tajné soli
 *   počítá jednosměrný kryptografický hash (SHA-256). Z hashe nelze zpětně zjistit IP adresu
 *   ani identitu osoby (anonymizace dle GDPR).
 * - Podrobné mezikroky úrovní se automaticky rotačně promazávají po 14 dnech (minimalizace údajů).
 *
 * Podporuje:
 * - POST:
 *     - action: 'level_complete': zaznamenání splnění dílčí úrovně (čas, pokus, hash)
 *     - action: 'game_complete' (nebo výchozí): finální uložení celé hry (nick, časy, pokus, hash)
 * - GET:
 *     - výchozí: bezpečný veřejný žebříček nejlepších časů (bez citlivých údajů)
 *     - ?audit=1: interní přehled všech záznamů a hashů pro autora (kontrola podvodů/anonymních oken)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Zjištění reálné IP adresy klienta (podpora pro proxy/Cloudflare i přímé spojení)
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    return trim($_SERVER['REMOTE_ADDR'] ?? '');
}

// Načtení volitelné lokální konfigurace (api/config.php), která se neverzuje v Gitu
$localConfig = [];
if (file_exists(__DIR__ . '/config.php')) {
    $loaded = require __DIR__ . '/config.php';
    if (is_array($loaded)) {
        $localConfig = $loaded;
    }
}
$adminKey = (string)(getenv('NAJDI_TO_ADMIN_KEY') ?: ($localConfig['admin_key'] ?? ''));

// Cesta k databázi SQLite
$dbDir = __DIR__ . '/data';
if (!is_dir($dbDir)) {
    @mkdir($dbDir, 0775, true);
}
if (!file_exists($dbDir . '/.htaccess')) {
    @file_put_contents($dbDir . '/.htaccess', "Require all denied\nDeny from all\n");
}
$dbPath = $dbDir . '/najdi_to.sqlite';

// Inicializace tajné soli pro hashování (pokud není v config.php, vytvoří se náhodná per-instance sůl)
$saltFile = $dbDir . '/.salt';
if (empty($localConfig['salt'])) {
    if (!file_exists($saltFile)) {
        @file_put_contents($saltFile, bin2hex(random_bytes(16)));
    }
    $salt = (string)(@file_get_contents($saltFile) ?: 'izo_default_salt');
} else {
    $salt = (string)$localConfig['salt'];
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Automatické vytvoření tabulek
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS najdi_to_scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            session_id TEXT DEFAULT '',
            nick TEXT NOT NULL,
            total_time INTEGER NOT NULL,
            difficulty TEXT NOT NULL,
            attempt INTEGER DEFAULT 1,
            location TEXT DEFAULT '',
            levels_json TEXT,
            ip_address TEXT DEFAULT NULL,
            ip_hash TEXT,
            user_agent TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_total_time ON najdi_to_scores(total_time);
        CREATE INDEX IF NOT EXISTS idx_location ON najdi_to_scores(location);
        CREATE INDEX IF NOT EXISTS idx_hash ON najdi_to_scores(ip_hash);

        CREATE TABLE IF NOT EXISTS najdi_to_level_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            session_id TEXT DEFAULT '',
            event TEXT DEFAULT 'level_complete',
            lvl INTEGER NOT NULL,
            level_time INTEGER NOT NULL,
            total_time INTEGER DEFAULT 0,
            difficulty TEXT NOT NULL,
            attempt INTEGER DEFAULT 1,
            location TEXT DEFAULT '',
            nick TEXT DEFAULT '',
            ip_address TEXT DEFAULT NULL,
            ip_hash TEXT,
            user_agent TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_log_created ON najdi_to_level_logs(created_at);
        CREATE INDEX IF NOT EXISTS idx_log_hash ON najdi_to_level_logs(ip_hash);
        CREATE INDEX IF NOT EXISTS idx_log_session ON najdi_to_level_logs(session_id);
    ");

    // Doplnění chybějících sloupců do existující tabulky najdi_to_scores
    $existingCols = $pdo->query("PRAGMA table_info(najdi_to_scores)")->fetchAll(PDO::FETCH_COLUMN, 1);
    foreach (['session_id', 'ip_address', 'ip_hash', 'user_agent'] as $col) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec("ALTER TABLE najdi_to_scores ADD COLUMN {$col} TEXT DEFAULT ''");
        }
    }

    // Retroaktivní anonymizace dřívějších IP adres v existující databázi (GDPR)
    try {
        $pdo->exec("UPDATE najdi_to_scores SET ip_address = NULL WHERE ip_address IS NOT NULL;");
        $pdo->exec("UPDATE najdi_to_level_logs SET ip_address = NULL WHERE ip_address IS NOT NULL;");
    } catch (Exception $e) {
        // Ignorovat, pokud sloupce ještě neexistují
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Nelze připojit SQLite databázi: ' . $e->getMessage()]);
    exit;
}

// Obsluha GET: vrácení žebříčku nebo auditu pro autora
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Auditní log pro autora (pro kontrolu rychlých pokusů z anonymních oken / stejné IP)
        if (isset($_GET['audit'])) {
            $remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
            $isLocal = in_array($remoteIp, ['127.0.0.1', '::1'], true);
            $providedKey = (string)($_GET['key'] ?? '');

            // Pokud není klíč nastaven, je vzdálený přístup k auditu zakázán
            if (!$isLocal && ($adminKey === '' || !hash_equals($adminKey, $providedKey))) {
                http_response_code(403);
                echo json_encode([
                    'status' => 'error',
                    'message' => $adminKey === ''
                        ? 'Audit je vypnut. Nastavte tajný klíč v api/config.php nebo v proměnné prostředí NAJDI_TO_ADMIN_KEY.'
                        : 'Přístup k auditu odepřen: neplatný klíč (?key=...).'
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $stmt = $pdo->query("
                SELECT id, created_at, session_id, event, lvl, level_time, total_time, difficulty, attempt, location, nick, ip_hash, user_agent
                FROM najdi_to_level_logs
                ORDER BY id DESC
                LIMIT 100
            ");
            echo json_encode(['status' => 'ok', 'audit' => $stmt->fetchAll()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Veřejný žebříček (bez hashů a uživatelských agentů)
        $location = trim($_GET['location'] ?? '');
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));

        if ($location !== '') {
            $stmt = $pdo->prepare("
                SELECT nick, total_time, difficulty, location, created_at
                FROM najdi_to_scores
                WHERE location = :loc
                ORDER BY total_time ASC, created_at ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':loc', $location, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("
                SELECT nick, total_time, difficulty, location, created_at
                FROM najdi_to_scores
                ORDER BY total_time ASC, created_at ASC
                LIMIT :lim
            ");
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
        }

        $rows = $stmt->fetchAll();
        echo json_encode(['status' => 'ok', 'leaderboard' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// Obsluha POST: zaznamenání úrovně nebo celkového výsledku
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Neplatná JSON data']);
        exit;
    }

    // Automatické rotační čištění auditních logů starších než 14 dní (minimalizace dat dle GDPR)
    try {
        $pdo->exec("DELETE FROM najdi_to_level_logs WHERE created_at < datetime('now', '-14 days')");
    } catch (Exception $e) {
        // Tichá ignorace
    }

    $ip = getClientIp();
    $ipHash = $ip !== '' ? hash('sha256', $ip . '_' . $salt) : null;
    $userAgent = mb_substr(trim($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255, 'UTF-8');
    $sessionId = mb_substr(trim((string)($data['session_id'] ?? '')), 0, 64, 'UTF-8');
    $attempt = max(1, (int)($data['attempt'] ?? 1));
    $location = mb_substr(trim((string)($data['location'] ?? '')), 0, 128, 'UTF-8');
    $difficulty = mb_substr(trim((string)($data['difficulty'] ?? 'easy')), 0, 32, 'UTF-8');
    $action = trim((string)($data['action'] ?? 'game_complete'));

    // 1) Zaznamenání dokončení jednotlivé úrovně
    if ($action === 'level_complete') {
        $lvl = (int)($data['lvl'] ?? 1);
        $levelTime = max(1, (int)($data['level_time'] ?? 0));
        $totalTime = max(0, (int)($data['total_time'] ?? 0));
        $nick = mb_substr(trim((string)($data['nick'] ?? '')), 0, 50, 'UTF-8');

        try {
            $stmt = $pdo->prepare("
                INSERT INTO najdi_to_level_logs (session_id, event, lvl, level_time, total_time, difficulty, attempt, location, nick, ip_address, ip_hash, user_agent)
                VALUES (:session_id, 'level_complete', :lvl, :level_time, :total_time, :diff, :attempt, :loc, :nick, NULL, :ip_hash, :ua)
            ");
            $stmt->execute([
                ':session_id' => $sessionId,
                ':lvl' => $lvl,
                ':level_time' => $levelTime,
                ':total_time' => $totalTime,
                ':diff' => $difficulty,
                ':attempt' => $attempt,
                ':loc' => $location,
                ':nick' => $nick,
                ':ip_hash' => $ipHash,
                ':ua' => $userAgent,
            ]);

            echo json_encode(['status' => 'ok', 'message' => 'Úroveň zaznamenána']);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Chyba zápisu logu: ' . $e->getMessage()]);
        }
        exit;
    }

    // 2) Finální uložení celé hry (game_complete)
    $nick = trim((string)($data['nick'] ?? ''));
    if ($nick === '') {
        $nick = 'Anonymní hledač';
    }
    $nick = mb_substr($nick, 0, 50, 'UTF-8');

    $totalTime = max(1, (int)($data['total_time'] ?? 0));
    $levelsJson = isset($data['levels']) ? json_encode($data['levels'], JSON_UNESCAPED_UNICODE) : null;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO najdi_to_scores (session_id, nick, total_time, difficulty, attempt, location, levels_json, ip_address, ip_hash, user_agent)
            VALUES (:session_id, :nick, :total_time, :diff, :attempt, :loc, :levels, NULL, :ip_hash, :ua)
        ");
        $stmt->execute([
            ':session_id' => $sessionId,
            ':nick' => $nick,
            ':total_time' => $totalTime,
            ':diff' => $difficulty,
            ':attempt' => $attempt,
            ':loc' => $location,
            ':levels' => $levelsJson,
            ':ip_hash' => $ipHash,
            ':ua' => $userAgent,
        ]);

        $insertId = (int)$pdo->lastInsertId();

        // Zápis finálního dokončení do auditního logu
        $logStmt = $pdo->prepare("
            INSERT INTO najdi_to_level_logs (session_id, event, lvl, level_time, total_time, difficulty, attempt, location, nick, ip_address, ip_hash, user_agent)
            VALUES (:session_id, 'game_complete', 0, 0, :total_time, :diff, :attempt, :loc, :nick, NULL, :ip_hash, :ua)
        ");
        $logStmt->execute([
            ':session_id' => $sessionId,
            ':total_time' => $totalTime,
            ':diff' => $difficulty,
            ':attempt' => $attempt,
            ':loc' => $location,
            ':nick' => $nick,
            ':ip_hash' => $ipHash,
            ':ua' => $userAgent,
        ]);

        echo json_encode(['status' => 'ok', 'id' => $insertId, 'message' => 'Výsledek úspěšně uložen']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Chyba zápisu do databáze: ' . $e->getMessage()]);
    }
    exit;
}
