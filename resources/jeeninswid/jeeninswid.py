#!/usr/bin/env python3
"""
JeeNinSwi - Démon principal
Gère la communication avec l'API Nintendo Switch Parental Controls
via la bibliothèque pynintendoparental (async, pip).

Architecture :
  - Un serveur HTTP aiohttp écoute sur 127.0.0.1:{port} pour recevoir les
    commandes de Jeedom (actions : refresh, add_bonus_time, suspend, etc.)
  - Une boucle de polling contacte l'API Nintendo toutes les N secondes
    (ou selon une expression cron si croniter est installé)
  - Les données récupérées sont envoyées à Jeedom via HTTP callback
    vers core/php/callback.php

Flux action Jeedom → démon :
  cmd::execute() → eqLogic::sendToDaemon() → POST /action → handle_action()
  → pynintendoparental API

Flux données démon → Jeedom :
  fetch_all_devices() → process_device() → send_callback()
  → POST callback.php → jeeninswi::callback() → updateFromData()
"""

import argparse
import asyncio
import json
import logging
import os
import signal
import sys
import time
from datetime import datetime, timedelta   # Imports à ce niveau pour éviter les imports dynamiques

import hashlib

import aiohttp
from aiohttp import web

# ─── Chemins cache images ─────────────────────────────────────────────────────
# jeeninswid.py est dans plugins/jeeninswi/resources/jeeninswid/ → remonter 4 niveaux
_DAEMON_DIR   = os.path.dirname(os.path.abspath(__file__))
_JEEDOM_ROOT  = os.path.normpath(os.path.join(_DAEMON_DIR, '..', '..', '..', '..'))
IMG_CACHE_DIR = os.path.join(_JEEDOM_ROOT, 'data', 'jeeninswi', 'images')
IMG_CACHE_WEB = 'data/jeeninswi/images'  # URL relative dans le navigateur

# croniter : si installé, permet un polling précis selon une expression cron
try:
    from croniter import croniter
    HAS_CRONITER = True
except ImportError:
    HAS_CRONITER = False

# ─── Import pynintendoparental ────────────────────────────────────────────────
# La bibliothèque doit être installée dans le venv via install_dep.sh
try:
    from pynintendoparental import NintendoParental
    from pynintendoparental.authenticator import Authenticator
    from pynintendoparental.exceptions import NoDevicesFoundException
    from pynintendoparental.enum import RestrictionMode   # Import top-level pour éviter l'import dans handle_action()
except ImportError as e:
    print(
        f"ERREUR: pynintendoparental non installé ({e}). "
        "Lancez le script d'installation des dépendances depuis l'interface Jeedom."
    )
    sys.exit(1)

# InvalidSessionTokenException : dans pynintendoauth si disponible
try:
    from pynintendoauth.exceptions import InvalidSessionTokenException
except ImportError:
    InvalidSessionTokenException = Exception


# ─── Configuration du logging ─────────────────────────────────────────────────
def setup_logging(log_file: str, debug: bool = False) -> logging.Logger:
    """
    Configure le logger principal.
    - En mode debug : niveau DEBUG (très verbeux, pour diagnostic)
    - En mode normal : niveau INFO (événements importants uniquement)
    - Si log_file est fourni : écriture dans le fichier Jeedom (pas de stdout)
    """
    level = logging.DEBUG if debug else logging.INFO
    if log_file:
        handlers = [logging.FileHandler(log_file)]
    else:
        handlers = [logging.StreamHandler()]
    logging.basicConfig(
        level=level,
        format='%(asctime)s [%(levelname)s] %(filename)s:%(lineno)d %(message)s',
        datefmt='%Y-%m-%d %H:%M:%S',
        handlers=handlers
    )
    return logging.getLogger('jeeninswi')


