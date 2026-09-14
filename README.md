# TP LLM — interface pédagogique de complétion

Cette application permet d’illustrer le fonctionnement autoregressif d’un grand modèle de langage. L’utilisateur commence une phrase, le modèle la poursuit, puis l’interface affiche un arbre des probabilités associées aux tokens générés.

## Modèle utilisé

L’interface utilise par défaut **DeepSeek V4 Flash 0731**, développé par DeepSeek et appelé par l’API serverless Fireworks AI. Le choix du modèle n’est pas exposé dans l’interface.

## Configuration

Copiez le fichier `php/config.example.php` sous le nom `php/config.local.php`, puis renseignez les clés nécessaires :

```php
<?php

return [
    'together_api_key' => 'VOTRE_CLE_TOGETHER_AI',
    'together_model' => 'Qwen/Qwen3.5-9B',
    'together_completions_endpoint' =>
        'https://api.together.ai/v1/completions',

    'fireworks_api_key' => 'VOTRE_CLE_FIREWORKS_AI',
    'fireworks_model' => 'accounts/fireworks/models/gpt-oss-20b',
    'fireworks_deepseek_model' =>
        'accounts/fireworks/models/deepseek-v4-flash-0731',

    'openai_api_key' => 'VOTRE_CLE_OPENAI',
    'openai_model' => 'gpt-3.5-turbo-instruct',

    'email_domains' => [
        'ipsa.fr',
        'etudiant.ipsa.fr',
    ],
];
```

Le fichier `php/config.local.php` est exclu du dépôt par `.gitignore`. Il ne doit jamais être publié.

Les mêmes valeurs peuvent être définies avec les variables d’environnement suivantes :

- `TOGETHER_API_KEY`, `TOGETHER_MODEL`, `TOGETHER_COMPLETIONS_ENDPOINT` ;
- `FIREWORKS_API_KEY`, `FIREWORKS_MODEL`, `FIREWORKS_DEEPSEEK_MODEL` ;
- `OPENAI_API_KEY`, `OPENAI_MODEL`.

## Prérequis

- PHP avec l’extension cURL ;
- sessions PHP activées ;
- accès HTTPS sortant vers les API configurées ;
- au moins une clé API correspondant à un modèle proposé.

## Validation de l’adresse électronique

La syntaxe de l’adresse et son domaine sont contrôlés côté serveur. Les domaines autorisés sont définis par le tableau `email_domains` dans `php/config.local.php`. L’ancienne propriété `email_domain` reste acceptée pour compatibilité. Une session PHP autorisée est créée après validation et vérifiée avant chaque appel au modèle.

## Structure principale

- `index.html` : interface utilisateur ;
- `scripts/gestionLLM.js` : appels depuis l’interface, normalisation des probabilités et construction de l’arbre ;
- `php/appelLLM.php` : validation de la requête et routage vers le fournisseur sélectionné ;
- `php/verifieEmail.php` : validation serveur de l’adresse électronique ;
- `php/config.example.php` : modèle de configuration sans secret.

## Sécurité

Le navigateur transmet uniquement un identifiant de modèle autorisé. Les endpoints, les modèles et les clés API sont sélectionnés côté PHP. Aucune clé API ne doit être placée dans les fichiers JavaScript ou HTML.
