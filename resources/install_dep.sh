#!/bin/bash
# JeeNinSwi - Installation des dépendances
# Crée un environnement virtuel Python isolé dans resources/venv/
# pour ne pas polluer le Python système (compatible Python 3.9+, patch auto pour 3.9).
#
# Argument $1 = fichier de progression (chemin absolu)

PROGRESS_FILE=$1
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VENV_DIR="$SCRIPT_DIR/venv"
VENV_PYTHON="$VENV_DIR/bin/python3"

echo "0" > "$PROGRESS_FILE"
echo "[jeeninswi] Début installation dépendances"
echo "[jeeninswi] Répertoire resources : $SCRIPT_DIR"
echo "[jeeninswi] Répertoire venv      : $VENV_DIR"

# ── Étape 1 : Vérifier que python3 est disponible ────────────────────────────
echo "[jeeninswi] Vérification de python3..."
python3 --version 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] ERREUR: python3 introuvable sur le système"
    echo "error" > "$PROGRESS_FILE"
    exit 1
fi
echo "10" > "$PROGRESS_FILE"

# ── Étape 2 : Vérifier python3-venv, installer via apt-get si absent ─────────
echo "[jeeninswi] Vérification du module venv..."
python3 -m venv --help > /dev/null 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] Module python3-venv absent — tentative d'installation automatique..."
    if command -v apt-get > /dev/null 2>&1; then
        sudo apt-get install -y python3-venv 2>&1
        if [ $? -ne 0 ]; then
            echo "[jeeninswi] ERREUR: Impossible d'installer python3-venv via apt-get"
            echo "error" > "$PROGRESS_FILE"
            exit 1
        fi
        echo "[jeeninswi] python3-venv installé avec succès."
        # Vérifier que le module est maintenant disponible
        python3 -m venv --help > /dev/null 2>&1
        if [ $? -ne 0 ]; then
            echo "[jeeninswi] ERREUR: python3-venv toujours indisponible après installation"
            echo "error" > "$PROGRESS_FILE"
            exit 1
        fi
    else
        echo "[jeeninswi] ERREUR: Module python3-venv absent et apt-get non disponible."
        echo "[jeeninswi]   → Installez python3-venv manuellement selon votre distribution."
        echo "error" > "$PROGRESS_FILE"
        exit 1
    fi
fi
echo "20" > "$PROGRESS_FILE"

# ── Étape 3 : Supprimer l'ancien venv puis recréer (état propre garanti) ─────
echo "[jeeninswi] Suppression de l'ancien venv (si présent)..."
rm -rf "$VENV_DIR"
echo "[jeeninswi] Création du venv Python dans $VENV_DIR..."
python3 -m venv "$VENV_DIR" 2>&1

# Vérification explicite : le binaire python3 doit exister dans le venv
if [ ! -f "$VENV_PYTHON" ]; then
    echo "[jeeninswi] ERREUR: $VENV_PYTHON introuvable après la création du venv."
    echo "[jeeninswi]   → La création du venv a échoué (dossier absent ou incomplet)."
    echo "error" > "$PROGRESS_FILE"
    exit 1
fi
echo "[jeeninswi] venv créé avec succès ($(\"$VENV_PYTHON\" --version 2>&1))."
echo "30" > "$PROGRESS_FILE"

# ── Étape 4 : Mettre à jour pip dans le venv ─────────────────────────────────
echo "[jeeninswi] Mise à jour de pip dans le venv..."
"$VENV_PYTHON" -m pip install --upgrade pip 2>&1
echo "40" > "$PROGRESS_FILE"

# ── Étape 5 : Installer pynintendoparental (critique — arrêt si échec) ────────
echo "[jeeninswi] Installation de pynintendoparental..."
"$VENV_PYTHON" -m pip install pynintendoparental 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] ERREUR: Installation de pynintendoparental échouée"
    echo "error" > "$PROGRESS_FILE"
    exit 1
fi
echo "60" > "$PROGRESS_FILE"

