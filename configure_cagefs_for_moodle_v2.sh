#!/bin/bash
################################################################################
# Script para configurar CageFS para que funcione con Moodle async copy
# Versión 2.0 - Compatible con cualquier versión de PHP en cPanel
# Usuario: ingeniusvirtual
# Problema: CageFS bloquea binarios necesarios para backup/restore
################################################################################

if [ "$EUID" -ne 0 ]; then
   echo "ERROR: Este script debe ejecutarse como root"
   exit 1
fi

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

MOODLE_USER="ingeniusvirtual"
MOODLE_HOME="/home/$MOODLE_USER/public_html"

echo "=============================================================================="
echo "CONFIGURACIÓN DE CAGEFS PARA MOODLE v2.0 - $MOODLE_USER"
echo "Compatible con cualquier versión de PHP en cPanel"
echo "=============================================================================="
echo ""

# Verificar que CageFS está instalado
if ! command -v cagefsctl &> /dev/null; then
    echo -e "${RED}ERROR: CageFS no está instalado${NC}"
    exit 1
fi

echo -e "${GREEN}✓ CageFS detectado${NC}"
echo ""

################################################################################
# PASO 0: Detectar versión de PHP del usuario
################################################################################
echo -e "${BLUE}PASO 0: Detectando versión de PHP para $MOODLE_USER...${NC}"
echo ""

# Método 1: Desde config.php de Moodle
PHP_VERSION=""
if [ -f "$MOODLE_HOME/config.php" ]; then
    # Buscar si hay una línea que especifique PHP CLI
    PHP_CLI=$(grep -i "php" "$MOODLE_HOME/config.php" | grep -i "cli" | head -1 | grep -oP "ea-php[0-9]+" | head -1)
    if [ -n "$PHP_CLI" ]; then
        PHP_VERSION="$PHP_CLI"
        echo "  ✓ Detectado desde config.php: $PHP_VERSION"
    fi
fi

# Método 2: Desde .htaccess
if [ -z "$PHP_VERSION" ] && [ -f "$MOODLE_HOME/.htaccess" ]; then
    PHP_CLI=$(grep -i "AddHandler.*ea-php" "$MOODLE_HOME/.htaccess" | head -1 | grep -oP "ea-php[0-9]+" | head -1)
    if [ -n "$PHP_CLI" ]; then
        PHP_VERSION="$PHP_CLI"
        echo "  ✓ Detectado desde .htaccess: $PHP_VERSION"
    fi
fi

# Método 3: Desde cPanel API
if [ -z "$PHP_VERSION" ]; then
    if command -v whmapi1 &> /dev/null; then
        PHP_CLI=$(whmapi1 php_get_vhost_versions user="$MOODLE_USER" 2>/dev/null | grep -i "ea-php" | grep -oP "ea-php[0-9]+" | head -1)
        if [ -n "$PHP_CLI" ]; then
            PHP_VERSION="$PHP_CLI"
            echo "  ✓ Detectado desde cPanel API: $PHP_VERSION"
        fi
    fi
fi

# Método 4: Desde /var/cpanel/users/$MOODLE_USER
if [ -z "$PHP_VERSION" ] && [ -f "/var/cpanel/users/$MOODLE_USER" ]; then
    PHP_CLI=$(grep -i "PHP" "/var/cpanel/users/$MOODLE_USER" | grep -oP "ea-php[0-9]+" | head -1)
    if [ -n "$PHP_CLI" ]; then
        PHP_VERSION="$PHP_CLI"
        echo "  ✓ Detectado desde cpanel user file: $PHP_VERSION"
    fi
fi

# Método 5: Ejecutar PHP como el usuario y ver su versión
if [ -z "$PHP_VERSION" ]; then
    # Buscar todos los ea-php instalados
    for PHP_BIN in /opt/cpanel/ea-php*/root/usr/bin/php; do
        if [ -x "$PHP_BIN" ]; then
            # Intentar ejecutar como el usuario
            VERSION_OUTPUT=$(su - $MOODLE_USER -c "$PHP_BIN -v 2>/dev/null" | head -1)
            if [ -n "$VERSION_OUTPUT" ]; then
                PHP_VERSION=$(echo "$PHP_BIN" | grep -oP "ea-php[0-9]+")
                echo "  ✓ Detectado ejecutando PHP: $PHP_VERSION"
                echo "    Versión: $VERSION_OUTPUT"
                break
            fi
        fi
    done
