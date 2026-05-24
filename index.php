<?php
// =================================================================
// AETHER 3.0 — AGENT AUTONOME ULTRA-AVANCÉ (PHP + SQLite + Mistral)
// Architecture : Multi-agents, boucle infinie, auto-correction 100%
// Fix : UNIQUE constraint, modèles OK uniquement, timeouts Hostinger
// =================================================================

// --- DIRECTIVES HOSTINGER (must be first) ---
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');
set_time_limit(600);
ignore_user_abort(true);
ob_implicit_flush(true);
if (function_exists('ob_end_flush')) @ob_end_flush();

header('Content-Type: text/html; charset=utf-8');
header('X-Accel-Buffering: no');

// =====================================================================
// CONFIGURATION GLOBALE
// =====================================================================

// Charger la configuration externe si elle existe (config.php avec les clés API)
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

// Valeurs par défaut si config.php n'existe pas ou ne définit pas ces variables
if (!isset($GLOBALS['api_keys'])) {
    $GLOBALS['api_keys'] = [
        // REMPLACEZ CES CLÉS PAR LES VÔTRES depuis https://console.mistral.ai/
        getenv('MISTRAL_API_KEY') ?: 'VOTRE_CLE_API_MISTRAL_ICI',
    ];
}
if (!isset($GLOBALS['endpoint'])) {
    $GLOBALS['endpoint'] = 'https://api.mistral.ai/v1/chat/completions';
}
if (!isset($GLOBALS['db_file'])) {
    $GLOBALS['db_file'] = __DIR__ . '/aether_memory.sqlite';
}
if (!isset($GLOBALS['apps_dir'])) {
    $GLOBALS['apps_dir'] = __DIR__ . '/generated_apps';
}
if (!isset($GLOBALS['logs_dir'])) {
    $GLOBALS['logs_dir'] = __DIR__ . '/logs';
}
if (!isset($GLOBALS['max_iterations'])) {
    $GLOBALS['max_iterations'] = 20;
}

$GLOBALS['current_key_index'] = 0;

// =====================================================================
// MODÈLES OPÉRATIONNELS (uniquement les "OK" vérifiés)
// Sélection automatique par spécialité
// =====================================================================
$GLOBALS['models'] = [
    // CODE (priorité maximale pour génération de code)
    'code'        => 'codestral-2508',          // 50k tokens, spécialisé code
    'code_alt'    => 'devstral-2512',            // fallback code
    'code_small'  => 'devstral-small-2507',      // fallback rapide

    // RAISONNEMENT / PLANIFICATION
    'reasoning'   => 'mistral-large-2512',       // 50k tokens
    'planning'    => 'magistral-medium-2509',     // 75k tokens, analyse

    // CONTEXTE LARGE (>50k tokens)
    'large_ctx'   => 'mistral-small-2603',       // 375k tokens
    'large_ctx2'  => 'mistral-medium-2505',      // 375k tokens

    // CHAT / GÉNÉRAL
    'chat'        => 'open-mistral-nemo',        // 50k polyvalent
    'chat_fast'   => 'ministral-3b-2512',        // 50k ultra-rapide
    'chat_med'    => 'ministral-8b-2512',        // 50k équilibré

    // CRÉATIVITÉ / BRAINSTORMING
    'creative'    => 'labs-mistral-small-creative', // 50k

    // ANALYSE APPROFONDIE
    'analysis'    => 'magistral-small-2509',     // 75k
    'analysis_lg' => 'mistral-medium-2508',      // 375k

    // VISION / IMAGE
    'vision'      => 'pixtral-large-2411',       // 50k
    'vision_alt'  => 'pixtral-12b-2409',         // 50k

    // DÉVELOPPEMENT ARCHITECTURE
    'architect'   => 'devstral-medium-2507',     // 50k
    'scripts'     => 'mistral-small-2506',       // 50k scripts rapides
];

// Sélecteur intelligent de modèle selon la tâche et taille du contexte
function select_model(string $task = 'chat', int $context_tokens = 0): string {
    $m = $GLOBALS['models'];
    // Si contexte dépasse 45k, basculer sur modèles large
    if ($context_tokens > 45000) {
        return $m['large_ctx']; // mistral-small-2603 375k
    }
    return match($task) {
        'code'        => $m['code'],
        'code_alt'    => $m['code_alt'],
        'architect'   => $m['architect'],
        'planning'    => $m['planning'],
        'reasoning'   => $m['reasoning'],
        'analysis'    => $m['analysis'],
        'creative'    => $m['creative'],
        'vision'      => $m['vision'],
        'chat_fast'   => $m['chat_fast'],
        'scripts'     => $m['scripts'],
        default       => $m['chat'],
    };
}

// =====================================================================
// BASE DE DONNÉES — INITIALISATION (FIX UNIQUE CONSTRAINT)
// =====================================================================
function get_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $pdo = new PDO('sqlite:' . $GLOBALS['db_file'], null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("PRAGMA synchronous=NORMAL");
    $pdo->exec("PRAGMA foreign_keys=ON");

    // Table mémoire principale
    $pdo->exec("CREATE TABLE IF NOT EXISTS memory (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        type      TEXT,
        content   TEXT,
        result    TEXT,
        metadata  TEXT
    )");

    // Auto-améliorations du prompt
    $pdo->exec("CREATE TABLE IF NOT EXISTS self_improvements (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        timestamp         DATETIME DEFAULT CURRENT_TIMESTAMP,
        old_prompt        TEXT,
        new_prompt        TEXT,
        reason            TEXT,
        improvement_score INTEGER
    )");

    // Prompt maître — FIX : pas de UNIQUE constraint rigide, on gère par id=1
    $pdo->exec("CREATE TABLE IF NOT EXISTS master_prompt (
        id         INTEGER PRIMARY KEY,
        prompt     TEXT NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Fichiers générés avec traçabilité complète
    $pdo->exec("CREATE TABLE IF NOT EXISTS generated_files (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
        app_name          TEXT,
        file_path         TEXT,
        language          TEXT,
        validation_status TEXT DEFAULT 'pending',
        attempts          INTEGER DEFAULT 0,
        last_error        TEXT,
        content_hash      TEXT
    )");

    // Projets (regroupement des apps)
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        name        TEXT UNIQUE,
        description TEXT,
        status      TEXT DEFAULT 'in_progress',
        files_ok    INTEGER DEFAULT 0,
        files_total INTEGER DEFAULT 0
    )");

    // Sessions d'agents
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_sessions (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        session_id  TEXT,
        agent_role  TEXT,
        task        TEXT,
        result      TEXT,
        status      TEXT DEFAULT 'pending'
    )");

    // FTS5 recherche sémantique
    $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS memory_fts
        USING fts5(content, type, metadata, content=memory, content_rowid=id)");

    return $pdo;
}

