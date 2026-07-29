<?php

/**
 * Copiez ce fichier sous le nom config.local.php, puis renseignez votre clé
 * Together AI et le domaine de messagerie autorisé.
 *
 * Ne publiez jamais config.local.php et ne l'ajoutez pas à un dépôt Git.
 */
return [
    'api_key' => 'COLLEZ_ICI_VOTRE_CLE_TOGETHER_AI',
    'model' => 'Qwen/Qwen3.5-9B',

    // Saisissez le domaine sans adresse utilisateur. Les formes "ipsa.fr"
    // et "@ipsa.fr" sont acceptées. Les sous-domaines ne sont pas autorisés
    // implicitement : "etudiant.ipsa.fr" doit être configuré explicitement.
    'email_domain' => 'ipsa.fr',
];