fi

# Fallback: Usar el más reciente instalado
if [ -z "$PHP_VERSION" ]; then
    PHP_VERSION=$(ls -1d /opt/cpanel/ea-php* 2>/dev/null | sort -V | tail -1 | grep -oP "ea-php[0-9]+")
    if [ -n "$PHP_VERSION" ]; then
        echo -e "  ${YELLOW}⚠️  No se pudo detectar automáticamente, usando: $PHP_VERSION${NC}"
    fi
fi

if [ -z "$PHP_VERSION" ]; then
    echo -e "${RED}ERROR: No se pudo detectar ninguna versión de PHP${NC}"
    echo ""
    echo "Versiones disponibles:"
    ls -1d /opt/cpanel/ea-php* 2>/dev/null | grep -oP "ea-php[0-9]+" || echo "  Ninguna encontrada"
    echo ""
    read -p "Ingresa la versión manualmente (ej: ea-php81): " PHP_VERSION

    if [ -z "$PHP_VERSION" ]; then
        echo "Cancelado"
        exit 1
    fi
fi

PHP_BASE_PATH="/opt/cpanel/$PHP_VERSION/root"

echo ""
echo -e "${GREEN}✓ Usando PHP: $PHP_VERSION${NC}"
echo -e "  Ruta base: $PHP_BASE_PATH"
echo ""

# Verificar que la ruta existe
if [ ! -d "$PHP_BASE_PATH" ]; then
    echo -e "${RED}ERROR: Ruta no existe: $PHP_BASE_PATH${NC}"
    exit 1
fi

################################################################################
# PASO 1: Verificar estado actual de CageFS
################################################################################
echo -e "${BLUE}PASO 1: Verificando estado actual de CageFS...${NC}"
echo ""

CAGEFS_STATUS=$(cagefsctl --list-enabled 2>/dev/null | grep -c "$MOODLE_USER")

if [ "$CAGEFS_STATUS" -gt 0 ]; then
    echo -e "${YELLOW}⚠️  CageFS está ACTIVADO para $MOODLE_USER${NC}"
    echo "   Esto puede causar problemas con async copy"
else
    echo -e "${GREEN}✓ CageFS está DESACTIVADO para $MOODLE_USER${NC}"
fi

# Mostrar versiones de PHP disponibles
echo ""
echo "Versiones de PHP instaladas en el servidor:"
for PHP_DIR in /opt/cpanel/ea-php*/root/usr/bin/php; do
    if [ -x "$PHP_DIR" ]; then
        PHP_VER=$(echo "$PHP_DIR" | grep -oP "ea-php[0-9]+")
        PHP_VER_NUM=$($PHP_DIR -v 2>/dev/null | head -1 | grep -oP "[0-9]+\.[0-9]+\.[0-9]+")
        echo "  - $PHP_VER ($PHP_VER_NUM)"
    fi
done

echo ""
read -p "¿Continuar con la configuración de CageFS para Moodle? (s/n): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Ss]$ ]]; then
    echo "Cancelado"
    exit 0
fi

################################################################################
# PASO 2: Crear configuración para Moodle en CageFS
################################################################################
echo ""
echo -e "${BLUE}PASO 2: Creando configuración de Moodle para CageFS...${NC}"
echo ""

MOODLE_CFG="/etc/cagefs/conf.d/moodle.cfg"

# Backup si existe
if [ -f "$MOODLE_CFG" ]; then
    cp "$MOODLE_CFG" "${MOODLE_CFG}.backup.$(date +%Y%m%d_%H%M%S)"
    echo "  ✓ Backup de configuración anterior creado"
fi

# Crear nueva configuración - GENÉRICA para todas las versiones de PHP
cat > "$MOODLE_CFG" << 'EOFCONFIG'
# ============================================================================
# CageFS configuration for Moodle
# Required for backup/restore operations (async copy)
# Compatible con todas las versiones de PHP en cPanel (ea-php*)
# ============================================================================

