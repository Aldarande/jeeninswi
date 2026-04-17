# CLAUDE.md — Plugin Jeedom JeeNinSwi

> Contexte de référence chargé automatiquement par Claude Code.
> Auteur : Antoine | ID plugin : `jeeninswi` | Bibliothèque : pynintendoparental (pip)

---

## 1. IDENTITÉ DU PLUGIN

| Champ | Valeur |
|---|---|
| ID | `jeeninswi` (jamais "nintendo", "switch" ou autre variante) |
| Nom | JeeNinSwi |
| Auteur | Antoine |
| Catégorie | monitoring |
| Jeedom min | 4.4.0 |
| Licence | AGPL |
| Démon | Oui — `resources/jeeninswid/jeeninswid.py` |
| Dépendances | `pynintendoparental` (pip), `requests` (pip) |

---

## 2. ARCHITECTURE DES FICHIERS

```
jeeninswi/
├── plugin_info/
│   ├── info.json
│   ├── install.php          ← install/update/remove (pas de cron — polling via démon)
│   └── icon.png             ← REQUIS par Jeedom (128×128 PNG)
│
├── core/
│   ├── class/jeeninswi.class.php    ← logique métier (eqLogic + cmd)
│   ├── ajax/jeeninswi.ajax.php      ← endpoints AJAX (token wizard, statut, actions)
│   ├── php/callback.php             ← callback HTTP du démon → Jeedom
│   ├── i18n/fr_FR.json
│   └── template/
│       ├── dashboard/jeeninswi.html ← widget dashboard
│       └── mobile/jeeninswi.html   ← widget mobile
│
├── desktop/
│   ├── php/jeeninswi.php     ← page configuration équipements
│   ├── js/jeeninswi.js       ← frontend (appels AJAX → core/ajax/jeeninswi.ajax.php)
│   ├── css/jeeninswi.css
│   └── modal/token_setup.php ← assistant 3 étapes pour obtenir le token Nintendo
│
└── resources/                ← ORTHOGRAPHE ANGLAISE (pas "ressources")
    ├── jeeninswid/jeeninswid.py ← démon Python principal
    ├── auth_helper.py           ← helper auth one-shot (hors démon)
    └── install_dep.sh
```

---

## 3. ARCHITECTURE DU DÉMON

Le démon est un processus Python persistant qui :
- Connecte à l'API Nintendo Parental Controls via `pynintendoparental`
- Polle les données toutes les N secondes (config `poll_interval`, défaut 300s)
- Envoie les données à Jeedom via HTTP callback → `core/php/callback.php`
- Expose un mini-serveur HTTP sur `127.0.0.1:8347` pour recevoir les actions Jeedom

### Flux action Jeedom → démon
```
cmd::execute() → eqLogic::sendToDaemon(action, params)
→ POST http://127.0.0.1:8347/action
→ daemon.handle_action()
→ pynintendoparental API
```

### Flux données démon → Jeedom
```
daemon.fetch_all_devices()
→ daemon.send_callback(data)
→ POST {callback_url} (core/php/callback.php)
→ jeeninswi::callback($data)
→ eqLogic::updateFromData($data)
→ cmd::event($value)
```

---

## 4. COMMANDES JEEDOM (logicalIds réels)

### Commandes INFO (créées automatiquement via postSave)

| logicalId | Nom | Type | Sous-type |
|---|---|---|---|
| `pseudo` | Pseudo | info | string |
| `avatar_url` | Avatar URL | info | string |
| `statut_en_ligne` | Statut en ligne | info | string |
| `jeu_en_cours` | Jeu en cours | info | string |
| `jeu_en_cours_image` | Jeu image | info | string |
| `temps_jour` | Temps de jeu (jour) | info | numeric |
| `temps_semaine` | Temps de jeu (sem.) | info | numeric |
| `temps_restant` | Temps restant | info | numeric |
| `temps_limite` | Limite quotidienne | info | numeric |
| `historique_jeux` | Historique jeux | info | string (JSON) |
| `gamechat_actif` | GameChat actif | info | binary |
| `console_bloquee` | Console bloquée | info | binary |

### Commandes ACTION

| logicalId | Nom | Sous-type |
|---|---|---|
| `bloquer_maintenant` | Bloquer maintenant | other |
| `lever_restriction` | Lever la restriction | other |
| `ajouter_temps_15` | Ajouter 15 min | other |
| `ajouter_temps_30` | Ajouter 30 min | other |
| `ajouter_temps_60` | Ajouter 60 min | other |
| `definir_limite` | Définir limite (min) | slider (0-360) |
| `gamechat_on` | Activer GameChat | other |
| `gamechat_off` | Désactiver GameChat | other |
| `rafraichir` | Rafraîchir | other |