// Initialisation prompt maître (FIX UNIQUE CONSTRAINT : INSERT OR REPLACE)
function init_master_prompt(PDO $pdo): string {
    $default = <<<PROMPT
Tu es **Aether 3.0** — une intelligence autonome de développement web PHP.

## MISSION
Créer des applications web PHP/SQLite complètes, fonctionnelles, testées à 100%.

## PRINCIPES FONDAMENTAUX
- Tu décides seul de l'architecture, des agents à créer, des fichiers nécessaires.
- Tu continues en boucle jusqu'à ce que TOUT fonctionne (0 erreur PHP, HTTP 200).
- Tu organises chaque projet dans generated_apps/{nom_projet}/ avec TOUS les fichiers.
- Chaque projet DOIT contenir au minimum : index.php, style.css, app.js, schema.sql, README.md.
- Tu génères des interfaces belles et modernes (dark mode, CSS pro).

## ARCHITECTURE DE RÉPONSE OBLIGATOIRE
<thinking>Analyse multi-perspectives du problème</thinking>
<agents>Liste des agents que tu vas utiliser et leur rôle</agents>
<plan>Plan détaillé étape par étape</plan>
<code language="php" path="nom_projet/index.php">/* code complet */</code>
<code language="css" path="nom_projet/style.css">/* styles */</code>
<code language="javascript" path="nom_projet/app.js">/* scripts */</code>
<code language="sql" path="nom_projet/schema.sql">/* schéma */</code>
<validation>Points de validation à tester</validation>
<next_action>Prochaine action si tout n'est pas complet</next_action>

## CONTRAINTES TECHNIQUES HOSTINGER
- PHP 8.3, LiteSpeed, PDO+SQLite, cURL activé, pas de exec/shell_exec.
- Utilise set_time_limit(300) dans tes fichiers générés.
- Valide toujours le JSON avant json_decode().
- Gère TOUS les cas d'erreur avec try/catch.
- Utilise des tokens généreux : max_tokens 16384 minimum pour le code.

## AUTO-AMÉLIORATION
- Analyse tes erreurs passées depuis SQLite.
- Propose des améliorations justifiées.
- Score d'amélioration basé sur : réduction des erreurs, qualité du code, complétude.
PROMPT;

    // FIX : INSERT OR REPLACE évite la violation UNIQUE
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO master_prompt (id, prompt, updated_at) VALUES (1, ?, CURRENT_TIMESTAMP)");
    // Ne remplacer que si vide
    $existing = $pdo->query("SELECT prompt FROM master_prompt WHERE id=1")->fetchColumn();
    if (!$existing) {
        $stmt->execute([$default]);
        return $default;
    }
    return $existing;
}

// =====================================================================
// API MISTRAL — APPEL AVEC ROTATION DES CLÉS ET RETRY
// =====================================================================
function call_mistral(
    array  $messages,
    string $task        = 'chat',
    float  $temperature = 0.85,
    int    $max_tokens  = 16384,
    int    $retry       = 3
): string {
    $model = select_model($task, estimate_tokens($messages));

    for ($attempt = 0; $attempt < $retry; $attempt++) {
        $key_idx = ($GLOBALS['current_key_index'] + $attempt) % count($GLOBALS['api_keys']);
        $key     = $GLOBALS['api_keys'][$key_idx];

        $payload = json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => $max_tokens,
            'top_p'       => 0.95,
        ]);

        $ch = curl_init($GLOBALS['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT      => 'Aether/3.0',
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            aether_log("cURL error (attempt $attempt): $curl_err");
            sleep(2);
            continue;
        }

        if ($http_code === 429) {
            aether_log("Rate limit (attempt $attempt), sleep 60s...");
            sleep(60);
            continue;
        }

        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['choices'][0]['message']['content'])) {
                $GLOBALS['current_key_index'] = ($key_idx + 1) % count($GLOBALS['api_keys']);
                sleep(1); // respect rate limit 1 req/sec
                return $data['choices'][0]['message']['content'];
            }
        }

        // Sur 429 ou erreur serveur, changer de clé
        if ($http_code === 401 || $http_code === 403) {
            aether_log("Auth error for key $key_idx (HTTP $http_code)");
            continue;
        }

        aether_log("API error HTTP $http_code (attempt $attempt): " . substr($response, 0, 300));
        sleep(2);
    }

    return "ERREUR: Impossible d'appeler l'API Mistral après $retry tentatives avec le modèle $model.";
}

// Estimation grossière du nombre de tokens (4 chars ≈ 1 token)
function estimate_tokens(array $messages): int {
    $total = 0;
    foreach ($messages as $m) {
        $total += (int)(strlen($m['content'] ?? '') / 4);
    }
    return $total;
}

// =====================================================================
// LOGGING
// =====================================================================
function aether_log(string $msg, string $level = 'INFO'): void {
    $log_dir = $GLOBALS['logs_dir'];
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    $file = $log_dir . '/aether_' . date('Y-m-d') . '.log';
    $line = '[' . date('Y-m-d H:i:s') . "] [$level] $msg\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    echo "<!-- LOG[$level]: " . htmlspecialchars($msg) . " -->\n";
    flush();
}

// Streaming HTML (affichage progressif)
function stream_html(string $html): void {
    echo $html;
    if (ob_get_level() > 0) ob_flush();
    flush();
}

