# JeeNinSwi — Plugin Jeedom Nintendo Switch

Plugin de contrôle parental Nintendo Switch & Switch 2 pour Jeedom.

## Fonctionnalités

- 🎮 Supervision de la console (jeu en cours, statut, avatar)
- ⏱️ Temps de jeu du jour et de la semaine
- 📚 Historique des jeux joués
- 🔴 Blocage immédiat de la console
- ✅ Lever les restrictions à distance
- ⏰ Ajout de temps bonus (15 / 30 / 60 min)
- 📅 Définition de la limite quotidienne
- 📆 Plannings par jour (ex. 15 min en semaine, 3h le week-end)
- 💬 Gestion du GameChat (Switch 2)
- ✅ Compatible Nintendo Switch 1 et Switch 2

## Prérequis

- Jeedom >= 4.4
- Python 3.8+
- Compte Nintendo avec l'app Parental Controls configurée
- La console doit être liée à votre compte superviseur

## Installation

1. Installer le plugin depuis le Market Jeedom
2. Installer les dépendances (bouton "Installer les dépendances")
3. Lancer le démon
4. Utiliser l'**assistant de token** pour vous authentifier
5. Les équipements sont créés automatiquement

## Structure du plugin

```text
jeeninswi/
├── plugin_info/info.json           ← Métadonnées Jeedom
├── core/
│   ├── class/jeeninswi.class.php  ← Logique principale
│   ├── php/callback.php            ← Réception données démon
│   └── i18n/fr_FR.json            ← Traductions
├── desktop/
│   ├── php/jeeninswi.php          ← Interface admin
│   ├── modal/token_setup.php      ← Assistant authentification
│   ├── js/jeeninswi.js            ← JavaScript frontend
│   └── css/jeeninswi.css          ← Styles
└── resources/
    ├── install_dep.sh              ← Installation dépendances
    └── jeeninswid/
        └── jeeninswid.py          ← Démon Python
```

## API utilisée

Ce plugin utilise `pynintendoparental`, une librairie Python open source (MIT)
qui wrappe l'API non officielle de l'app Nintendo Switch Parental Controls.

Le token d'authentification est récupéré une seule fois via un flux OAuth
intercepté, puis stocké de manière sécurisée dans la configuration Jeedom.

## Commandes disponibles

### Informations (lecture)

| ID | Nom | Type |
| --- | --- | --- |
| pseudo | Pseudo | string |
| statut_en_ligne | Statut en ligne | string |
| jeu_en_cours | Jeu en cours | string |
| temps_jour | Temps de jeu (jour) | numeric (min) |
| temps_semaine | Temps de jeu (semaine) | numeric (min) |
| temps_restant | Temps restant | numeric (min) |
| temps_limite | Limite quotidienne | numeric (min) |
| historique_jeux | Historique jeux | JSON string |
| gamechat_actif | GameChat actif | binary (Switch 2) |
| console_bloquee | Console bloquée | binary |

### Actions

| ID | Nom | Paramètre |
| --- | --- | --- |
| bloquer_maintenant | Bloquer maintenant | — |
| lever_restriction | Lever la restriction | — |
| ajouter_temps_15 | Ajouter 15 min | — |
| ajouter_temps_30 | Ajouter 30 min | — |
| ajouter_temps_60 | Ajouter 60 min | — |
| definir_limite | Définir limite | slider (0-360 min) |
| gamechat_on | Activer GameChat | — |
| gamechat_off | Désactiver GameChat | — |
| rafraichir | Rafraîchir | — |

## Soutenir le projet

JeeNinSwi est gratuit et open-source. Si vous l'appréciez, vous pouvez soutenir son développement :

[![Ko-fi](https://img.shields.io/badge/Ko--fi-Offrir%20un%20café-FF5E5B?logo=ko-fi&logoColor=white)](https://ko-fi.com/aldarande)
[![GitHub Sponsors](https://img.shields.io/badge/GitHub%20Sponsors-Sponsoriser-ea4aaa?logo=github-sponsors&logoColor=white)](https://github.com/sponsors/Aldarande)

## Licence

AGPL v3
