# JeeNinSwi — Documentation utilisateur

> Plugin Jeedom de contrôle parental Nintendo Switch  
> Auteur : Antoine | ID plugin : `jeeninswi`

---

## Présentation

JeeNinSwi intègre l'application **Nintendo Switch Parental Controls** dans Jeedom. Il vous permet de superviser et de contrôler le temps de jeu de vos enfants directement depuis votre tableau de bord, sans avoir à ouvrir l'application Nintendo sur votre téléphone.

### Ce que le plugin peut faire

| Fonctionnalité | Description |
|---|---|
| Temps de jeu | Voir le temps joué aujourd'hui, ce mois, et le temps restant |
| Historique 7 jours | Graphique du temps de jeu sur les 7 derniers jours |
| Jeu en cours | Identifier le dernier jeu lancé |
| Limite quotidienne | Consulter la limite configurée dans l'app Nintendo |
| +15 min de bonus | Accorder un temps supplémentaire depuis Jeedom |
| +30 min de bonus | Accorder un bonus plus long |
| Bloquer maintenant | Couper l'accès à la console immédiatement |
| Lever la restriction | Restaurer l'accès normal |
| Mode alerte | La console envoie une alerte sans se couper |
| Mode blocage forcé | La console se coupe automatiquement à la fin du temps |
| Rafraîchir | Forcer une mise à jour des données depuis Nintendo |

### Ce que le plugin ne peut pas faire (limitations API Nintendo)

- Modifier la limite quotidienne (lecture seule via pynintendoparental)
- Contrôler le GameChat Switch 2 (API non publique)
- Voir l'avatar Nintendo (non exposé par l'API)
- Contrôler plusieurs comptes Nintendo avec des tokens différents par console

---

## Prérequis

- Jeedom 4.4 ou supérieur
- Python 3.8+ sur la machine Jeedom (généralement déjà présent)
- Accès à l'application **Nintendo Switch Parental Controls** sur votre téléphone
- Un compte Nintendo associé à la console de l'enfant

---

## Installation

### 1. Installer le plugin

Installez JeeNinSwi depuis le Market Jeedom ou copiez le dossier dans `plugins/`.

### 2. Installer les dépendances Python

Allez dans **Plugins → Gestion des plugins → JeeNinSwi** et cliquez sur **Installer les dépendances**.

Cette étape crée un environnement virtuel Python isolé dans `resources/venv/` et installe :
- `pynintendoparental` — bibliothèque de communication avec l'API Nintendo
- `aiohttp` — HTTP asynchrone (utilisé par le démon)
- `croniter` — calcul précis des intervalles de polling

> **Pourquoi un venv ?**  
> L'environnement virtuel isole les dépendances du plugin du Python système.  
> Aucun paquet n'est installé en dehors du dossier `resources/venv/`.

### 3. Activer le plugin et démarrer le démon

Dans la page de configuration du plugin, activez le plugin et démarrez le démon.  
Le démon est un processus Python persistant qui communique avec l'API Nintendo.

---

## Obtenir le token d'authentification Nintendo

Le plugin nécessite un **token de session Nintendo** pour accéder à l'API Parental Controls.  
Ce token est obtenu via un processus OAuth en 3 étapes.

### Étape 1 — Générer l'URL de connexion

1. Ouvrez la configuration d'un équipement JeeNinSwi
2. Cliquez sur **Assistant Token Nintendo**
3. Cliquez sur **Générer l'URL de connexion**
4. Une URL Nintendo apparaît → copiez-la et ouvrez-la dans votre navigateur

### Étape 2 — Vous connecter avec votre compte Nintendo

