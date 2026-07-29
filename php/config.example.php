<?php

/**
 * Copiez ce fichier sous le nom config.local.php, puis renseignez les clés API
 * nécessaires et le domaine de messagerie autorisé.
 *
 * Ne publiez jamais config.local.php et ne l'ajoutez pas à un dépôt Git.
 */
return [
    // Together AI : Qwen via /v1/chat/completions.
    'together_api_key' => 'COLLEZ_ICI_VOTRE_CLE_TOGETHER_AI',
    'together_model' => 'Qwen/Qwen3.5-9B',

    // OpenAI : modèle Instruct via /v1/completions.
    'openai_api_key' => 'COLLEZ_ICI_VOTRE_CLE_OPENAI',
    'openai_model' => 'gpt-3.5-turbo-instruct',

    // Saisissez le domaine sans adresse utilisateur. Les formes "ipsa.fr"
    // et "@ipsa.fr" sont acceptées. Les sous-domaines ne sont pas autorisés
    // implicitement : "etudiant.ipsa.fr" doit être configuré explicitement.
    'email_domain' => 'ipsa.fr',
];