# ─── Classe principale du démon ───────────────────────────────────────────────
class JeeNinSwiDaemon:
    """
    Démon JeeNinSwi — gère plusieurs comptes Nintendo en parallèle.

    Chaque compte Nintendo est identifié par son token de session.
    Un verrou asyncio par token sérialise les accès à l'API Nintendo
    pour éviter les conflits (limite de débit côté Nintendo).
    """

    def __init__(self, args):
        self.port          = args.port
        self.callback_url  = args.callback
        self.poll_cron     = args.poll_cron
        self.poll_interval = self._cron_to_seconds(args.poll_cron)
        self.pid_file      = args.pid_file
        self.log           = setup_logging(args.log_file, args.debug)
        self.running       = True

        # ── Structures multi-comptes ────────────────────────────────────────
        # apis : token → instance NintendoParental (l'API pour ce compte)
        self.apis: dict         = {}
        # token_devices : token → set(device_ids) supervisés pour ce compte
        self.token_devices: dict = {}
        # api_locks : token → asyncio.Lock — un verrou par compte Nintendo
        self.api_locks: dict    = {}
        # auth_sessions : token → aiohttp.ClientSession (session d'authentification)
        # Stockées ici pour pouvoir les fermer proprement à l'arrêt ou en cas d'erreur
        self.auth_sessions: dict = {}
        # http_session : session aiohttp partagée pour les callbacks vers Jeedom
        # Initialisée dans run() pour être dans la même boucle asyncio
        self.http_session        = None

        # Pré-remplir token_devices depuis l'argument --tokens (mapping token → [device_ids])
        try:
            tokens_map = json.loads(args.tokens) if args.tokens else {}
            for token, device_ids in tokens_map.items():
                if token:
                    self.token_devices[token] = set(device_ids)
                    self.log.debug(f'[init] Token …{token[-6:]} pré-enregistré avec {len(device_ids)} device(s): {device_ids}')
            self.log.info(f'[init] {len(self.token_devices)} compte(s) Nintendo pré-chargé(s) au démarrage')
        except Exception as e:
            self.log.error(f'[init] Erreur parsing --tokens: {e}')

        # ── Écrire le PID pour que Jeedom puisse surveiller le processus ────
        with open(self.pid_file, 'w') as f:
            f.write(str(os.getpid()))
        self.log.debug(f'[init] PID={os.getpid()} écrit dans {self.pid_file}')

        # ── Gérer SIGTERM / SIGINT proprement ────────────────────────────────
        # SIGTERM : envoyé par Jeedom pour arrêter le démon (deamon_stop)
        # SIGINT  : Ctrl+C en développement
        signal.signal(signal.SIGTERM, self._handle_signal)
        signal.signal(signal.SIGINT, self._handle_signal)

    # ── Utilitaires ──────────────────────────────────────────────────────────

    def _cron_to_seconds(self, cron_expr: str) -> int:
        """
        Convertit une expression cron (ex: '*/5 * * * *') en secondes.
        Utilise croniter si disponible pour un calcul précis, sinon fallback
        sur l'analyse manuelle du premier champ.
        """
        if HAS_CRONITER:
            try:
                c = croniter(cron_expr, time.time())
                next1 = c.get_next(float)
                next2 = c.get_next(float)
                return max(60, int(next2 - next1))
            except Exception:
                pass
        # Fallback : '*/N * * * *' → N*60 secondes
        try:
            parts = cron_expr.strip().split()
            if len(parts) >= 1 and parts[0].startswith('*/'):
                return int(parts[0][2:]) * 60
        except Exception:
            pass
        return 300  # Défaut : 5 minutes

    def _handle_signal(self, signum, frame):
        """Gestionnaire de signal SIGTERM/SIGINT — arrêt propre du démon."""
        self.log.info(f'Signal {signum} reçu — arrêt du démon')
        self.running = False
        if os.path.exists(self.pid_file):
            os.remove(self.pid_file)
        sys.exit(0)

    def _get_lock(self, token: str) -> asyncio.Lock:
        """
        Retourne le verrou asyncio pour ce token (créé si inexistant).
        Ce verrou garantit qu'un seul appel API est en cours par compte Nintendo.
        """
        if token not in self.api_locks:
            self.log.debug(f'[lock] Création verrou pour token …{token[-6:]}')
            self.api_locks[token] = asyncio.Lock()
        return self.api_locks[token]

    # ── Connexion à l'API Nintendo ───────────────────────────────────────────

    async def get_or_connect(self, token: str):
        """
        Retourne l'API NintendoParental pour ce token.
        Si le token n'est pas encore connecté, déclenche la connexion OAuth.
        Thread-safe via asyncio.Lock : une seule connexion simultanée par token.
        """
        if not token:
            self.log.debug('[get_or_connect] Token vide — connexion ignorée')
            return None
        self.log.debug(f'[get_or_connect] Token …{token[-6:]}')
        async with self._get_lock(token):
            if token in self.apis:
                self.log.debug(f'[get_or_connect] Token …{token[-6:]} déjà connecté — réutilisation')
                return self.apis[token]
            self.log.debug(f'[get_or_connect] Token …{token[-6:]} nouveau — connexion OAuth en cours')
            return await self._connect_token(token)

    async def _connect_token(self, token: str):
        """
        Authentifie un token Nintendo et crée l'instance NintendoParental.
        DOIT être appelé sous _get_lock(token) pour éviter les connexions doubles.
        Stocke la session aiohttp dans self.auth_sessions pour fermeture propre.
        """
        self.log.info(f'Connexion Nintendo pour token …{token[-6:]}')
        try:
            # Fermer l'éventuelle ancienne session pour ce token (reconnexion)
            old_session = self.auth_sessions.pop(token, None)
            if old_session and not old_session.closed:
                await old_session.close()
                self.log.debug(f'[_connect_token] Ancienne session fermée pour token …{token[-6:]}')

            # Créer une session dédiée à ce compte (ne pas réutiliser la session callback)
            session = aiohttp.ClientSession()
            self.auth_sessions[token] = session  # Référence stockée pour fermeture future

            auth = Authenticator(session_token=token, client_session=session)
            self.log.debug(f'[_connect_token] async_complete_login en cours…')
            await auth.async_complete_login(use_session_token=True)

            self.log.debug(f'[_connect_token] Login OK — création NintendoParental (fr-FR, Europe/Paris)')
            api = await NintendoParental.create(auth, timezone='Europe/Paris', lang='fr-FR')
            self.apis[token] = api
            self.log.info(f'Token …{token[-6:]} connecté. Comptes actifs : {len(self.apis)}')
            return api

        except InvalidSessionTokenException:
            self.log.error(
                f'Token …{token[-6:]} invalide ou expiré — '
                'relancez l\'assistant token dans la configuration Jeedom'
            )
            await self._close_token_session(token)
            return None
        except Exception as e:
            self.log.error(f'Erreur connexion token …{token[-6:]}: {type(e).__name__}: {e}')
            await self._close_token_session(token)
            return None

    async def _close_token_session(self, token: str):
        """
        Ferme la session aiohttp d'un token et le supprime de tous les dictionnaires.
        Appelé quand un token expire ou devient invalide.
        """
        session = self.auth_sessions.pop(token, None)
        if session and not session.closed:
            try:
                await session.close()
                self.log.debug(f'[_close_token_session] Session fermée pour token …{token[-6:]}')
            except Exception:
                pass
        self.apis.pop(token, None)
        self.api_locks.pop(token, None)
        self.token_devices.pop(token, None)

    # ── Récupération des données Nintendo ────────────────────────────────────

    async def fetch_all_devices(self):
        """
        Lance le polling pour tous les comptes Nintendo connectés.
        Chaque compte est traité séquentiellement (un verrou par token).
        """
        if not self.apis:
            self.log.debug('[fetch_all_devices] Aucun compte connecté — polling ignoré')
            return
        self.log.debug(f'[fetch_all_devices] Début polling {len(self.apis)} compte(s)')
        for token, api in list(self.apis.items()):
            await self._fetch_for_token(token, api)
        self.log.debug('[fetch_all_devices] Polling terminé')

    async def _fetch_for_token(self, token: str, api):
        """
        Acquiert le verrou puis appelle _do_fetch.
        Utilisé par le polling autonome pour éviter les conflits avec les actions.
        """
        self.log.debug(f'[_fetch_for_token] Acquisition verrou token …{token[-6:]}')
        async with self._get_lock(token):
            await self._do_fetch(token, api)

    async def _do_fetch(self, token: str, api):
        """
        Appelle api.update() et envoie les données vers Jeedom via callback.
        NE prend PAS le verrou — doit être appelé avec le verrou déjà tenu
        (par _fetch_for_token ou handle_action action=refresh).
        """
        registered = self.token_devices.get(token, set())
        self.log.debug(
            f'[_do_fetch] token …{token[-6:]} | '
            f'devices filtrés: {registered if registered else "tous"}'
        )
        try:
            self.log.debug(f'[_do_fetch] api.update() en cours…')
            await api.update()
            devices = api.devices or {}
            self.log.debug(f'[_do_fetch] {len(devices)} device(s) retourné(s) par l\'API Nintendo')

            processed = 0
            for device_id, device in devices.items():
                if registered and device_id not in registered:
                    self.log.debug(f'[_do_fetch] device {device_id} ignoré (non enregistré pour ce token)')
                    continue
                self.log.debug(f'[_do_fetch] Traitement device {device_id} ({device.name})')
                await self.process_device(device)
                processed += 1
            self.log.debug(f'[_do_fetch] {processed}/{len(devices)} device(s) traité(s) pour token …{token[-6:]}')

        except InvalidSessionTokenException:
            self.log.error(
                f'Session expirée pour token …{token[-6:]} — '
                'suppression du compte, reconnexion nécessaire'
            )
            await self._close_token_session(token)
        except NoDevicesFoundException:
            self.log.warning(
                f'Aucune console associée au compte token …{token[-6:]}. '
                'Vérifiez l\'application Nintendo Switch Parental Controls.'
            )
        except Exception as e:
            self.log.error(f'Erreur fetch token …{token[-6:]}: {type(e).__name__}: {e}')

    async def process_device(self, device):
        """
        Transforme un objet device pynintendoparental en dict Jeedom
        et déclenche l'envoi du callback.
        """
        self.log.debug(f'[process_device] device_id={device.device_id} | name={device.name}')
        try:
            # forced_termination_mode=True → FORCED(0=blocage), False → ALARM(1=alerte)
            restr            = getattr(device, 'forced_termination_mode', None)
            restriction_mode = 0 if restr is True else 1

            # limit_time=0 signifie une suspension immédiate (blocage total)
            limit_time = getattr(device, 'limit_time', None)
            suspended  = (limit_time is not None and int(limit_time) == 0)

            self.log.debug(
                f'[process_device] {device.device_id} — '
                f'limit_time={limit_time} | suspended={suspended} | restriction_mode={restriction_mode}'
            )

            # Cache titre/icône depuis les jeux joués aujourd'hui (player.apps)
            # Contient meta.title et meta.imageUri fournis par l'API Nintendo
            game_meta = self._build_game_meta_cache(device)

            # Télécharger et cacher localement les images de jeux (évite CORS/auth Nintendo)
            for entry in game_meta.values():
                if entry.get('image', '').startswith('http'):
                    entry['image'] = await self._cache_image(entry['image'])

            # Historiques (utilisent game_meta avec URLs locales)
            player_history = self._get_player_history(device)
            for p in player_history:
                if p.get('image', '').startswith('http'):
                    p['image'] = await self._cache_image(p['image'])

            current_game = (
                {'name': player_history[0]['title'], 'image_url': player_history[0]['image']}
                if player_history else {'name': '', 'image_url': ''}
            )

            playtime_today  = int(getattr(device, 'today_playing_time', 0) or 0)
            time_remaining  = (-1 if limit_time in (None, -1)
                               else int(getattr(device, 'today_time_remaining', 0) or 0))
            # limit_time est déjà la limite effective du jour (reflète la règle par-jour)
            daily_limit_today = int(limit_time) if limit_time not in (None, -1) else -1

            # Log INFO : mode de planification + limite effective
            timer_mode = getattr(device, 'timer_mode', None)
            self.log.info(
                f'[schedule] {device.device_id} — timer_mode={timer_mode} | '
                f'limit_time={limit_time} | today_playing={playtime_today} | '
                f'today_remaining={time_remaining} | daily_limit={daily_limit_today}'
            )

            data = {
                'device_id':        device.device_id,
                'nickname':         device.name or '',
                'avatar_url':       '',  # Non exposé par pynintendoparental
                'online_status':    'online',
                'current_game':     current_game,
                'playtime_today':   playtime_today,
                'playtime_month':   int(getattr(device, 'month_playing_time', 0) or 0),
                # -1 = pas de limite configurée (limit_time = None ou -1)
                # sinon : minutes restantes réelles (peut être 0 = temps épuisé)
                'time_remaining':   time_remaining,
                'daily_limit':      daily_limit_today,
                'game_history':     self._get_game_history(device, game_meta),
                'player_history':   player_history,
                'disabled_today':   int(getattr(device, 'today_disabled_time', 0) or 0),
                'exceeded_today':   int(getattr(device, 'today_exceeded_time', 0) or 0),
                'month_days':       self._get_month_stat(device, 'totalDays'),
                'month_avg':        self._get_month_stat(device, 'averageTime'),
                'gamechat_enabled': None,  # Non supporté par pynintendoparental actuellement
                'suspended':        suspended,
                'restriction_mode': restriction_mode,
                'daily_summaries':  self._get_daily_summaries_7days(device),
                'last_sync':        datetime.now().strftime('%d/%m/%Y %H:%M'),
            }

            self.log.debug(f'[process_device] Données device {device.device_id}: {json.dumps(data, ensure_ascii=False)}')
            await self.send_callback(data)

        except Exception as e:
            self.log.error(f'Erreur processing device {device.device_id}: {type(e).__name__}: {e}')

    @staticmethod
    def _to_str(val) -> str:
        """
        Extrait une URL (str) depuis une valeur qui peut être str, dict ou list.
        imageUri dans pynintendoparental peut être un dict {size: url} ou une str directe.
        """
        if not val:
            return ''
        if isinstance(val, str):
            return val
        if isinstance(val, dict):
            # Ex : {"small": "https://...", "large": "https://..."} → premier http trouvé
            for v in val.values():
                if isinstance(v, str) and v.startswith('http'):
                    return v
        if isinstance(val, (list, tuple)) and val:
            return JeeNinSwiDaemon._to_str(val[0])
        return ''

    @staticmethod
    def _val(obj, *keys):
        """Lit obj[key] ou obj.key (supporte dict et objet Python)."""
        for key in keys:
            if obj is None:
                return None
            if isinstance(obj, dict):
                obj = obj.get(key)
            else:
                obj = getattr(obj, key, None)
        return obj

    async def _cache_image(self, url: str) -> str:
        """
        Télécharge une image Nintendo et la stocke dans data/jeeninswi/images/.
        Retourne l'URL locale (servie par Jeedom) si succès, sinon l'URL originale.
        Les images déjà présentes en cache local sont réutilisées sans re-téléchargement.
        """
        if not url or not url.startswith('http'):
            return url

        path_part = url.split('?')[0].rsplit('/', 1)[-1]
        ext = path_part.rsplit('.', 1)[-1][:4].lower() if '.' in path_part else 'jpg'
        if ext not in ('jpg', 'jpeg', 'png', 'webp', 'gif'):
            ext = 'jpg'
        filename  = hashlib.md5(url.encode()).hexdigest() + '.' + ext
        local_path = os.path.join(IMG_CACHE_DIR, filename)
        web_path   = f'{IMG_CACHE_WEB}/{filename}'

        if os.path.exists(local_path):
            return web_path

        session = self.http_session
        if session is None or session.closed:
            return url

        try:
            os.makedirs(IMG_CACHE_DIR, exist_ok=True)
            async with session.get(url, timeout=aiohttp.ClientTimeout(total=10),
                                   allow_redirects=True) as resp:
                if resp.status == 200:
                    content = await resp.read()
                    if content:
                        with open(local_path, 'wb') as f:
                            f.write(content)
                        self.log.debug(f'[image_cache] {filename} ({len(content)}o)')
                        return web_path
                else:
                    self.log.debug(f'[image_cache] HTTP {resp.status} — {url[:70]}')
        except asyncio.TimeoutError:
            self.log.debug(f'[image_cache] Timeout — {url[:70]}')
        except Exception as e:
            self.log.debug(f'[image_cache] Erreur {type(e).__name__} — {url[:70]}')

        return url

    def _build_game_meta_cache(self, device) -> dict:
        """
        Construit {APP_ID_UPPER: {title, image}} en essayant toutes les sources
        disponibles dans pynintendoparental (dict ou objet).
        """
        cache = {}

        # ── Source 1 : daily_summaries → players → playedGames → meta ────────
        try:
            daily = getattr(device, 'daily_summaries', None) or []
            self.log.debug(f'[meta_cache] daily_summaries : {len(daily)} entrées, type[0]={type(daily[0]).__name__ if daily else "—"}')
            for day in daily[:7]:
                players_list = self._val(day, 'players') or []
                for p in players_list:
                    for pg in (self._val(p, 'playedGames') or self._val(p, 'played_games') or []):
                        meta   = self._val(pg, 'meta') or {}
                        app_id = (self._val(meta, 'applicationId') or '').upper()
                        title  = self._val(meta, 'title') or ''
                        image  = self._to_str(self._val(meta, 'imageUri') or self._val(meta, 'imageUrl'))
                        if app_id and (title or image) and app_id not in cache:
                            cache[app_id] = {'title': title, 'image': image}
        except Exception as e:
            self.log.warning(f'[meta_cache] daily_summaries erreur: {e}')

        self.log.debug(f'[meta_cache] après daily_summaries : {len(cache)} jeux')

        # ── Source 2 : device.players (session courante) ─────────────────────
        try:
            players = getattr(device, 'players', None) or []
            if isinstance(players, dict):
                players = list(players.values())
            for player in players:
                apps = (self._val(player, 'apps') or self._val(player, 'applications') or [])
                for app in apps:
                    app_id = (self._val(app, 'application_id') or
                              self._val(app, 'applicationId') or '').upper()
                    title  = (self._val(app, 'name') or
                              self._val(app, 'title') or '')
                    image  = self._to_str(self._val(app, 'image_uri') or
                                          self._val(app, 'imageUri') or
                                          self._val(app, 'image_url'))
                    if app_id and (title or image) and app_id not in cache:
                        cache[app_id] = {'title': title, 'image': image}
        except Exception as e:
            self.log.warning(f'[meta_cache] device.players erreur: {e}')

        self.log.debug(f'[meta_cache] après device.players : {len(cache)} jeux')

        # ── Source 3 : device.applications (liste blanche) ───────────────────
        try:
            applications = getattr(device, 'applications', None) or {}
            if isinstance(applications, list):
                applications = {getattr(a, 'id', str(i)): a for i, a in enumerate(applications)}
            for app_id, app_obj in applications.items():
                key = app_id.upper()
                if key in cache:
                    continue
                title = (self._val(app_obj, 'name') or self._val(app_obj, 'title') or '')
                image = self._to_str(
                    self._val(app_obj, 'image_url') or self._val(app_obj, 'imageUrl') or
                    self._val(app_obj, 'image_uri') or self._val(app_obj, 'imageUri')
                )
                if title or image:
                    cache[key] = {'title': title, 'image': image}
        except Exception as e:
            self.log.warning(f'[meta_cache] applications erreur: {e}')

        self.log.info(f'[meta_cache] TOTAL : {len(cache)} jeux — ' +
                      (', '.join(f'{v["title"]}(img={bool(v["image"])})' for v in list(cache.values())[:8])))
        return cache

    def _get_game_history(self, device, game_meta: dict = None) -> list:
        """
        Retourne les 5 jeux les plus joués sur les 7 derniers jours glissants.
        Source principale : device.daily_summaries (7 dernières entrées)
          → players[i].playedGames[j].meta : applicationId, title, imageUri, playingTime
        Fallback : last_month_summary si daily_summaries est vide/CALCULATING.
        """
        if game_meta is None:
            game_meta = {}
        games: dict = {}

        # ── Source 1 : daily_summaries 7 jours (titre + icône inclus) ──────
        try:
            daily = getattr(device, 'daily_summaries', None) or []
            for day in daily[:7]:
                for player_day in (self._val(day, 'players') or []):
                    for pg in (self._val(player_day, 'playedGames') or
                               self._val(player_day, 'played_games') or []):
                        meta    = self._val(pg, 'meta') or {}
                        app_id  = self._val(meta, 'applicationId') or ''
                        minutes = int(self._val(pg, 'playingTime') or 0)
                        if not app_id or minutes <= 0:
                            continue
                        image = self._to_str(
                            self._val(meta, 'imageUri') or
                            self._val(meta, 'imageUrl') or
                            game_meta.get(app_id.upper(), {}).get('image')
                        )
                        if app_id not in games:
                            games[app_id] = {
                                'app_id': app_id,
                                'title':  (self._val(meta, 'title') or
                                           game_meta.get(app_id.upper(), {}).get('title') or '__UNKNOWN__'),
                                'image':  image,
                                'minutes': 0,
                            }
                        games[app_id]['minutes'] += minutes
        except Exception as e:
            self.log.warning(f'[_get_game_history] Erreur daily_summaries: {e}')

        # ── Fallback : last_month_summary si aucune donnée 7j ───────────────
        if not games:
            try:
                lms = getattr(device, 'last_month_summary', None)
                if lms and isinstance(lms, dict):
                    for day in (lms.get('overall', {}).get('dailyStats') or []):
                        for app_id, game_data in (day.get('games') or {}).items():
                            minutes = int(game_data.get('totalTime') or 0)
                            if minutes <= 0:
                                continue
                            if app_id not in games:
                                meta_entry = game_meta.get(app_id.upper(), {})
                                games[app_id] = {
                                    'app_id': app_id,
                                    'title':   meta_entry.get('title') or '__UNKNOWN__',
                                    'image':   meta_entry.get('image') or '',
                                    'minutes': 0,
                                }
                            games[app_id]['minutes'] += minutes
            except Exception as e:
                self.log.warning(f'[_get_game_history] Erreur last_month_summary: {e}')

        result = sorted(games.values(), key=lambda x: x['minutes'], reverse=True)
        unknown_counter = 1
        for g in result:
            if g['title'] == '__UNKNOWN__':
                g['title'] = f'Jeu #{unknown_counter}'
                unknown_counter += 1
        if result:
            sample = result[0]
            self.log.info(f'[game_history] {len(result)} jeux — ex: "{sample["title"]}" img="{sample["image"][:80] if sample["image"] else "(vide)"}"')
        return result[:5]

    def _get_player_history(self, device) -> list:
        """
        Retourne la liste des joueurs avec leur temps cumulé sur 7 jours glissants.
        Source principale : device.daily_summaries[:7]
          → players[i].profile : playerId, nickname, imageUri
          → players[i].playingTime : minutes ce jour
        Fallback : device.players (temps aujourd'hui seulement) si daily_summaries vide.
        """
        players_7d: dict = {}  # playerId → {title, image, minutes}

        # ── Source 1 : daily_summaries 7 jours ──────────────────────────────
        try:
            daily = getattr(device, 'daily_summaries', None) or []
            for day in daily[:7]:
                if not isinstance(day, dict):
                    continue
                for player_day in (day.get('players') or []):
                    profile   = player_day.get('profile') or {}
                    player_id = profile.get('playerId') or ''
                    nickname  = profile.get('nickname') or ''
                    image     = self._to_str(profile.get('imageUri'))
                    minutes   = int(player_day.get('playingTime') or 0)
                    if not nickname:
                        continue
                    if player_id not in players_7d:
                        players_7d[player_id] = {'title': nickname, 'image': image, 'minutes': 0}
                    players_7d[player_id]['minutes'] += minutes
        except Exception as e:
            self.log.warning(f'[_get_player_history] Erreur daily_summaries: {e}')

        result = list(players_7d.values())

        # ── Fallback : last_month_summary.players[] ──────────────────────────
        # Utilisé quand daily_summaries.players est vide (CALCULATING sur Switch 2).
        # Cohérent avec le fallback jeux qui utilise aussi last_month_summary.
        if not result:
            try:
                lms = getattr(device, 'last_month_summary', None)
                if lms and isinstance(lms, dict):
                    for p in (lms.get('players') or []):
                        profile  = p.get('profile') or {}
                        nickname = profile.get('nickname') or ''
                        image    = self._to_str(profile.get('imageUri'))
                        if not nickname:
                            continue
                        # Somme de tous les jours du mois pour ce joueur
                        daily_stats = (p.get('summary') or {}).get('dailyStats') or []
                        minutes = sum(int(d.get('totalTime') or 0) for d in daily_stats)
                        result.append({'title': nickname, 'image': image, 'minutes': minutes})
            except Exception as e:
                self.log.warning(f'[_get_player_history] Erreur last_month_summary.players: {e}')

        # ── Fallback final : nom de la console + total 7j ───────────────────
        if not result:
            name = getattr(device, 'name', '') or ''
            if name:
                total = sum(
                    int(d.get('playingTime') or 0)
                    for d in (getattr(device, 'daily_summaries', None) or [])[:7]
                    if isinstance(d, dict)
                )
                result.append({'title': name, 'image': '', 'minutes': total})

        self.log.debug(f'[_get_player_history] {len(result)} joueur(s) — 7j glissants')
        result.sort(key=lambda x: x['minutes'], reverse=True)
        return result

    def _get_month_stat(self, device, key: str) -> int:
        """Retourne une statistique mensuelle depuis last_month_summary.overall.stat."""
        try:
            lms = getattr(device, 'last_month_summary', None)
            if lms and isinstance(lms, dict):
                overall = lms.get('overall', {})
                # Log de debug pour voir la structure complète (une seule fois par device)
                return int(overall.get('stat', {}).get(key, 0) or 0)
        except Exception:
            pass
        return 0

    def _get_week_schedule(self, device) -> list:
        """
        Retourne les limites par jour [lun, mar, mer, jeu, ven, sam, dim] en minutes.
        Source : device.parental_control_settings["playTimerRegulations"]
          - timerMode == "EACH_DAY_OF_THE_WEEK" → eachDayOfTheWeekRegulations{DAY: {timeToPlayInOneDay: {limitTime}}}
          - timerMode == "DAILY" → dailyRegulations{timeToPlayInOneDay: {limitTime}}
        """
        global_lim = int(getattr(device, 'limit_time', -1) or -1)
        result = [global_lim] * 7

        DAY_TO_DOW = {
            'MONDAY': 0, 'TUESDAY': 1, 'WEDNESDAY': 2, 'THURSDAY': 3,
            'FRIDAY': 4, 'SATURDAY': 5, 'SUNDAY': 6,
        }

        try:
            pcs = getattr(device, 'parental_control_settings', None) or {}
            ptr = (pcs.get('playTimerRegulations') if isinstance(pcs, dict) else {}) or {}

            if not ptr:
                self.log.info(f'[week_schedule] parental_control_settings absent ou vide — fallback {global_lim} min')
                return result

            timer_mode = str(ptr.get('timerMode', ''))
            self.log.info(f'[week_schedule] timerMode={timer_mode} | global_lim={global_lim}')

            if timer_mode == 'EACH_DAY_OF_THE_WEEK':
                each_day = ptr.get('eachDayOfTheWeekRegulations') or {}
                for day_name, reg in each_day.items():
                    dow = DAY_TO_DOW.get(day_name.upper())
                    if dow is None:
                        continue
                    lim = ((reg.get('timeToPlayInOneDay') or {}).get('limitTime')
                           if isinstance(reg, dict) else None)
                    if lim is not None:
                        result[dow] = int(lim)
                self.log.info(f'[week_schedule] EACH_DAY_OF_THE_WEEK → {result}')

            else:
                # DAILY : même limite pour tous les jours
                daily = ptr.get('dailyRegulations') or {}
                lim = (daily.get('timeToPlayInOneDay') or {}).get('limitTime')
                if lim is not None:
                    result = [int(lim)] * 7
                self.log.info(f'[week_schedule] DAILY → {result}')

        except Exception as e:
            self.log.warning(f'[week_schedule] {type(e).__name__}: {e}')

        return result

    def _get_daily_summaries_7days(self, device) -> list:
        """
        Retourne les 7 derniers jours glissants avec le total de minutes et le détail par jeu.
        Format : [{'date': 'YYYY-MM-DD', 'minutes': N, 'games': [{app_id, title, minutes}]}]
        Correction : le total est sommé depuis players[].playingTime, pas depuis un champ
        top-level inexistant dans la structure de l'API Nintendo.
        """
        result = []
        today     = datetime.now()
        summaries = getattr(device, 'daily_summaries', None) or []
        by_date: dict = {}

        # Log de la structure brute du premier résumé (une seule fois, aide au debug)
        if summaries and isinstance(summaries, list) and summaries[0] is not None:
            s0 = summaries[0]
            if isinstance(s0, dict):
                self.log.debug(f'[daily_summaries] keys summary[0]: {list(s0.keys())}')
            else:
                attrs = [a for a in dir(s0) if not a.startswith('_')]
                self.log.debug(f'[daily_summaries] attrs summary[0]: {attrs[:30]}')

        try:
            if isinstance(summaries, list):
                for s in summaries:
                    d = str(self._val(s, 'date') or '')
                    if not d:
                        continue
                    entry = by_date.setdefault(d, {'minutes': 0, 'games': {}, 'limit': -1})
                    # Limite configurée pour ce jour — teste tous les noms de champ connus
                    day_lim = int(
                        self._val(s, 'maxTime') or self._val(s, 'maxPlayTime') or
                        self._val(s, 'limitTime') or self._val(s, 'limit') or
                        self._val(s, 'playLimitTime') or self._val(s, 'dailyMaxPlayTime') or
                        self._val(s, 'max_time') or self._val(s, 'time_limit') or -1
                    )
                    if day_lim >= 0:
                        entry['limit'] = day_lim
                    for player_day in (self._val(s, 'players') or []):
                        entry['minutes'] += int(self._val(player_day, 'playingTime') or 0)
                        for pg in (self._val(player_day, 'playedGames') or
                                   self._val(player_day, 'played_games') or []):
                            meta   = self._val(pg, 'meta') or {}
                            app_id = self._val(meta, 'applicationId') or ''
                            title  = self._val(meta, 'title') or ''
                            mins   = int(self._val(pg, 'playingTime') or 0)
                            if app_id and mins > 0:
                                g = entry['games'].setdefault(app_id, {'title': title, 'minutes': 0})
                                g['minutes'] += mins
            elif isinstance(summaries, dict):
                for key, val in summaries.items():
                    minutes = int((val.get('playingTime', 0) if isinstance(val, dict) else val) or 0)
                    lim = int((val.get('maxTime', -1) or val.get('limit', -1)) if isinstance(val, dict) else -1)
                    by_date[str(key)] = {'minutes': minutes, 'games': {}, 'limit': lim}
        except Exception as e:
            self.log.warning(f'[_get_daily_summaries_7days] Erreur: {e}')

        # Récupérer le planning hebdomadaire pour les jours sans limite explicite
        week_schedule = self._get_week_schedule(device)

        for i in range(7, 0, -1):
            d   = today - timedelta(days=i)
            key = d.strftime('%Y-%m-%d')
            dow = d.weekday()  # 0=lun, 6=dim
            entry = by_date.get(key, {})
            day_limit = entry.get('limit', -1)
            # Fallback : planning hebdomadaire si la limite n'est pas dans les summaries
            if day_limit < 0 and 0 <= dow < 7:
                day_limit = week_schedule[dow]
            games_list = sorted(
                [{'app_id': k, 'title': v['title'], 'minutes': v['minutes']}
                 for k, v in entry.get('games', {}).items()],
                key=lambda x: x['minutes'], reverse=True
            )
            result.append({
                'date':    key,
                'minutes': entry.get('minutes', 0),
                'games':   games_list,
                'limit':   day_limit,
            })
        return result

    # ── Callback vers Jeedom (HTTP asynchrone — ne bloque pas la boucle) ─────

    async def send_callback(self, data: dict):
        """
        Envoie les données d'un device à Jeedom via HTTP POST (asynchrone).
        Utilise la session aiohttp partagée (self.http_session) créée dans run().
        Si la session n'est pas disponible, crée une session temporaire.
        Remplace l'ancienne implémentation basée sur requests.post() qui bloquait
        la boucle asyncio pendant l'attente réseau.
        """
        device_id = data.get('device_id', '?')
        self.log.debug(f'[send_callback] Envoi données vers Jeedom pour device {device_id}')

        if self.http_session is None or self.http_session.closed:
            # Cas de secours : créer une session temporaire (ne devrait pas arriver)
            self.log.warning('[send_callback] Session HTTP non disponible — session temporaire')
            async with aiohttp.ClientSession() as tmp:
                await self._do_post_callback(tmp, data)
            return

        await self._do_post_callback(self.http_session, data)

    async def _do_post_callback(self, session: aiohttp.ClientSession, data: dict):
        """Exécute le POST HTTP vers callback.php avec gestion des erreurs."""
        device_id = data.get('device_id', '?')
        try:
            async with session.post(
                self.callback_url,
                json=data,
                timeout=aiohttp.ClientTimeout(total=5),
                headers={'Content-Type': 'application/json'},
            ) as resp:
                if resp.status != 200:
                    text = await resp.text()
                    self.log.warning(f'[send_callback] Callback HTTP {resp.status} pour device {device_id}: {text[:200]}')
                else:
                    self.log.debug(f'[send_callback] Callback OK pour device {device_id}')
        except aiohttp.ClientError as e:
            self.log.error(f'[send_callback] Erreur réseau pour device {device_id}: {type(e).__name__}: {e}')
        except asyncio.TimeoutError:
            self.log.error(f'[send_callback] Timeout (5s) pour device {device_id} — Jeedom injoignable ?')

    # ── Traitement des actions Jeedom → Nintendo ─────────────────────────────

    async def handle_action(self, payload: dict) -> dict:
        """
        Traite une action Jeedom reçue via POST /action.
        Actions supportées : refresh, suspend, add_bonus_time, set_daily_limit,
                             set_restriction_mode, signaler, set_gamechat
        """
        action    = payload.get('action')
        device_id = payload.get('device_id')
        token     = payload.get('token', '')
        self.log.info(f'Action reçue: {action} | device={device_id}')
        self.log.debug(
            f'[handle_action] token={"…" + token[-6:] if token else "ABSENT"} '
            f'| payload_keys={list(payload.keys())}'
        )

        # Enregistrer le device sur ce compte (pour filtrer le polling)
        if token and device_id:
            was_new = device_id not in self.token_devices.get(token, set())
            self.token_devices.setdefault(token, set()).add(device_id)
            if was_new:
                self.log.debug(f'[handle_action] device {device_id} enregistré sur token …{token[-6:]}')

        # Connexion Nintendo (ou réutilisation si déjà connecté)
        api = await self.get_or_connect(token)
        if api is None:
            self.log.error('[handle_action] Connexion impossible — token absent ou invalide')
            return {'status': 'error', 'message': 'Token manquant ou invalide'}

        # Trouver le device dans le cache ; rafraîchir si absent
        async with self._get_lock(token):
            device = (api.devices or {}).get(device_id)
            if device is None:
                self.log.debug(f'[handle_action] Device {device_id} absent du cache — api.update()')
                await api.update()
                device = (api.devices or {}).get(device_id)
                self.log.debug(f'[handle_action] Après update: device {"trouvé" if device else "INTROUVABLE"}')

        if device is None:
            self.log.error(f'[handle_action] Device {device_id} introuvable dans l\'API Nintendo')
            return {'status': 'error', 'message': f'Device {device_id} introuvable'}

        # Exécuter l'action sous verrou pour éviter les conflits avec le polling
        async with self._get_lock(token):
            try:
                if action == 'suspend':
                    # Suspend : met la limite à 0 (blocage immédiat) ou -1 (illimité)
                    suspended = payload.get('suspended', True)
                    self.log.debug(f'[handle_action] suspend={suspended} → update_max_daily_playtime({0 if suspended else -1})')
                    await device.update_max_daily_playtime(0 if suspended else -1)
                    self.log.info(f'Console {device_id} {"bloquée" if suspended else "débloquée"}')
                    return {'status': 'ok', 'suspended': suspended}

                elif action == 'add_bonus_time':
                    minutes = int(payload.get('minutes', 15))
                    # SECURITY: valider la plage de valeurs
                    if not (1 <= minutes <= 480):
                        return {'status': 'error', 'message': 'Valeur hors limite (1-480 min)'}
                    self.log.debug(f'[handle_action] add_bonus_time {minutes} min pour device {device_id}')
                    await device.add_extra_time(minutes)
                    self.log.info(f'+{minutes} minutes de bonus ajoutées pour device {device_id}')
                    return {'status': 'ok', 'bonus_minutes': minutes}

                elif action == 'set_daily_limit':
                    minutes = int(payload.get('minutes', 120))
                    # SECURITY: valider la plage (0=blocage immédiat, 360=6h max selon slider UI)
                    if not (0 <= minutes <= 360):
                        return {'status': 'error', 'message': 'Valeur hors limite (0-360 min)'}
                    self.log.debug(f'[handle_action] set_daily_limit {minutes} min pour device {device_id}')
                    await device.update_max_daily_playtime(minutes)
                    self.log.info(f'Limite quotidienne fixée à {minutes} min pour device {device_id}')
                    return {'status': 'ok', 'daily_limit': minutes}

                elif action == 'set_restriction_mode':
                    # mode 'forced' → FORCED_TERMINATION (coupe la console)
                    # mode 'alarm'  → ALARM (envoie une alerte sans couper)
                    mode_str = payload.get('mode', 'alarm')
                    mode = RestrictionMode.FORCED_TERMINATION if mode_str == 'forced' else RestrictionMode.ALARM
                    self.log.debug(f'[handle_action] set_restriction_mode={mode_str} ({mode}) pour device {device_id}')
                    await device.set_restriction_mode(mode)
                    self.log.info(f'Mode restriction → {mode_str} pour device {device_id}')
                    return {'status': 'ok', 'mode': mode_str}

                elif action == 'subtract_time':
                    minutes = int(payload.get('minutes', 15))
                    # SECURITY: valider la plage de valeurs
                    if not (1 <= minutes <= 480):
                        return {'status': 'error', 'message': 'Valeur hors limite (1-480 min)'}
                    current = getattr(device, 'limit_time', None)
                    if current is None or int(current) < 0:
                        self.log.warning(f'[handle_action] subtract_time ignoré : aucune limite configurée pour {device_id}')
                        return {'status': 'error', 'message': 'Aucune limite quotidienne configurée'}
                    new_limit = max(0, int(current) - minutes)
                    self.log.debug(f'[handle_action] subtract_time {minutes} min : {current} → {new_limit} pour {device_id}')
                    await device.update_max_daily_playtime(new_limit)
                    self.log.info(f'Limite réduite de {minutes} min → {new_limit} min pour {device_id}')
                    return {'status': 'ok', 'new_limit': new_limit}

                elif action == 'signaler':
                    # Action de signalement : enregistrement local (pas d'API Nintendo)
                    self.log.info(f'Signalement reçu pour device {device_id}')
                    return {'status': 'ok', 'message': 'Signalement enregistré'}

                elif action == 'set_gamechat':
                    # GameChat non supporté par pynintendoparental (Switch 2 uniquement, API non publique)
                    self.log.warning(f'[handle_action] set_gamechat non supporté par pynintendoparental')
                    return {'status': 'error', 'message': 'GameChat non supporté par la bibliothèque'}

                elif action == 'refresh':
                    # Attente synchrone sous le verrou déjà tenu : appel _do_fetch (pas
                    # _fetch_for_token qui tenterait de ré-acquérir le verrou → deadlock).
                    # PHP reçoit la réponse seulement quand callback.php a mis à jour Jeedom.
                    # JS fait alors location.reload() sans aucun polling.
                    # PHP timeout = 30s (sendToDaemon) — Nintendo API répond en 5-20s.
                    await self._do_fetch(token, api)
                    self.log.debug(f'[handle_action] refresh terminé pour device {device_id}')
                    return {'status': 'ok'}

                else:
                    self.log.warning(f'[handle_action] Action inconnue : {action}')
                    return {'status': 'error', 'message': f'Action inconnue: {action}'}

            except Exception as e:
                self.log.error(f'Erreur exécution action {action} pour device {device_id}: {type(e).__name__}: {e}')
                return {'status': 'error', 'message': str(e)}

    # ── Serveur HTTP aiohttp (même boucle asyncio — pas de thread) ───────────

    async def _handle_http_action(self, request: web.Request) -> web.Response:
        """
        Endpoint POST /action du mini-serveur HTTP.
        Reçoit les commandes de Jeedom (sendToDaemon) et retourne le résultat JSON.
        Tourne dans la même boucle asyncio que le polling — pas de blocage.
        """
        # SECURITY: limiter la taille du payload entrant (64 Ko)
        if request.content_length is not None and request.content_length > 65536:
            self.log.warning(f'[http] Payload trop volumineux: {request.content_length} octets')
            return web.Response(status=413, text='Payload trop volumineux')
        try:
            payload = await request.json()
        except Exception as e:
            self.log.warning(f'[http] Corps JSON invalide : {e}')
            return web.Response(status=400, text='JSON invalide')

        action    = payload.get('action', '?')
        device_id = payload.get('device_id', '?')
        self.log.debug(f'[http] POST /action — action={action} | device={device_id}')

        result = await self.handle_action(payload)
        return web.Response(
            content_type='application/json',
            body=json.dumps(result).encode(),
        )

    # ── Boucle principale ────────────────────────────────────────────────────

    async def run(self):
        """
        Point d'entrée de la boucle asyncio du démon.
        1. Démarre le serveur HTTP aiohttp (sur 127.0.0.1:{port})
        2. Ouvre la session HTTP pour les callbacks Jeedom
        3. Lance la boucle de polling Nintendo
        4. Ferme proprement tout à l'arrêt
        """
        # ── Démarrer le serveur HTTP aiohttp ─────────────────────────────────
        app = web.Application()
        app.router.add_post('/action', self._handle_http_action)
        runner = web.AppRunner(app)
        await runner.setup()
        site = web.TCPSite(runner, '127.0.0.1', self.port)
        await site.start()
        self.log.info(f'Serveur HTTP démon démarré sur 127.0.0.1:{self.port} (aiohttp natif)')

        # ── Ouvrir la session HTTP partagée pour les callbacks Jeedom ────────
        # async with garantit la fermeture propre même en cas d'exception
        async with aiohttp.ClientSession() as self.http_session:
            self.log.info(
                'Démon JeeNinSwi démarré — en attente des actions Jeedom sur le port %d',
                self.port
            )
            # SECURITY: masquer la clé API dans les logs
            _safe_cb = (self.callback_url.split('apikey=')[0] + 'apikey=****') \
                       if 'apikey=' in self.callback_url else self.callback_url
            self.log.debug(
                f'[run] PID={os.getpid()} | poll_interval={self.poll_interval}s '
                f'| poll_cron={self.poll_cron} | callback={_safe_cb}'
            )

            # ── Connexion initiale de tous les comptes pré-chargés ────────────
            # Les tokens sont passés au démarrage via --tokens (PHP deamon_start).
            # Sans ça, le démon attendrait qu'une action soit envoyée pour se connecter.
            if self.token_devices:
                self.log.info(f'[run] Connexion initiale de {len(self.token_devices)} compte(s) Nintendo...')
                for token in list(self.token_devices.keys()):
                    await self.get_or_connect(token)
                # Premier poll immédiat après connexion
                if self.apis:
                    self.log.info('[run] Premier poll immédiat après démarrage')
                    await self.fetch_all_devices()
            else:
                self.log.info(
                    '[run] Aucun compte pré-chargé — le démon attendra une action Jeedom '
                    '(ex: bouton Rafraîchir) pour se connecter à Nintendo.'
                )

            # ── Boucle de polling Nintendo ────────────────────────────────────
            # Attend poll_interval secondes, puis poll tous les comptes connectés.
            while self.running:
                await asyncio.sleep(self.poll_interval)
                if not self.running:
                    break
                if self.apis:
                    self.log.debug('Polling %d compte(s) Nintendo...', len(self.apis))
                    await self.fetch_all_devices()
                else:
                    self.log.info(
                        '[run] Aucun compte connecté — prochain poll dans %ds.',
                        self.poll_interval
                    )

        # ── Nettoyage à l'arrêt ───────────────────────────────────────────────
        self.log.info('[run] Fermeture des sessions Nintendo...')
        for token in list(self.auth_sessions.keys()):
            await self._close_token_session(token)
        await runner.cleanup()
        self.log.info('[run] Démon arrêté proprement.')