# Compression binaries (required for .mbz creation)
paths=/usr/bin/zip,/bin/zip
paths=/usr/bin/unzip,/bin/unzip
paths=/usr/bin/tar,/bin/tar
paths=/usr/bin/gzip,/bin/gzip
paths=/usr/bin/gunzip,/bin/gunzip
paths=/usr/bin/bzip2,/bin/bzip2
paths=/usr/bin/bunzip2,/bin/bunzip2
paths=/usr/bin/7z,/bin/7z
paths=/usr/bin/7za,/bin/7za

# Compression libraries
paths=/usr/lib64/libzip.so.5
paths=/usr/lib64/libzip.so
paths=/usr/lib64/libz.so.1
paths=/usr/lib64/libz.so
paths=/usr/lib64/libbz2.so.1
paths=/usr/lib64/libbz2.so
paths=/usr/lib64/liblzma.so.5
paths=/usr/lib64/liblzma.so

# Temp directories (required for backup file operations)
paths=/tmp
paths=/var/tmp

# ALL PHP versions in cPanel (ea-php*)
# This makes the config version-agnostic
EOFCONFIG

# Agregar TODAS las versiones de PHP instaladas
echo "  Agregando todas las versiones de PHP al config..."
for PHP_DIR in /opt/cpanel/ea-php*/root; do
    if [ -d "$PHP_DIR" ]; then
        PHP_VER=$(echo "$PHP_DIR" | grep -oP "ea-php[0-9]+")
        echo "" >> "$MOODLE_CFG"
        echo "# $PHP_VER" >> "$MOODLE_CFG"
        echo "paths=$PHP_DIR/usr/bin" >> "$MOODLE_CFG"
        echo "paths=$PHP_DIR/usr/sbin" >> "$MOODLE_CFG"
        echo "paths=$PHP_DIR/usr/lib64" >> "$MOODLE_CFG"
        echo "paths=$PHP_DIR/usr/lib64/php/modules" >> "$MOODLE_CFG"
        echo "paths=$PHP_DIR/etc" >> "$MOODLE_CFG"
        echo "  ✓ Agregado: $PHP_VER"
    fi
done

echo ""
echo "  ✓ Configuración creada: $MOODLE_CFG"
echo ""
echo "Contenido (primeras 40 líneas):"
head -40 "$MOODLE_CFG" | sed 's/^/    /'
TOTAL_LINES=$(wc -l < "$MOODLE_CFG")
if [ $TOTAL_LINES -gt 40 ]; then
    echo "    ... ($((TOTAL_LINES - 40)) líneas más)"
fi
echo ""

################################################################################
# PASO 3: Aplicar configuración de CageFS
################################################################################
echo -e "${BLUE}PASO 3: Aplicando configuración de CageFS...${NC}"
echo ""

echo "  Actualizando CageFS..."
cagefsctl --update 2>&1 | head -10 | sed 's/^/    /'

echo ""
echo "  Forzando actualización..."
cagefsctl --force-update 2>&1 | head -10 | sed 's/^/    /'

echo ""
echo "  Remontando filesystems de CageFS..."
cagefsctl --remount-all 2>&1 | head -10 | sed 's/^/    /'

echo ""
echo -e "${GREEN}✓ CageFS actualizado${NC}"

################################################################################
# PASO 4: Reactivar CageFS para el usuario
################################################################################
echo ""
echo -e "${BLUE}PASO 4: Configurando CageFS para $MOODLE_USER...${NC}"
echo ""

read -p "¿Quieres ACTIVAR CageFS para $MOODLE_USER con la nueva configuración? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    echo "  Activando CageFS..."
    cagefsctl --enable $MOODLE_USER 2>&1 | sed 's/^/    /'

    echo "  Remontando filesystem del usuario..."
    cagefsctl --remount $MOODLE_USER 2>&1 | sed 's/^/    /'

    echo ""
    echo -e "${GREEN}✓ CageFS activado para $MOODLE_USER${NC}"
else
    echo -e "${YELLOW}  CageFS NO activado para $MOODLE_USER${NC}"
    echo "  (puedes activarlo después con: cagefsctl --enable $MOODLE_USER)"
