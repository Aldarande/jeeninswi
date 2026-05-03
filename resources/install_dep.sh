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

# ── Étape 1 : Vérifier que python3-venv est disponible ───────────────────────
echo "[jeeninswi] Vérification de python3..."
python3 --version 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] ERREUR: python3 introuvable sur le système"
    echo "error" > "$PROGRESS_FILE"
    exit 1
fi
echo "10" > "$PROGRESS_FILE"

# ── Étape 2 : Créer le venv si absent (ou le recréer si corrompu) ─────────────
if [ ! -f "$VENV_PYTHON" ]; then
    echo "[jeeninswi] Création du venv Python dans $VENV_DIR..."
    python3 -m venv "$VENV_DIR" 2>&1
    if [ $? -ne 0 ]; then
        echo "[jeeninswi] ERREUR: Impossible de créer le venv."
        echo "[jeeninswi]   → Sur Debian/Ubuntu : apt-get install -y python3-venv"
        echo "error" > "$PROGRESS_FILE"
        exit 1
    fi
    echo "[jeeninswi] venv créé avec succès."
else
    echo "[jeeninswi] venv déjà présent — mise à jour des paquets uniquement."
fi
echo "20" > "$PROGRESS_FILE"

# ── Étape 3 : Mettre à jour pip dans le venv ─────────────────────────────────
echo "[jeeninswi] Mise à jour de pip dans le venv..."
"$VENV_PYTHON" -m pip install --upgrade pip 2>&1
echo "30" > "$PROGRESS_FILE"

# ── Étape 4 : Installer pynintendoparental ────────────────────────────────────
echo "[jeeninswi] Installation de pynintendoparental..."
"$VENV_PYTHON" -m pip install pynintendoparental 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] ERREUR: Installation de pynintendoparental échouée"
    echo "error" > "$PROGRESS_FILE"
    exit 1
fi
echo "60" > "$PROGRESS_FILE"

# ── Étape 4b : Patch compatibilité Python < 3.10 (pynintendoauth) ────────────
PYTHON_MAJOR=$("$VENV_PYTHON" -c "import sys; print(sys.version_info.major)")
PYTHON_MINOR=$("$VENV_PYTHON" -c "import sys; print(sys.version_info.minor)")
if [ "$PYTHON_MAJOR" -eq 3 ] && [ "$PYTHON_MINOR" -lt 10 ]; then
    echo "[jeeninswi] Python 3.${PYTHON_MINOR} détecté — patch pynintendoauth (syntaxe X|None requiert 3.10+)..."
    "$VENV_PYTHON" << 'PYEOF'
import sys, re, os, glob

site_packages = os.path.join(sys.prefix, 'lib',
    'python{}.{}'.format(sys.version_info.major, sys.version_info.minor), 'site-packages')

def patch_py_file(path):
    try:
        with open(path, 'r', encoding='utf-8') as f:
            content = f.read()
    except Exception:
        return False
    # Ne patcher que les fichiers qui utilisent la syntaxe X | Y dans les annotations
    if not re.search(r'\|\s*None\b|\bNone\s*\|', content):
        return False
    if 'from __future__ import annotations' in content:
        return False
    # Insérer après le shebang si présent, sinon en tête de fichier
    if content.startswith('#!'):
        nl = content.index('\n') + 1
        content = content[:nl] + 'from __future__ import annotations\n' + content[nl:]
    else:
        content = 'from __future__ import annotations\n' + content
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print('[jeeninswi] Patché : ' + os.path.relpath(path, site_packages))
    return True

for pkg in ('pynintendoauth', 'pynintendoparental'):
    pkg_dir = os.path.join(site_packages, pkg)
    if os.path.isdir(pkg_dir):
        for py_file in glob.glob(os.path.join(pkg_dir, '**', '*.py'), recursive=True):
            patch_py_file(py_file)
PYEOF
    echo "[jeeninswi] Patch Python 3.9 terminé."
fi
echo "65" > "$PROGRESS_FILE"

# ── Étape 5 : Installer aiohttp (HTTP async pour le démon) ───────────────────
echo "[jeeninswi] Installation de aiohttp..."
"$VENV_PYTHON" -m pip install aiohttp 2>&1
if [ $? -ne 0 ]; then
    echo "[jeeninswi] AVERTISSEMENT: Installation de aiohttp échouée (non bloquant)"
fi
echo "75" > "$PROGRESS_FILE"

# ── Étape 6 : Installer croniter (polling précis selon expression cron) ───────
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