# ── Étape 5b : Patch compatibilité Python < 3.10 (pynintendoauth) ────────────
PYTHON_MAJOR=$("$VENV_PYTHON" -c "import sys; print(sys.version_info.major)")
PYTHON_MINOR=$("$VENV_PYTHON" -c "import sys; print(sys.version_info.minor)")
if [ "$PYTHON_MAJOR" -eq 3 ] && [ "$PYTHON_MINOR" -lt 10 ]; then
    echo "[jeeninswi] Python 3.${PYTHON_MINOR} détecté — patch pynintendoauth (syntaxe X|None requiert 3.10+)..."
    "$VENV_PYTHON" << 'PYEOF'
import sys, re, os, glob

site_packages = os.path.join(sys.prefix, 'lib',
    'python{}.{}'.format(sys.version_info.major, sys.version_info.minor), 'site-packages')

STRENUM_SHIM = (
    'try:\n'
    '    from enum import StrEnum\n'
    'except ImportError:\n'
    '    from enum import Enum\n'
    '    class StrEnum(str, Enum):\n'
    '        pass\n'
)

def patch_py_file(path):
    try:
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
    except Exception:
        return False
    original = content

    # Patch 1 : X | None → from __future__ import annotations (Python 3.10+)
    if re.search(r'\|\s*None\b|\bNone\s*\|', content):
        if 'from __future__ import annotations' not in content:
            if content.startswith('#!'):
                nl = content.index('\n') + 1
                content = content[:nl] + 'from __future__ import annotations\n' + content[nl:]
            else:
                content = 'from __future__ import annotations\n' + content

    # Patch 2 : StrEnum absent de Python < 3.11
    if re.search(r'from enum import.*\bStrEnum\b', content):
        if 'class StrEnum' not in content:
            # Cas multi-imports : retirer StrEnum de la liste (ex: from enum import Enum, StrEnum)
            content = re.sub(r',\s*StrEnum\b', '', content)
            content = re.sub(r'\bStrEnum\s*,\s*', '', content)
            # Cas import seul : from enum import StrEnum → remplacer toute la ligne par le shim
            before = content
            content = re.sub(
                r'^from enum import StrEnum[^\S\n]*$',
                STRENUM_SHIM.rstrip('\n'),
                content,
                flags=re.MULTILINE
            )
            # Cas multi-imports restants (seulement si la passe standalone n'a rien changé,
            # pour éviter de matcher la ligne du shim lui-même et créer du Python invalide)
            if content == before and re.search(r'from enum import[^\n]*\bStrEnum\b', content):
                content = re.sub(
                    r'(from enum import[^\n]*\n)',
                    r'\1' + STRENUM_SHIM,
                    content, count=1
                )

    if content != original:
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        print('[jeeninswi] Patché : ' + os.path.relpath(path, site_packages))
        return True
    return False

for pkg in ('pynintendoauth', 'pynintendoparental'):
    pkg_dir = os.path.join(site_packages, pkg)
    if os.path.isdir(pkg_dir):
        for py_file in glob.glob(os.path.join(pkg_dir, '**', '*.py'), recursive=True):
            patch_py_file(py_file)
PYEOF
    echo "[jeeninswi] Patch Python 3.9 terminé."
fi
echo "65" > "$PROGRESS_FILE"

# ── Étape 6 : Installer aiohttp (important — non bloquant) ───────────────────
echo "[jeeninswi] Installation de aiohttp..."
"$VENV_PYTHON" -m pip install aiohttp 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] AVERTISSEMENT: Installation de aiohttp échouée (non bloquant)"
fi
echo "75" > "$PROGRESS_FILE"

# ── Étape 7 : Installer croniter (optionnel — fallback 5 min si absent) ───────
echo "[jeeninswi] Installation de croniter..."
"$VENV_PYTHON" -m pip install croniter 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] AVERTISSEMENT: Installation de croniter échouée (non bloquant, fallback 5 min)"
fi
echo "90" > "$PROGRESS_FILE"

# ── Vérification finale ───────────────────────────────────────────────────────
echo "[jeeninswi] Vérification finale de pynintendoparental dans le venv..."
"$VENV_PYTHON" -c "import pynintendoparental; print('[jeeninswi] pynintendoparental OK')" 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] ERREUR: pynintendoparental non importable dans le venv"
    echo "error" > "$PROGRESS_FILE"
    exit 1
fi

echo "[jeeninswi] Toutes les dépendances sont installées dans le venv."
echo "100" > "$PROGRESS_FILE"
echo "[jeeninswi] Installation terminée avec succès"
rm -f "$PROGRESS_FILE"
exit 0
