#!/usr/bin/env python3
"""
JeeNinSwi - Helper d'authentification one-shot
Construit l'URL OAuth Nintendo identique à nxapi (samuelthomas2774/nxapi)
et échange le token. Appelé depuis PHP (jeeninswi.ajax.php) via shell_exec,
retourne du JSON sur stdout.

IMPORTANT : Ce script doit être lancé avec le Python du venv :
  resources/venv/bin/python3 auth_helper.py --action ...
Le PHP utilise automatiquement le venv si install_dep.sh a été exécuté.

Actions :
  --action get_auth_url  : génère l'URL OAuth Nintendo (étape 1)
  --action exchange_token --redirect-url "npf...://auth#..." : échange le code (étape 2)
"""

import argparse
import asyncio
import base64
import hashlib
import json
import os
import sys
from urllib.parse import urlencode, urlparse, parse_qs

import aiohttp

# ─── Constantes OAuth (source : nxapi/src/api/moon.ts) ───────────────────────
ZNMA_CLIENT_ID   = '54789befb391a838'
ZNMA_REDIRECT    = 'npf54789befb391a838://auth'
ZNMA_SCOPES      = ' '.join([
    'openid',
    'user',
    'user.mii',                          # présent dans nxapi, absent de pynintendoparental !
    'moonUser:administration',
    'moonDevice:create',
    'moonOwnedDevice:administration',
    'moonParentalControlSetting',
    'moonParentalControlSetting:update',
    'moonParentalControlSettingState',
    'moonPairingState',
    'moonSmartDevice:administration',
    'moonDailySummary',
    'moonMonthlySummary',
])

AUTHORIZE_URL      = 'https://accounts.nintendo.com/connect/1.0.0/authorize'
SESSION_TOKEN_URL  = 'https://accounts.nintendo.com/connect/1.0.0/api/session_token'
TOKEN_URL          = 'https://accounts.nintendo.com/connect/1.0.0/api/token'

# ─── Helpers PKCE (source : nxapi/src/api/na.ts) ─────────────────────────────
def _b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b'=').decode()

def generate_auth_data():
    """Génère state, verifier, challenge — identique à nxapi generateAuthData()."""
    state     = _b64url(os.urandom(36))   # 36 bytes → ~48 chars base64url
    verifier  = _b64url(os.urandom(32))   # 32 bytes → ~43 chars base64url
    challenge = _b64url(hashlib.sha256(verifier.encode()).digest())

    params = {
        'state':                               state,
        'redirect_uri':                        ZNMA_REDIRECT,
        'client_id':                           ZNMA_CLIENT_ID,
        'scope':                               ZNMA_SCOPES,
        'response_type':                       'session_token_code',
        'session_token_code_challenge':        challenge,
        'session_token_code_challenge_method': 'S256',
        'theme':                               'login_form',
    }
    url = AUTHORIZE_URL + '?' + urlencode(params)
    return url, state, verifier


def parse_redirect_url(redirect_url: str) -> str:
    """Extrait session_token_code depuis npf...://auth#session_token_code=xxx&..."""
    parsed = urlparse(redirect_url)
    fragment = parsed.fragment  # ex: session_token_code=xxx&state=yyy&...
    params = {}
    for part in fragment.split('&'):
        if '=' in part:
            k, v = part.split('=', 1)
            params[k] = v
    code = params.get('session_token_code')
    if not code:
        raise ValueError('session_token_code introuvable dans l\'URL de redirection')
    return code


async def exchange_session_token(session_token_code: str, verifier: str) -> str:
    """Échange session_token_code + verifier contre un session_token (source : nxapi)."""
    headers = {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept':       'application/json',
        'User-Agent':   'NASDKAPI; Android',
    }
    body = urlencode({
        'client_id':                    ZNMA_CLIENT_ID,
        'session_token_code':           session_token_code,
        'session_token_code_verifier':  verifier,
    })
    async with aiohttp.ClientSession() as session:
        async with session.post(SESSION_TOKEN_URL, data=body, headers=headers) as resp:
            data = await resp.json(content_type=None)
            token = data.get('session_token')
            if not token:
                # (F-005) Ne pas exposer le payload complet Nintendo dans le message d'erreur
                raise Exception(f'session_token absent dans la réponse (clés reçues : {list(data.keys())})')
            return token


async def get_devices_from_token(session_token: str) -> list:
    """Récupère la liste des consoles via pynintendoparental."""
    try:
        from pynintendoparental import NintendoParental
        from pynintendoparental.authenticator import Authenticator
    except ImportError:
        raise Exception('pynintendoparental non installé')

    async with aiohttp.ClientSession() as session:
        auth = Authenticator(session_token=session_token, client_session=session)
        await auth.async_complete_login(use_session_token=True)
        api = await NintendoParental.create(auth, timezone='Europe/Paris', lang='fr-FR')

    devices = []
    for device_id, device in (api.devices or {}).items():
        devices.append({
            'id':   device.device_id,
            'name': device.name or ('Console ' + str(device.device_id)[:8]),
        })
    return devices


# ─── Actions ──────────────────────────────────────────────────────────────────

async def action_get_auth_url(state_file: str):
    try:
        url, state, verifier = generate_auth_data()
        with open(state_file, 'w') as f:
            json.dump({'state': state, 'verifier': verifier}, f)
        print(json.dumps({'auth_url': url}))
    except Exception as e:
        print(json.dumps({'error': str(e)}))
        sys.exit(1)


async def action_exchange_token(redirect_url: str, state_file: str):
    try:
        with open(state_file, 'r') as f:
            saved = json.load(f)
        saved_state = saved.get('state', '')
        verifier    = saved['verifier']

        session_token_code, state_from_url = parse_redirect_url(redirect_url)

        # SECURITY: vérifier le state OAuth pour prévenir les attaques CSRF
        if not saved_state or saved_state != state_from_url:
            raise ValueError(
                'State OAuth invalide — l\'URL de redirection ne correspond pas à la session initiée. '
                'Relancez depuis l\'étape 1.'
            )

        # Supprimer le fichier d'état AVANT l'échange (invalide le state même en cas d'erreur réseau)
        try:
            os.remove(state_file)
        except OSError:
            pass

        session_token = await exchange_session_token(session_token_code, verifier)
        devices       = await get_devices_from_token(session_token)

        print(json.dumps({'token': session_token, 'devices': devices}))

    except FileNotFoundError:
        print(json.dumps({'error': 'Fichier d\'état introuvable — relancez depuis l\'étape 1'}))
        sys.exit(1)
    except Exception as e:
        print(json.dumps({'error': str(e)}))
        sys.exit(1)


def main():
    parser = argparse.ArgumentParser(description='JeeNinSwi Auth Helper')
    parser.add_argument('--action',       required=True, choices=['get_auth_url', 'exchange_token'])
    parser.add_argument('--redirect-url', default='',    help='URL de redirection npf://...')
    parser.add_argument('--state-file',   required=True, help='Fichier JSON pour persister l\'état OAuth')
    args = parser.parse_args()

    if args.action == 'get_auth_url':
        asyncio.run(action_get_auth_url(args.state_file))
    elif args.action == 'exchange_token':
        asyncio.run(action_exchange_token(args.redirect_url, args.state_file))


if __name__ == '__main__':
    main()