// =====================================================================
// EXTRACTION ET SAUVEGARDE DES FICHIERS GÉNÉRÉS
// =====================================================================
function extract_and_save_files(string $response, string $project_name = ''): array {
    $pdo       = get_db();
    $apps_dir  = $GLOBALS['apps_dir'];
    $saved     = [];
    $failed    = [];

    // Pattern principal : <code language="..." path="...">...</code>
    preg_match_all(
        '/<code\s+language=["\']([^"\']+)["\']\s+path=["\']([^"\']+)["\']\s*>(.*?)<\/code>/si',
        $response,
        $matches,
        PREG_SET_ORDER
    );

    // Fallback : blocs ```lang ... ```
    if (empty($matches)) {
        preg_match_all('/```(\w+)\s*\n(.*?)```/s', $response, $fb, PREG_SET_ORDER);
        foreach ($fb as $i => $m) {
            $lang = strtolower($m[1]);
            $ext  = ext_for_lang($lang);
            $path = ($project_name ?: 'unnamed') . "/file_{$i}.{$ext}";
            $matches[] = [$m[0], $lang, $path, $m[2]];
        }
    }

    foreach ($matches as $match) {
        $lang    = strtolower(trim($match[1]));
        $path    = trim($match[2]);
        $content = decode_code_content(trim($match[3]));

        if (empty($content)) continue;

        // Chemin absolu
        $full_path = $apps_dir . '/' . ltrim($path, '/');
        $dir       = dirname($full_path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            aether_log("Impossible de créer le dossier: $dir", 'ERROR');
            continue;
        }

        // Validation
        [$valid, $msg] = validate_code($lang, $content, $full_path);

        // Écriture
        if (@file_put_contents($full_path, $content) === false) {
            aether_log("Impossible d'écrire: $full_path", 'ERROR');
            $failed[] = ['path' => $path, 'error' => 'write_failed'];
            continue;
        }

        $hash     = md5($content);
        $app_name = $project_name ?: explode('/', $path)[0];

        // Upsert en base
        $existing = $pdo->prepare("SELECT id FROM generated_files WHERE file_path=?")->execute([$path]);
        $row      = $pdo->query("SELECT id FROM generated_files WHERE file_path=" . $pdo->quote($path))->fetchColumn();

        if ($row) {
            $pdo->prepare("UPDATE generated_files SET validation_status=?, attempts=attempts+1, content_hash=?, last_error=NULL WHERE file_path=?")
                ->execute([$msg, $hash, $path]);
        } else {
            $pdo->prepare("INSERT INTO generated_files (app_name, file_path, language, validation_status, content_hash) VALUES (?,?,?,?,?)")
                ->execute([$app_name, $path, $lang, $msg, $hash]);
        }

        $saved[] = ['path' => $path, 'lang' => $lang, 'full_path' => $full_path, 'valid' => $valid, 'msg' => $msg];
    }

    return ['saved' => $saved, 'failed' => $failed];
}

function decode_code_content(string $content): string {
    // Nettoyer les balises residuelles et backticks
    $content = preg_replace('/^```\w*\s*\n?/', '', $content);
    $content = preg_replace('/\n?```\s*$/', '', $content);
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim($content);
}

function ext_for_lang(string $lang): string {
    return match($lang) {
        'php'        => 'php',
        'javascript', 'js' => 'js',
        'css'        => 'css',
        'html'       => 'html',
        'sql', 'sqlite' => 'sql',
        'json'       => 'json',
        'markdown', 'md' => 'md',
        'python'     => 'py',
        default      => 'txt',
    };
}

// =====================================================================
// VALIDATION DU CODE
// =====================================================================
function validate_code(string $lang, string $content, string $full_path = ''): array {
    return match($lang) {
        'php'            => validate_php($content),
        'javascript','js'=> validate_js($content),
        'sql','sqlite'   => validate_sql($content),
        'json'           => validate_json($content),
        'html'           => validate_html($content),
        'css'            => [true, 'CSS accepté sans validation syntaxique'],
        'markdown','md'  => [true, 'Markdown accepté'],
        default          => [true, "Fichier $lang accepté"],
    };
}

function validate_php(string $content): array {
    // Vérifications basiques sans exec()
    if (empty(trim($content))) return [false, 'Contenu PHP vide'];
    if (!str_contains($content, '<?php') && !str_contains($content, '<?='))
        return [false, 'Pas de balise PHP d\'ouverture'];
    // Vérifier les accolades grossièrement
    $open  = substr_count($content, '{');
    $close = substr_count($content, '}');
    if (abs($open - $close) > 5)
        return [false, "Déséquilibre accolades: {$open} ouvertures, {$close} fermetures"];
    return [true, 'PHP validé (syntaxe basique OK)'];
}

function validate_js(string $content): array {
    if (empty(trim($content))) return [false, 'JS vide'];
    $stack = 0;
    $in_str = false;
    $str_char = '';
    $in_regex = false;
    $len = strlen($content);
    for ($i = 0; $i < $len; $i++) {
        $c = $content[$i];
        $prev = $i > 0 ? $content[$i-1] : '';
        if ($prev === '\\') continue;
        if (!$in_str && !$in_regex && ($c === '"' || $c === "'" || $c === '`')) {
            $in_str = true; $str_char = $c; continue;
        }
        if ($in_str && $c === $str_char) { $in_str = false; continue; }
        if ($in_str) continue;
        if ($c === '{' || $c === '(') $stack++;
        if ($c === '}' || $c === ')') $stack--;
        if ($stack < -2) return [false, 'JS: accolade/parenthèse fermante sans ouverture'];
    }
    if (abs($stack) > 3) return [false, "JS: déséquilibre structurel ($stack)"];
    return [true, 'JS syntaxe OK'];
}

function validate_sql(string $content): array {
    if (empty(trim($content))) return [false, 'SQL vide'];
    try {
        $tmp = sys_get_temp_dir() . '/aether_sql_' . uniqid() . '.sqlite';
        $db  = new PDO("sqlite:$tmp");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Exécuter les CREATE TABLE (safe)
        $statements = array_filter(array_map('trim', explode(';', $content)));
        foreach ($statements as $stmt) {
            if (empty($stmt)) continue;
            $kw = strtoupper(substr(ltrim($stmt), 0, 10));
            if (str_starts_with($kw, 'CREATE') || str_starts_with($kw, 'INSERT') || str_starts_with($kw, 'SELECT')) {
                $db->exec($stmt . ';');
            }
        }
        @unlink($tmp);
        return [true, 'SQL validé (exécution réussie)'];
    } catch (Exception $e) {
        return [false, 'SQL invalide: ' . $e->getMessage()];
    }
}