1. Connectez-vous avec le compte Nintendo **parent** (celui qui gère le contrôle parental)
2. Nintendo vous redirige vers une URL de type `npf54789befb391a838://auth#session_token_code=...`
3. **Copiez cette URL complète** (depuis la barre d'adresse du navigateur)

> **Conseil** : Sur mobile, l'URL s'affiche dans la barre d'adresse quelques instants avant  
> que l'app Nintendo Parental Controls s'ouvre. Copiez-la rapidement.  
> Sur PC, le navigateur affichera une erreur "impossible d'ouvrir" mais l'URL est visible.

### Étape 3 — Finaliser et créer les équipements

1. Collez l'URL copiée dans le champ **URL de redirection**
2. Cliquez sur **Valider**
3. Le plugin récupère automatiquement la liste de vos consoles et crée les équipements Jeedom

---

## Configuration des équipements

Chaque console Nintendo apparaît comme un **équipement Jeedom** indépendant.

### Options de configuration

| Option | Description | Défaut |
|---|---|---|
| Nom | Nom de l'équipement dans Jeedom | Nom Nintendo |
| Objet parent | Objet Jeedom auquel rattacher l'équipement | — |
| Activé | Active/désactive la supervision | Oui |
| Visible | Affiche/masque le widget sur le dashboard | Oui |
| Intervalle de polling | Fréquence de mise à jour (secondes) | 300 (5 min) |

> **Note** : Le nom de l'équipement dans Jeedom est indépendant du profil Nintendo.  
> Renommer un équipement dans Jeedom ne modifie pas l'app Nintendo, et vice versa.

---

## Widget Dashboard

Le widget JeeNinSwi affiche pour chaque console :

```
┌─────────────────────────────────┐
│ NOM PROFIL             [⟳]      │
│ TEMPS RESTANT : 1h 23min        │
├─────────────────────────────────┤
│  HISTORIQUE D'USAGE (7 JOURS)   │
│  [graphique barres verticales]  │
│  Lun. Mar. Mer. Jeu. Ven. Sam.  │
├─────────────────────────────────┤
│ +15 MIN  🎮 BONUS  ⚠ SIGNALER  │
├─────────────────────────────────┤
│    MODE DE RESTRICTION          │
│  [ALERTE]  [BLOCAGE FORCÉ]      │
└─────────────────────────────────┘
```

### Bouton Rafraîchir [⟳]

Force une mise à jour immédiate des données depuis l'API Nintendo.  
L'icône tourne pendant la requête.

### Graphique 7 jours

- **Vert** : temps joué dans la limite
- **Rouge** : temps joué hors limite (dépassement)
- **Gris** : aucune session de jeu ce jour
- **Barre bleue** : ligne de limite quotidienne

### Boutons d'action

- **+15 MIN** : ajoute 15 minutes au temps restant
- **🎮 BONUS** : ajoute 30 minutes (bonus plus long)
- **⚠ SIGNALER** : enregistre un signalement (usage interne, aucun impact Nintendo)

### Modes de restriction

- **ALERTE** : la console envoie une notification à l'enfant mais ne se coupe pas
- **BLOCAGE FORCÉ** : la console se coupe automatiquement à la fin du temps alloué

---

## Commandes disponibles

### Commandes INFO (informations)

| Nom | logicalId | Description |
|---|---|---|
| Pseudo | `pseudo` | Nom du profil Nintendo |
| Statut en ligne | `statut_en_ligne` | En ligne / Hors ligne |
| Jeu en cours | `jeu_en_cours` | Dernier jeu lancé |
| Temps de jeu (jour) | `temps_jour` | Minutes jouées aujourd'hui |
| Temps de jeu (mois) | `temps_semaine` | Minutes jouées ce mois |
| Temps restant | `temps_restant` | Minutes restantes (-1 = illimité) |
| Limite quotidienne | `temps_limite` | Limite en minutes (-1 = pas de limite) |
| Console bloquée | `console_bloquee` | 1 = bloquée, 0 = normale |
| Mode restriction | `mode_restriction` | 0 = blocage forcé, 1 = alerte |
| Historique jeux | `historique_jeux` | JSON des jeux du jour |
| Timeline JSON | `timeline_json` | JSON pour le graphique 7 jours |

### Commandes ACTION

| Nom | logicalId | Description |
|---|---|---|
| Rafraîchir | `rafraichir` | Mise à jour immédiate depuis Nintendo |
| Bloquer maintenant | `bloquer_maintenant` | Suspend l'accès à la console |
| Lever la restriction | `lever_restriction` | Restaure l'accès normal |
| Ajouter 15 min | `ajouter_temps_15` | Bonus de 15 minutes |
| Ajouter 30 min | `ajouter_temps_30` | Bonus de 30 minutes |
| Ajouter 60 min | `ajouter_temps_60` | Bonus d'une heure |
| Définir limite | `definir_limite` | Fixe la limite en minutes (slider 0-360) |
| Mode alerte | `mode_alerte` | Active le mode alerte |
| Mode blocage | `mode_blocage` | Active le mode blocage forcé |

---

## Scénarios Jeedom

Exemples de scénarios utiles avec JeeNinSwi :

### Couper la console à l'heure du dîner

```
Déclencheur : 18h30 tous les soirs
Condition   : [Switch Thiebault][console_bloquee] == 0
Action      : Commande [Switch Thiebault][Bloquer maintenant]
              Message push "Console coupée pour le dîner"
```

### Alerter si le temps dépasse 2h

```
Déclencheur : [Switch Thiebault][temps_jour] change
Condition   : [Switch Thiebault][temps_jour] > 120
Action      : Message push "Thiebault a joué plus de 2h aujourd'hui"
```

### Bonus le vendredi soir

```
Déclencheur : Vendredi 20h00
Condition   : [Switch Thiebault][console_bloquee] == 0
Action      : Commande [Switch Thiebault][Ajouter 60 min]
              Message push "Bonus d'1h accordé ce vendredi !"
```

---

## Dépannage

### Le démon ne démarre pas

1. Vérifiez que les dépendances sont installées (**OK** dans la page configuration)
2. Consultez les logs : **Analyse → Logs → jeeninswi**
3. Relancez l'installation des dépendances si nécessaire
4. Vérifiez que Python 3.8+ est disponible sur le serveur Jeedom

### Les données ne se mettent pas à jour

1. Cliquez sur **⟳** dans le widget pour forcer un rafraîchissement
2. Vérifiez les logs pour voir si le démon communique avec l'API Nintendo
3. Vérifiez que le token Nintendo est toujours valide (les tokens expirent après plusieurs mois)
4. Si le token est expiré, relancez l'**Assistant Token Nintendo**

### L'assistant token affiche une erreur

- **"URL de redirection invalide"** : L'URL copiée ne commence pas par `npf54789befb391a838://auth#`.  
  Assurez-vous de copier l'**URL complète** depuis la barre d'adresse du navigateur.
- **"Impossible de générer l'URL"** : Les dépendances Python ne sont pas installées.  
  Relancez l'installation des dépendances.
- **"Fichier d'état introuvable"** : Relancez depuis l'étape 1 (l'état OAuth a expiré).

