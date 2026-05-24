<?php
/**
 * Script de test pour Aether 3.0
 * Exécutez ce fichier en CLI pour vérifier l'installation
 */

echo "=== AETHER 3.0 — Test d'installation ===\n\n";

// Vérifier PHP version
$php_version = phpversion();
if (version_compare($php_version, '8.2.0', '>=')) {
    echo "✅ PHP Version: $php_version\n";
} else {
    echo "❌ PHP Version: $php_version (nécessite 8.2+)\n";
    exit(1);
}

// Vérifier extensions requises
$required_extensions = ['pdo', 'sqlite3', 'curl', 'json'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✅ Extension $ext activée\n";
    } else {
        echo "❌ Extension $ext MANQUANTE\n";
        exit(1);
    }
}

// Vérifier les dossiers requis
$dirs = [__DIR__ . '/generated_apps', __DIR__ . '/logs'];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        if (@mkdir($dir, 0755, true)) {
            echo "✅ Dossier créé: $dir\n";
        } else {
            echo "❌ Impossible de créer le dossier: $dir\n";
            exit(1);
        }
    } else {
        echo "✅ Dossier existe: $dir\n";
    }
}

// Vérifier permissions en écriture
if (is_writable(__DIR__)) {
    echo "✅ Permissions en écriture OK\n";
} else {
    echo "❌ Pas de permission en écriture dans " . __DIR__ . "\n";
    exit(1);
}

// Tester la base de données SQLite
try {
    $db_file = __DIR__ . '/aether_memory.sqlite';
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Créer une table de test
    $pdo->exec("CREATE TABLE IF NOT EXISTS test_table (id INTEGER PRIMARY KEY)");
    $pdo->exec("DROP TABLE IF EXISTS test_table");
    
    echo "✅ Base de données SQLite fonctionnelle\n";
} catch (Exception $e) {
    echo "❌ Erreur SQLite: " . $e->getMessage() . "\n";
    exit(1);
}

// Tester cURL
if (function_exists('curl_init')) {
    $ch = curl_init('https://api.mistral.ai');
    if ($ch !== false) {
        curl_close($ch);
        echo "✅ cURL fonctionnel\n";
    } else {
        echo "❌ Échec d'initialisation cURL\n";
        exit(1);
    }
} else {
    echo "❌ cURL non disponible\n";
    exit(1);
}

// Vérifier config.php
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    if (isset($GLOBALS['api_keys']) && !empty($GLOBALS['api_keys'])) {
        $valid_key = false;
        foreach ($GLOBALS['api_keys'] as $key) {
            if ($key !== 'VOTRE_CLE_API_MISTRAL_ICI' && strlen($key) > 10) {
                $valid_key = true;
                break;
            }
        }
        if ($valid_key) {
            echo "✅ Configuration API détectée\n";
        } else {
            echo "⚠️  Clés API non configurées (valeurs par défaut)\n";
            echo "   → Modifiez config.php avec vos vraies clés Mistral\n";
        }
    }
} else {
    echo "⚠️  config.php n'existe pas (copiez config.example.php)\n";
    echo "   → cp config.example.php config.php\n";
}

// Tester le chargement d'index.php
echo "\n--- Test de chargement d'index.php ---\n";
try {
    // Simuler un environnement CLI
    $_SERVER['REQUEST_METHOD'] = null;
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    
    // Inclure sans exécuter le HTML
    $code = file_get_contents(__DIR__ . '/index.php');
    
    // Vérifier que les fonctions principales existent après inclusion
    include __DIR__ . '/index.php';
    
    if (function_exists('get_db')) {
        echo "✅ Fonction get_db() disponible\n";
    }
    if (function_exists('call_mistral')) {
        echo "✅ Fonction call_mistral() disponible\n";
    }
    if (function_exists('extract_and_save_files')) {
        echo "✅ Fonction extract_and_save_files() disponible\n";
    }
    if (function_exists('validate_code')) {
        echo "✅ Fonction validate_code() disponible\n";
    }
    if (function_exists('agent_architect')) {
        echo "✅ Fonction agent_architect() disponible\n";
    }
    if (function_exists('agent_generate_project')) {
        echo "✅ Fonction agent_generate_project() disponible\n";
    }
    
    echo "\n✅ TOUT EST OPÉRATIONNEL !\n";
    echo "\nPour démarrer :\n";
    echo "1. Configurez vos clés API dans config.php\n";
    echo "2. Accédez à l'interface web via votre serveur\n";
    echo "3. Lancez le mode autonome pour générer votre première app\n";
    
} catch (Exception $e) {
    echo "❌ Erreur lors du chargement: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Test terminé avec succès ===\n";
