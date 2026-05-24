# Aether 3.0 — Agent Autonome de Développement PHP

Un système multi-agents autonome qui génère, teste et corrige automatiquement des applications web PHP/SQLite complètes.

## 🚀 Fonctionnalités

- **Mode Autonome** : Génération complète d'applications avec architecture automatique
- **Chat Avancé** : Conversation contextuelle avec extraction de code
- **Web Search** : Recherche DuckDuckGo intégrée pour enrichir le contexte
- **Auto-Amélioration** : Le système apprend de ses erreurs passées
- **Boucle de Correction Infinie** : Test HTTP automatique et corrections jusqu'à 100% de réussite
- **Multi-Agents** : Architecte, Générateur, Validateur, Correcteur
- **Sélection Intelligente de Modèles** : Utilise les meilleurs modèles Mistral selon la tâche

## 📋 Prérequis

- **PHP 8.2+** (testé sur 8.3)
- **PDO SQLite** activé
- **cURL** activé
- **LiteSpeed/Apache/Nginx** avec support HTTPS
- **Clé API Mistral** (https://console.mistral.ai/)

## 🔧 Installation

### 1. Cloner ou copier les fichiers

```bash
cd /votre/dossier/web
# Les fichiers doivent être présents : index.php, config.example.php
```

### 2. Configurer les clés API

```bash
cp config.example.php config.php
nano config.php
# Remplissez vos clés API Mistral dans $GLOBALS['api_keys']
```

### 3. Vérifier les permissions

```bash
chmod 755 generated_apps logs
chmod 644 index.php config.php
```

### 4. Accéder à l'interface

Ouvrez votre navigateur sur `https://votre-domaine.com/aether/`

## 🎯 Utilisation

### Mode Autonome (Recommandé)

1. Entrez votre demande (ex: "Crée une application de gestion de tâches")
2. Donnez un nom au projet (ex: `todo_app`)
3. Cliquez sur **🤖 Mode Autonome**

Le système va :
- Rechercher du contexte sur le web
- Définir l'architecture optimale
- Générer tous les fichiers (index.php, style.css, app.js, schema.sql, README.md)
- Tester chaque fichier PHP via HTTP
- Corriger automatiquement les erreurs détectées
- Vous fournir l'URL de l'application fonctionnelle

### Chat

Pour des questions ou des générations partielles de code.

### Web Search

Recherche d'informations techniques avant génération.

### Auto-Amélioration

Améliore le prompt maître en analysant l'historique des erreurs.

## 📁 Structure des Fichiers

```
aether/
├── index.php              # Application principale
├── config.php             # Configuration (clés API) - À CRÉER
├── config.example.php     # Exemple de configuration
├── aether_memory.sqlite   # Base de données (généré automatiquement)
├── generated_apps/        # Applications générées
│   ├── todo_app/
│   │   ├── index.php
│   │   ├── style.css
│   │   ├── app.js
│   │   ├── schema.sql
│   │   └── README.md
│   └── ...
└── logs/                  # Logs quotidiens
    └── aether_YYYY-MM-DD.log
```

## 🧠 Modèles Mistral Utilisés

| Tâche | Modèle | Tokens |
|-------|--------|--------|
| Code | codestral-2508 | 50k |
| Planification | magistral-medium-2509 | 75k |
| Contexte large | mistral-small-2603 | 375k |
| Chat | open-mistral-nemo | 50k |
| Analyse | magistral-small-2509 | 75k |

## ⚙️ Configuration Hostinger (Recommandé)

Le code inclut déjà les optimisations pour Hostinger :

```php
ini_set('max_execution_time', 600);
ini_set('memory_limit', '512M');
set_time_limit(600);
ignore_user_abort(true);
```

### .htaccess recommandé

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]

# Désactiver le buffering pour le streaming
SetOutputFilter DEFLATE
```

## 🛠 Dépannage

### Erreur "could not find driver"

Installez PDO SQLite :
```bash
# Debian/Ubuntu
apt-get install php-sqlite3

# Redémarrer PHP-FPM
systemctl restart php8.2-fpm
```

### Erreurs API 429 (Rate Limit)

- Ajoutez plusieurs clés API dans `config.php`
- Le système rotate automatiquement entre les clés

### Timeout de génération

- Augmentez `max_execution_time` dans `config.php`
- Réduisez la complexité de la demande initiale

### Fichiers non extraits

Vérifiez que l'IA utilise bien le format :
```xml
<code language="php" path="projet/index.php">/* code */</code>
```

## 📊 Statistiques

L'interface affiche :
- Nombre de fichiers générés
- Taux de validation HTTP
- Projets créés
- Entrées mémoire
- Auto-améliorations appliquées

## 🔒 Sécurité

- Ne jamais committer `config.php` dans Git
- Les clés API sont rotatées automatiquement
- Validation syntaxique du code avant exécution
- Logs complets dans `logs/`

## 📝 Licence

Usage personnel et commercial autorisé.

---

**Aether 3.0** • Développé avec ❤️ pour l'automatisation du développement web
