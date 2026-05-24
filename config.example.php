<?php
// =================================================================
// AETHER 3.0 — FICHIER DE CONFIGURATION
// Copiez ce fichier en config.php et remplissez vos clés API
// =================================================================

// Ne jamais committer ce fichier dans Git
// Ajoutez config.php à votre .gitignore

$GLOBALS['api_keys'] = [
    // Remplacez par vos VRAIES clés API Mistral
    // Obtenez-les sur https://console.mistral.ai/
    'VOTRE_CLE_API_MISTRAL_ICI',
    // Vous pouvez ajouter plusieurs clés pour la rotation
    // 'DEUXIEME_CLE_OPTIONNELLE',
];

$GLOBALS['endpoint'] = 'https://api.mistral.ai/v1/chat/completions';

// Autres configurations optionnelles
$GLOBALS['db_file']    = __DIR__ . '/aether_memory.sqlite';
$GLOBALS['apps_dir']   = __DIR__ . '/generated_apps';
$GLOBALS['logs_dir']   = __DIR__ . '/logs';

// Limites
$GLOBALS['max_iterations']    = 20;        // Max itérations de correction
$GLOBALS['max_execution_time'] = 600;      // Secondes (10 min)
$GLOBALS['memory_limit']       = '512M';   // Limite mémoire PHP

// Modèles (optionnel - les défauts sont dans index.php)
// $GLOBALS['models'] = [ ... ];