### Les équipements sont créés en double

Cela ne devrait plus arriver avec la version actuelle. Si c'est le cas :
1. Supprimez les doublons depuis **Plugins → Monitoring → JeeNinSwi**
2. Relancez l'assistant token pour mettre à jour le token sur les équipements restants

---

## Architecture technique

Pour les développeurs ou utilisateurs avancés :

```
Jeedom (PHP)          Démon Python          API Nintendo
    │                      │                      │
    │── sendToDaemon ──────▶│                      │
    │   POST /action        │── NintendoParental ──▶│
    │                      │   (pynintendoparental)│
    │◀── callback.php ─────│◀──────────────────────│
    │   POST + JSON         │
    │── updateFromData ────▶│
    │   (cmd::event)        │
```

Le démon Python tourne en continu et poll l'API Nintendo toutes les 5 minutes (configurable).  
Il écoute également sur `127.0.0.1:8347` pour les actions immédiates (refresh, bonus, blocage).

---

## Support et contribution

- **Bugs et suggestions** : ouvrez une issue sur le dépôt GitHub du plugin
- **Logs de débogage** : activez le mode debug dans la configuration et consultez `Analyse → Logs → jeeninswi`
- **Bibliothèque Nintendo** : [pynintendoparental](https://github.com/pantherale0/pynintendoparental)
- **Référence protocole** : [nxapi](https://github.com/samuelthomas2774/nxapi)