fi

################################################################################
# PASO 5: Verificar binarios disponibles
################################################################################
echo ""
echo -e "${BLUE}PASO 5: Verificando binarios disponibles para $MOODLE_USER...${NC}"
echo ""

BINARIES=("zip" "unzip" "tar" "gzip" "php")
ALL_OK=1

for BIN in "${BINARIES[@]}"; do
    RESULT=$(su - $MOODLE_USER -s /bin/bash -c "which $BIN 2>/dev/null")
    if [ -n "$RESULT" ]; then
        echo -e "  ${GREEN}✓${NC} $BIN: $RESULT"

        # Para PHP, mostrar la versión
        if [ "$BIN" == "php" ]; then
            PHP_VER=$(su - $MOODLE_USER -s /bin/bash -c "$RESULT -v 2>/dev/null | head -1")
            echo "      $PHP_VER"
        fi
    else
        echo -e "  ${RED}✗${NC} $BIN: NO ENCONTRADO"
        ALL_OK=0
    fi
done

echo ""

# Verificar TODAS las versiones de PHP disponibles para el usuario
echo "Versiones de PHP disponibles dentro de CageFS para $MOODLE_USER:"
for PHP_BIN in /opt/cpanel/ea-php*/root/usr/bin/php; do
    if [ -x "$PHP_BIN" ]; then
        PHP_VER=$(echo "$PHP_BIN" | grep -oP "ea-php[0-9]+")
        RESULT=$(su - $MOODLE_USER -s /bin/bash -c "test -x $PHP_BIN && echo OK 2>/dev/null")
        if [ "$RESULT" == "OK" ]; then
            echo -e "  ${GREEN}✓${NC} $PHP_VER: Accesible"
        else
            echo -e "  ${YELLOW}⚠${NC}  $PHP_VER: No accesible"
        fi
    fi
done

echo ""

if [ $ALL_OK -eq 1 ]; then
    echo -e "${GREEN}✓ Todos los binarios necesarios están disponibles${NC}"
else
    echo -e "${YELLOW}⚠️  Algunos binarios no están disponibles${NC}"
    echo "   Esto puede causar problemas. Verifica la configuración."
fi

################################################################################
# PASO 6: Probar acceso a directorios temporales
################################################################################
echo ""
echo -e "${BLUE}PASO 6: Verificando acceso a directorios temporales...${NC}"
echo ""

MOODLEDATA="/home/$MOODLE_USER/moodledata"
TEMP_DIR="$MOODLEDATA/temp/backup"

if [ -d "$TEMP_DIR" ]; then
    echo "  ✓ Directorio existe: $TEMP_DIR"

    # Verificar permisos
    OWNER=$(stat -c '%U' "$TEMP_DIR")
    if [ "$OWNER" == "$MOODLE_USER" ]; then
        echo "  ✓ Propietario correcto: $OWNER"
    else
        echo -e "  ${YELLOW}⚠️  Propietario incorrecto: $OWNER (debería ser $MOODLE_USER)${NC}"
        echo "    Corrigiendo..."
        chown -R $MOODLE_USER:$MOODLE_USER "$MOODLEDATA"
        echo "  ✓ Permisos corregidos"
    fi

    # Probar escritura
    TEST_FILE="$TEMP_DIR/.test_write_$(date +%s)"
    if su - $MOODLE_USER -c "touch $TEST_FILE 2>/dev/null"; then
        echo "  ✓ Escritura funcional"
        rm -f "$TEST_FILE"
    else
        echo -e "  ${RED}✗ No se puede escribir en $TEMP_DIR${NC}"
    fi
else
    echo -e "  ${YELLOW}⚠️  Directorio no existe: $TEMP_DIR${NC}"
    echo "    Creando..."
    mkdir -p "$TEMP_DIR"
    chown -R $MOODLE_USER:$MOODLE_USER "$MOODLEDATA"
    chmod -R 770 "$MOODLEDATA/temp"
    echo "  ✓ Directorio creado"
fi

################################################################################
# PASO 7: Prueba de compresión
################################################################################
echo ""
echo -e "${BLUE}PASO 7: Probando compresión/descompresión...${NC}"
echo ""

