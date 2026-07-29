# Playground pédagogique de complétion LLM

Cette version utilise l'endpoint moderne de chat de Together AI :

```text
POST https://api.together.ai/v1/chat/completions
```

Un message système demande au modèle de produire uniquement la continuation du texte saisi. Cette formulation permet d'utiliser la structure moderne des `logprobs`, qui associe explicitement une liste d'alternatives à chaque token généré.

Le modèle configuré par défaut est :

```text
Qwen/Qwen3.5-9B
```

## Configuration recommandée

Définissez la clé API dans l'environnement du serveur web :

```bash
export TOGETHER_API_KEY="votre_cle_together"
export TOGETHER_MODEL="Qwen/Qwen3.5-9B"
```

Selon le serveur web utilisé, les variables doivent être configurées dans Apache, Nginx, PHP-FPM, le panneau d'hébergement ou le système de déploiement.

## Configuration locale alternative

Lorsque les variables d'environnement ne sont pas disponibles :

1. copiez `php/config.example.php` sous le nom `php/config.local.php` ;
2. remplacez la valeur d'exemple par votre clé Together AI ;
3. ne publiez jamais ce fichier et ne l'ajoutez pas au contrôle de version.

## Prérequis

- PHP avec l'extension cURL ;
- accès sortant HTTPS vers `api.together.ai` ;
- une clé API Together AI valide.

## Principales modifications

- remplacement de l'appel OpenAI par l'endpoint de chat Together AI ;
- suppression de la clé API intégrée au code ;
- modèle configurable ;
- validation du prompt et gestion des erreurs HTTP/JSON ;
- traitement défensif de plusieurs structures de `logprobs` ;
- échappement HTML des tokens affichés.


## Affichage des probabilités

Pour chaque position, l'interface affiche cinq candidats classés par probabilité
décroissante. Le token effectivement généré est indiqué en gras à son rang réel. L'endpoint de chat renvoie
une collection `top_logprobs` séparée pour chaque token généré ; le nombre de
candidats affichés ne dépend donc plus du rang probabiliste du token tiré. Les
probabilités très faibles sont affichées sous la forme `< 0,01%` au lieu d'être
arrondies à zéro.

Les probabilités correspondent au contexte complet réellement envoyé au modèle :
le message système de continuation et le texte saisi par l'utilisateur.