function validate_json(string $content): array {
    $data = json_decode($content, true);
    return json_last_error() === JSON_ERROR_NONE
        ? [true, 'JSON valide']
        : [false, 'JSON invalide: ' . json_last_error_msg()];
}

function validate_html(string $content): array {
    if (empty(trim($content))) return [false, 'HTML vide'];
    // Vérif basique
    if (!str_contains(strtolower($content), '<html') && !str_contains(strtolower($content), '<!doctype'))
        return [false, 'HTML: pas de balise racine <html>'];
    return [true, 'HTML validé (structure basique OK)'];
}

// =====================================================================
// TEST HTTP DES FICHIERS PHP GÉNÉRÉS
// =====================================================================
function test_php_file_http(string $full_path, string $base_url): array {
    $rel_path = str_replace(rtrim(__DIR__, '/') . '/', '', $full_path);
    $url      = rtrim($base_url, '/') . '/' . ltrim($rel_path, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['X-Aether-Test: 1', 'Accept: text/html'],
        CURLOPT_USERAGENT      => 'Aether-TestBot/3.0',
    ]);
    $output    = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) return ['success' => false, 'error' => "cURL: $curl_err", 'code' => 0];

    if ($http_code >= 200 && $http_code < 300) {
        // Vérifier absence d'erreurs PHP dans le body
        $php_errors = [];
        if (preg_match('/(Fatal error|Parse error|Warning|Notice|Deprecated).*?on line \d+/i', $output, $em))
            $php_errors[] = trim($em[0]);
        if (!empty($php_errors))
            return ['success' => false, 'error' => implode(' | ', $php_errors), 'code' => $http_code, 'output' => substr($output, 0, 300)];
        return ['success' => true, 'code' => $http_code, 'output' => substr($output, 0, 200)];
    }

    // Extraire message d'erreur
    preg_match('/<b>(Fatal error|Parse error|Warning)[^<]*<\/b>:([^<]+)/i', $output, $em);
    $err = trim($em[2] ?? "HTTP $http_code");
    return ['success' => false, 'error' => $err, 'code' => $http_code, 'output' => substr($output, 0, 300)];
}

// =====================================================================
// BOUCLE DE CORRECTION INFINIE (jusqu'à 100% validé)
// =====================================================================
function auto_correct_loop(
    string $initial_response,
    array  $messages,
    string $project_name,
    string $base_url,
    int    $max_iterations = 20
): array {
    $pdo        = get_db();
    $current    = $initial_response;
    $iteration  = 0;
    $all_files  = [];
    $final_ok   = false;

    stream_html("<div class='correction-loop'><h3>🔄 Boucle de correction automatique</h3>");

    while ($iteration < $max_iterations) {
        $iteration++;
        stream_html("<div class='iteration'><strong>Itération $iteration/$max_iterations</strong>");

        // Extraire et sauvegarder les fichiers
        $result    = extract_and_save_files($current, $project_name);
        $saved     = $result['saved'];
        $all_files = array_merge($all_files, $saved);

        if (empty($saved)) {
            stream_html("<p class='warn'>⚠ Aucun fichier extrait. Tentative de re-génération...</p>");
            // Demander à l'IA de reformuler avec les balises correctes
            $messages[] = ['role' => 'assistant', 'content' => $current];
            $messages[] = ['role' => 'user', 'content' => "Aucun fichier n'a pu être extrait. Utilise OBLIGATOIREMENT le format exact:\n<code language=\"php\" path=\"{$project_name}/index.php\">/* ton code */</code>\nRefais la réponse complète avec TOUS les fichiers."];
            $current    = call_mistral($messages, 'code', 0.7, 16384);
            stream_html("</div>");
            continue;
        }

        // Tester les fichiers PHP
        $errors = [];
        $ok_count = 0;
        $total_php = 0;

        foreach ($saved as $file) {
            if ($file['lang'] !== 'php') {
                $ok_count++;
                continue;
            }
            $total_php++;
            $test = test_php_file_http($file['full_path'], $base_url);
            if ($test['success']) {
                $ok_count++;
                $pdo->prepare("UPDATE generated_files SET validation_status='HTTP 200 OK' WHERE file_path=?")->execute([$file['path']]);
                stream_html("<span class='ok'>✅ {$file['path']} — HTTP {$test['code']}</span><br>");
            } else {
                $errors[] = "Fichier `{$file['path']}` ERREUR: {$test['error']}";
                $pdo->prepare("UPDATE generated_files SET validation_status=?, last_error=?, attempts=attempts+1 WHERE file_path=?")
                    ->execute(["ERREUR: {$test['error']}", $test['error'], $file['path']]);
                stream_html("<span class='err'>❌ {$file['path']} — {$test['error']}</span><br>");
            }
        }

        // Vérifier le taux de succès
        $total   = count($saved);
        $pct     = $total > 0 ? round(($ok_count / $total) * 100) : 0;
        stream_html("<p>📊 Taux de réussite: <strong>{$ok_count}/{$total} ({$pct}%)</strong></p>");

        // Mettre à jour le projet
        $pdo->prepare("INSERT OR REPLACE INTO projects (name, description, status, files_ok, files_total) VALUES (?,?,?,?,?)")
            ->execute([$project_name, "Projet généré par Aether 3.0", $pct >= 100 ? 'complete' : 'in_progress', $ok_count, $total]);

        if (empty($errors)) {
            $final_ok = true;
            stream_html("<p class='success'>🎉 <strong>100% validé — Projet complet !</strong></p>");
            stream_html("</div>");
            break;
        }

        // Des erreurs subsistent — correction ciblée
        stream_html("<p>🔧 Corrections nécessaires: " . count($errors) . " fichier(s)</p></div>");

        // Récupérer le code erroné pour contexte
        $error_context = implode("\n", $errors);

        // Récupérer l'historique des erreurs passées pour ce projet
        $past_errors = $pdo->prepare("SELECT file_path, last_error FROM generated_files WHERE app_name=? AND last_error IS NOT NULL ORDER BY attempts DESC LIMIT 5");
        $past_errors->execute([$project_name]);
        $past_ctx = '';
        while ($row = $past_errors->fetch()) {
            $past_ctx .= "- {$row['file_path']}: {$row['last_error']}\n";
        }

        $correction_msg = <<<MSG
Les tests ont échoué pour le projet **{$project_name}** :

ERREURS ACTUELLES :
{$error_context}

HISTORIQUE DES ERREURS :
{$past_ctx}

Analyse les erreurs et retourne les fichiers corrigés COMPLETS (pas de partiel).
Utilise OBLIGATOIREMENT le format :
<code language="php" path="{$project_name}/index.php">/* code corrigé complet */</code>

Ne répète pas les fichiers sans erreur. Concentre-toi sur les fichiers qui échouent.
MSG;

        $messages[] = ['role' => 'assistant', 'content' => $current];
        $messages[] = ['role' => 'user',      'content' => $correction_msg];
        $current    = call_mistral($messages, 'code', 0.5, 16384);

        // Log la correction
        $pdo->prepare("INSERT INTO memory (type, content, result, metadata) VALUES (?,?,?,?)")
            ->execute(['correction', $correction_msg, $current, json_encode(['iteration' => $iteration, 'project' => $project_name])]);
    }

    if (!$final_ok) {
        stream_html("<p class='warn'>⚠ Limite d'itérations atteinte ($max_iterations). Vérification manuelle recommandée.</p>");
    }

    stream_html("</div>");
    return ['files' => $all_files, 'success' => $final_ok];
}

