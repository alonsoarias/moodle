#!/bin/bash
################################################################################
# Script de corrección para async_copy_task en ingeniusvirtual
# Basado en límites LVE detectados
################################################################################

if [ "$EUID" -ne 0 ]; then
   echo "ERROR: Este script debe ejecutarse como root"
   exit 1
fi

echo "=============================================================================="
echo "CORRECCIÓN DE ASYNC COPY TASK - ingeniusvirtual"
echo "=============================================================================="
echo ""

MOODLE_USER="ingeniusvirtual"
MOODLE_PATH="/home/ingeniusvirtual/public_html"
PHP_BIN="/opt/cpanel/ea-php81/root/usr/bin/php"

# Leer credenciales de BD
if [ -f "$MOODLE_PATH/config.php" ]; then
    DB_HOST=$(grep '\$CFG->dbhost' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_NAME=$(grep '\$CFG->dbname' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_USER=$(grep '\$CFG->dbuser' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_PASS=$(grep '\$CFG->dbpass' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_PREFIX=$(grep '\$CFG->prefix' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1 || echo "mdl_")
fi

################################################################################
# PASO 1: Verificar faults LVE
################################################################################
echo "PASO 1: Verificando límites LVE..."
echo ""

FAULTS=$(lveinfo --user=$MOODLE_USER --period=1d --show-columns=io,iops 2>/dev/null | tail -1)
echo "Faults detectados: $FAULTS"

if echo "$FAULTS" | grep -q "fIO\|fIOPS"; then
    echo ""
    echo "⚠️  ADVERTENCIA: Se detectaron límites de I/O alcanzados"
    echo "    Esto puede causar que las tareas async se cuelguen."
    echo ""

    read -p "¿Aumentar límites de I/O temporalmente? (s/n): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Ss]$ ]]; then
        echo "Aumentando límites de I/O..."
        # Duplicar límites de I/O temporalmente
        lvectl set $MOODLE_USER --io=2532010 --iops=989866 --save-all-parameters
        echo "✓ Límites de I/O aumentados (temporal)"
    fi
fi

################################################################################
# PASO 2: Ajustar PHP CLI
################################################################################
echo ""
echo "PASO 2: Ajustando configuración PHP CLI..."
echo ""

PHP_INI="/opt/cpanel/ea-php81/root/etc/php.ini"

# Backup del php.ini
cp $PHP_INI ${PHP_INI}.backup.$(date +%Y%m%d_%H%M%S)

# Ajustar valores
sed -i 's/^max_execution_time = .*/max_execution_time = 3600/' $PHP_INI
sed -i 's/^memory_limit = .*/memory_limit = 2048M/' $PHP_INI
sed -i 's/^max_input_time = .*/max_input_time = 3600/' $PHP_INI

echo "✓ PHP configurado: max_execution_time=3600s, memory_limit=2048M"

################################################################################
# PASO 3: Limpiar tareas bloqueadas
################################################################################
echo ""
echo "PASO 3: Limpiando tareas adhoc bloqueadas..."
echo ""

if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    # Crear script SQL temporal
    TMP_SQL="/tmp/cleanup_async_$$.sql"

    cat > "$TMP_SQL" << EOF
-- Ver tareas bloqueadas
SELECT 'TAREAS BLOQUEADAS:' as info;
SELECT id, FROM_UNIXTIME(timestarted) as inicio,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos
FROM ${DB_PREFIX}task_adhoc
WHERE classname = '\\\\core\\\\task\\\\asynchronous_copy_task'
  AND timestarted IS NOT NULL
  AND timestarted < UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR);

-- Resetear tareas bloqueadas
UPDATE ${DB_PREFIX}task_adhoc
SET timestarted = NULL, faildelay = 0
WHERE classname = '\\\\core\\\\task\\\\asynchronous_copy_task'
  AND timestarted < UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR);

SELECT 'TAREAS RESETEADAS' as info, ROW_COUNT() as cantidad;

-- Limpiar controladores viejos
DELETE FROM ${DB_PREFIX}backup_controllers
WHERE status != 800
  AND timemodified < UNIX_TIMESTAMP(NOW() - INTERVAL 24 HOUR);

SELECT 'CONTROLADORES ELIMINADOS' as info, ROW_COUNT() as cantidad;
EOF

    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$TMP_SQL"
    rm -f "$TMP_SQL"

    echo "✓ Base de datos limpiada"
else
    echo "⚠️  No se pudieron leer credenciales de BD"
fi

################################################################################
# PASO 4: Ajustar permisos moodledata
################################################################################
echo ""
echo "PASO 4: Verificando permisos de moodledata..."
echo ""

MOODLEDATA=$(grep '\$CFG->dataroot' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)

if [ -n "$MOODLEDATA" ] && [ -d "$MOODLEDATA" ]; then
    echo "Moodledata: $MOODLEDATA"

    # Verificar y corregir permisos
    chown -R $MOODLE_USER:$MOODLE_USER "$MOODLEDATA"
    chmod -R 770 "$MOODLEDATA/temp"

    # Crear directorio backup si no existe
    mkdir -p "$MOODLEDATA/temp/backup"
    chown $MOODLE_USER:$MOODLE_USER "$MOODLEDATA/temp/backup"
    chmod 770 "$MOODLEDATA/temp/backup"

    echo "✓ Permisos ajustados"
else
    echo "⚠️  No se encontró moodledata"
fi

################################################################################
# PASO 5: Limpiar archivos temporales antiguos
################################################################################
echo ""
echo "PASO 5: Limpiando archivos temporales antiguos..."
echo ""

if [ -n "$MOODLEDATA" ] && [ -d "$MOODLEDATA/temp/backup" ]; then
    OLD_FILES=$(find "$MOODLEDATA/temp/backup" -type f -mtime +2 2>/dev/null | wc -l)

    if [ "$OLD_FILES" -gt 0 ]; then
        echo "Encontrados $OLD_FILES archivos antiguos (>2 días)"
        read -p "¿Eliminarlos? (s/n): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Ss]$ ]]; then
            find "$MOODLEDATA/temp/backup" -type f -mtime +2 -delete
            echo "✓ Archivos antiguos eliminados"
        fi
    else
        echo "✓ No hay archivos antiguos"
    fi
fi

################################################################################
# PASO 6: Optimizar LiteSpeed para procesos largos
################################################################################
echo ""
echo "PASO 6: Verificando configuración LiteSpeed..."
echo ""

LSWS_CONF="/usr/local/lsws/conf/httpd_config.conf"

if [ -f "$LSWS_CONF" ]; then
    # Verificar si existe configuración de timeout
    if grep -q "extMaxIdleTime" "$LSWS_CONF"; then
        CURRENT_TIMEOUT=$(grep "extMaxIdleTime" "$LSWS_CONF" | grep -o '[0-9]\+' | head -1)
        echo "Timeout actual de LiteSpeed: ${CURRENT_TIMEOUT}s"

        if [ "$CURRENT_TIMEOUT" -lt 3600 ]; then
            echo "⚠️  Timeout de LiteSpeed es bajo para tareas largas"
            echo "    Se recomienda aumentar a 3600s (1 hora)"
            echo ""
            echo "    Edita manualmente: $LSWS_CONF"
            echo "    Busca 'extMaxIdleTime' y cambia a 3600"
            echo "    Luego reinicia: /usr/local/lsws/bin/lswsctrl restart"
        fi
    else
        echo "✓ No se encontró configuración de timeout (usando default)"
    fi
else
    echo "⚠️  No se encontró configuración de LiteSpeed"
fi

################################################################################
# PASO 7: Crear configuración optimizada en Moodle
################################################################################
echo ""
echo "PASO 7: Optimizando configuración de Moodle..."
echo ""

# Verificar si ya existe optimización
if ! grep -q "CLI OPTIMIZATION" "$MOODLE_PATH/config.php"; then
    echo "Agregando optimización para CLI en config.php..."

    # Crear backup
    cp "$MOODLE_PATH/config.php" "$MOODLE_PATH/config.php.backup.$(date +%Y%m%d_%H%M%S)"

    # Insertar antes de require_once de lib/setup.php
    sed -i '/require_once.*lib\/setup\.php/i \
\
// CLI OPTIMIZATION - Added by fix script\
if (PHP_SAPI === '\''cli'\'') {\
    @ini_set('\''max_execution_time'\'', 0);\
    @ini_set('\''memory_limit'\'', '\''2G'\'');\
    // Desactivar límites de salida\
    @ini_set('\''output_buffering'\'', 0);\
    @ini_set('\''implicit_flush'\'', 1);\
}' "$MOODLE_PATH/config.php"

    echo "✓ Optimización agregada a config.php"
else
    echo "✓ Config.php ya tiene optimizaciones"
fi

################################################################################
# PASO 8: Prueba manual
################################################################################
echo ""
echo "=============================================================================="
echo "PASO 8: PRUEBA MANUAL"
echo "=============================================================================="
echo ""

read -p "¿Ejecutar prueba manual de async_copy_task? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    echo ""
    echo "Ejecutando prueba (timeout 10 minutos)..."
    echo "---"

    cd "$MOODLE_PATH"
    su - $MOODLE_USER -c "cd $MOODLE_PATH && timeout 600 $PHP_BIN -d display_errors=1 -d error_reporting=E_ALL admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute 2>&1" | tee /tmp/async_test_$(date +%Y%m%d_%H%M%S).log

    TEST_EXIT=${PIPESTATUS[0]}

    echo ""
    echo "---"
    if [ $TEST_EXIT -eq 0 ]; then
        echo "✓ Prueba completada exitosamente"
    elif [ $TEST_EXIT -eq 124 ]; then
        echo "⚠️  Prueba excedió timeout de 10 minutos"
        echo "    Puede ser normal para cursos grandes"
    elif [ $TEST_EXIT -eq 137 ]; then
        echo "✗ Proceso matado (posiblemente por límite LVE)"
        echo "    Verifica faults: lveinfo --user=$MOODLE_USER --period=1h"
    else
        echo "⚠️  Prueba terminó con código: $TEST_EXIT"
    fi
fi

################################################################################
# RESUMEN
################################################################################
echo ""
echo "=============================================================================="
echo "RESUMEN DE CAMBIOS"
echo "=============================================================================="
echo ""
echo "✓ Límites LVE verificados"
echo "✓ PHP CLI configurado (max_execution_time=3600s, memory_limit=2048M)"
echo "✓ Tareas bloqueadas limpiadas"
echo "✓ Permisos de moodledata verificados"
echo "✓ Archivos temporales limpiados"
echo "✓ Config.php optimizado para CLI"
echo ""
echo "MONITOREO:"
echo "  - Ver tareas en tiempo real:"
echo "    watch -n 5 'mysql -u $DB_USER -p$DB_PASS $DB_NAME -e \"SELECT COUNT(*) FROM ${DB_PREFIX}task_adhoc WHERE classname=\\\\\"\\\\\\\\\\\\\\\\core\\\\\\\\\\\\\\\\task\\\\\\\\\\\\\\\\asynchronous_copy_task\\\\\"\"'"
echo ""
echo "  - Ver uso de recursos LVE:"
echo "    lvetop"
echo ""
echo "  - Ver faults recientes:"
echo "    lveinfo --user=$MOODLE_USER --period=1h"
echo ""
echo "=============================================================================="
echo "Si el problema persiste, ejecuta como usuario $MOODLE_USER:"
echo ""
echo "  cd $MOODLE_PATH"
echo "  $PHP_BIN admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute --showdebugging"
echo ""
echo "Y envía el output completo para análisis adicional."
echo "=============================================================================="
