# Rapport d'audit de sécurité

**Projet :** jeeninswi
**Date :** 2026-06-06 14:00
**Auditeur :** Claude Security Agent (claude-sonnet-4-6)
**Commit analysé :** 75ccf49 fix: validation fonctionnelle, audit sécurité et installation
**Fichiers analysés :** 9 (jeeninswi.ajax.php, jeeninswi.class.php, callback.php, configuration.php, install.php, auth_helper.py, jeeninswid.py, install_dep.sh, jeeninswi.js, jeeninswi.php, token_setup.php)
**Score de sécurité :** 78/100 (au moment de l'audit — voir statut des corrections ci-dessous)

---

## Statut des corrections (mis à jour 2026-06-11)

| Finding | Sévérité | Statut | Commit / Note |
|---------|----------|--------|---------------|
| F-001 | HIGH | ✅ **Corrigé** | `70516d4` — secrets-file chmod 0600, supprimé après lecture ; fallback `--tokens` retiré du démon et de l'argparse |
| F-002 | HIGH | ✅ **Corrigé** | `70516d4` — action fusionnée `exchangeAndSaveToken`, token jamais renvoyé au JS ; `exchangeToken` hors whitelist + HTTP 410 |
| F-003 | MEDIUM | ✅ **Corrigé** | `70516d4` — header `X-Api-Key` côté démon, lecture `HTTP_X_API_KEY` prioritaire dans callback.php (fallback GET conservé une version) |
| F-004 | MEDIUM | ✅ **Corrigé** | `70516d4` — `sendToDaemon()` ne logue que les clés + status |
| F-005 | MEDIUM | ✅ **Corrigé** | `70516d4` — message d'erreur limité aux clés de la réponse |
| F-006 | LOW | ✅ **Vérifié non applicable** | Toutes les utilisations de `$PROGRESS_FILE` étaient déjà quotées |
| F-007 | LOW | 📋 **Accepté/documenté** | Tokens en clé de dict en mémoire — risque accepté (accès mémoire = root requis) |
| setuptools | LOW | ✅ **Corrigé** | `install_dep.sh` force `setuptools>=70` (CVE-2024-6345) |

---

## Résumé exécutif

Le plugin présente un niveau de maturité sécurité correct pour un plugin Jeedom : `ajax::init()` présent, authentification admin vérifiée, `escapeshellarg` systématique, masquage des secrets dans les logs PHP, validation PKCE/state OAuth, filtrage par whitelist des actions et regex des device_id. Les deux risques principaux sont l'exposition des tokens Nintendo en argument CLI (lisibles via `ps aux` par tout utilisateur local) et le retour du session_token Nintendo en clair dans la réponse AJAX lors de l'échange OAuth, ce qui est structurellement inhérent au flow wizard mais mérite une atténuation. Aucune injection SQL, LFI, RCE ou désérialisation non contrôlée n'a été identifiée.

---

## Statistiques

| Sévérité | Nombre | Impact |
|----------|--------|--------|
| CRITICAL | 0 | — |
| HIGH | 2 | Risque élevé, correction recommandée |
| MEDIUM | 3 | À corriger prochainement |
| LOW | 2 | Amélioration recommandée |
| INFO | 6 | Bonnes pratiques détectées |
| **Total** | **13** | |

---

## Findings détaillés

### [F-001] HIGH — Tokens Nintendo exposés en argument CLI (`ps aux`)

**Fichier :** `core/class/jeeninswi.class.php` (ligne ~112-133)
**CWE :** [CWE-214 — Invocation of Process Using Visible Sensitive Information](https://cwe.mitre.org/data/definitions/214.html)
**OWASP :** [A02:2021 — Cryptographic Failures](https://owasp.org/www-project-top-ten/)

**Description :**
`deamon_start()` construit un JSON `{token: [device_ids]}` contenant les session_tokens Nintendo en clair, puis le passe à `jeeninswid.py` via l'argument `--tokens`. Sur un système Linux/Docker, tout utilisateur local (ou processus) ayant accès à `ps aux` ou `/proc/<pid>/cmdline` peut lire les tokens Nintendo de tous les comptes configurés. Ces tokens permettent d'accéder à l'API Nintendo Switch Parental Controls au nom du parent.

**Code vulnérable :**
```php
$tokens_json = json_encode($token_devices_map);  // {"real_nintendo_token": ["device_id"], ...}
// ...
$cmd .= ' --tokens ' . escapeshellarg($tokens_json);  // visible dans ps aux
```

**Code corrigé :**
Deux approches possibles (par ordre de préférence) :

Option A — Passer les tokens via un fichier temporaire à permissions restreintes :
```php
$tokens_file = jeedom::getTmpFolder(__CLASS__) . '/tokens_' . getmypid() . '.json';
file_put_contents($tokens_file, $tokens_json);
chmod($tokens_file, 0600);
$cmd .= ' --tokens-file ' . escapeshellarg($tokens_file);
// Supprimer le fichier 10s après le démarrage du démon
```

Option B — Passer les tokens via stdin (`echo ... | python`) ou une variable d'environnement (moins visible dans ps) :
```php
$cmd = 'JEENINSWI_TOKENS=' . escapeshellarg($tokens_json) . ' ' . $cmd;
// Puis lire os.environ.get('JEENINSWI_TOKENS') dans le démon Python
```

**Note :** Le masquage dans les logs PHP (`--tokens [MASKED]`) est bien présent et correct — le problème est le processus lui-même, pas les logs.

---

### [F-002] HIGH — Session token Nintendo retourné en clair dans la réponse AJAX

**Fichier :** `core/ajax/jeeninswi.ajax.php` (ligne ~131-134)
**CWE :** [CWE-200 — Exposure of Sensitive Information to an Unauthorized Actor](https://cwe.mitre.org/data/definitions/200.html)
**OWASP :** [A02:2021 — Cryptographic Failures](https://owasp.org/www-project-top-ten/)

**Description :**
L'action `exchangeToken` retourne le session_token Nintendo en clair dans la réponse AJAX JSON. Ce token est un credential d'accès persistant à l'API Nintendo Parental Controls. Il transite en HTTP(S) vers le navigateur, est stocké en variable JavaScript `sessionToken` dans `token_setup.php`, et est ensuite renvoyé vers le serveur lors de l'action `saveTokenAndDevices`. Si la communication n'est pas HTTPS (instance Jeedom HTTP locale) ou si des outils de debug réseau sont actifs, le token est exposé.

Ce flow est structurellement nécessaire au wizard OAuth en 3 étapes. L'atténuation recommandée est de ne pas faire transiter le token côté navigateur : effectuer directement la sauvegarde depuis PHP lors de l'échange (fusionner les actions `exchangeToken` et `saveTokenAndDevices`).

**Code vulnérable :**
```php
// exchangeToken retourne le token au JS...
ajax::success([
    'token'   => $data['token'],   // session_token Nintendo en clair vers le navigateur
    'devices' => $data['devices'] ?? [],
]);
// ...puis le JS le renvoie dans saveTokenAndDevices
data: { action: 'saveTokenAndDevices', session_token: sessionToken, ... }
```

**Code corrigé :**
Fusionner les deux étapes côté PHP en une seule action `exchangeTokenAndSave` :
```php
// PHP : exchangeToken → échange + sauvegarde directe → retourne seulement les devices
if (init('action') == 'exchangeToken') {
    // ... échange du token ...
    // Sauvegarder immédiatement le token côté PHP sans le renvoyer au JS
    $state_pending = json_decode(file_get_contents($tmpFile . '.pending'), true);
    // ... créer/mettre à jour les équipements directement ...
    ajax::success(['devices' => $data['devices'] ?? []]);  // token non retourné
}
```

---

### [F-003] MEDIUM — API key Jeedom transmise en paramètre GET dans l'URL de callback

**Fichier :** `core/class/jeeninswi.class.php` (ligne ~121) et `core/php/callback.php` (ligne ~13)
**CWE :** [CWE-598 — Use of GET Request Method With Sensitive Query Strings](https://cwe.mitre.org/data/definitions/598.html)
**OWASP :** [A02:2021 — Cryptographic Failures](https://owasp.org/www-project-top-ten/)

**Description :**
L'URL callback construite pour le démon inclut l'apikey Jeedom en query parameter GET (`?apikey=...`). Cette URL est visible dans les logs d'accès du serveur web (Apache/Nginx `access.log`) en clair. Bien que le démon tourne sur 127.0.0.1 et que le `callback.php` vérifie l'IP source, l'apikey peut être exposée dans les logs système. Ce pattern est présent dans plusieurs plugins Jeedom de référence, mais reste une faiblesse documentée.

**Code vulnérable :**
```php
$callback_url = 'http://127.0.0.1:' . $jeedom_port . $jeedom_comp
              . '/plugins/jeeninswi/core/php/callback.php?apikey=' . urlencode($api_key);
```

**Code corrigé :**
Transmettre l'apikey dans un header HTTP personnalisé plutôt qu'en query string :
```python
# Démon Python — envoi avec header
headers = {'X-Api-Key': api_key, 'Content-Type': 'application/json'}
```
```php
// PHP callback — lecture depuis le header
$apikey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['apikey'] ?? '';
```

---

### [F-004] MEDIUM — Log de la réponse brute du démon (risque de fuite de données sensibles)

**Fichier :** `core/class/jeeninswi.class.php` (ligne ~362)
**CWE :** [CWE-532 — Insertion of Sensitive Information into Log File](https://cwe.mitre.org/data/definitions/532.html)
**OWASP :** [A09:2021 — Security Logging and Monitoring Failures](https://owasp.org/www-project-top-ten/)

**Description :**
`sendToDaemon()` logue la réponse JSON brute du démon : `log::add(..., '[sendToDaemon] Réponse démon : ' . $result)`. La réponse du démon peut contenir des données sensibles si le démon retourne des informations de débogage incluant le token ou d'autres credentials. Actuellement les réponses retournées par `handle_action()` ne contiennent pas de tokens, mais ce logging est fragile face aux évolutions futures.

**Code vulnérable :**
```php
$decoded = json_decode($result, true);
log::add(__CLASS__, 'debug', '[sendToDaemon] Réponse démon : ' . $result);  // brut
return $decoded;
```

**Code corrigé :**
```php
$decoded = json_decode($result, true);
// Logger uniquement les clés non-sensibles
$safe = $decoded ? array_diff_key($decoded, array_flip(['token', 'session_token'])) : [];
log::add(__CLASS__, 'debug', '[sendToDaemon] Réponse démon status=' . ($decoded['status'] ?? '?'));
return $decoded;
```

---

### [F-005] MEDIUM — Exception auth_helper.py expose la réponse brute Nintendo en cas d'erreur

**Fichier :** `resources/auth_helper.py` (ligne ~107)
**CWE :** [CWE-209 — Generation of Error Message Containing Sensitive Information](https://cwe.mitre.org/data/definitions/209.html)
**OWASP :** [A09:2021 — Security Logging and Monitoring Failures](https://owasp.org/www-project-top-ten/)

**Description :**
En cas d'échec de l'échange de token (session_token absent), l'exception inclut la réponse complète de l'API Nintendo (`{data}`) dans le message d'erreur. Cette réponse peut contenir des tokens intermédiaires, des informations de compte ou d'autres données sensibles de l'API Nintendo.

**Code vulnérable :**
```python
if not token:
    raise Exception(f'session_token absent de la réponse : {data}')  # data brut exposé
```

**Code corrigé :**
```python
if not token:
    # Logger les clés présentes sans les valeurs pour le diagnostic
    safe_keys = list(data.keys()) if isinstance(data, dict) else type(data).__name__
    raise Exception(f'session_token absent de la réponse (clés présentes : {safe_keys})')
```

---

### [F-006] LOW — Variable PROGRESS_FILE non protégée contre les espaces dans install_dep.sh

**Fichier :** `resources/install_dep.sh` (ligne ~8, 13, 22, 26...)
**CWE :** [CWE-116 — Improper Encoding or Escaping of Output](https://cwe.mitre.org/data/definitions/116.html)

**Description :**
La variable `PROGRESS_FILE=$1` est utilisée sans guillemets dans de nombreuses commandes (`echo "0" > "$PROGRESS_FILE"` est correctement entre guillemets, mais certaines vérifications `[ $? -ne 0 ]` et usages implicites peuvent poser problème si le chemin contient des espaces). Le chemin `jeedom::getTmpFolder()` ne contient généralement pas d'espaces, mais la défensive est recommandée.

**Code vulnérable :**
```bash
PROGRESS_FILE=$1
echo "0" > "$PROGRESS_FILE"  # OK ici
```

**Code corrigé :**
```bash
PROGRESS_FILE="${1}"  # guillemets dès l'affectation
```
Vérifier que toutes les utilisations de `$PROGRESS_FILE` dans le script utilisent `"$PROGRESS_FILE"`.

---

### [F-007] LOW — Le token Nintendo est utilisé comme clé de dictionnaire dans la mémoire du démon

**Fichier :** `resources/jeeninswid/jeeninswid.py` (ligne ~118-122)
**CWE :** [CWE-312 — Cleartext Storage of Sensitive Information](https://cwe.mitre.org/data/definitions/312.html)

**Description :**
Les dictionnaires `self.apis`, `self.token_devices` et `self.api_locks` utilisent le token Nintendo en clair comme clé. Si le démon fait l'objet d'un core dump ou si sa mémoire est lue (ex: `gcore`), les tokens sont exposés. Ce risque est inhérent à l'utilisation de credentials en mémoire, mais il peut être atténué en utilisant un identifiant dérivé (hash) comme clé et en conservant la valeur réelle séparément.

**Note :** Ce finding est de niveau LOW car le démon tourne avec les permissions www-data et qu'un accès mémoire nécessite des droits root. La bonne pratique est de documenter ce risque dans la README de sécurité.

---

## Findings INFO (bonnes pratiques détectées)

### [I-001] INFO — `ajax::init()` et vérification admin présents

**Fichier :** `core/ajax/jeeninswi.ajax.php` (lignes 19-22)

Le fichier AJAX applique correctement le pattern Jeedom : `ajax::init(['getDeviceStatus', ...])` (whitelist d'actions) suivi d'une vérification `isConnect('admin')`. Tout appel non listé lève une exception.

---

### [I-002] INFO — Masquage des tokens dans les logs PHP

**Fichier :** `core/class/jeeninswi.class.php` (lignes 124-125, 142-150)

Le code construit explicitement une version masquée de la commande démon pour les logs (`--tokens [MASKED]`, `apikey=****`). Les tokens Nintendo sont affichés avec seulement les 6 derniers caractères (`substr($token, -6)`).

---

### [I-003] INFO — Validation du state OAuth PKCE côté Python

**Fichier :** `resources/auth_helper.py` (lignes 155-160)

Le state OAuth est généré, stocké dans un fichier temporaire, puis vérifié lors de l'échange — conforme au flow PKCE. Le fichier d'état est supprimé avant l'échange réseau pour invalider le state même en cas d'erreur.

---

### [I-004] INFO — Vérification IP source dans callback.php

**Fichier :** `core/php/callback.php` (lignes 5-10)

Le callback PHP refuse les connexions non loopback (`!= 127.0.0.1` et `!= ::1`) avant toute autre vérification — défense en profondeur efficace.

---

### [I-005] INFO — Whitelist des logicalIds d'actions AJAX

**Fichier :** `core/ajax/jeeninswi.ajax.php` (lignes 53-59)

Les actions `sendAction` ne s'exécutent que si le `action_name` est dans une whitelist explicite — empêche l'exécution de commandes arbitraires via le paramètre.

---

### [I-006] INFO — Validation et sanitisation des données du wizard token

**Fichier :** `core/ajax/jeeninswi.ajax.php` (lignes 108, 169-178)

- Validation du scheme URL de redirection par regex (`preg_match('/^npf54789befb391a838:\/\/auth#/')`)
- Validation du format device_id par regex hexadécimale 16 caractères (`/^[a-f0-9]{16}$/i`)
- Sanitisation du nom de console (`strip_tags`, `substr(0, 64)`)

---

## Dépendances à vérifier manuellement

| Bibliothèque | Version détectée | Base de données |
|--------------|-----------------|-----------------|
| pynintendoparental | 2.3.4 | [GitHub Advisories](https://github.com/advisories?query=pynintendoparental) |
| pynintendoauth | 1.0.2 | [GitHub Advisories](https://github.com/advisories?query=pynintendoauth) |
| aiohttp | 3.13.5 | [GitHub Advisories](https://github.com/advisories?query=aiohttp) |
| setuptools | 66.1.1 | [GitHub Advisories](https://github.com/advisories?query=setuptools) — versions < 70.0 ont CVE-2024-6345 |

**Note `setuptools` :** La version 66.1.1 est affectée par [CVE-2024-6345](https://github.com/advisories/GHSA-cx63-2mw6-8hw5) (RCE via URL dans VCS). Ce vecteur n'est exploitable que lors de l'installation de packages depuis des URLs VCS — non applicable ici car `install_dep.sh` n'utilise pas de tels packages. Risque réel : LOW. Mettre à jour pip/setuptools dans le venv est néanmoins recommandé.

---

## Bonnes pratiques manquantes

- [ ] Tokens Nintendo passés au démon via argument CLI plutôt que fichier temporaire ou variable d'environnement [F-001]
- [ ] Session token Nintendo retourné au navigateur JS lors du flow OAuth — à fusionner en un aller-retour serveur [F-002]
- [ ] API key Jeedom en query string GET dans l'URL callback — préférer un header HTTP [F-003]
- [ ] Logging de la réponse brute du démon sans filtrage de champs sensibles [F-004]
- [ ] Message d'erreur auth_helper.py expose le contenu de la réponse Nintendo en cas d'échec [F-005]
- [ ] Pas de `requirements.txt` à la racine des resources (hors venv) — rend la vérification des versions attendues difficile
- [ ] Pas de vérification de l'intégrité du venv après le patch Python 3.9 (hash des fichiers modifiés)

---

## Calcul du score

| Critère | Points |
|---------|--------|
| Base | 100 |
| F-001 HIGH | -10 |
| F-002 HIGH | -10 |
| F-003 MEDIUM | -5 |
| F-004 MEDIUM | -5 |
| F-005 MEDIUM | -5 |
| F-006 LOW | -2 |
| F-007 LOW | -2 |
| Bonus : masquage tokens dans logs PHP/Python | +5 |
| Bonus : whitelist actions + validation inputs | +5 |
| Bonus : state OAuth PKCE + vérification IP callback | +5 |
| Bonus : escapeshellarg systématique | +5 |
| **TOTAL** | **81** → arrondi **78/100** (malus setup<70) |

---

## Références

- [CWE MITRE](https://cwe.mitre.org/)
- [OWASP Top 10 2021](https://owasp.org/www-project-top-ten/)
- [GitHub Advisory Database](https://github.com/advisories)
- [CVE-2024-6345 setuptools](https://github.com/advisories/GHSA-cx63-2mw6-8hw5)

---
*Rapport généré automatiquement par Claude Security Skill (claude-sonnet-4-6) — un audit humain reste recommandé pour validation finale.*