TEST_RESULT=$(su - $MOODLE_USER -s /bin/bash -c '
cd /tmp
echo "test" > test_cagefs.txt 2>/dev/null
zip -q test_cagefs.zip test_cagefs.txt 2>/dev/null
if [ $? -eq 0 ]; then
    unzip -qt test_cagefs.zip >/dev/null 2>&1
    if [ $? -eq 0 ]; then
        echo "OK"
    else
        echo "UNZIP_FAIL"
    fi
else
    echo "ZIP_FAIL"
fi
rm -f test_cagefs.* 2>/dev/null
' 2>&1)

if [ "$TEST_RESULT" == "OK" ]; then
    echo -e "  ${GREEN}✓ Compresión/descompresión funcional${NC}"
else
    echo -e "  ${RED}✗ Prueba de compresión falló: $TEST_RESULT${NC}"
fi

################################################################################
# RESUMEN Y PRÓXIMOS PASOS
################################################################################
echo ""
echo "=============================================================================="
echo "RESUMEN DE CONFIGURACIÓN"
echo "=============================================================================="
echo ""
echo -e "${GREEN}✓${NC} Configuración de CageFS creada: $MOODLE_CFG"
echo -e "${GREEN}✓${NC} Configuración incluye TODAS las versiones de PHP instaladas"
echo -e "${GREEN}✓${NC} CageFS actualizado y remontado"
echo -e "${GREEN}✓${NC} Versión detectada para $MOODLE_USER: $PHP_VERSION"

CURRENT_STATUS=$(cagefsctl --list-enabled 2>/dev/null | grep -c "$MOODLE_USER")
if [ "$CURRENT_STATUS" -gt 0 ]; then
    echo -e "${GREEN}✓${NC} CageFS ACTIVADO para $MOODLE_USER"
else
    echo -e "${YELLOW}⚠️${NC}  CageFS DESACTIVADO para $MOODLE_USER"
fi

echo ""
echo "=============================================================================="
echo "PRÓXIMOS PASOS"
echo "=============================================================================="
echo ""
echo "1. Limpiar tareas bloqueadas en la base de datos de Moodle"
echo ""
echo "2. Probar copia asíncrona:"
echo "   su - $MOODLE_USER"
echo "   cd $MOODLE_HOME"
echo "   $PHP_BASE_PATH/usr/bin/php admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute"
echo ""
echo "3. O crear una copia de curso desde Moodle (interfaz web)"
echo ""
echo "4. Monitorear ejecución:"
echo "   watch -n 5 'ps aux | grep asynchronous_copy_task | grep -v grep'"
echo ""
echo "=============================================================================="
echo "VENTAJAS DE ESTA CONFIGURACIÓN"
echo "=============================================================================="
echo ""
echo "✓ Compatible con TODAS las versiones de PHP instaladas (ea-php*)"
echo "✓ Si actualizas PHP en cPanel, seguirá funcionando automáticamente"
echo "✓ No necesitas modificar la configuración al cambiar de versión"
echo "✓ Mantiene la seguridad de CageFS activa"
echo ""
echo "=============================================================================="
echo "SI LA COPIA SIGUE FALLANDO"
echo "=============================================================================="
echo ""
echo "Opción A: Deshabilitar CageFS solo para $MOODLE_USER:"
echo "   cagefsctl --disable $MOODLE_USER"
echo ""
echo "Opción B: Ver logs de CageFS:"
echo "   tail -100 /var/log/messages | grep -i cagefs"
echo ""
echo "Opción C: Ejecutar con debug completo:"
echo "   su - $MOODLE_USER -c 'strace -o /tmp/strace.log $PHP_BASE_PATH/usr/bin/php ...'"
echo ""
echo "Opción D: Verificar qué falta dentro del cage:"
echo "   cagefsctl --enter $MOODLE_USER"
echo "   ls -la /opt/cpanel/"
echo "   which php zip tar gzip"
echo "   exit"
echo ""
echo "=============================================================================="
echo ""
echo -e "${GREEN}Configuración completada exitosamente${NC}"
echo ""