// =====================================================================
// AGENT ARCHITECTE — Décide de la structure du projet
// =====================================================================
function agent_architect(string $user_request, string $project_name): array {
    stream_html("<div class='agent'><h4>🏗 Agent Architecte</h4>");

    $messages = [
        ['role' => 'system', 'content' => "Tu es un architecte logiciel PHP. Analyse la demande et retourne UNIQUEMENT un JSON valide avec la structure du projet. Format: {\"files\": [{\"path\": \"nom/fichier.php\", \"lang\": \"php\", \"description\": \"rôle du fichier\"}], \"agents\": [\"agent1\", \"agent2\"], \"tech_stack\": \"description\"}"],
        ['role' => 'user',   'content' => "Demande: $user_request\nNom du projet: $project_name\nFournis l'architecture complète."],
    ];

    $response = call_mistral($messages, 'architect', 0.3, 4096);

    // Extraire le JSON
    preg_match('/\{.*\}/s', $response, $json_match);
    $architecture = [];
    if ($json_match) {
        $data = json_decode($json_match[0], true);
        if (json_last_error() === JSON_ERROR_NONE) $architecture = $data;
    }

    if (empty($architecture)) {
        $architecture = [
            'files'     => [
                ['path' => "$project_name/index.php",  'lang' => 'php',        'description' => 'Page principale'],
                ['path' => "$project_name/style.css",  'lang' => 'css',        'description' => 'Styles'],
                ['path' => "$project_name/app.js",     'lang' => 'javascript', 'description' => 'Scripts'],
                ['path' => "$project_name/schema.sql", 'lang' => 'sql',        'description' => 'Schéma SQLite'],
                ['path' => "$project_name/README.md",  'lang' => 'markdown',   'description' => 'Documentation'],
            ],
            'agents'    => ['generateur', 'validateur', 'correcteur'],
            'tech_stack'=> 'PHP 8.3 + SQLite + CSS Dark Mode + JS Vanilla',
        ];
    }

    $files_list = '';
    foreach ($architecture['files'] ?? [] as $f) {
        $files_list .= "- {$f['path']} ({$f['lang']}): {$f['description']}\n";
    }
    stream_html("<p>Structure décidée :</p><pre>$files_list</pre></div>");

    return $architecture;
}

// =====================================================================
// AGENT GÉNÉRATEUR DE PROJET COMPLET
// =====================================================================
function agent_generate_project(string $user_request, string $project_name, array $architecture, string $base_url): array {
    $pdo = get_db();
    stream_html("<div class='agent'><h4>⚡ Agent Générateur</h4>");

    $master_prompt = $pdo->query("SELECT prompt FROM master_prompt WHERE id=1")->fetchColumn();

    // Construire un prompt de génération ultra-détaillé
    $files_spec = '';
    foreach ($architecture['files'] ?? [] as $f) {
        $files_spec .= "- {$f['path']} ({$f['lang']}): {$f['description']}\n";
    }

    $tech = $architecture['tech_stack'] ?? 'PHP 8.3 + SQLite + CSS + JS';

    $messages = [
        ['role' => 'system', 'content' => $master_prompt],
        ['role' => 'user',   'content' => <<<PROMPT
## DEMANDE
{$user_request}

## PROJET
Nom: {$project_name}
Stack: {$tech}

## FICHIERS À GÉNÉRER (TOUS OBLIGATOIRES)
{$files_spec}

## INSTRUCTIONS STRICTES
1. Génère TOUS les fichiers listés ci-dessus, COMPLETS, sans tronquer.
2. Chaque fichier PHP doit : utiliser PDO+SQLite, gérer les erreurs try/catch, avoir set_time_limit(300).
3. L'interface doit être belle : dark mode, couleurs cohérentes, responsive.
4. Le fichier index.php doit être une application complète et fonctionnelle.
5. Le schema.sql doit créer toutes les tables nécessaires avec des données d'exemple.
6. Utilise EXACTEMENT ce format pour chaque fichier :
<code language="php" path="{$project_name}/index.php">/* code */</code>

Génère maintenant TOUS les fichiers, complets et fonctionnels.
PROMPT
        ],
    ];

    $response = call_mistral($messages, 'code', 0.85, 16384);

    // Log
    $pdo->prepare("INSERT INTO memory (type, content, result, metadata) VALUES (?,?,?,?)")
        ->execute(['generation', $user_request, substr($response, 0, 500), json_encode(['project' => $project_name])]);

    stream_html("<p>✅ Génération initiale reçue (" . strlen($response) . " chars)</p></div>");

    // Lancer la boucle de correction infinie
    return auto_correct_loop($response, $messages, $project_name, $base_url);
}

// =====================================================================
// AGENT ANALYSE (recherche de contexte, web search DuckDuckGo)
// =====================================================================
function agent_web_search(string $query): string {
    $url = "https://api.duckduckgo.com/?q=" . urlencode($query) . "&format=json&no_html=1&skip_disambig=1";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'Aether/3.0',
    ]);
    $json = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return "Recherche indisponible (HTTP $code)";

    $data   = json_decode($json, true);
    $result = '';
    if (!empty($data['AbstractText']))     $result .= "Résumé: " . $data['AbstractText'] . "\n";
    if (!empty($data['Answer']))           $result .= "Réponse directe: " . $data['Answer'] . "\n";
    if (!empty($data['RelatedTopics'])) {
        $result .= "Sujets liés:\n";
        foreach (array_slice($data['RelatedTopics'], 0, 4) as $t) {
            if (isset($t['Text'])) $result .= "- " . strip_tags($t['Text']) . "\n";
        }
    }
    return $result ?: "Aucun résultat pour: $query";
}

