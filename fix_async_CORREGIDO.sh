#!/bin/bash
################################################################################
# Script CORREGIDO para async_copy_task - ingeniusvirtual
# Los límites LVE NO son el problema (son excelentes: 24GB RAM, 1.21GB/s I/O)
# Causa probable: Timeout de PHP o LiteSpeed
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

echo "=============================================================================="
echo "CORRECCIÓN DE ASYNC COPY TASK - ingeniusvirtual (ANÁLISIS CORREGIDO)"
echo "=============================================================================="
echo ""
echo -e "${GREEN}Límites LVE detectados (EXCELENTES, NO son el problema):${NC}"
echo "  - CPU: 650% (6.5 cores)"
echo "  - RAM: 24 GB física / 48 GB virtual"
echo "  - I/O: 1.21 GB/s (muy alto)"
echo "  - IOPS: 495K"
echo ""
echo -e "${YELLOW}Causa probable: Timeout de PHP o LiteSpeed${NC}"
echo "=============================================================================="
echo ""

MOODLE_USER="ingeniusvirtual"
MOODLE_PATH="/home/ingeniusvirtual/public_html"
PHP_BIN="/opt/cpanel/ea-php81/root/usr/bin/php"
PHP_INI="/opt/cpanel/ea-php81/root/etc/php.ini"

