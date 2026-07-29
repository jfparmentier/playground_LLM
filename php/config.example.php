<?php

/**
 * Copiez ce fichier sous le nom config.local.php, puis renseignez les clés API
 * nécessaires et les domaines de messagerie autorisés.
 *
 * Ne publiez jamais config.local.php et ne l'ajoutez pas à un dépôt Git.
 */
return [
    // Together AI : Qwen via un unique appel avec n = 1.
    'together_api_key' => 'COLLEZ_ICI_VOTRE_CLE_TOGETHER_AI',
    'together_model' => 'Qwen/Qwen3.5-9B',

    // Endpoint documenté pour les complétions Together AI.
    'together_completions_endpoint' =>
        'https://api.together.ai/v1/completions',

    // Fireworks AI : modèle serverless via /inference/v1/completions.
    'fireworks_api_key' => 'COLLEZ_ICI_VOTRE_CLE_FIREWORKS_AI',
    'fireworks_model' => 'accounts/fireworks/models/gpt-oss-20b',

    // OpenAI : modèle Instruct via /v1/completions.
    'openai_api_key' => 'COLLEZ_ICI_VOTRE_CLE_OPENAI',
    'openai_model' => 'gpt-3.5-turbo-instruct',

    // Saisissez un ou plusieurs domaines sans adresse utilisateur. Les formes
    // "ipsa.fr" et "@ipsa.fr" sont acceptées. Les sous-domaines ne sont pas
    // autorisés implicitement : ajoutez-les explicitement dans ce tableau.
    'email_domains' => [
        'ipsa.fr',
        'etudiant.ipsa.fr',
    ],
];