# ─── Point d'entrée CLI ───────────────────────────────────────────────────────
def main():
    parser = argparse.ArgumentParser(
        description='JeeNinSwi Daemon — Nintendo Switch Parental Controls pour Jeedom'
    )
    parser.add_argument(
        '--tokens', default='{}',
        help='JSON object {token: [device_ids]} des comptes Nintendo à superviser au démarrage'
    )
    parser.add_argument(
        '--port', type=int, default=55147,
        help='Port HTTP du démon (doit correspondre à DAEMON_PORT_DEFAULT dans la classe PHP)'
    )
    parser.add_argument(
        '--callback', required=True,
        help='URL callback Jeedom (core/php/callback.php?apikey=...)'
    )
    parser.add_argument(
        '--poll-cron', default='*/5 * * * *',
        help='Expression cron pour le polling Nintendo (ex: */5 * * * * = toutes les 5 min)'
    )
    parser.add_argument(
        '--pid-file', required=True,
        help='Fichier PID du démon (utilisé par Jeedom pour vérifier l\'état)'
    )
    parser.add_argument(
        '--log-file', default='',
        help='Fichier de log (vide = stdout). Doit correspondre au log Jeedom du plugin.'
    )
    parser.add_argument(
        '--debug', action='store_true',
        help='Mode debug : logs très verbeux (niveau DEBUG)'
    )
    args = parser.parse_args()

    daemon = JeeNinSwiDaemon(args)
    asyncio.run(daemon.run())


if __name__ == '__main__':
    main()