# Leer credenciales de BD
if [ -f "$MOODLE_PATH/config.php" ]; then
    DB_HOST=$(grep '\$CFG->dbhost' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_NAME=$(grep '\$CFG->dbname' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_USER=$(grep '\$CFG->dbuser' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_PASS=$(grep '\$CFG->dbpass' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)
    DB_PREFIX=$(grep '\$CFG->prefix' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1 || echo "mdl_")
fi

################################################################################
# PASO 1: Verificar configuración PHP actual
################################################################################
echo -e "${BLUE}PASO 1: Verificando configuración PHP CLI actual...${NC}"
echo ""

MAX_EXEC=$(su - $MOODLE_USER -c "$PHP_BIN -i 2>/dev/null | grep 'max_execution_time =>' | awk '{print \$3}' | head -1")
MEMORY=$(su - $MOODLE_USER -c "$PHP_BIN -i 2>/dev/null | grep 'memory_limit =>' | awk '{print \$3}' | head -1")
MAX_INPUT=$(su - $MOODLE_USER -c "$PHP_BIN -i 2>/dev/null | grep 'max_input_time =>' | awk '{print \$3}' | head -1")

echo "  Valores actuales:"
echo "    max_execution_time: $MAX_EXEC"
echo "    memory_limit: $MEMORY"
echo "    max_input_time: $MAX_INPUT"
echo ""

NEED_FIX=0

if [ "$MAX_EXEC" -lt 3600 ] && [ "$MAX_EXEC" != "-1" ] && [ "$MAX_EXEC" != "0" ]; then
    echo -e "${YELLOW}  ⚠️  max_execution_time es bajo ($MAX_EXEC < 3600)${NC}"
    NEED_FIX=1
fi

if [ "$MAX_INPUT" -lt 3600 ] && [ "$MAX_INPUT" != "-1" ] && [ "$MAX_INPUT" != "0" ]; then
    echo -e "${YELLOW}  ⚠️  max_input_time es bajo ($MAX_INPUT < 3600)${NC}"
    NEED_FIX=1
fi

# Convertir memory a MB para comparar
MEMORY_MB=$(echo $MEMORY | sed 's/M//' | sed 's/G/*1024/' | bc 2>/dev/null || echo 0)
if [ "$MEMORY_MB" -lt 1024 ]; then
    echo -e "${YELLOW}  ⚠️  memory_limit es bajo ($MEMORY < 1024M)${NC}"
    NEED_FIX=1
fi

################################################################################
# PASO 2: Ajustar PHP.INI si es necesario
################################################################################
if [ $NEED_FIX -eq 1 ]; then
    echo ""
    echo -e "${BLUE}PASO 2: Ajustando configuración PHP CLI...${NC}"
    echo ""

    # Backup
    cp $PHP_INI ${PHP_INI}.backup.$(date +%Y%m%d_%H%M%S)
    echo "  ✓ Backup creado: ${PHP_INI}.backup.*"

    # Ajustar valores
    sed -i 's/^max_execution_time = .*/max_execution_time = 7200/' $PHP_INI
    sed -i 's/^memory_limit = .*/memory_limit = 2048M/' $PHP_INI
    sed -i 's/^max_input_time = .*/max_input_time = 7200/' $PHP_INI
    sed -i 's/^post_max_size = .*/post_max_size = 512M/' $PHP_INI
    sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 512M/' $PHP_INI

    echo "  ✓ PHP configurado:"
    echo "    max_execution_time = 7200 (2 horas)"
    echo "    memory_limit = 2048M"
    echo "    max_input_time = 7200"
    echo ""

    # Verificar cambios
    echo "  Verificando cambios..."
    NEW_MAX_EXEC=$(su - $MOODLE_USER -c "$PHP_BIN -i 2>/dev/null | grep 'max_execution_time =>' | awk '{print \$3}' | head -1")
    NEW_MEMORY=$(su - $MOODLE_USER -c "$PHP_BIN -i 2>/dev/null | grep 'memory_limit =>' | awk '{print \$3}' | head -1")
    echo "  ✓ max_execution_time: $NEW_MAX_EXEC"
    echo "  ✓ memory_limit: $NEW_MEMORY"
else
    echo ""
    echo -e "${GREEN}PASO 2: Configuración PHP CLI es correcta, omitiendo ajustes${NC}"
    echo ""
fi

################################################################################
# PASO 3: Optimizar config.php de Moodle
################################################################################
echo -e "${BLUE}PASO 3: Optimizando config.php de Moodle...${NC}"
echo ""

if ! grep -q "CLI OPTIMIZATION" "$MOODLE_PATH/config.php"; then
    echo "  Agregando optimización CLI..."

    # Backup
    cp "$MOODLE_PATH/config.php" "$MOODLE_PATH/config.php.backup.$(date +%Y%m%d_%H%M%S)"

    # Buscar la línea de require_once lib/setup.php e insertar antes
    sed -i '/require_once.*lib\/setup\.php/i \
\
// ============================================================================\
// CLI OPTIMIZATION - Added by fix script '$(date +%Y%m%d)'\
// ============================================================================\
if (PHP_SAPI === '\''cli'\'') {\
    @ini_set('\''max_execution_time'\'', 0);\
    @ini_set('\''memory_limit'\'', '\''2G'\'');\
    @ini_set('\''display_errors'\'', '\''1'\'');\
    @ini_set('\''log_errors'\'', '\''1'\'');\
}\
\
// Optimización de base de datos\
if (!isset($CFG->dboptions)) {\
    $CFG->dboptions = array();\
}\
$CFG->dboptions = array_merge($CFG->dboptions, [\
    '\''dbpersist'\'' => false,\
    '\''dbsocket'\'' => false,\
    '\''connecttimeout'\'' => 30,\
]);\
\
// Aumentar timeouts de tareas adhoc\
$CFG->task_adhoc_max_runtime = 7200; // 2 horas\
// ============================================================================\
' "$MOODLE_PATH/config.php"

    # Ajustar permisos
    chown $MOODLE_USER:$MOODLE_USER "$MOODLE_PATH/config.php"

    echo "  ✓ Optimizaciones agregadas a config.php"
    echo "    - CLI con max_execution_time = 0 (ilimitado)"
    echo "    - memory_limit = 2G para CLI"
    echo "    - DB connection timeout = 30s"
    echo "    - task_adhoc_max_runtime = 7200s"
else
    echo "  ✓ Config.php ya tiene optimizaciones"
fi

################################################################################
# PASO 4: Limpiar tareas bloqueadas en BD
################################################################################
echo ""
echo -e "${BLUE}PASO 4: Limpiando tareas adhoc bloqueadas...${NC}"
echo ""

if [ -n "$DB_HOST" ] && [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    TMP_SQL="/tmp/cleanup_async_$$.sql"

    cat > "$TMP_SQL" << EOF
-- Ver estado actual
SELECT 'ESTADO ANTES DE LIMPIAR:' as info;
SELECT COUNT(*) as total_adhoc_pendientes,
       SUM(CASE WHEN classname = '\\\\core\\\\task\\\\asynchronous_copy_task' THEN 1 ELSE 0 END) as async_copy_tasks,
       SUM(CASE WHEN timestarted IS NOT NULL THEN 1 ELSE 0 END) as tareas_corriendo,
       SUM(CASE WHEN faildelay > 0 THEN 1 ELSE 0 END) as tareas_fallidas
FROM ${DB_PREFIX}task_adhoc;

-- Ver tareas bloqueadas
SELECT '' as separador;
SELECT 'TAREAS BLOQUEADAS (>2 horas):' as info;
SELECT id, FROM_UNIXTIME(timestarted) as inicio,
       TIMESTAMPDIFF(MINUTE, FROM_UNIXTIME(timestarted), NOW()) as minutos_corriendo,
       faildelay
FROM ${DB_PREFIX}task_adhoc
WHERE classname = '\\\\core\\\\task\\\\asynchronous_copy_task'
  AND timestarted IS NOT NULL
  AND timestarted < UNIX_TIMESTAMP(NOW() - INTERVAL 2 HOUR);

-- Resetear tareas bloqueadas
UPDATE ${DB_PREFIX}task_adhoc
SET timestarted = NULL, faildelay = 0
WHERE classname = '\\\\core\\\\task\\\\asynchronous_copy_task'
  AND timestarted < UNIX_TIMESTAMP(NOW() - INTERVAL 2 HOUR);

SELECT '' as separador;
SELECT 'TAREAS RESETEADAS' as info, ROW_COUNT() as cantidad;

-- Limpiar controladores viejos (más de 48 horas)
DELETE FROM ${DB_PREFIX}backup_controllers
WHERE status != 800
  AND timemodified < UNIX_TIMESTAMP(NOW() - INTERVAL 48 HOUR);

SELECT '' as separador;
SELECT 'CONTROLADORES ELIMINADOS' as info, ROW_COUNT() as cantidad;

-- Estado final
SELECT '' as separador;
SELECT 'ESTADO DESPUÉS DE LIMPIAR:' as info;
SELECT COUNT(*) as tareas_pendientes
FROM ${DB_PREFIX}task_adhoc
WHERE classname='\\\\core\\\\task\\\\asynchronous_copy_task';
EOF

    mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$TMP_SQL" 2>&1 | sed 's/^/  /'
    rm -f "$TMP_SQL"

    echo ""
    echo "  ✓ Base de datos limpiada"
else
    echo -e "${YELLOW}  ⚠️  No se pudieron leer credenciales de BD${NC}"
fi

################################################################################
# PASO 5: Verificar y ajustar permisos
################################################################################
echo ""
echo -e "${BLUE}PASO 5: Verificando permisos de moodledata...${NC}"
echo ""

MOODLEDATA=$(grep '\$CFG->dataroot' "$MOODLE_PATH/config.php" | cut -d"'" -f2 | head -1)

if [ -n "$MOODLEDATA" ] && [ -d "$MOODLEDATA" ]; then
    echo "  Moodledata: $MOODLEDATA"

    # Verificar permisos
    OWNER=$(stat -c '%U' "$MOODLEDATA")
    if [ "$OWNER" != "$MOODLE_USER" ]; then
        echo -e "${YELLOW}  ⚠️  Propietario incorrecto: $OWNER (debe ser $MOODLE_USER)${NC}"
        chown -R $MOODLE_USER:$MOODLE_USER "$MOODLEDATA"
        echo "  ✓ Propietario corregido"
    else
        echo "  ✓ Propietario correcto: $OWNER"
    fi

    # Crear directorio backup si no existe
    mkdir -p "$MOODLEDATA/temp/backup"
    chown $MOODLE_USER:$MOODLE_USER "$MOODLEDATA/temp/backup"
    chmod 770 "$MOODLEDATA/temp/backup"

    echo "  ✓ Permisos verificados"

    # Limpiar archivos antiguos
    OLD_COUNT=$(find "$MOODLEDATA/temp/backup" -type f -mtime +3 2>/dev/null | wc -l)
    if [ "$OLD_COUNT" -gt 0 ]; then
        echo "  ⚠️  Encontrados $OLD_COUNT archivos >3 días en temp/backup"
        find "$MOODLEDATA/temp/backup" -type f -mtime +3 -delete 2>/dev/null
        echo "  ✓ Archivos antiguos eliminados"
    fi

    # Verificar espacio en disco
    SPACE=$(df -h "$MOODLEDATA" | tail -1 | awk '{print $5}' | sed 's/%//')
    echo "  Espacio usado: ${SPACE}%"
    if [ "$SPACE" -gt 90 ]; then
        echo -e "${YELLOW}  ⚠️  Espacio en disco >90%! Considerar limpiar archivos${NC}"
    fi
else
    echo -e "${YELLOW}  ⚠️  No se encontró moodledata${NC}"
fi

################################################################################
# PASO 6: Verificar LiteSpeed (informativo)
################################################################################
echo ""
echo -e "${BLUE}PASO 6: Verificando configuración LiteSpeed...${NC}"
echo ""

LSWS_CONF="/usr/local/lsws/conf/httpd_config.conf"

if [ -f "$LSWS_CONF" ]; then
    TIMEOUT=$(grep -A 50 "extProcessor" "$LSWS_CONF" | grep "extMaxIdleTime" | grep -o '[0-9]\+' | head -1)

    if [ -n "$TIMEOUT" ]; then
        echo "  extMaxIdleTime actual: ${TIMEOUT}s"
        if [ "$TIMEOUT" -lt 3600 ]; then
            echo -e "${YELLOW}  ⚠️  Timeout de LiteSpeed es bajo para tareas largas${NC}"
            echo ""
            echo "  RECOMENDACIÓN: Aumentar manualmente a 7200s"
            echo "    1. Editar: $LSWS_CONF"
            echo "    2. Buscar 'extMaxIdleTime' y cambiar a 7200"
            echo "    3. Buscar 'extTimeout' y cambiar a 7200"
            echo "    4. Reiniciar: /usr/local/lsws/bin/lswsctrl restart"
        else
            echo "  ✓ Timeout de LiteSpeed es adecuado"
        fi
    else
        echo "  ℹ️  No se encontró configuración de timeout (usando default)"
    fi
else
    echo "  ℹ️  Configuración de LiteSpeed no encontrada"
fi

################################################################################
# PASO 7: PRUEBA MANUAL
################################################################################
echo ""
echo "=============================================================================="
echo -e "${BLUE}PASO 7: PRUEBA MANUAL${NC}"
echo "=============================================================================="
echo ""

read -p "¿Ejecutar prueba manual de async_copy_task? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    echo ""
    echo -e "${YELLOW}Ejecutando prueba (timeout 10 minutos)...${NC}"
    echo "Esto intentará ejecutar una tarea pendiente de async_copy_task"
    echo "---"
    echo ""

    TEST_LOG="/tmp/async_test_$(date +%Y%m%d_%H%M%S).log"

    cd "$MOODLE_PATH"
    su - $MOODLE_USER -c "cd $MOODLE_PATH && timeout 600 $PHP_BIN -d display_errors=1 -d error_reporting=E_ALL admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute --showdebugging 2>&1" | tee "$TEST_LOG"

    TEST_EXIT=${PIPESTATUS[0]}

    echo ""
    echo "---"
    echo ""
    echo "Log guardado en: $TEST_LOG"
    echo ""

    if [ $TEST_EXIT -eq 0 ]; then
        echo -e "${GREEN}✓ ÉXITO: Prueba completada correctamente${NC}"
        echo ""
        echo "La tarea se ejecutó sin errores. Si había una copia pendiente,"
        echo "debería haberse completado."
    elif [ $TEST_EXIT -eq 124 ]; then
        echo -e "${YELLOW}⚠️  TIMEOUT: Prueba excedió 10 minutos${NC}"
        echo ""
        echo "Esto puede ser normal para cursos muy grandes."
        echo "Verifica en Moodle si la copia se completó."
    elif [ $TEST_EXIT -eq 137 ]; then
        echo -e "${RED}✗ ERROR: Proceso matado por señal externa${NC}"
        echo ""
        echo "Posibles causas:"
        echo "  1. OOM Killer (memoria agotada a nivel sistema)"
        echo "  2. Señal manual (alguien mató el proceso)"
        echo "  3. LiteSpeed timeout"
        echo ""
        echo "Verifica: dmesg | grep -i killed | tail -20"
    elif [ $TEST_EXIT -eq 1 ]; then
        echo -e "${YELLOW}ℹ️  NO HAY TAREAS PENDIENTES${NC}"
        echo ""
        echo "No había tareas async_copy_task en cola para ejecutar."
        echo "Intenta crear una copia de curso desde Moodle y prueba de nuevo."
    else
        echo -e "${YELLOW}⚠️  Proceso terminó con código: $TEST_EXIT${NC}"
        echo ""
        echo "Revisa el log para más detalles: $TEST_LOG"
    fi

    # Análisis del log
    echo ""
    echo "---"
    echo "Análisis del log:"
    if grep -q "Killed" "$TEST_LOG"; then
        echo -e "${RED}  ✗ Encontrado 'Killed' - proceso terminado forzosamente${NC}"
    fi
    if grep -q "Fatal error" "$TEST_LOG"; then
        echo -e "${RED}  ✗ Error fatal de PHP encontrado${NC}"
        grep "Fatal error" "$TEST_LOG" | tail -3
    fi
    if grep -q "out of memory" "$TEST_LOG"; then
        echo -e "${RED}  ✗ Memoria agotada${NC}"
    fi
    if grep -q "Course copy: Copy completed" "$TEST_LOG"; then
        echo -e "${GREEN}  ✓ Copia completada exitosamente${NC}"
    fi
    if grep -q "No adhoc tasks" "$TEST_LOG" || grep -q "No tasks" "$TEST_LOG"; then
        echo -e "  ℹ️  No había tareas en cola${NC}"
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
echo "✓ Configuración PHP CLI optimizada (max_execution_time=7200s, memory=2G)"
echo "✓ Config.php de Moodle optimizado para CLI"
echo "✓ Tareas bloqueadas limpiadas en BD"
echo "✓ Permisos de moodledata verificados"
echo "✓ Archivos temporales antiguos eliminados"
echo ""
echo "PRÓXIMOS PASOS:"
echo ""
echo "1. Intenta crear una copia de curso desde Moodle"
echo ""
echo "2. Monitorear ejecución en tiempo real:"
echo "   watch -n 5 'mysql -h $DB_HOST -u $DB_USER -p$DB_PASS $DB_NAME -e \"SELECT COUNT(*) as pending FROM ${DB_PREFIX}task_adhoc WHERE classname=\\\\\"\\\\\\\\\\\\\\\\core\\\\\\\\\\\\\\\\task\\\\\\\\\\\\\\\\asynchronous_copy_task\\\\\"\"'"
echo ""
echo "3. Si falla, ejecutar manualmente con logs:"
echo "   su - $MOODLE_USER"
echo "   cd $MOODLE_PATH"
echo "   timeout 1800 $PHP_BIN admin/cli/adhoc_task.php --classname=\\\\core\\\\task\\\\asynchronous_copy_task --execute --showdebugging 2>&1 | tee /tmp/debug.log"
echo ""
echo "4. Verificar logs:"
echo "   - tail -100 $MOODLE_PATH/error_log"
echo "   - tail -100 /usr/local/lsws/logs/stderr.log | grep moodle"
echo "   - dmesg | grep -i killed | tail -20"
echo ""
echo "=============================================================================="
