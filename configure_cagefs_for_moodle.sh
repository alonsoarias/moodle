#!/bin/bash
################################################################################
# Script para configurar CageFS para que funcione con Moodle async copy
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

echo "=============================================================================="
echo "CONFIGURACIÓN DE CAGEFS PARA MOODLE - $MOODLE_USER"
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

# Crear nueva configuración
cat > "$MOODLE_CFG" << 'EOF'
# ============================================================================
# CageFS configuration for Moodle
# Required for backup/restore operations (async copy)
# ============================================================================

# Compression binaries (required for .mbz creation)
paths=/usr/bin/zip,/bin/zip
paths=/usr/bin/unzip,/bin/unzip
paths=/usr/bin/tar,/bin/tar
paths=/usr/bin/gzip,/bin/gzip
paths=/usr/bin/gunzip,/bin/gunzip
paths=/usr/bin/bzip2,/bin/bzip2
paths=/usr/bin/bunzip2,/bin/bunzip2

# PHP CLI and modules (ea-php81)
paths=/opt/cpanel/ea-php81/root/usr/bin
paths=/opt/cpanel/ea-php81/root/usr/lib64
paths=/opt/cpanel/ea-php81/root/usr/lib64/php/modules
paths=/opt/cpanel/ea-php81/root/etc

# Compression libraries
paths=/usr/lib64/libzip.so.5
paths=/usr/lib64/libzip.so
paths=/usr/lib64/libz.so.1
paths=/usr/lib64/libz.so
paths=/usr/lib64/libbz2.so.1
paths=/usr/lib64/libbz2.so

# Temp directories (required for backup file operations)
paths=/tmp
paths=/var/tmp
EOF

echo "  ✓ Configuración creada: $MOODLE_CFG"
echo ""
cat "$MOODLE_CFG" | sed 's/^/    /'
echo ""

################################################################################
# PASO 3: Aplicar configuración de CageFS
################################################################################
echo -e "${BLUE}PASO 3: Aplicando configuración de CageFS...${NC}"
echo ""

echo "  Actualizando CageFS..."
cagefsctl --update 2>&1 | sed 's/^/    /'

echo ""
echo "  Forzando actualización..."
cagefsctl --force-update 2>&1 | sed 's/^/    /'

echo ""
echo "  Remontando filesystems de CageFS..."
cagefsctl --remount-all 2>&1 | sed 's/^/    /'

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
    else
        echo -e "  ${RED}✗${NC} $BIN: NO ENCONTRADO"
        ALL_OK=0
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
echo -e "${GREEN}✓${NC} CageFS actualizado y remontado"

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
echo "1. Limpiar tareas bloqueadas:"
echo "   mysql -u root -p -e \"UPDATE nombre_bd.mdl_task_adhoc SET timestarted=NULL WHERE classname='\\\\\\\\core\\\\\\\\task\\\\\\\\asynchronous_copy_task'\""
echo ""
echo "2. Probar copia asíncrona:"
echo "   su - $MOODLE_USER"
echo "   cd /home/$MOODLE_USER/public_html"
echo "   /opt/cpanel/ea-php81/root/usr/bin/php admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute"
echo ""
echo "3. O crear una copia de curso desde Moodle"
echo ""
echo "4. Monitorear ejecución:"
echo "   watch -n 5 'ps aux | grep asynchronous_copy_task | grep -v grep'"
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
echo "Opción C: Ejecutar con debug:"
echo "   su - $MOODLE_USER -c 'strace -o /tmp/strace.log /opt/cpanel/ea-php81/root/usr/bin/php ...'"
echo ""
echo "=============================================================================="