---

## 5. CONFIGURATION ÉQUIPEMENT (clés config)

| Clé | Description |
|---|---|
| `device_id` | ID de la console Nintendo (ex: `1234567890ABCDEF`) |
| `is_switch2` | Boolean — active les commandes Switch 2 (GameChat) |
| `poll_interval` | Intervalle de polling en secondes (défaut 300) |

### Configuration globale plugin (config::byKey)

| Clé | Description |
|---|---|
| `nintendo_token` | Token de session Nintendo (récupéré via l'assistant) |
| `device_ids` | JSON array des device IDs supervisés |
| `poll_interval` | Intervalle de polling global |

---

## 6. ENDPOINTS AJAX (core/ajax/jeeninswi.ajax.php)

Tous les appels JS pointent vers `core/ajax/jeeninswi.ajax.php` avec `action=xxx`.

| action | Description |
|---|---|
| `getDeviceStatus` | Retourne les valeurs courantes des commandes d'un équipement |
| `sendAction` | Exécute une commande action sur un équipement |
| `getAuthUrl` | Génère l'URL d'authentification Nintendo (auth_helper.py) |
| `exchangeToken` | Échange l'URL de redirection contre un token + liste des devices |
| `saveTokenAndDevices` | Sauvegarde le token et crée les équipements |

---

## 7. RÈGLES DE DÉVELOPPEMENT

1. **Classe cmd : nommage `{pluginId}Cmd`** — Jeedom génère `jeeninswiCmd` depuis l'ID du plugin. La classe doit s'appeler `jeeninswiCmd extends cmd`, pas `jeeninswi_cmd`. Même pattern que `ProJoteCmd` dans ProJote.
2. **`eqLogicManager` n'existe pas dans Jeedom 4.4** — utiliser `eqLogic::byType()`, `eqLogic::byTypeAndSearchConfiguration()`, `eqLogic::byId()`.
2. **Jamais de `isConnect()` en dehors d'une fonction dans `*.class.php`** — cela casse l'autoloading Jeedom. La vérification d'auth est dans le fichier ajax.
3. **AJAX** : toujours appeler `core/ajax/jeeninswi.ajax.php`, jamais `core/ajax/plugin.ajax.php` + `callPluginAjax`.
4. **AJAX PHP** : toujours commencer par `ajax::checkToken()` puis `isConnect('admin')`.
5. **Callback** : le fichier `callback.php` est public (appelé par le démon) mais vérifie que l'IP source est `127.0.0.1` ou `::1`.
6. **Templates** : les variables de remplacement sont `#variable#`. Toujours inclure `#eqLogic_id#` et `#name#`.
7. **Dossier ressources** : `resources/` (orthographe anglaise, contrairement à pawjote).
8. **Démon PID** : géré via `jeedom::getTmpFolder('jeeninswi') . '/daemon.pid'`.
9. **kill -9** : éviter — préférer `SIGTERM` puis vérifier, `SIGKILL` en dernier recours.
10. **Logs** : `log::add('jeeninswi', 'info|debug|warning|error', $message)`.
11. **i18n** : toutes les chaînes affichées passent par `__('chaîne', __FILE__)`.
12. **Dropdown objet parent** : `jeedom::getObjectDropdown()` n'existe pas — utiliser `jeeObject::buildTree(null, false)` avec un `<select class="eqLogicAttr form-control" data-l1key="object_id">` (voir pawjote comme référence).

---

## 8. BIBLIOTHÈQUE pynintendoparental

- Package pip : `pynintendoparental`
- Async Python (asyncio)
- Classe principale : `NintendoParentalControls`
- Authentification : OAuth Nintendo — nécessite un token de session obtenu via l'app

### Flux d'authentification (assistant token_setup.php)

1. **Étape 1** → `auth_helper.py --action get_auth_url` → retourne une URL Nintendo OAuth
2. **Étape 2** → L'utilisateur se connecte et copie l'URL de redirection `npf...://auth#...`
3. **Étape 3** → `auth_helper.py --action exchange_token --redirect-url "npf..."` → retourne token + liste devices
4. **Sauvegarde** → token stocké dans `config::byKey('nintendo_token', 'jeeninswi')`

### API principales

```python
from pynintendoparental import NintendoParentalControls

api = await NintendoParentalControls.from_token(token)
await api.update()
devices = api.devices  # liste de Device

device.id               # ID unique console
device.name             # Nom du profil
device.suspended        # bool
device.remaining_time   # minutes restantes
device.play_summary     # PlaySummary (today_playing_minutes, week_total_playing_minutes)
device.play_histories   # liste PlayHistory (software_title, total_playing_minutes, etc.)
device.game_chat_enabled # bool (Switch 2)

await device.set_suspended(True/False)
await device.add_bonus_time(minutes)
await device.set_play_time(minutes)
await device.set_game_chat_enabled(True/False)
```

---

## 9. SOURCES DE RÉFÉRENCE OFFICIELLES

### Plugin de référence — ProJote (PRIORITAIRE)

**C'est la référence principale à imiter.** Même auteur, même environnement, plugin le plus abouti.

| Fichier | Chemin local |
|---|---|
| Page desktop | `C:/Users/athie/SynologyDrive/Dev/ProJote/desktop/php/ProJote.php` |
| Classe principale | `C:/Users/athie/SynologyDrive/Dev/ProJote/core/class/ProJote.class.php` |
| AJAX | `C:/Users/athie/SynologyDrive/Dev/ProJote/core/ajax/ProJote.ajax.php` |
| JS frontend | `C:/Users/athie/SynologyDrive/Dev/ProJote/desktop/js/ProJote.js` |
| Template widget | `C:/Users/athie/SynologyDrive/Dev/ProJote/core/template/dashboard/ProJote.html` |

**Patterns clés à respecter (issus de ProJote) :**
- Traductions : `{{ma chaîne}}` (jamais `__('...', __FILE__)` dans les templates desktop)
- Objet parent : `jeeObject::buildTree(null, false)` dans un `<select>`
- Catégories : `jeedom::getConfiguration('eqLogic:category')`
- Cartes équipement : `$eqLogic->getImage()` + `$eqLogic->getHumanName(true, true)`
- Structure page : `eqLogicThumbnailDisplay` (liste) + `eqLogic` (détail) avec onglets
- Onglets obligatoires : flèche retour, Equipement, Commandes
- Fin de fichier : `include_file('desktop', 'jeeninswi', 'js', 'jeeninswi')` PUIS `include_file('core', 'plugin.template', 'js')`
- Boutons gestion : `eqLogicAction logoPrimary data-action="add"` + `data-action="gotoPluginConf"`

---

### Jeedom — à consulter 

| Ressource | URL | Usage |
|---|---|---|
| Classes PHP Jeedom (phpdoc) | https://doc.jeedom.com/dev/phpdoc/4.0/namespaces/default.html | Référence des classes et méthodes disponibles |
| Tutoriel plugin Jeedom | https://doc.jeedom.com/fr_FR/dev/tutorial_plugin | Structure de fichiers, cycle de vie |
| Démon et dépendances | https://doc.jeedom.com/fr_FR/dev/daemon_plugin | Pattern démon Python, install_dep.sh |
| Core Jeedom (GitHub) | https://github.com/jeedom/core | Source de vérité — méthodes réelles des classes |

**Règle** : toujours vérifier le phpdoc ou le core GitHub avant d'appeler une méthode Jeedom. Ne jamais inventer de méthode (`eqLogicManager`, `jeedom::getObjectDropdown`, etc. n'existent pas).

**Plugin de référence à imiter** : `pawjote` (dans ce même dépôt Docker) — même auteur, même patterns.

---

### Nintendo Switch — intégration API

| Ressource | URL | Usage |
|---|---|---|
| **nxapi** (référence principale) | https://github.com/samuelthomas2774/nxapi | Bibliothèque Node.js/TS, référence la plus complète pour l'API Parental Controls, NSO, SplatNet |
| pynintendoparental | https://github.com/pantherale0/pynintendoparental | Wrapper Python async utilisé par le démon |
| pynintendoparental PyPI | https://pypi.org/project/pynintendoparental/ | Installation pip |
| imink f-API | https://github.com/imink-app/f-API | Génération des tokens f/s pour l'auth OAuth Nintendo |

**Note nxapi** : c'est la référence la plus solide pour comprendre l'authentification Nintendo (HMAC, tokens de session, flux OAuth). Le démon Python s'appuie sur `pynintendoparental` qui implémente le même protocole. En cas de doute sur le comportement de l'API Nintendo, consulter nxapi en priorité.