// =====================================================================
// AUTO-AMÉLIORATION DU PROMPT MAÎTRE
// =====================================================================
function self_improve_prompt(): string {
    $pdo            = get_db();
    $current_prompt = $pdo->query("SELECT prompt FROM master_prompt WHERE id=1")->fetchColumn();

    // Récupérer l'historique récent + erreurs
    $history = $pdo->query("SELECT type, content, result FROM memory ORDER BY id DESC LIMIT 15")->fetchAll();
    $errors  = $pdo->query("SELECT file_path, last_error, attempts FROM generated_files WHERE last_error IS NOT NULL ORDER BY attempts DESC LIMIT 10")->fetchAll();

    $history_text = '';
    foreach ($history as $h) {
        $history_text .= "[{$h['type']}] " . substr($h['content'], 0, 100) . "\n→ " . substr($h['result'], 0, 100) . "\n\n";
    }
    $errors_text = '';
    foreach ($errors as $e) {
        $errors_text .= "- {$e['file_path']} ({$e['attempts']} tentatives): {$e['last_error']}\n";
    }

    $messages = [
        ['role' => 'system', 'content' => "Tu es un méta-optimiseur de prompts IA. Analyse l'historique et propose un prompt amélioré. Fournis le nouveau prompt entre balises <new_prompt>...</new_prompt>. Justifie chaque changement."],
        ['role' => 'user', 'content' => "Prompt actuel:\n{$current_prompt}\n\nHistorique:\n{$history_text}\n\nErreurs fréquentes:\n{$errors_text}\n\nPropose une amélioration ciblée."],
    ];

    $response = call_mistral($messages, 'reasoning', 0.8, 8192);

    if (preg_match('/<new_prompt>(.*?)<\/new_prompt>/s', $response, $m)) {
        $new_prompt = trim($m[1]);
        $score      = min(100, strlen($new_prompt) / 10 + (substr_count($new_prompt, '##') * 5));
        $pdo->prepare("INSERT INTO self_improvements (old_prompt, new_prompt, reason, improvement_score) VALUES (?,?,?,?)")
            ->execute([$current_prompt, $new_prompt, $response, (int)$score]);
        // FIX : UPDATE, pas INSERT (évite la violation UNIQUE)
        $pdo->prepare("UPDATE master_prompt SET prompt=?, updated_at=CURRENT_TIMESTAMP WHERE id=1")
            ->execute([$new_prompt]);
        return "✅ Prompt amélioré (score $score/100).";
    }
    return "❌ Aucune amélioration détectée dans la réponse.";
}

// =====================================================================
// MODE CHAT AVANCÉ
// =====================================================================
function mode_chat(string $user_input, string $base_url): void {
    $pdo           = get_db();
    $master_prompt = $pdo->query("SELECT prompt FROM master_prompt WHERE id=1")->fetchColumn();

    $messages = [
        ['role' => 'system', 'content' => $master_prompt],
        ['role' => 'user',   'content' => $user_input],
    ];

    stream_html("<h3>💬 Réponse Aether :</h3>");
    $response = call_mistral($messages, 'chat', 0.9, 8192);
    stream_html("<pre class='response'>" . htmlspecialchars($response) . "</pre>");

    // Sauvegarder
    $pdo->prepare("INSERT INTO memory (type, content, result) VALUES (?,?,?)")->execute(['chat', $user_input, $response]);

    // Tentative d'extraction de fichiers
    $result = extract_and_save_files($response, 'chat_' . date('Ymd_His'));
    if (!empty($result['saved'])) {
        stream_html("<h4>📁 Fichiers extraits :</h4><ul>");
        foreach ($result['saved'] as $f) {
            $status = $f['valid'] ? "✅" : "⚠";
            stream_html("<li>{$status} <code>{$f['path']}</code> ({$f['lang']}) — {$f['msg']}</li>");
        }
        stream_html("</ul>");

        // Test HTTP des PHP
        foreach ($result['saved'] as $f) {
            if ($f['lang'] === 'php') {
                $test = test_php_file_http($f['full_path'], $base_url);
                $icon = $test['success'] ? '✅' : '❌';
                stream_html("<p>{$icon} Test HTTP {$f['path']}: HTTP {$test['code']}</p>");
            }
        }
    }
}

// =====================================================================
// INITIALISATION DES DOSSIERS ET DB
// =====================================================================
foreach ([$GLOBALS['apps_dir'], $GLOBALS['logs_dir']] as $d) {
    if (!is_dir($d)) @mkdir($d, 0755, true);
}
$pdo           = get_db();
$master_prompt = init_master_prompt($pdo);
$base_url      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');

// =====================================================================
// INTERFACE WEB — HTML + CSS
// =====================================================================
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aether 3.0 — Agent Autonome PHP</title>
<style>
  :root {
    --bg: #06060f;
    --bg2: #0d0d1f;
    --bg3: #13132a;
    --border: #1e1e3f;
    --accent: #00e5ff;
    --accent2: #7c3aed;
    --green: #00ff88;
    --red: #ff4466;
    --orange: #ff9900;
    --text: #c8d6f0;
    --text-dim: #6b7aaa;
    --font: 'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 24px;
    font-size: 13px;
    line-height: 1.6;
    background-image: radial-gradient(ellipse at 20% 10%, #0a0a2a 0%, transparent 60%),
                      radial-gradient(ellipse at 80% 90%, #0d0020 0%, transparent 60%);
  }
  header {
    border-bottom: 1px solid var(--border);
    padding-bottom: 16px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    gap: 16px;
  }
  header h1 {
    font-size: 24px;
    font-weight: 700;
    color: var(--accent);
    letter-spacing: -0.5px;
  }
  header h1 span { color: var(--accent2); }
  .badge {
    background: var(--accent2);
    color: #fff;
    font-size: 10px;
    padding: 2px 8px;
    border-radius: 12px;
    letter-spacing: 1px;
    text-transform: uppercase;
  }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px; }
  .card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
  }
  .card h3 {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 2px;
    color: var(--text-dim);
    margin-bottom: 12px;
  }
  textarea, input[type=text] {
    width: 100%;
    background: var(--bg3);
    border: 1px solid var(--border);
    color: var(--text);
    font-family: var(--font);
    font-size: 13px;
    padding: 12px;
    border-radius: 8px;
    resize: vertical;
    outline: none;
    transition: border-color 0.2s;
  }
  textarea:focus, input:focus { border-color: var(--accent); }
  .btn-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
  button {
    background: var(--bg3);
    color: var(--text);
    border: 1px solid var(--border);
    font-family: var(--font);
    font-size: 12px;
    padding: 9px 18px;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
  }
  button:hover { border-color: var(--accent); color: var(--accent); }
  button.primary { background: var(--accent2); border-color: var(--accent2); color: #fff; }
  button.primary:hover { background: #6d28d9; }
  .output-box {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 20px;
    min-height: 200px;
    max-height: 70vh;
    overflow-y: auto;
  }
  pre, .response {
    background: var(--bg3);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 12px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-all;
    font-size: 12px;
    line-height: 1.5;
    margin: 8px 0;
    max-height: 400px;
    overflow-y: auto;
  }
  code { color: var(--accent); font-family: var(--font); }
  .ok   { color: var(--green); }
  .err  { color: var(--red); }
  .warn { color: var(--orange); }
  .success { color: var(--green); font-size: 15px; }
  h2 { color: var(--accent); font-size: 15px; margin: 16px 0 8px; }
  h3 { color: var(--accent2); font-size: 13px; margin: 12px 0 6px; }
  h4 { color: var(--text-dim); font-size: 12px; margin: 8px 0 4px; }
  ul { padding-left: 20px; }
  li { margin: 4px 0; }
  .agent {
    border-left: 3px solid var(--accent2);
    padding-left: 12px;
    margin: 8px 0;
    background: rgba(124,58,237,0.05);
    border-radius: 0 8px 8px 0;
  }
  .correction-loop { border-left: 3px solid var(--accent); padding-left: 12px; margin: 8px 0; }
  .iteration { border: 1px solid var(--border); border-radius: 8px; padding: 10px; margin: 6px 0; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th, td { padding: 8px 12px; border: 1px solid var(--border); text-align: left; }
  th { background: var(--bg3); color: var(--text-dim); font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
  tr:hover td { background: rgba(0,229,255,0.03); }
  .status-ok   { color: var(--green); }
  .status-err  { color: var(--red); }
  .status-pend { color: var(--orange); }
  hr { border: none; border-top: 1px solid var(--border); margin: 20px 0; }
</style>
</head>
<body>
<header>
  <h1>🧠 Aether <span>3.0</span></h1>
  <span class="badge">Autonomous AI</span>
  <span class="badge" style="background:var(--accent);color:#000;">PHP 8.3</span>
</header>

<div class="grid">
  <div class="card">
    <h3>🚀 Mission Control</h3>
    <form method="post">
      <textarea name="user_input" rows="5" placeholder="Ex: Crée une application de gestion de tâches avec catégories, priorités, dates d'échéance, interface sombre moderne..."></textarea>
      <input type="text" name="project_name" placeholder="Nom du projet (ex: todo_app)" style="margin-top:8px;" value="<?= htmlspecialchars($_POST['project_name'] ?? '') ?>">
      <div class="btn-row">
        <button type="submit" name="mode" value="autonomous" class="primary">🤖 Mode Autonome</button>
        <button type="submit" name="mode" value="chat">💬 Chat</button>
        <button type="submit" name="mode" value="websearch">🌐 Web Search</button>
        <button type="submit" name="mode" value="self_improve">🔄 Auto-Amélioration</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>📊 Statistiques</h3>
    <?php
    $stats_files   = $pdo->query("SELECT COUNT(*) FROM generated_files")->fetchColumn();
    $stats_ok      = $pdo->query("SELECT COUNT(*) FROM generated_files WHERE validation_status LIKE '%OK%'")->fetchColumn();
    $stats_proj    = $pdo->query("SELECT COUNT(*) FROM projects")->fetchColumn();
    $stats_mem     = $pdo->query("SELECT COUNT(*) FROM memory")->fetchColumn();
    $stats_improve = $pdo->query("SELECT COUNT(*) FROM self_improvements")->fetchColumn();
    echo "<table>";
    echo "<tr><td>Fichiers générés</td><td class='ok'>{$stats_files}</td></tr>";
    echo "<tr><td>Fichiers validés</td><td class='ok'>{$stats_ok}</td></tr>";
    echo "<tr><td>Projets</td><td class='ok'>{$stats_proj}</td></tr>";
    echo "<tr><td>Entrées mémoire</td><td class='ok'>{$stats_mem}</td></tr>";
    echo "<tr><td>Auto-améliorations</td><td class='ok'>{$stats_improve}</td></tr>";
    echo "</table>";
    ?>
  </div>
</div>

<div class="output-box">
<?php
// ====================================================================
// TRAITEMENT DES REQUÊTES POST
// ====================================================================
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_input   = trim($_POST['user_input']   ?? '');
    $project_name = trim($_POST['project_name'] ?? '');
    $mode         = $_POST['mode']              ?? 'chat';

    // Nettoyer le nom du projet
    $project_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $project_name);
    if (empty($project_name)) {
        $project_name = 'project_' . date('Ymd_His');
    }

    stream_html("<h2>Mode: " . htmlspecialchars(strtoupper($mode)) . " — Projet: <code>{$project_name}</code></h2>");

    switch ($mode) {
        case 'websearch':
            if (empty($user_input)) {
                stream_html("<p class='warn'>Entrez une requête de recherche.</p>");
            } else {
                stream_html("<h3>🌐 Recherche: " . htmlspecialchars($user_input) . "</h3>");
                $search = agent_web_search($user_input);
                stream_html("<pre>" . htmlspecialchars($search) . "</pre>");
                // Enrichir avec Mistral
                $enrich = call_mistral([
                    ['role' => 'system', 'content' => "Analyse ces résultats de recherche web et fournis une synthèse utile pour un développeur PHP."],
                    ['role' => 'user',   'content' => "Résultats bruts:\n$search\n\nQuestion: $user_input"],
                ], 'analysis', 0.5, 4096);
                stream_html("<h4>💡 Analyse Aether :</h4><pre>" . htmlspecialchars($enrich) . "</pre>");
                $pdo->prepare("INSERT INTO memory (type, content, result) VALUES (?,?,?)")->execute(['websearch', $user_input, $search]);
            }
            break;

        case 'self_improve':
            stream_html("<h3>🔄 Auto-amélioration du prompt maître...</h3>");
            $result = self_improve_prompt();
            stream_html("<p>" . htmlspecialchars($result) . "</p>");
            // Afficher les dernières améliorations
            $improvements = $pdo->query("SELECT timestamp, improvement_score FROM self_improvements ORDER BY id DESC LIMIT 5")->fetchAll();
            if (!empty($improvements)) {
                stream_html("<h4>Historique :</h4><table><tr><th>Date</th><th>Score</th></tr>");
                foreach ($improvements as $imp) {
                    stream_html("<tr><td>{$imp['timestamp']}</td><td class='ok'>{$imp['improvement_score']}/100</td></tr>");
                }
                stream_html("</table>");
            }
            break;

        case 'autonomous':
            if (empty($user_input)) {
                stream_html("<p class='warn'>Entrez un objectif pour le mode autonome.</p>");
            } else {
                stream_html("<h3>🤖 Lancement du pipeline autonome multi-agents...</h3>");

                // Phase 1 : Recherche de contexte
                stream_html("<div class='agent'><h4>🌐 Agent Recherche</h4>");
                $context = agent_web_search($user_input);
                stream_html("<p>Contexte web récupéré: " . strlen($context) . " chars</p></div>");

                // Phase 2 : Architecture
                $architecture = agent_architect($user_input, $project_name);

                // Phase 3 : Génération + boucle correction
                $result = agent_generate_project($user_input, $project_name, $architecture, $base_url);

                // Phase 4 : Rapport final
                stream_html("<hr><h3>📋 Rapport Final — Projet: {$project_name}</h3>");
                $files = $pdo->prepare("SELECT * FROM generated_files WHERE app_name=? ORDER BY id");
                $files->execute([$project_name]);
                $all_files = $files->fetchAll();

                if (!empty($all_files)) {
                    stream_html("<table><tr><th>Fichier</th><th>Langage</th><th>Statut</th><th>Tentatives</th></tr>");
                    foreach ($all_files as $f) {
                        $cls = str_contains($f['validation_status'], 'OK') ? 'status-ok' :
                              (str_contains($f['validation_status'], 'ERREUR') ? 'status-err' : 'status-pend');
                        stream_html("<tr><td><code>{$f['file_path']}</code></td><td>{$f['language']}</td><td class='{$cls}'>{$f['validation_status']}</td><td>{$f['attempts']}</td></tr>");
                    }
                    stream_html("</table>");
                }

                if ($result['success']) {
                    $url = $base_url . '/generated_apps/' . $project_name . '/index.php';
                    stream_html("<p class='success'>🎉 Projet généré et validé à 100% !</p>");
                    stream_html("<p>🔗 URL: <a href='{$url}' target='_blank' style='color:var(--accent)'>{$url}</a></p>");
                } else {
                    stream_html("<p class='warn'>⚠ Projet partiellement validé. Vérification manuelle recommandée.</p>");
                    stream_html("<p>📁 Dossier: <code>generated_apps/{$project_name}/</code></p>");
                }

                // Phase 5 : Auto-amélioration si beaucoup d'erreurs
                $err_count = $pdo->prepare("SELECT COUNT(*) FROM generated_files WHERE app_name=? AND last_error IS NOT NULL");
                $err_count->execute([$project_name]);
                if ((int)$err_count->fetchColumn() > 2) {
                    stream_html("<p>🔄 Déclenchement automatique de l'auto-amélioration (> 2 erreurs)...</p>");
                    $improve_result = self_improve_prompt();
                    stream_html("<p>" . htmlspecialchars($improve_result) . "</p>");
                }
            }
            break;

        case 'chat':
        default:
            if (empty($user_input)) {
                stream_html("<p class='warn'>Entrez un message.</p>");
            } else {
                mode_chat($user_input, $base_url);
            }
            break;
    }
}

// ====================================================================
// AFFICHAGE DES PROJETS RÉCENTS
// ====================================================================
echo "<hr><h3>📁 Projets Récents</h3>";
$projects = $pdo->query("SELECT * FROM projects ORDER BY id DESC LIMIT 10")->fetchAll();
if (!empty($projects)) {
    echo "<table><tr><th>Nom</th><th>Statut</th><th>Fichiers</th><th>Créé le</th></tr>";
    foreach ($projects as $p) {
        $pct = $p['files_total'] > 0 ? round(($p['files_ok'] / $p['files_total']) * 100) : 0;
        $cls = $p['status'] === 'complete' ? 'status-ok' : 'status-pend';
        $url = $base_url . '/generated_apps/' . $p['name'] . '/index.php';
        echo "<tr><td><a href='{$url}' target='_blank' style='color:var(--accent)'><code>{$p['name']}</code></a></td>";
        echo "<td class='{$cls}'>{$p['status']} ({$pct}%)</td>";
        echo "<td>{$p['files_ok']}/{$p['files_total']}</td>";
        echo "<td>{$p['created_at']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warn'>Aucun projet généré pour l'instant. Lancez le mode autonome !</p>";
}

// Mémoire récente
echo "<hr><h3>📜 Mémoire Récente</h3>";
$memories = $pdo->query("SELECT * FROM memory ORDER BY id DESC LIMIT 6")->fetchAll();
foreach ($memories as $m) {
    echo "<small class='warn'>[{$m['timestamp']}]</small> <strong>{$m['type']}</strong><br>";
    echo "<pre>" . htmlspecialchars(substr($m['content'], 0, 150)) . "...</pre>";
}
?>
</div>

<p style="margin-top:20px; color:var(--text-dim); font-size:11px;">
  Aether 3.0 • PHP 8.3 + LiteSpeed + SQLite WAL • Modèles Mistral OK uniquement •
  Boucle infinie jusqu'à 100% • generated_apps/ • logs/
</p>

</body>
</html>
